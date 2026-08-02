<?php
// Εύρεση src/bootstrap.php ανεβαίνοντας φακέλους — ανθεκτικό σε διαφορετικά deployments.
for ($d = __DIR__; $d !== dirname($d); $d = dirname($d)) {
    if (is_file($d . '/src/bootstrap.php')) { require $d . '/src/bootstrap.php'; break; }
}
unset($d);
require PUBLIC_ROOT . '/assets/layout.php';
require_club();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];
$club = $_SESSION['user'];

// Επιλογή πρωταθλήματος (?g=gamecode)
$gamecode = $_GET['g'] ?? null;
if ($gamecode) {
    $game = db_one($pdo, "SELECT * FROM `{$T['games']}` WHERE gamecode=?", [$gamecode]);
} else {
    $game = db_one($pdo, "SELECT * FROM `{$T['games']}` WHERE status='Y' ORDER BY gameid DESC LIMIT 1");
}

if (!$game) {
    render_header('Δηλώσεις Ομάδων', 'club', 'teams');
    echo '<div class="card"><p class="muted">Δεν υπάρχει ενεργό πρωτάθλημα.</p></div>';
    render_footer();
    exit;
}

$open = is_registration_open($game);
$action = $_GET['action'] ?? '';
$editId = (int)($_GET['id'] ?? 0);
$edit = null;

// ----- POST operations --------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if (!$open) {
        flash_set('error', 'Η προθεσμία δηλώσεων έχει λήξει.');
        redirect(url('club/teams.php?g=' . urlencode($game['gamecode'])));
    }

    if ($op === 'delete_team') {
        $tid = (int)($_POST['teamid'] ?? 0);
        $t = db_one($pdo, "SELECT * FROM `{$T['teams']}` WHERE teamid=? AND clubcode=? AND gamecode=?", [$tid, $club['clubcode'], $game['gamecode']]);
        if (!$t) { flash_set('error', 'Δεν βρέθηκε ομάδα.'); }
        else {
            $pdo->beginTransaction();
            try {
                // Διαγραφή games2 εγγραφών των παικτών της ομάδας (βασικών + αναπληρωματικών)
                $allCodes = explode('-', $t['playercodes']);
                if (!empty($t['substitutes'])) {
                    foreach (explode('-', (string)$t['substitutes']) as $sc) {
                        if ($sc !== '') { $allCodes[] = $sc; }
                    }
                }
                $place = array_fill(0, count($allCodes), '?');
                $st = $pdo->prepare("DELETE FROM `{$T['games2']}` WHERE gamecode=? AND clubcode=? AND playercode1 IN (" . implode(',', $place) . ")");
                $st->execute(array_merge([$game['gamecode'], $club['clubcode']], $allCodes));
                // Διαγραφή ομάδας
                $pdo->prepare("DELETE FROM `{$T['teams']}` WHERE teamid=?")->execute([$tid]);
                // Αν δεν έμεινε καμία ομάδα, διαγραφή της aa εγγραφής
                $remaining = (int)db_one($pdo, "SELECT COUNT(*) c FROM `{$T['teams']}` WHERE clubcode=? AND gamecode=? AND status='Y'", [$club['clubcode'], $game['gamecode']])['c'];
                if ($remaining === 0) {
                    $pdo->prepare("DELETE FROM `{$T['aa']}` WHERE clubcode=? AND gamecode=?")->execute([$club['clubcode'], $game['gamecode']]);
                }
                $pdo->commit();
                flash_set('success', 'Η ομάδα διαγράφηκε.');
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash_set('error', 'Σφάλμα: ' . $e->getMessage());
            }
        }
        redirect(url('club/teams.php?g=' . urlencode($game['gamecode'])));
    }

    if ($op === 'save_team') {
        $tid = (int)($_POST['teamid'] ?? 0);
        $category = (string)($_POST['category'] ?? 'M');
        $playercodesRaw = trim((string)($_POST['playercodes'] ?? ''));
        $codes = array_values(array_filter(explode('-', $playercodesRaw), 'strlen'));

        // Αναπληρωματικός — μόνο για Τριπλέτες, max 1
        $substituteCode = trim((string)($_POST['substitute'] ?? ''));
        if ($game['gametype'] !== 'Triplets') {
            $substituteCode = '';
        }
        if ($substituteCode !== '' && in_array($substituteCode, $codes, true)) {
            flash_set('error', 'Ο αναπληρωματικός δεν μπορεί να είναι ταυτόχρονα και βασικός.');
            redirect(url('club/teams.php?g=' . urlencode($game['gamecode']) . '&action=' . ($tid ? "edit&id=$tid" : 'new') . '&cat=' . urlencode($category)));
        }

        $allowedCats = categories_for($game['gametype']);
        if (!in_array($category, $allowedCats, true)) {
            flash_set('error', 'Μη έγκυρη κατηγορία για αυτό το πρωτάθλημα.');
            redirect(url('club/teams.php?g=' . urlencode($game['gamecode']) . '&action=' . ($tid ? "edit&id=$tid" : 'new') . '&cat=' . urlencode($category)));
        }

        // Ανάκτηση αθλητών
        $playerRows = [];
        if ($codes) {
            $in = implode(',', array_fill(0, count($codes), '?'));
            $playerRows = db_all($pdo, "SELECT * FROM `{$T['players']}` WHERE playercode IN ($in)", $codes);
        }

        // Ανάκτηση αναπληρωματικού
        $substituteRow = null;
        if ($substituteCode !== '') {
            $substituteRow = db_one($pdo, "SELECT * FROM `{$T['players']}` WHERE playercode=?", [$substituteCode]);
            if (!$substituteRow) {
                flash_set('error', 'Μη έγκυρος αναπληρωματικός αθλητής.');
                redirect(url('club/teams.php?g=' . urlencode($game['gamecode']) . '&action=' . ($tid ? "edit&id=$tid" : 'new') . '&cat=' . urlencode($category)));
            }
            if ($substituteRow['clubcode'] !== $club['clubcode']) {
                flash_set('error', 'Ο αναπληρωματικός πρέπει να ανήκει στον σύλλογό σας.');
                redirect(url('club/teams.php?g=' . urlencode($game['gamecode'])));
            }
            if ($category === 'M' && $substituteRow['gender'] !== 'M') {
                flash_set('error', 'Στην κατηγορία Ανδρών ο αναπληρωματικός πρέπει να είναι άνδρας.');
                redirect(url('club/teams.php?g=' . urlencode($game['gamecode']) . '&action=' . ($tid ? "edit&id=$tid" : 'new') . '&cat=' . urlencode($category)));
            }
            if ($category === 'F' && $substituteRow['gender'] !== 'F') {
                flash_set('error', 'Στην κατηγορία Γυναικών η αναπληρωματική πρέπει να είναι γυναίκα.');
                redirect(url('club/teams.php?g=' . urlencode($game['gamecode']) . '&action=' . ($tid ? "edit&id=$tid" : 'new') . '&cat=' . urlencode($category)));
            }
        }

        // Ταυτοποίηση όλων των clubcodes ως ίδιος σύλλογος
        foreach ($playerRows as $pr) {
            if ($pr['clubcode'] !== $club['clubcode']) {
                flash_set('error', 'Επιλέξατε αθλητή που δεν ανήκει στον σύλλογό σας.');
                redirect(url('club/teams.php?g=' . urlencode($game['gamecode'])));
            }
        }

        $err = validate_team_composition($playerRows, $game['gametype'], $category);
        if ($err) {
            flash_set('error', $err);
            redirect(url('club/teams.php?g=' . urlencode($game['gamecode']) . '&action=' . ($tid ? "edit&id=$tid" : 'new') . '&cat=' . urlencode($category)));
        }

        // Όλοι οι αθλητές για uniqueness check (βασικοί + αναπληρωματικός)
        $allCodes = $codes;
        if ($substituteCode !== '') { $allCodes[] = $substituteCode; }
        $inAll = implode(',', array_fill(0, count($allCodes), '?'));

        // Simpler: πρώτα διαγράφουμε τις παλιές games2 της ομάδας (αν edit) μέσα σε transaction, μετά ελέγχουμε.
        try {
            $pdo->beginTransaction();

            if ($tid > 0) {
                $existing = db_one($pdo, "SELECT * FROM `{$T['teams']}` WHERE teamid=? AND clubcode=? AND gamecode=?", [$tid, $club['clubcode'], $game['gamecode']]);
                if (!$existing) throw new RuntimeException('Δεν βρέθηκε ομάδα προς επεξεργασία.');
                $oldCodes = explode('-', $existing['playercodes']);
                if (!empty($existing['substitutes'])) {
                    foreach (explode('-', (string)$existing['substitutes']) as $oc) {
                        if ($oc !== '') { $oldCodes[] = $oc; }
                    }
                }
                if ($oldCodes) {
                    $phOld = implode(',', array_fill(0, count($oldCodes), '?'));
                    $pdo->prepare("DELETE FROM `{$T['games2']}` WHERE gamecode=? AND clubcode=? AND playercode1 IN ($phOld)")
                        ->execute(array_merge([$game['gamecode'], $club['clubcode']], $oldCodes));
                }
            }

            // Έλεγχος ότι κανένας δεν είναι σε άλλη ομάδα (μετά τη διαγραφή) — είτε βασικός είτε αναπληρωματικός
            $conflict = db_one($pdo,
                "SELECT playercode1 FROM `{$T['games2']}` WHERE gamecode=? AND playercode1 IN ($inAll) LIMIT 1",
                array_merge([$game['gamecode']], $allCodes)
            );
            if ($conflict) {
                throw new RuntimeException('Ο αθλητής ' . $conflict['playercode1'] . ' είναι ήδη δηλωμένος σε άλλη ομάδα.');
            }

            // Υπολογισμός teamname + teamNumber
            if ($tid > 0) {
                // Διατηρούμε το ίδιο όνομα αν κατηγορία δεν άλλαξε
                $tn = $existing['teamname'];
                $teamNumber = parse_team_number_from_name($tn);
                $cat = $existing['category'] ?? $category;
                if ($cat !== $category) {
                    // Αναρίθμηση
                    [$tn, $teamNumber] = next_teamname($pdo, $T, $club, $game['gamecode'], $category);
                }
            } else {
                [$tn, $teamNumber] = next_teamname($pdo, $T, $club, $game['gamecode'], $category);
            }

            // Save team (substitutes: single code ή NULL)
            $subsField = $substituteCode !== '' ? $substituteCode : null;
            if ($tid > 0) {
                $pdo->prepare("UPDATE `{$T['teams']}` SET teamname=?, playercodes=?, substitutes=?, category=?, status='Y' WHERE teamid=?")
                    ->execute([$tn, implode('-', $codes), $subsField, $category, $tid]);
            } else {
                $pdo->prepare("INSERT INTO `{$T['teams']}` (teamname, playercodes, substitutes, gamecode, status, clubcode, category) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$tn, implode('-', $codes), $subsField, $game['gamecode'], 'Y', $club['clubcode'], $category]);
            }

            // Detect role column (exists only after ALTER TABLE app_games2 ADD COLUMN role)
            $hasRoleCol = false;
            try {
                $colChk = db_one($pdo, "SHOW COLUMNS FROM `{$T['games2']}` LIKE 'role'");
                $hasRoleCol = !empty($colChk);
            } catch (Throwable $e) { $hasRoleCol = false; }

            // Insert games2 εγγραφές — κάθε βασικός παίκτης παίρνει μοναδικό teamcode
            // Για ομάδα N μεγέθους k: indices (N-1)*k+1 ... N*k
            $teamSize = count($codes);
            if ($hasRoleCol) {
                $st = $pdo->prepare("INSERT INTO `{$T['games2']}` (playercode1, clubcode, gamecode, checkstatus, teamcode, `role`, `save`) VALUES (?,?,?,?,?,?,?)");
                foreach ($codes as $i => $pcode) {
                    $tcIndex = ($teamNumber - 1) * $teamSize + ($i + 1);
                    $st->execute([$pcode, $club['clubcode'], $game['gamecode'], 'Y', teamcode_for($category, $tcIndex), 'starter', 'Y']);
                }
                if ($substituteCode !== '') {
                    // Ο αναπληρωματικός παίρνει το teamcode της ομάδας (1ο index)
                    $tcIndex = ($teamNumber - 1) * $teamSize + 1;
                    $st->execute([$substituteCode, $club['clubcode'], $game['gamecode'], 'Y', teamcode_for($category, $tcIndex), 'substitute', 'Y']);
                }
            } else {
                $st = $pdo->prepare("INSERT INTO `{$T['games2']}` (playercode1, clubcode, gamecode, checkstatus, teamcode, `save`) VALUES (?,?,?,?,?,?)");
                foreach ($codes as $i => $pcode) {
                    $tcIndex = ($teamNumber - 1) * $teamSize + ($i + 1);
                    $st->execute([$pcode, $club['clubcode'], $game['gamecode'], 'Y', teamcode_for($category, $tcIndex), 'Y']);
                }
                if ($substituteCode !== '') {
                    $tcIndex = ($teamNumber - 1) * $teamSize + 1;
                    $st->execute([$substituteCode, $club['clubcode'], $game['gamecode'], 'Y', teamcode_for($category, $tcIndex), 'Y']);
                }
            }

            // Ενημέρωση aa
            $existsAA = db_one($pdo, "SELECT aaid FROM `{$T['aa']}` WHERE clubcode=? AND gamecode=?", [$club['clubcode'], $game['gamecode']]);
            if (!$existsAA) {
                $pdo->prepare("INSERT INTO `{$T['aa']}` (clubcode, gamecode, gamestatus) VALUES (?,?, 'Y')")
                    ->execute([$club['clubcode'], $game['gamecode']]);
            }

            $pdo->commit();
            flash_set('success', $tid ? 'Η ομάδα ενημερώθηκε.' : 'Η ομάδα δηλώθηκε.');
            redirect(url('club/teams.php?g=' . urlencode($game['gamecode'])));
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', $e->getMessage());
            redirect(url('club/teams.php?g=' . urlencode($game['gamecode'])));
        }
    }
}

/**
 * Επιστρέφει [teamname, teamNumber] για την επόμενη διαθέσιμη ομάδα της κατηγορίας.
 */
function next_teamname(PDO $pdo, array $T, array $club, string $gamecode, string $category): array
{
    // Μετρώ υπάρχοντα team numbers ίδιας κατηγορίας
    $rows = db_all(
        $pdo,
        "SELECT teamname FROM `{$T['teams']}` WHERE clubcode=? AND gamecode=? AND category=?",
        [$club['clubcode'], $gamecode, $category]
    );
    $used = [];
    foreach ($rows as $r) {
        $num = parse_team_number_from_name($r['teamname']);
        if ($num > 0) { $used[] = $num; }
    }
    $n = 1; while (in_array($n, $used, true)) { $n++; }

    $cs = db_one($pdo, "SELECT shortname FROM `{$T['clubs']}` WHERE clubcode=?", [$club['clubcode']]);
    $shortname = $cs['shortname'] ?? substr($club['clubname'], 0, 4);
    return [teamname_for($shortname, $category, $n), $n];
}

/**
 * Εξάγει τον αύξοντα αριθμό ομάδας από ένα teamname (π.χ. GAL3 -> 3, GAL2w -> 2, GALmix1 -> 1).
 * Αναγνωρίζει ρητά τα suffixes "w" και το "mix" για να μην μπερδεύεται με shortnames.
 */
function parse_team_number_from_name(string $teamname): int
{
    if (preg_match('/(\d+)w?$/', $teamname, $m)) {
        return (int)$m[1];
    }
    return 0;
}

// ----- GET views --------------------------------------------------------
if ($action === 'edit' && $editId > 0) {
    $edit = db_one($pdo, "SELECT * FROM `{$T['teams']}` WHERE teamid=? AND clubcode=? AND gamecode=?", [$editId, $club['clubcode'], $game['gamecode']]);
    if (!$edit) { flash_set('error', 'Δεν βρέθηκε ομάδα.'); redirect(url('club/teams.php?g=' . urlencode($game['gamecode']))); }
}
if ($action === 'new') {
    $edit = ['teamid' => 0, 'category' => $_GET['cat'] ?? (categories_for($game['gametype'])[0] ?? 'M'), 'playercodes' => '', 'substitutes' => ''];
}

// Φόρτωσε όλους τους παίκτες του συλλόγου
$players = db_all($pdo, "SELECT * FROM `{$T['players']}` WHERE clubcode=? AND active='Y' ORDER BY playercode", [$club['clubcode']]);
if (function_exists('hpf_decrypt_rows')) {
    $players = hpf_decrypt_rows($players, hpf_encrypted_cols('players'));
    usort($players, fn($a, $b) => strcasecmp((string)($a['lastname'] ?? ''), (string)($b['lastname'] ?? '')));
}

// Λίστα δηλωμένων ομάδων
$teams = db_all($pdo, "SELECT * FROM `{$T['teams']}` WHERE clubcode=? AND gamecode=? AND status='Y' ORDER BY category, teamname", [$club['clubcode'], $game['gamecode']]);

// Χάρτης δηλωμένων playercodes (για να τα κρύψω από επιλογή νέας ομάδας)
$declaredCodes = [];
$g2 = db_all($pdo, "SELECT playercode1 FROM `{$T['games2']}` WHERE clubcode=? AND gamecode=? AND checkstatus='Y'", [$club['clubcode'], $game['gamecode']]);
foreach ($g2 as $r) $declaredCodes[$r['playercode1']] = true;
// Για edit, επιτρέπουμε τους ήδη επιλεγμένους
$editSelected = [];
if ($edit && ($edit['playercodes'] ?? '')) {
    foreach (explode('-', $edit['playercodes']) as $pc) {
        $editSelected[$pc] = true;
        unset($declaredCodes[$pc]);
    }
}

render_header('Δηλώσεις — ' . $game['gamecode'], 'club', 'teams');
?>

<div class="main__header">
    <div>
        <h2 class="main__title">Δηλώσεις Ομάδων</h2>
        <p class="main__sub">
            <strong><?= h($game['name']) ?></strong> · <?= h(gametype_label($game['gametype'])) ?>
            <?php if (!empty($game['registration_deadline'])): ?>
                · Προθεσμία: <?= h(date('d/m/Y H:i', strtotime($game['registration_deadline']))) ?>
            <?php endif; ?>
        </p>
    </div>
    <?php if (!$edit && $open): ?>
        <div>
            <?php foreach (categories_for($game['gametype']) as $cat):
                $label = $cat === 'M' ? 'Ανδρικό' : ($cat === 'F' ? 'Γυναικείο' : 'Μεικτό'); ?>
                <a class="btn" href="<?= h(url('club/teams.php?g=' . urlencode($game['gamecode']) . '&action=new&cat=' . urlencode($cat))) ?>">+ Νέα <?= h($label) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (!$open): ?>
    <div class="flash flash--warning">Οι δηλώσεις για αυτό το πρωτάθλημα είναι κλειστές.</div>
<?php endif; ?>

<?php if ($edit):
    $cat = $edit['category'] ?? ($_GET['cat'] ?? 'M');
    $size = team_size_for($game['gametype']);
    $catLabel = $cat === 'M' ? 'Ανδρών' : ($cat === 'F' ? 'Γυναικών' : 'Μεικτό');
?>
<div class="card">
    <h3 class="card__title"><?= $edit['teamid'] ? 'Επεξεργασία' : 'Νέα' ?> Ομάδα — Κατηγορία <?= h($catLabel) ?> (<?= $size ?> παίκτες)</h3>
    <p class="card__subtitle">Επιλέξτε <?= $size ?> αθλητές από τη λίστα.
        <?php if ($cat === 'MIX'): ?> Απαιτείται <strong>1 άνδρας + 1 γυναίκα</strong>. <?php endif; ?>
    </p>

    <form method="post" data-team-builder data-team-size="<?= $size ?>" data-category="<?= h($cat) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="save_team">
        <input type="hidden" name="teamid" value="<?= (int)$edit['teamid'] ?>">
        <input type="hidden" name="category" value="<?= h($cat) ?>">
        <input type="hidden" name="playercodes" value="<?= h($edit['playercodes'] ?? '') ?>" data-selected-input>

        <div class="builder">
            <div>
                <div class="field__label">Αθλητές συλλόγου</div>
                <div class="players-list" role="listbox">
                    <?php
                    // Φιλτράρισμα βάσει κατηγορίας
                    $visible = array_filter($players, function($p) use ($cat) {
                        if ($cat === 'M')   return $p['gender'] === 'M';
                        if ($cat === 'F')   return $p['gender'] === 'F';
                        return true; // MIX -> όλοι
                    });
                    ?>
                    <?php if (empty($visible)): ?>
                        <div class="player-row muted">Δεν υπάρχουν διαθέσιμοι αθλητές.</div>
                    <?php endif; ?>
                    <?php foreach ($visible as $p):
                        $isDeclared = !empty($declaredCodes[$p['playercode']]);
                        $isSelected = !empty($editSelected[$p['playercode']]);
                    ?>
                        <label class="player-row <?= $isSelected ? 'player-row--selected' : '' ?> <?= $isDeclared ? 'player-row--disabled' : '' ?>"
                               data-player-row
                               data-playercode="<?= h($p['playercode']) ?>"
                               data-firstname="<?= h($p['firstname']) ?>"
                               data-lastname="<?= h($p['lastname']) ?>"
                               data-gender="<?= h($p['gender']) ?>">
                            <div>
                                <input type="checkbox" <?= $isSelected ? 'checked' : '' ?> <?= $isDeclared ? 'disabled' : '' ?>>
                                <strong><?= h($p['lastname']) ?></strong> <?= h($p['firstname']) ?>
                                <span class="muted">· <?= h($p['playercode']) ?></span>
                            </div>
                            <span class="badge badge--<?= h($p['gender']) ?>"><?= $p['gender']==='M'?'Α':'Γ' ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <div class="field__label">Επιλεγμένη ομάδα</div>
                <div class="selected-box" data-selected-summary></div>

                <?php if ($game['gametype'] === 'Triplets'): ?>
                    <?php
                    // Διαθέσιμοι αναπληρωματικοί: ίδιο φύλο κατηγορίας, ίδιος σύλλογος,
                    // όχι ήδη δηλωμένοι σε άλλη ομάδα αυτού του πρωταθλήματος.
                    $currentSub = (string)($edit['substitutes'] ?? '');
                    $subCandidates = array_filter($players, function($p) use ($cat, $declaredCodes, $currentSub) {
                        if ($cat === 'M' && $p['gender'] !== 'M') return false;
                        if ($cat === 'F' && $p['gender'] !== 'F') return false;
                        // Αν είναι ήδη δηλωμένος αλλού, κρύβεται (εκτός αν είναι ο τρέχων sub της ομάδας)
                        if (!empty($declaredCodes[$p['playercode']]) && $p['playercode'] !== $currentSub) return false;
                        return true;
                    });
                    ?>
                    <div class="field mt-16">
                        <div class="field__label">Αναπληρωματικός (προαιρετικά, max 1)</div>
                        <select name="substitute" class="input" data-substitute-select>
                            <option value="">— Κανένας —</option>
                            <?php foreach ($subCandidates as $p): ?>
                                <option value="<?= h($p['playercode']) ?>"
                                        data-playercode="<?= h($p['playercode']) ?>"
                                        <?= $currentSub === $p['playercode'] ? 'selected' : '' ?>>
                                    <?= h($p['lastname']) ?> <?= h($p['firstname']) ?> (<?= h($p['playercode']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="muted">Ο αναπληρωματικός πρέπει να είναι διαφορετικός από τους 3 βασικούς.</small>
                    </div>
                <?php endif; ?>

                <div class="mt-16">
                    <button class="btn" type="submit" data-submit-team disabled>Αποθήκευση Ομάδας</button>
                    <a class="btn btn--ghost" href="<?= h(url('club/teams.php?g=' . urlencode($game['gamecode']))) ?>">Ακύρωση</a>
                </div>
            </div>
        </div>
    </form>
</div>
<?php else: ?>
    <div class="card">
        <h3 class="card__title">Δηλωμένες Ομάδες (<?= count($teams) ?>)</h3>
        <?php if (!$teams): ?>
            <p class="muted">Δεν έχετε δηλώσει καμία ομάδα ακόμη.</p>
        <?php else: ?>
            <table class="table">
                <thead><tr><th>Όνομα</th><th>Κατηγορία</th><th>Παίκτες</th><th class="actions"></th></tr></thead>
                <tbody>
                <?php foreach ($teams as $t):
                    $codes = explode('-', $t['playercodes']);
                    $subCodes = [];
                    if (!empty($t['substitutes'])) {
                        foreach (explode('-', (string)$t['substitutes']) as $sc) {
                            if ($sc !== '') { $subCodes[] = $sc; }
                        }
                    }
                    $allCodes = array_values(array_unique(array_merge($codes, $subCodes)));
                    $in = implode(',', array_fill(0, count($allCodes), '?'));
                    $plist = db_all($pdo, "SELECT * FROM `{$T['players']}` WHERE playercode IN ($in)", $allCodes);
                    if (function_exists('hpf_decrypt_rows')) { $plist = hpf_decrypt_rows($plist, hpf_encrypted_cols('players')); }
                    // Index by playercode για γρήγορο lookup
                    $pmap = [];
                    foreach ($plist as $pl) { $pmap[$pl['playercode']] = $pl; }
                ?>
                    <tr>
                        <td><strong><?= h($t['teamname']) ?></strong></td>
                        <td><span class="badge badge--<?= h($t['category']) ?>">
                            <?= $t['category']==='M'?'Άνδρες':($t['category']==='F'?'Γυναίκες':'Μεικτό') ?>
                        </span></td>
                        <td>
                            <?php foreach ($codes as $pc): if (!isset($pmap[$pc])) continue; $pl = $pmap[$pc]; ?>
                                <span><?= h($pl['lastname']) ?> <?= h($pl['firstname']) ?> <span class="muted">(<?= h($pl['playercode']) ?>)</span></span><br>
                            <?php endforeach; ?>
                            <?php foreach ($subCodes as $pc): if (!isset($pmap[$pc])) continue; $pl = $pmap[$pc]; ?>
                                <span><em><?= h($pl['lastname']) ?> <?= h($pl['firstname']) ?></em> <span class="muted">(<?= h($pl['playercode']) ?>)</span> <span class="badge">Αναπληρωματικός</span></span><br>
                            <?php endforeach; ?>
                        </td>
                        <td class="actions">
                            <?php if ($open): ?>
                                <a class="btn btn--sm btn--ghost" href="<?= h(url('club/teams.php?g=' . urlencode($game['gamecode']) . '&action=edit&id=' . $t['teamid'])) ?>">Επεξεργασία</a>
                                <form method="post" style="display:inline" data-confirm="Διαγραφή ομάδας;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="op" value="delete_team">
                                    <input type="hidden" name="teamid" value="<?= (int)$t['teamid'] ?>">
                                    <button class="btn btn--sm btn--danger" type="submit">Διαγραφή</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php render_footer(); ?>

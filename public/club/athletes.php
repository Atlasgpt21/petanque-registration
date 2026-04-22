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
$readonly = is_readonly_external();

// Actions
$action = $_GET['action'] ?? '';
$editId = (int)($_GET['id'] ?? 0);
$edit = null;

if ($readonly && ($action === 'new' || $action === 'edit')) {
    flash_set('warning', 'Η διαχείριση αθλητών γίνεται στο κεντρικό σύστημα της ΕΟΠ. Εδώ μόνο προβολή.');
    redirect(url('club/athletes.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $readonly) {
    flash_set('error', 'Η διαχείριση αθλητών είναι απενεργοποιημένη σε αυτή την εγκατάσταση.');
    redirect(url('club/athletes.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'delete') {
        $pid = (int)($_POST['playerid'] ?? 0);
        // Έλεγχος δικαιώματος: μόνο αθλητής του συλλόγου
        $p = db_one($pdo, "SELECT * FROM `{$T['players']}` WHERE playerid=? AND clubcode=?", [$pid, $club['clubcode']]);
        if (!$p) { flash_set('error', 'Δεν βρέθηκε αθλητής.'); redirect(url('club/athletes.php')); }
        // Μη διαγραφή αν είναι ήδη σε ομάδα ενεργού πρωταθλήματος
        $in = db_one($pdo, "SELECT 1 FROM `{$T['games2']}` g2 JOIN `{$T['games']}` g ON g.gamecode=g2.gamecode WHERE g2.playercode1=? AND g.status='Y'", [$p['playercode']]);
        if ($in) {
            flash_set('error', 'Δεν μπορείτε να διαγράψετε παίκτη που είναι δηλωμένος σε ενεργό πρωτάθλημα.');
        } else {
            $pdo->prepare("DELETE FROM `{$T['players']}` WHERE playerid=? AND clubcode=?")->execute([$pid, $club['clubcode']]);
            flash_set('success', 'Ο αθλητής διαγράφηκε.');
        }
        redirect(url('club/athletes.php'));
    }

    if ($op === 'save') {
        $id = (int)($_POST['playerid'] ?? 0);
        $firstname = trim((string)($_POST['firstname'] ?? ''));
        $lastname  = trim((string)($_POST['lastname'] ?? ''));
        $gender    = (string)($_POST['gender'] ?? 'M');
        $birthdate = trim((string)($_POST['birthdate'] ?? '')) ?: null;
        $licenseno = trim((string)($_POST['licenseno'] ?? '')) ?: null;
        $playercode = trim((string)($_POST['playercode'] ?? ''));

        $errors = [];
        if ($firstname === '' || $lastname === '') { $errors[] = 'Απαιτούνται όνομα και επώνυμο.'; }
        if (!in_array($gender, ['M', 'F'], true)) { $errors[] = 'Μη έγκυρο φύλο.'; }

        if ($id > 0) {
            $existing = db_one($pdo, "SELECT * FROM `{$T['players']}` WHERE playerid=? AND clubcode=?", [$id, $club['clubcode']]);
            if (!$existing) { flash_set('error', 'Μη έγκυρη εγγραφή.'); redirect(url('club/athletes.php')); }
            if ($playercode === '') $playercode = $existing['playercode'];
        }

        if ($playercode === '') {
            // auto-generate 6-digit incrementing
            $max = db_one($pdo, "SELECT MAX(CAST(playercode AS UNSIGNED)) m FROM `{$T['players']}`")['m'] ?? 0;
            $playercode = str_pad((string)((int)$max + 1), 6, '0', STR_PAD_LEFT);
        }

        if (!$errors) {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE `{$T['players']}` SET firstname=?, lastname=?, gender=?, birthdate=?, licenseno=?, playercode=? WHERE playerid=? AND clubcode=?")
                        ->execute([$firstname, $lastname, $gender, $birthdate, $licenseno, $playercode, $id, $club['clubcode']]);
                    flash_set('success', 'Ο αθλητής ενημερώθηκε.');
                } else {
                    $pdo->prepare("INSERT INTO `{$T['players']}` (playercode, clubcode, firstname, lastname, gender, birthdate, licenseno, active) VALUES (?,?,?,?,?,?,?, 'Y')")
                        ->execute([$playercode, $club['clubcode'], $firstname, $lastname, $gender, $birthdate, $licenseno]);
                    flash_set('success', 'Ο αθλητής προστέθηκε.');
                }
                redirect(url('club/athletes.php'));
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errors[] = 'Ο κωδικός αθλητή υπάρχει ήδη.';
                } else {
                    $errors[] = 'Σφάλμα αποθήκευσης: ' . $e->getMessage();
                }
            }
        }
        foreach ($errors as $e) flash_set('error', $e);
        // Επαναφόρτωση με τα δεδομένα της φόρμας
        $edit = ['playerid' => $id, 'playercode' => $playercode, 'firstname' => $firstname, 'lastname' => $lastname, 'gender' => $gender, 'birthdate' => $birthdate, 'licenseno' => $licenseno];
        $action = 'edit';
    }
}

if ($action === 'edit' && $editId > 0 && !$edit) {
    $edit = db_one($pdo, "SELECT * FROM `{$T['players']}` WHERE playerid=? AND clubcode=?", [$editId, $club['clubcode']]);
    if (!$edit) { flash_set('error', 'Δεν βρέθηκε αθλητής.'); redirect(url('club/athletes.php')); }
}
if ($action === 'new' && !$edit) { $edit = ['playerid' => 0]; }

$players = db_all($pdo, "SELECT * FROM `{$T['players']}` WHERE clubcode=? ORDER BY playercode", [$club['clubcode']]);
if (function_exists('hpf_decrypt_rows')) {
    $players = hpf_decrypt_rows($players, hpf_encrypted_cols('players'));
    usort($players, fn($a, $b) => strcasecmp((string)($a['lastname'] ?? ''), (string)($b['lastname'] ?? '')));
}
if (isset($edit) && $edit && !empty($edit['playerid']) && function_exists('hpf_decrypt_row')) {
    $edit = hpf_decrypt_row($edit, hpf_encrypted_cols('players'));
}

render_header('Αθλητές — ' . $club['clubname'], 'club', 'athletes');
?>
<div class="main__header">
    <div>
        <h2 class="main__title">Αθλητές Συλλόγου</h2>
        <p class="main__sub"><?= count($players) ?> καταχωρημένοι αθλητές</p>
    </div>
    <?php if (!$edit && !$readonly): ?>
        <a class="btn" href="<?= h(url('club/athletes.php?action=new')) ?>">+ Νέος Αθλητής</a>
    <?php endif; ?>
</div>
<?php if ($readonly): ?>
    <div class="flash flash--info">Η λίστα αθλητών έρχεται από το κεντρικό σύστημα της ΕΟΠ. Για εγγραφές/διαγραφές, χρησιμοποιήστε το κεντρικό σύστημα.</div>
<?php endif; ?>

<?php if ($edit): ?>
    <div class="card">
        <h3 class="card__title"><?= $edit['playerid'] ? 'Επεξεργασία Αθλητή' : 'Νέος Αθλητής' ?></h3>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="save">
            <input type="hidden" name="playerid" value="<?= (int)($edit['playerid'] ?? 0) ?>">

            <div class="row">
                <div class="field">
                    <label class="field__label">Όνομα *</label>
                    <input class="input" type="text" name="firstname" value="<?= h($edit['firstname'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label class="field__label">Επώνυμο *</label>
                    <input class="input" type="text" name="lastname" value="<?= h($edit['lastname'] ?? '') ?>" required>
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label class="field__label">Φύλο *</label>
                    <select class="select" name="gender">
                        <option value="M" <?= ($edit['gender'] ?? '')==='M'?'selected':'' ?>>Άνδρας</option>
                        <option value="F" <?= ($edit['gender'] ?? '')==='F'?'selected':'' ?>>Γυναίκα</option>
                    </select>
                </div>
                <div class="field">
                    <label class="field__label">Ημ. Γέννησης</label>
                    <input class="input" type="date" name="birthdate" value="<?= h($edit['birthdate'] ?? '') ?>">
                </div>
                <div class="field">
                    <label class="field__label">Αρ. Δελτίου</label>
                    <input class="input" type="text" name="licenseno" value="<?= h($edit['licenseno'] ?? '') ?>">
                </div>
            </div>
            <div class="field">
                <label class="field__label">Κωδικός Αθλητή</label>
                <input class="input" type="text" name="playercode" value="<?= h($edit['playercode'] ?? '') ?>" placeholder="αφήστε κενό για αυτόματη δημιουργία">
                <span class="field__hint">6-ψήφιος κωδικός. Αν αφήσετε κενό θα δημιουργηθεί αυτόματα.</span>
            </div>
            <button class="btn" type="submit">Αποθήκευση</button>
            <a class="btn btn--ghost" href="<?= h(url('club/athletes.php')) ?>">Ακύρωση</a>
        </form>
    </div>
<?php else: ?>
    <div class="card">
        <table class="table">
            <thead>
                <tr>
                    <th>Κωδ.</th>
                    <th>Επώνυμο</th>
                    <th>Όνομα</th>
                    <th>Φύλο</th>
                    <th>Ημ. Γέν.</th>
                    <th>Δελτίο</th>
                    <th class="actions"></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$players): ?>
                <tr><td colspan="7" class="muted tc">Δεν έχετε καταχωρήσει αθλητές.</td></tr>
            <?php endif; ?>
            <?php foreach ($players as $p): ?>
                <tr>
                    <td><code><?= h($p['playercode']) ?></code></td>
                    <td><strong><?= h($p['lastname']) ?></strong></td>
                    <td><?= h($p['firstname']) ?></td>
                    <td><span class="badge badge--<?= h($p['gender']) ?>"><?= h(gender_label($p['gender'])) ?></span></td>
                    <td><?= h($p['birthdate'] ?? '—') ?></td>
                    <td><?= h($p['licenseno'] ?? '—') ?></td>
                    <td class="actions">
                        <?php if (!$readonly): ?>
                            <a class="btn btn--sm btn--ghost" href="<?= h(url('club/athletes.php?action=edit&id=' . $p['playerid'])) ?>">Επεξεργασία</a>
                            <form method="post" style="display:inline" onsubmit="return confirm('Διαγραφή αθλητή;');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="op" value="delete">
                                <input type="hidden" name="playerid" value="<?= (int)$p['playerid'] ?>">
                                <button class="btn btn--sm btn--danger" type="submit">Διαγραφή</button>
                            </form>
                        <?php else: ?>
                            <span class="muted">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php render_footer(); ?>

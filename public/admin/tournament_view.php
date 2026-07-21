<?php
// Εύρεση src/bootstrap.php ανεβαίνοντας φακέλους — ανθεκτικό σε διαφορετικά deployments.
for ($d = __DIR__; $d !== dirname($d); $d = dirname($d)) {
    if (is_file($d . '/src/bootstrap.php')) { require $d . '/src/bootstrap.php'; break; }
}
unset($d);
require PUBLIC_ROOT . '/assets/layout.php';
require_admin();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];

$id   = (int)($_GET['id'] ?? 0);
$tour = tour_get($pdo, $id);
if (!$tour) {
    flash_set('error', 'Η διοργάνωση δεν βρέθηκε.');
    redirect(url('admin/tournaments.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    try {
        if ($op === 'import') {
            $n = tour_import_teams($pdo, $id, $tour['gamecode']);
            flash_set($n > 0 ? 'success' : 'info', $n > 0 ? "Προστέθηκαν $n ομάδες." : 'Δεν βρέθηκαν νέες ομάδες.');
        } elseif ($op === 'delete_team') {
            tour_delete_team($pdo, $id, (int)($_POST['team_id'] ?? 0));
            flash_set('success', 'Η ομάδα αφαιρέθηκε.');
        } elseif ($op === 'toggle_withdrawn') {
            tour_set_team_withdrawn($pdo, $id, (int)($_POST['team_id'] ?? 0), ($_POST['withdrawn'] ?? '') === '1');
            flash_set('success', 'Ενημερώθηκε η συμμετοχή της ομάδας.');
        } elseif ($op === 'generate_round') {
            $rn = tour_generate_round($pdo, $id);
            flash_set('success', "Δημιουργήθηκε ο γύρος $rn.");
        } elseif ($op === 'save_results') {
            $roundNo = (int)($_POST['round_no'] ?? 0);
            $results = [];
            foreach (($_POST['home'] ?? []) as $mid => $v) { $results[(int)$mid]['home'] = $v; }
            foreach (($_POST['away'] ?? []) as $mid => $v) { $results[(int)$mid]['away'] = $v; }
            tour_save_results($pdo, $id, $roundNo, $results);
            flash_set('success', "Αποθηκεύτηκαν τα αποτελέσματα του γύρου $roundNo.");
        } elseif ($op === 'delete_last_round') {
            tour_delete_last_round($pdo, $id);
            flash_set('success', 'Ο τελευταίος γύρος διαγράφηκε.');
        } elseif ($op === 'finish') {
            $pdo->prepare("UPDATE `" . tour_tables()['tournaments'] . "` SET status='finished' WHERE id=?")->execute([$id]);
            flash_set('success', 'Η διοργάνωση ολοκληρώθηκε.');
        } elseif ($op === 'reopen') {
            $pdo->prepare("UPDATE `" . tour_tables()['tournaments'] . "` SET status='running' WHERE id=?")->execute([$id]);
            flash_set('success', 'Η διοργάνωση άνοιξε ξανά.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(url('admin/tournament_view.php?id=' . $id));
}

$teams     = tour_teams($pdo, $id);
$rounds    = tour_rounds($pdo, $id);
$standings = tour_standings($pdo, $id);
$labels    = tour_team_labels($pdo, $id);
$game      = db_one($pdo, "SELECT * FROM `{$T['games']}` WHERE gamecode=?", [$tour['gamecode']]);

$hasRounds = $rounds !== [];
$lastRound = 0;
$lastCompleted = true;
foreach ($rounds as $r) {
    if ((int)$r['round_no'] >= $lastRound) {
        $lastRound = (int)$r['round_no'];
        $lastCompleted = $r['status'] === 'completed';
    }
}
$activeCount = 0;
foreach ($teams as $t) { if ((int)$t['withdrawn'] === 0) { $activeCount++; } }

render_header('Διοργάνωση: ' . $tour['name'], 'admin', 'tournaments');
?>
<div class="main__header">
    <div>
        <h2 class="main__title"><?= h($tour['name']) ?></h2>
        <p class="main__sub">
            Ελβετικό Σύστημα · Πρωτάθλημα <code><?= h($tour['gamecode']) ?></code>
            <?= $game ? '— ' . h($game['name']) : '' ?>
            · <span class="badge <?= $tour['status']==='finished'?'badge--off':($tour['status']==='running'?'badge--on':'') ?>">
                <?= $tour['status']==='finished'?'Ολοκληρώθηκε':($tour['status']==='running'?'Σε εξέλιξη':'Προετοιμασία') ?>
            </span>
        </p>
    </div>
    <div>
        <a class="btn btn--ghost btn--sm" href="<?= h(url('admin/tournaments.php')) ?>">← Πίσω</a>
        <a class="btn btn--ghost btn--sm" href="<?= h(url('admin/tournament_print.php?id=' . $id)) ?>" target="_blank">Εκτύπωση</a>
    </div>
</div>

<div class="grid grid--4">
    <div class="stat"><div class="stat__label">Ομάδες</div><div class="stat__value"><?= count($teams) ?></div></div>
    <div class="stat"><div class="stat__label">Ενεργές</div><div class="stat__value"><?= $activeCount ?></div></div>
    <div class="stat"><div class="stat__label">Γύροι</div><div class="stat__value"><?= count($rounds) ?></div></div>
    <div class="stat"><div class="stat__label">Τρέχων γύρος</div><div class="stat__value"><?= $lastRound ?: '—' ?></div></div>
</div>

<!-- Ομάδες / Import -->
<div class="card">
    <div class="main__header" style="margin-bottom:12px;">
        <h3 class="card__title" style="margin:0;">Ομάδες (<?= count($teams) ?>)</h3>
        <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="import">
            <button class="btn btn--sm btn--accent" type="submit">↻ Λήψη ομάδων από το πρωτάθλημα</button>
        </form>
    </div>
    <?php if (!$teams): ?>
        <p class="muted">Καμία ομάδα. Πατήστε «Λήψη ομάδων» για αυτόματη εισαγωγή των δηλωμένων ομάδων του πρωταθλήματος <code><?= h($tour['gamecode']) ?></code>.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Seed</th><th>Ομάδα</th><th>Σύλλογος</th><th>Κατάσταση</th><th class="actions"></th></tr></thead>
            <tbody>
            <?php foreach ($teams as $t): ?>
                <tr>
                    <td><?= $t['seed'] !== null ? (int)$t['seed'] : '—' ?></td>
                    <td><strong><?= h($t['teamname']) ?></strong></td>
                    <td><?= h($t['clubcode'] ?? '—') ?></td>
                    <td>
                        <?php if ((int)$t['withdrawn'] === 1): ?>
                            <span class="badge badge--off">Αποχώρησε</span>
                        <?php else: ?>
                            <span class="badge badge--on">Ενεργή</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <form method="post" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="op" value="toggle_withdrawn">
                            <input type="hidden" name="team_id" value="<?= (int)$t['id'] ?>">
                            <input type="hidden" name="withdrawn" value="<?= (int)$t['withdrawn'] === 1 ? '0' : '1' ?>">
                            <button class="btn btn--sm btn--ghost" type="submit"><?= (int)$t['withdrawn'] === 1 ? 'Επαναφορά' : 'Αποχώρηση' ?></button>
                        </form>
                        <?php if (!$hasRounds): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Αφαίρεση ομάδας;');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="op" value="delete_team">
                                <input type="hidden" name="team_id" value="<?= (int)$t['id'] ?>">
                                <button class="btn btn--sm btn--danger" type="submit">✕</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($hasRounds): ?><p class="field__hint mt-8">Η αφαίρεση ομάδων απενεργοποιείται μόλις ξεκινήσουν οι γύροι — χρησιμοποιήστε «Αποχώρηση».</p><?php endif; ?>
    <?php endif; ?>
</div>

<!-- Κατάταξη -->
<div class="card">
    <h3 class="card__title">Κατάταξη</h3>
    <?php if (!$standings): ?>
        <p class="muted">—</p>
    <?php else: ?>
    <table class="table">
        <thead><tr>
            <th>#</th><th>Ομάδα</th>
            <th class="tc" title="Αγώνες">Αγ.</th>
            <th class="tc" title="Νίκες">Ν</th>
            <th class="tc" title="Ισοπαλίες">Ι</th>
            <th class="tc" title="Ήττες">Η</th>
            <th class="tc" title="Βαθμοί">Βαθ.</th>
            <th class="tc" title="Buchholz">Buch.</th>
            <th class="tc" title="Fine Buchholz">F.Buch.</th>
            <th class="tc" title="Διαφορά πόντων">Διαφ.</th>
            <th class="tc" title="Υπέρ:Κατά">Πόντοι</th>
        </tr></thead>
        <tbody>
        <?php foreach ($standings as $s): ?>
            <tr<?= !empty($s['withdrawn']) ? ' class="muted"' : '' ?>>
                <td><?= (int)$s['rank'] ?></td>
                <td><strong><?= h($s['label']) ?></strong><?= !empty($s['withdrawn']) ? ' <span class="badge badge--off">αποχ.</span>' : '' ?></td>
                <td class="tc"><?= (int)$s['played'] ?></td>
                <td class="tc"><?= (int)$s['wins'] ?></td>
                <td class="tc"><?= (int)$s['draws'] ?></td>
                <td class="tc"><?= (int)$s['losses'] ?></td>
                <td class="tc"><strong><?= (int)$s['points'] ?></strong></td>
                <td class="tc"><?= (int)$s['buchholz'] ?></td>
                <td class="tc"><?= (int)$s['fine_buchholz'] ?></td>
                <td class="tc"><?= ($s['diff'] > 0 ? '+' : '') . (int)$s['diff'] ?></td>
                <td class="tc"><?= (int)$s['pf'] ?>:<?= (int)$s['pa'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="field__hint mt-8">Ισοβαθμία: Βαθμοί → Buchholz → Fine Buchholz → Διαφορά πόντων.</p>
    <?php endif; ?>
</div>

<!-- Ενέργειες γύρων -->
<div class="card">
    <h3 class="card__title">Γύροι</h3>
    <?php
        $canGenerate = $activeCount >= 2 && (!$hasRounds || $lastCompleted) && $tour['status'] !== 'finished';
    ?>
    <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="generate_round">
        <button class="btn" type="submit" <?= $canGenerate ? '' : 'disabled' ?>>
            + Κλήρωση <?= $hasRounds ? 'επόμενου γύρου (' . ($lastRound + 1) . ')' : '1ου γύρου' ?>
        </button>
    </form>
    <?php if ($hasRounds && $tour['status'] !== 'finished'): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Διαγραφή τελευταίου γύρου (<?= $lastRound ?>);');">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="delete_last_round">
            <button class="btn btn--ghost" type="submit">Διαγραφή γύρου <?= $lastRound ?></button>
        </form>
    <?php endif; ?>
    <?php if ($hasRounds): ?>
        <?php if ($tour['status'] !== 'finished'): ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="finish">
                <button class="btn btn--ghost" type="submit" <?= $lastCompleted ? '' : 'disabled' ?>>Ολοκλήρωση διοργάνωσης</button>
            </form>
        <?php else: ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="reopen">
                <button class="btn btn--ghost" type="submit">Άνοιγμα ξανά</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    <?php if (!$canGenerate && $activeCount >= 2 && $hasRounds && !$lastCompleted): ?>
        <p class="field__hint mt-8">Καταχωρήστε όλα τα αποτελέσματα του γύρου <?= $lastRound ?> για να κληρωθεί ο επόμενος.</p>
    <?php endif; ?>
</div>

<!-- Αγώνες ανά γύρο (νεότερος πρώτος) -->
<?php foreach (array_reverse($rounds) as $r): $rn = (int)$r['round_no']; $matches = tour_matches($pdo, $id, $rn); ?>
<div class="card">
    <h3 class="card__title">
        Γύρος <?= $rn ?>
        <span class="badge <?= $r['status']==='completed'?'badge--on':'' ?>" style="margin-left:.5rem;">
            <?= $r['status']==='completed' ? 'Ολοκληρώθηκε' : 'Σε εξέλιξη' ?>
        </span>
    </h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="save_results">
        <input type="hidden" name="round_no" value="<?= $rn ?>">
        <table class="table">
            <thead><tr><th>Πίστα</th><th class="right">Γηπεδούχος</th><th class="tc">Σκορ</th><th>Φιλοξενούμενος</th><th class="tc">Αποτ.</th></tr></thead>
            <tbody>
            <?php foreach ($matches as $m):
                $mid = (int)$m['id'];
                $homeLabel = $labels[(int)$m['home_team_id']] ?? ('#' . (int)$m['home_team_id']);
                if ((int)$m['is_bye'] === 1): ?>
                    <tr>
                        <td><?= (int)$m['board_no'] ?></td>
                        <td class="right"><strong><?= h($homeLabel) ?></strong></td>
                        <td class="tc" colspan="2"><span class="badge badge--on">ΡΕΠΟ (νίκη <?= (int)$m['home_score'] ?>:<?= (int)$m['away_score'] ?>)</span></td>
                        <td class="tc">✓</td>
                    </tr>
                <?php else:
                    $awayLabel = $labels[(int)$m['away_team_id']] ?? ('#' . (int)$m['away_team_id']);
                    $hs = $m['home_score']; $as = $m['away_score'];
                    $res = '—';
                    if ($m['status'] === 'played') {
                        $res = ((int)$hs > (int)$as) ? '◄' : (((int)$hs < (int)$as) ? '►' : '=');
                    }
                    $locked = $tour['status'] === 'finished';
                ?>
                    <tr>
                        <td><?= (int)$m['board_no'] ?></td>
                        <td class="right"><strong><?= h($homeLabel) ?></strong></td>
                        <td class="tc nowrap">
                            <input class="input" style="width:56px;display:inline-block;text-align:center" type="number" min="0" max="99"
                                   name="home[<?= $mid ?>]" value="<?= $hs === null ? '' : (int)$hs ?>" <?= $locked ? 'disabled' : '' ?>>
                            :
                            <input class="input" style="width:56px;display:inline-block;text-align:center" type="number" min="0" max="99"
                                   name="away[<?= $mid ?>]" value="<?= $as === null ? '' : (int)$as ?>" <?= $locked ? 'disabled' : '' ?>>
                        </td>
                        <td><strong><?= h($awayLabel) ?></strong></td>
                        <td class="tc"><?= $res ?></td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($tour['status'] !== 'finished'): ?>
            <button class="btn" type="submit">Αποθήκευση αποτελεσμάτων</button>
        <?php endif; ?>
    </form>
</div>
<?php endforeach; ?>

<?php render_footer(); ?>

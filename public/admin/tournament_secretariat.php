<?php
// Γραμματεία αγώνων — αποκλειστικά καταχώρηση σκορ ανά γύρο/στάδιο.
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
    if (($_POST['op'] ?? '') === 'save_results') {
        $roundNo = (int)($_POST['round_no'] ?? 0);
        $results = [];
        foreach (($_POST['home'] ?? []) as $mid => $v) { $results[(int)$mid]['home'] = $v; }
        foreach (($_POST['away'] ?? []) as $mid => $v) { $results[(int)$mid]['away'] = $v; }
        try {
            tour_save_results($pdo, $id, $roundNo, $results);
            flash_set('success', "Αποθηκεύτηκαν τα αποτελέσματα του γύρου $roundNo.");
        } catch (\Throwable $e) {
            flash_set('error', $e->getMessage());
        }
    }
    redirect(url('admin/tournament_secretariat.php?id=' . $id . '#round-' . (int)($_POST['round_no'] ?? 0)));
}

$rounds   = tour_rounds($pdo, $id);
$labels   = tour_team_labels($pdo, $id);
$locked   = $tour['status'] === 'finished';
$cat      = (string)($tour['category'] ?? 'ALL');
$catLabel = tour_category_label($cat);

render_header('Γραμματεία: ' . $tour['name'], 'admin', 'tournaments');
?>
<div class="main__header">
    <div>
        <h2 class="main__title">📝 Γραμματεία — <?= h($tour['name']) ?>
            <?php if ($catLabel !== ''): ?><span class="badge badge--<?= h($cat) ?>"><?= h($catLabel) ?></span><?php endif; ?>
        </h2>
        <p class="main__sub">Καταχώρηση αποτελεσμάτων. Ο γύρος ολοκληρώνεται μόλις συμπληρωθούν όλα τα σκορ.</p>
    </div>
    <div>
        <a class="btn btn--ghost btn--sm" href="<?= h(url('admin/tournament_view.php?id=' . $id)) ?>">← Διαχείριση</a>
    </div>
</div>

<?php if ($locked): ?>
    <div class="flash flash--warning">Η διοργάνωση είναι ολοκληρωμένη. Ανοίξτε την ξανά από τη Διαχείριση για αλλαγές.</div>
<?php endif; ?>

<?php if (!$rounds): ?>
    <div class="card"><p class="muted">Δεν έχουν κληρωθεί γύροι ακόμη.</p></div>
<?php endif; ?>

<?php foreach (array_reverse($rounds) as $r): $rn = (int)$r['round_no']; $matches = tour_matches($pdo, $id, $rn);
    $phase = $r['phase'] ?? 'swiss';
    $stage = (string)($r['stage'] ?? '');
    $title = $phase === 'swiss' ? "Γύρος $rn" : (($phase === 'friendship' ? 'Κύπελλο Φιλίας' : 'Knockout') . ($stage !== '' ? ' — ' . $stage : ''));
?>
<div class="card" id="round-<?= $rn ?>">
    <div class="main__header" style="margin-bottom:12px;">
        <h3 class="card__title" style="margin:0;">
            <?= h($title) ?>
            <span class="badge <?= $r['status']==='completed'?'badge--on':'' ?>" style="margin-left:.5rem;"><?= $r['status']==='completed' ? 'Ολοκληρώθηκε' : 'Σε εξέλιξη' ?></span>
        </h3>
        <a class="btn btn--ghost btn--sm" href="<?= h(url('admin/tournament_match_sheets.php?id=' . $id . '&round=' . $rn)) ?>" target="_blank">🖨 Φύλλα αγώνα</a>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="save_results">
        <input type="hidden" name="round_no" value="<?= $rn ?>">
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Γήπεδο</th><th class="right">Γηπεδούχος</th><th class="tc">Σκορ</th><th>Φιλοξενούμενος</th><th class="tc">Αποτ.</th></tr></thead>
            <tbody>
            <?php foreach ($matches as $m):
                $mid = (int)$m['id'];
                $homeLabel = $m['home_team_id'] !== null ? ($labels[(int)$m['home_team_id']] ?? ('#' . (int)$m['home_team_id'])) : '—';
                $courtLbl = $m['court_no'] !== null ? (int)$m['court_no'] : (int)$m['board_no'];
                if ((int)$m['is_bye'] === 1): ?>
                    <tr>
                        <td>—</td>
                        <td class="right"><strong><?= h($homeLabel) ?></strong></td>
                        <td class="tc" colspan="2"><span class="badge badge--on">Bye<?= $m['home_score'] !== null ? ' (' . (int)$m['home_score'] . ':' . (int)$m['away_score'] . ')' : '' ?></span></td>
                        <td class="tc">✓</td>
                    </tr>
                <?php else:
                    $awayLabel = $m['away_team_id'] !== null ? ($labels[(int)$m['away_team_id']] ?? ('#' . (int)$m['away_team_id'])) : '—';
                    $hs = $m['home_score']; $as = $m['away_score'];
                    $res = '—';
                    if ($m['status'] === 'played') {
                        $res = ((int)$hs > (int)$as) ? '◄' : (((int)$hs < (int)$as) ? '►' : '=');
                    }
                ?>
                    <tr>
                        <td><strong><?= $courtLbl ?></strong></td>
                        <td class="right"><strong><?= h($homeLabel) ?></strong></td>
                        <td class="tc nowrap">
                            <input class="input" style="width:56px;display:inline-block;text-align:center" type="number" min="0" max="13"
                                   name="home[<?= $mid ?>]" value="<?= $hs === null ? '' : (int)$hs ?>" <?= $locked ? 'disabled' : '' ?>>
                            :
                            <input class="input" style="width:56px;display:inline-block;text-align:center" type="number" min="0" max="13"
                                   name="away[<?= $mid ?>]" value="<?= $as === null ? '' : (int)$as ?>" <?= $locked ? 'disabled' : '' ?>>
                        </td>
                        <td><strong><?= h($awayLabel) ?></strong></td>
                        <td class="tc"><?= $res ?></td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if (!$locked): ?>
            <button class="btn" type="submit">Αποθήκευση αποτελεσμάτων</button>
        <?php endif; ?>
    </form>
</div>
<?php endforeach; ?>

<?php render_footer(); ?>

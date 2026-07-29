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
            flash_set('success', "Δημιουργήθηκε ο γύρος $rn. Ανοίγουν τα φύλλα αγώνα για εκτύπωση.");
            redirect(url('admin/tournament_view.php?id=' . $id . '&print=' . $rn));
        } elseif ($op === 'generate_ko') {
            $phase = ($_POST['phase'] ?? '') === 'friendship' ? 'friendship' : 'ko';
            $rn = tour_generate_ko($pdo, $id, $phase);
            flash_set('success', "Κληρώθηκε νέο στάδιο (γύρος $rn). Ανοίγουν τα φύλλα αγώνα.");
            redirect(url('admin/tournament_view.php?id=' . $id . '&print=' . $rn));
        } elseif ($op === 'save_results') {
            $roundNo = (int)($_POST['round_no'] ?? 0);
            $results = [];
            foreach (($_POST['home'] ?? []) as $mid => $v) { $results[(int)$mid]['home'] = $v; }
            foreach (($_POST['away'] ?? []) as $mid => $v) { $results[(int)$mid]['away'] = $v; }
            tour_save_results($pdo, $id, $roundNo, $results);
            flash_set('success', "Αποθηκεύτηκαν τα αποτελέσματα του γύρου $roundNo.");
            redirect(url('admin/tournament_view.php?id=' . $id . '&r=' . $roundNo));
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

$teams       = tour_teams($pdo, $id);
$rounds      = tour_rounds($pdo, $id);
$standings   = tour_standings($pdo, $id);
$labels      = tour_team_labels($pdo, $id);
$labelsShort = tour_team_labels_short($pdo, $id);
$game        = db_one($pdo, "SELECT * FROM `{$T['games']}` WHERE gamecode=?", [$tour['gamecode']]);

$cat      = (string)($tour['category'] ?? 'ALL');
$catLabel = tour_category_label($cat);

$swissRounds  = tour_rounds_phase($pdo, $id, 'swiss');
$koRounds     = tour_rounds_phase($pdo, $id, 'ko');
$friendRounds = tour_rounds_phase($pdo, $id, 'friendship');

$hasRounds = $rounds !== [];
$lastRound = tour_last_round_no($pdo, $id);
$activeCount = 0;
foreach ($teams as $t) { if ((int)$t['withdrawn'] === 0) { $activeCount++; } }

// Κατάσταση φάσεων.
$roundsPlanned = (int)($tour['rounds_planned'] ?? 5);
if ($roundsPlanned < 1) { $roundsPlanned = 5; }
$swissCompletedCount = 0;
foreach ($swissRounds as $r) { if ($r['status'] === 'completed') { $swissCompletedCount++; } }
// Η knockout ενεργοποιείται μόνο όταν ολοκληρωθούν ΟΛΟΙ οι προγραμματισμένοι
// γύροι του Swiss (π.χ. και οι 5).
$swissComplete = $swissRounds !== [] && $swissCompletedCount >= $roundsPlanned;
$lastSwissCompleted = true;
$lastSwissNo = 0;
foreach ($swissRounds as $r) { $lastSwissNo = max($lastSwissNo, (int)$r['round_no']); }
foreach ($swissRounds as $r) { if ((int)$r['round_no'] === $lastSwissNo) { $lastSwissCompleted = $r['status'] === 'completed'; } }

$koStarted = $koRounds !== [];
$koDone = false; $koLastCompleted = true;
if ($koStarted) {
    $lastKoNo = 0; foreach ($koRounds as $r) { $lastKoNo = max($lastKoNo, (int)$r['round_no']); }
    foreach ($koRounds as $r) { if ((int)$r['round_no'] === $lastKoNo) { $koLastCompleted = $r['status'] === 'completed'; } }
    foreach (tour_matches($pdo, $id, $lastKoNo) as $m) { if (($m['stage'] ?? '') === 'Τελικός') { $koDone = true; } }
}
$frStarted = $friendRounds !== [];
$frDone = false; $frLastCompleted = true;
if ($frStarted) {
    $lastFrNo = 0; foreach ($friendRounds as $r) { $lastFrNo = max($lastFrNo, (int)$r['round_no']); }
    foreach ($friendRounds as $r) { if ((int)$r['round_no'] === $lastFrNo) { $frLastCompleted = $r['status'] === 'completed'; } }
    foreach (tour_matches($pdo, $id, $lastFrNo) as $m) { if (($m['stage'] ?? '') === 'Τελικός') { $frDone = true; } }
}

$canSwiss = $activeCount >= 2 && !$koStarted && !$frStarted && $lastSwissCompleted
    && $lastSwissNo < $roundsPlanned && $tour['status'] !== 'finished';
$koSize   = (int)($tour['ko_size'] ?? 0);
$courts   = (int)($tour['courts'] ?? 0);

$sheetsUrl = static fn (int $r): string => url('admin/tournament_match_sheets.php?id=' . $id . '&round=' . $r);
$printNow  = isset($_GET['print']) ? (int)$_GET['print'] : 0;

$isFinished = $tour['status'] === 'finished';
$finalStandings = ($isFinished && $hasRounds) ? tour_final_standings($pdo, $id) : [];

// Επιλεγμένος γύρος για το card «Τρέχων γύρος» (default: ο τελευταίος).
$roundNos = array_map(static fn (array $r): int => (int)$r['round_no'], $rounds);
$viewRound = $lastRound;
if (isset($_GET['r']) && in_array((int)$_GET['r'], $roundNos, true)) {
    $viewRound = (int)$_GET['r'];
}
$viewRoundRow = null;
foreach ($rounds as $r) { if ((int)$r['round_no'] === $viewRound) { $viewRoundRow = $r; } }
$viewMatches = $viewRound > 0 ? tour_matches($pdo, $id, $viewRound) : [];

render_header('Διοργάνωση: ' . $tour['name'], 'admin', 'tournaments');
?>
<div class="main__header">
    <div>
        <h2 class="main__title">
            <?= h($tour['name']) ?>
            <?php if ($catLabel !== ''): ?><span class="badge badge--<?= h($cat) ?>" style="vertical-align:middle;"><?= h($catLabel) ?></span><?php endif; ?>
        </h2>
        <p class="main__sub">
            Ελβετικό Σύστημα · Πρωτάθλημα <code><?= h($tour['gamecode']) ?></code>
            <?= $game ? '— ' . h($game['name']) : '' ?>
            · <span class="badge <?= $tour['status']==='finished'?'badge--off':($tour['status']==='running'?'badge--on':'') ?>">
                <?= $tour['status']==='finished'?'Ολοκληρώθηκε':($tour['status']==='running'?'Σε εξέλιξη':'Προετοιμασία') ?>
            </span>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-start">
        <a class="btn btn--ghost btn--sm" href="<?= h(url('admin/tournaments.php')) ?>">← Πίσω</a>
        <a class="btn btn--ghost btn--sm" href="<?= h(url('admin/tournament_settings.php?id=' . $id)) ?>">⚙ Ρυθμίσεις</a>
        <a class="btn btn--accent btn--sm" href="<?= h(url('admin/tournament_secretariat.php?id=' . $id)) ?>">📝 Γραμματεία</a>
        <a class="btn btn--ghost btn--sm" href="<?= h(url('admin/tournament_print.php?id=' . $id)) ?>" target="_blank">🖨 Εκτύπωση</a>
        <a class="btn btn--ghost btn--sm" href="<?= h(url('results.php?t=' . $id)) ?>" target="_blank">🌐 Live</a>
    </div>
</div>

<div class="grid grid--4">
    <div class="stat"><div class="stat__label">Ομάδες</div><div class="stat__value"><?= count($teams) ?></div></div>
    <div class="stat"><div class="stat__label">Ενεργές</div><div class="stat__value"><?= $activeCount ?></div></div>
    <div class="stat"><div class="stat__label">Γήπεδα</div><div class="stat__value"><?= $courts > 0 ? $courts : '∞' ?></div></div>
    <div class="stat"><div class="stat__label">Γύροι</div><div class="stat__value"><?= count($rounds) ?></div></div>
</div>

<?php if ($isFinished && $finalStandings): ?>
<!-- Τελική κατάταξη -->
<div class="card">
    <h3 class="card__title">🏁 Τελική Κατάταξη</h3>
    <div class="table-responsive">
    <table class="table table--compact">
        <thead><tr>
            <th>#</th><th>Ομάδα</th>
            <th class="tc" title="Νίκες 1ης φάσης">Ν</th>
            <th class="tc" title="Βαθμοί 1ης φάσης">Βαθ.</th>
        </tr></thead>
        <tbody>
        <?php foreach ($finalStandings as $s): $fr = (int)$s['final_rank'];
            $medal = $fr === 1 ? '🥇' : ($fr === 2 ? '🥈' : ($fr === 3 ? '🥉' : ''));
            $short = $labelsShort[(int)$s['id']] ?? tour_team_label_short((string)$s['label']); ?>
            <tr<?= !empty($s['withdrawn']) ? ' class="muted"' : '' ?>>
                <td><strong><?= $fr ?></strong> <?= $medal ?></td>
                <td class="wrap"><?= h($short) ?><?= !empty($s['withdrawn']) ? ' <span class="badge badge--off">αποχ.</span>' : '' ?></td>
                <td class="tc"><?= (int)$s['wins'] ?></td>
                <td class="tc"><?= (int)$s['points'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="field__hint mt-8">Θέσεις 1–4 από τους τελικούς· οι υπόλοιποι με σειρά αποκλεισμού και κατάταξης 1ης φάσης.</p>
</div>
<?php endif; ?>

<?php if (!$hasRounds): ?>
<!-- Ομάδες / Import -->
<div class="card">
    <div class="main__header" style="margin-bottom:12px;">
        <h3 class="card__title" style="margin:0;">Ομάδες (<?= count($teams) ?>)</h3>
        <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="import">
            <button class="btn btn--sm btn--accent" type="submit">↻ Λήψη ομάδων από το πρωτάθλημα<?= $catLabel !== '' ? ' (' . h($catLabel) . ')' : '' ?></button>
        </form>
    </div>
    <?php if (!$teams): ?>
        <p class="muted">Καμία ομάδα. Πατήστε «Λήψη ομάδων» για αυτόματη εισαγωγή των δηλωμένων ομάδων<?= $catLabel !== '' ? ' κατηγορίας «' . h($catLabel) . '»' : '' ?> του πρωταθλήματος <code><?= h($tour['gamecode']) ?></code>.</p>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table">
            <thead><tr><th>Seed</th><th>Ομάδα</th><th>Σύλλογος</th><th>Κατάσταση</th><th class="actions"></th></tr></thead>
            <tbody>
            <?php foreach ($teams as $t): ?>
                <tr>
                    <td><?= $t['seed'] !== null ? (int)$t['seed'] : '—' ?></td>
                    <td><strong><?= h($t['label']) ?></strong></td>
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
                            <form method="post" style="display:inline" data-confirm="Αφαίρεση ομάδας;">
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
        </div>
        <?php if ($hasRounds): ?><p class="field__hint mt-8">Η αφαίρεση ομάδων απενεργοποιείται μόλις ξεκινήσουν οι γύροι — χρησιμοποιήστε «Αποχώρηση».</p><?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Φάσεις / Κληρώσεις -->
<div class="card">
    <h3 class="card__title">Φάσεις &amp; Κληρώσεις</h3>

    <h4 class="mt-0" style="font-size:14px;">1η φάση — Ελβετικό</h4>
    <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="generate_round">
        <button class="btn" type="submit" <?= $canSwiss ? '' : 'disabled' ?>>
            + Κλήρωση <?= $swissRounds ? 'επόμενου γύρου (' . ($lastSwissNo + 1) . ')' : '1ου γύρου' ?>
        </button>
    </form>
    <?php if ($hasRounds && $tour['status'] !== 'finished'): ?>
        <form method="post" style="display:inline" data-confirm="Διαγραφή τελευταίου γύρου (<?= $lastRound ?>);">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="delete_last_round">
            <button class="btn btn--ghost" type="submit">Διαγραφή γύρου <?= $lastRound ?></button>
        </form>
    <?php endif; ?>
    <?php if ($swissRounds && !$lastSwissCompleted): ?>
        <p class="field__hint mt-8">Καταχωρήστε όλα τα αποτελέσματα του γύρου <?= $lastSwissNo ?> (<a href="<?= h(url('admin/tournament_secretariat.php?id=' . $id)) ?>">Γραμματεία</a>) για να κληρωθεί ο επόμενος.</p>
    <?php elseif ($lastSwissCompleted && $lastSwissNo >= $roundsPlanned): ?>
        <p class="field__hint mt-8">Ολοκληρώθηκαν και οι <?= $roundsPlanned ?> γύροι του Ελβετικού. Προχωρήστε στη φάση knockout.</p>
    <?php endif; ?>

    <?php if ($koSize >= 2 || (int)($tour['friendship_cup'] ?? 0) === 1): ?>
    <hr>
    <h4 style="font-size:14px;">2η φάση — Knockout</h4>
    <?php if (!$swissComplete): ?>
        <p class="field__hint">Τα κουμπιά knockout ενεργοποιούνται όταν ολοκληρωθούν και οι <?= $roundsPlanned ?> γύροι του Swiss (τώρα: <?= $swissCompletedCount ?>/<?= $roundsPlanned ?>).</p>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2">
    <?php if ($koSize >= 2): ?>
        <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="generate_ko">
            <input type="hidden" name="phase" value="ko">
            <?php $koLabel = !$koStarted ? "Έναρξη knockout (TOP-$koSize)" : ($koDone ? 'Ολοκληρώθηκε το ταμπλό' : 'Επόμενο στάδιο knockout'); ?>
            <button class="btn btn--accent" type="submit" <?= (!$swissComplete || $koDone || ($koStarted && !$koLastCompleted)) ? 'disabled' : '' ?>>🏆 <?= h($koLabel) ?></button>
        </form>
    <?php endif; ?>
    <?php if ((int)($tour['friendship_cup'] ?? 0) === 1): ?>
        <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="generate_ko">
            <input type="hidden" name="phase" value="friendship">
            <?php $frLabel = !$frStarted ? 'Έναρξη Κυπέλλου Φιλίας (17–32)' : ($frDone ? 'Ολοκληρώθηκε το Κύπελλο' : 'Επόμενο στάδιο Κυπέλλου'); ?>
            <button class="btn btn--ghost" type="submit" <?= (!$swissComplete || $frDone || ($frStarted && !$frLastCompleted)) ? 'disabled' : '' ?>>🤝 <?= h($frLabel) ?></button>
        </form>
    <?php endif; ?>
    </div>
    <?php endif; ?>

    <hr>
    <?php if ($hasRounds): ?>
        <?php if ($tour['status'] !== 'finished'): ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="finish">
                <button class="btn btn--ghost btn--sm" type="submit">Ολοκλήρωση διοργάνωσης</button>
            </form>
        <?php else: ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="reopen">
                <button class="btn btn--ghost btn--sm" type="submit">Άνοιγμα ξανά</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($hasRounds): ?>
<div class="grid grid--2 grid--stretch">

    <!-- Κατάταξη 1ης φάσης (compact) -->
    <div class="card">
        <h3 class="card__title">Κατάταξη 1ης φάσης</h3>
        <?php if (!$standings): ?>
            <p class="muted">—</p>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table--compact table--sm">
            <thead><tr>
                <th>#</th><th>Ομάδα</th>
                <th class="tc" title="Νίκες">Ν</th>
                <th class="tc" title="Ήττες">Η</th>
                <th class="tc" title="Βαθμοί">Β</th>
                <th class="tc" title="Buchholz">Bch</th>
                <th class="tc" title="Fine Buchholz">FB</th>
                <th class="tc" title="Διαφορά πόντων">Δ</th>
            </tr></thead>
            <tbody>
            <?php foreach ($standings as $s):
                $short = $labelsShort[(int)$s['id']] ?? tour_team_label_short((string)$s['label']); ?>
                <tr<?= !empty($s['withdrawn']) ? ' class="muted"' : '' ?>>
                    <td><?= (int)$s['rank'] ?></td>
                    <td class="wrap"><?= h($short) ?><?= !empty($s['withdrawn']) ? ' <span class="badge badge--off">αποχ.</span>' : '' ?></td>
                    <td class="tc"><?= (int)$s['wins'] ?></td>
                    <td class="tc"><?= (int)$s['losses'] ?></td>
                    <td class="tc"><strong><?= (int)$s['points'] ?></strong></td>
                    <td class="tc"><?= (int)$s['buchholz'] ?></td>
                    <td class="tc"><?= (int)$s['fine_buchholz'] ?></td>
                    <td class="tc"><?= ($s['diff'] > 0 ? '+' : '') . (int)$s['diff'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <p class="field__hint mt-8">Ισοβαθμία: Βαθμοί → Buchholz → Fine Buchholz → Διαφορά.</p>
        <?php endif; ?>
    </div>

    <!-- Τρέχων / επιλεγμένος γύρος -->
    <?php
        $vPhase = $viewRoundRow['phase'] ?? 'swiss';
        $vStage = (string)($viewRoundRow['stage'] ?? '');
        $vTitle = $vPhase === 'swiss' ? "Γύρος $viewRound" : (($vPhase === 'friendship' ? 'Κύπελλο Φιλίας' : 'Knockout') . ($vStage !== '' ? ' — ' . $vStage : ''));
        $vCompleted = ($viewRoundRow['status'] ?? '') === 'completed';
    ?>
    <div class="card">
        <div class="main__header" style="margin-bottom:12px;gap:8px;flex-wrap:wrap;">
            <h3 class="card__title" style="margin:0;">
                <?= h($vTitle) ?>
                <span class="badge <?= $vCompleted ? 'badge--on' : '' ?>" style="margin-left:.4rem;"><?= $vCompleted ? 'Ολοκληρώθηκε' : 'Σε εξέλιξη' ?></span>
            </h3>
            <div class="d-flex gap-2 align-items-center flex-wrap">
                <?php if (count($rounds) > 1): ?>
                <select class="input input--sm" onchange="location.href='<?= h(url('admin/tournament_view.php?id=' . $id . '&r=')) ?>'+this.value">
                    <?php foreach ($rounds as $r): $rn = (int)$r['round_no'];
                        $rp = $r['phase'] ?? 'swiss'; $rs = (string)($r['stage'] ?? '');
                        $rt = $rp === 'swiss' ? "Γύρος $rn" : (($rp === 'friendship' ? 'Φιλίας' : 'KO') . ($rs !== '' ? ' — ' . $rs : '')); ?>
                        <option value="<?= $rn ?>" <?= $rn === $viewRound ? 'selected' : '' ?>><?= h($rt) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <a class="btn btn--ghost btn--sm" href="<?= h($sheetsUrl($viewRound)) ?>" target="_blank">🖨 Φύλλα αγώνα</a>
            </div>
        </div>
        <form method="post" class="tv-scores">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="save_results">
            <input type="hidden" name="round_no" value="<?= $viewRound ?>">
            <div class="table-responsive">
            <table class="table table--compact table--sm">
                <thead><tr><th>Γηπ.</th><th class="right">Γηπεδούχος</th><th class="tc">Σκορ</th><th>Φιλοξ.</th></tr></thead>
                <tbody>
                <?php foreach ($viewMatches as $m):
                    $mid = (int)$m['id'];
                    $homeLabel = $m['home_team_id'] !== null ? ($labelsShort[(int)$m['home_team_id']] ?? ('#' . (int)$m['home_team_id'])) : '—';
                    $courtLbl = $m['court_no'] !== null ? (int)$m['court_no'] : (int)$m['board_no'];
                    if ((int)$m['is_bye'] === 1): ?>
                        <tr>
                            <td>—</td>
                            <td class="right wrap"><strong><?= h($homeLabel) ?></strong></td>
                            <td class="tc" colspan="2"><span class="badge badge--on">Bye<?= $m['home_score'] !== null ? ' (' . (int)$m['home_score'] . ':' . (int)$m['away_score'] . ')' : '' ?></span></td>
                        </tr>
                    <?php else:
                        $awayLabel = $m['away_team_id'] !== null ? ($labelsShort[(int)$m['away_team_id']] ?? ('#' . (int)$m['away_team_id'])) : '—';
                        $hs = $m['home_score']; $as = $m['away_score'];
                        $stageTag = ($vPhase !== 'swiss' && ($m['stage'] ?? '') !== '' && ($m['stage'] ?? '') !== $vStage) ? ' <span class="badge">' . h((string)$m['stage']) . '</span>' : '';
                    ?>
                        <tr>
                            <td><strong><?= $courtLbl ?></strong></td>
                            <td class="right wrap"><strong><?= h($homeLabel) ?></strong><?= $stageTag ?></td>
                            <td class="tc nowrap">
                                <input class="input input--score" type="number" min="0" max="13"
                                       name="home[<?= $mid ?>]" value="<?= $hs === null ? '' : (int)$hs ?>" <?= $isFinished ? 'disabled' : '' ?>>
                                :
                                <input class="input input--score" type="number" min="0" max="13"
                                       name="away[<?= $mid ?>]" value="<?= $as === null ? '' : (int)$as ?>" <?= $isFinished ? 'disabled' : '' ?>>
                            </td>
                            <td class="wrap"><strong><?= h($awayLabel) ?></strong></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php if (!$isFinished): ?>
                <button class="btn btn--sm" type="submit">💾 Αποθήκευση σκορ</button>
            <?php endif; ?>
        </form>
    </div>

</div>
<?php endif; ?>

<?php if ($printNow > 0): ?>
<script>window.open(<?= json_encode($sheetsUrl($printNow)) ?>, '_blank');</script>
<?php endif; ?>

<?php render_footer(); ?>

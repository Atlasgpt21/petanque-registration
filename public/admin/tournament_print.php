<?php
// Εύρεση src/bootstrap.php ανεβαίνοντας φακέλους — ανθεκτικό σε διαφορετικά deployments.
for ($d = __DIR__; $d !== dirname($d); $d = dirname($d)) {
    if (is_file($d . '/src/bootstrap.php')) { require $d . '/src/bootstrap.php'; break; }
}
unset($d);
require_admin();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];
$id   = (int)($_GET['id'] ?? 0);
$tour = tour_get($pdo, $id);
if (!$tour) { http_response_code(404); exit('Not found'); }

$standings = tour_standings($pdo, $id);
$rounds    = tour_rounds($pdo, $id);
$labels    = tour_team_labels($pdo, $id);
$game      = db_one($pdo, "SELECT * FROM `{$T['games']}` WHERE gamecode=?", [$tour['gamecode']]);
?>
<!doctype html>
<html lang="el"><head><meta charset="utf-8">
<title>Εκτύπωση — <?= h($tour['name']) ?></title>
<style>
  body { font-family: system-ui, Arial, sans-serif; margin: 24px; color:#1f1d1b; font-size:13px; }
  h1 { font-size: 18px; margin: 0 0 2px; }
  h2 { font-size: 14px; margin: 22px 0 6px; border-bottom:1px solid #ccc; padding-bottom:3px; }
  .sub { color:#555; margin:0 0 8px; }
  table { width:100%; border-collapse:collapse; margin-bottom:10px; }
  th,td { border:1px solid #ccc; padding:5px 7px; text-align:left; }
  th { background:#f2f2f2; }
  .tc { text-align:center; } .right { text-align:right; }
  @media print { .noprint { display:none; } }
</style></head>
<body onload="window.print()">
<div class="noprint" style="margin-bottom:12px;"><button onclick="window.print()">Εκτύπωση</button></div>
<h1><?= h($tour['name']) ?></h1>
<p class="sub">Ελβετικό Σύστημα · Πρωτάθλημα <?= h($tour['gamecode']) ?><?= $game ? ' — ' . h($game['name']) : '' ?> · <?= date('d/m/Y H:i') ?></p>

<h2>Κατάταξη</h2>
<table>
    <thead><tr>
        <th>#</th><th>Ομάδα</th><th class="tc">Αγ.</th><th class="tc">Ν</th><th class="tc">Ι</th><th class="tc">Η</th>
        <th class="tc">Βαθ.</th><th class="tc">Buch.</th><th class="tc">F.Buch.</th><th class="tc">Διαφ.</th><th class="tc">Πόντοι</th>
    </tr></thead>
    <tbody>
    <?php foreach ($standings as $s): ?>
        <tr>
            <td><?= (int)$s['rank'] ?></td>
            <td><?= h($s['label']) ?><?= !empty($s['withdrawn']) ? ' (αποχ.)' : '' ?></td>
            <td class="tc"><?= (int)$s['played'] ?></td>
            <td class="tc"><?= (int)$s['wins'] ?></td>
            <td class="tc"><?= (int)$s['draws'] ?></td>
            <td class="tc"><?= (int)$s['losses'] ?></td>
            <td class="tc"><b><?= (int)$s['points'] ?></b></td>
            <td class="tc"><?= (int)$s['buchholz'] ?></td>
            <td class="tc"><?= (int)$s['fine_buchholz'] ?></td>
            <td class="tc"><?= ($s['diff'] > 0 ? '+' : '') . (int)$s['diff'] ?></td>
            <td class="tc"><?= (int)$s['pf'] ?>:<?= (int)$s['pa'] ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php foreach ($rounds as $r): $rn = (int)$r['round_no']; $matches = tour_matches($pdo, $id, $rn);
    $phase = $r['phase'] ?? 'swiss'; $stage = (string)($r['stage'] ?? '');
    $title = $phase === 'swiss' ? "Γύρος $rn" : (($phase === 'friendship' ? 'Κύπελλο Φιλίας' : 'Knockout') . ($stage !== '' ? ' — ' . $stage : '')); ?>
<h2><?= h($title) ?></h2>
<table>
    <thead><tr><th>Γήπεδο</th><th class="right">Γηπεδούχος</th><th class="tc">Σκορ</th><th>Φιλοξενούμενος</th></tr></thead>
    <tbody>
    <?php foreach ($matches as $m):
        $homeLabel = $m['home_team_id'] !== null ? ($labels[(int)$m['home_team_id']] ?? ('#' . (int)$m['home_team_id'])) : '—';
        $courtLbl = $m['court_no'] !== null ? (int)$m['court_no'] : (int)$m['board_no']; ?>
        <?php if ((int)$m['is_bye'] === 1): ?>
            <tr><td>—</td><td class="right"><?= h($homeLabel) ?></td><td class="tc" colspan="2">Bye<?= $m['home_score'] !== null ? ' (' . (int)$m['home_score'] . ':' . (int)$m['away_score'] . ')' : '' ?></td></tr>
        <?php else:
            $awayLabel = $m['away_team_id'] !== null ? ($labels[(int)$m['away_team_id']] ?? ('#' . (int)$m['away_team_id'])) : '—';
            $sc = $m['status'] === 'played' ? ((int)$m['home_score'] . ':' . (int)$m['away_score']) : '__ : __'; ?>
            <tr><td><?= $courtLbl ?></td><td class="right"><?= h($homeLabel) ?></td><td class="tc"><?= h($sc) ?></td><td><?= h($awayLabel) ?></td></tr>
        <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endforeach; ?>
</body></html>

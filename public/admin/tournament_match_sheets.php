<?php
// Εκτυπώσιμα φύλλα αγώνα ενός γύρου — 3 φύλλα ανά σελίδα Α4.
for ($d = __DIR__; $d !== dirname($d); $d = dirname($d)) {
    if (is_file($d . '/src/bootstrap.php')) { require $d . '/src/bootstrap.php'; break; }
}
unset($d);
require_admin();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];

$id      = (int)($_GET['id'] ?? 0);
$roundNo = (int)($_GET['round'] ?? 0);
$tour    = tour_get($pdo, $id);
if (!$tour || $roundNo <= 0) {
    http_response_code(404);
    echo 'Δεν βρέθηκε.';
    exit;
}

$round   = null;
foreach (tour_rounds($pdo, $id) as $r) { if ((int)$r['round_no'] === $roundNo) { $round = $r; } }
if (!$round) { http_response_code(404); echo 'Ο γύρος δεν βρέθηκε.'; exit; }

$matches  = tour_matches($pdo, $id, $roundNo);
$labels   = tour_team_labels($pdo, $id);
$game     = db_one($pdo, "SELECT name FROM `{$T['games']}` WHERE gamecode=?", [$tour['gamecode']]);
$cat      = (string)($tour['category'] ?? 'ALL');
$catLabel = tour_category_label($cat);
$phase    = $round['phase'] ?? 'swiss';
$stage    = (string)($round['stage'] ?? '');
$roundTitle = $phase === 'swiss' ? "Γύρος $roundNo" : (($phase === 'friendship' ? 'Κύπελλο Φιλίας' : 'Knockout') . ($stage !== '' ? ' — ' . $stage : ''));
$tourTitle = $tour['name'] . ($catLabel !== '' ? ' — ' . $catLabel : '');
?>
<!doctype html>
<html lang="el">
<head>
<meta charset="utf-8">
<title>Φύλλα αγώνα — <?= h($tourTitle) ?> — <?= h($roundTitle) ?></title>
<style>
  @page { size: A4 portrait; margin: 8mm; }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; color: #111; }
  .toolbar { padding: 10px; background: #f2efe9; text-align: center; }
  .toolbar button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
  .sheets { padding: 0; }
  .sheet {
    height: 91mm;                 /* 3 φύλλα ανά Α4 (≈281mm ωφέλιμο ύψος / 3) */
    border: 1px solid #000;
    padding: 5mm 6mm;
    margin-bottom: 3mm;
    page-break-inside: avoid;
    display: flex;
    flex-direction: column;
  }
  .sheet:nth-child(3n) { margin-bottom: 0; page-break-after: always; }
  .sheet__head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid #000; padding-bottom: 2mm; }
  .sheet__title { font-size: 12pt; font-weight: bold; margin: 0; }
  .sheet__meta { font-size: 9pt; color: #333; margin-top: 1mm; }
  .sheet__court { text-align: right; font-size: 9pt; }
  .sheet__court b { font-size: 15pt; display: block; }
  .teams { flex: 1; display: flex; align-items: stretch; margin-top: 3mm; }
  .team { flex: 1; display: flex; flex-direction: column; justify-content: center; }
  .team__role { font-size: 8pt; text-transform: uppercase; letter-spacing: .04em; color: #555; }
  .team__name { font-size: 13pt; font-weight: bold; margin-top: 1mm; }
  .vs { width: 16mm; display: flex; align-items: center; justify-content: center; font-size: 11pt; color: #777; }
  .score { display: flex; gap: 6mm; align-items: center; justify-content: center; margin-top: 3mm; }
  .score__box { width: 20mm; height: 14mm; border: 1.5px solid #000; }
  .score__sep { font-size: 16pt; }
  .bye { flex: 1; display: flex; align-items: center; justify-content: center; font-size: 13pt; font-weight: bold; }
  .sign { display: flex; justify-content: space-between; font-size: 8pt; color: #555; border-top: 1px dashed #999; padding-top: 1.5mm; margin-top: 2mm; }
  @media print { .toolbar { display: none; } .sheet { margin-bottom: 3mm; } }
</style>
</head>
<body onload="window.print()">
<div class="toolbar"><button type="button" onclick="window.print()">🖨 Εκτύπωση</button></div>
<div class="sheets">
<?php $n = 0; foreach ($matches as $m): $n++;
    $homeLabel = $m['home_team_id'] !== null ? ($labels[(int)$m['home_team_id']] ?? ('#' . (int)$m['home_team_id'])) : '—';
    $isBye = (int)$m['is_bye'] === 1;
    $awayLabel = (!$isBye && $m['away_team_id'] !== null) ? ($labels[(int)$m['away_team_id']] ?? ('#' . (int)$m['away_team_id'])) : '';
    $courtLbl = $m['court_no'] !== null ? (int)$m['court_no'] : (int)$m['board_no'];
    $mStage = (string)($m['stage'] ?? '');
    $sheetRound = ($phase !== 'swiss' && $mStage !== '') ? $mStage : $roundTitle;
?>
  <div class="sheet">
    <div class="sheet__head">
      <div>
        <p class="sheet__title"><?= h($tourTitle) ?></p>
        <p class="sheet__meta"><?= h($game['name'] ?? $tour['gamecode']) ?> · <?= h($sheetRound) ?></p>
      </div>
      <div class="sheet__court">Γήπεδο<b><?= $isBye ? '—' : $courtLbl ?></b></div>
    </div>
    <?php if ($isBye): ?>
      <div class="bye">Bye — Πρόκριση άνευ αγώνα</div>
    <?php else: ?>
      <div class="teams">
        <div class="team"><span class="team__role">Γηπεδούχος</span><span class="team__name"><?= h($homeLabel) ?></span></div>
        <div class="vs">vs</div>
        <div class="team" style="text-align:right;"><span class="team__role">Φιλοξενούμενος</span><span class="team__name"><?= h($awayLabel) ?></span></div>
      </div>
      <div class="score">
        <div class="score__box"></div>
        <span class="score__sep">:</span>
        <div class="score__box"></div>
      </div>
    <?php endif; ?>
    <div class="sign"><span>Υπογραφή γηπεδούχου</span><span>Διαιτητής</span><span>Υπογραφή φιλοξ.</span></div>
  </div>
<?php endforeach; ?>
</div>
</body>
</html>

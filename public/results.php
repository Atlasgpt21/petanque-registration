<?php
// Δημόσια σελίδα live αποτελεσμάτων (χωρίς login) — για ενσωμάτωση/σύνδεση στο hpf.gr.
for ($d = __DIR__; $d !== dirname($d); $d = dirname($d)) {
    if (is_file($d . '/src/bootstrap.php')) { require $d . '/src/bootstrap.php'; break; }
}
unset($d);

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];

$tid  = (int)($_GET['t'] ?? 0);
$tour = $tid > 0 ? tour_get($pdo, $tid) : null;

$bsHref = url('assets/vendor/bootstrap/bootstrap.min.css');
?>
<!doctype html>
<html lang="el">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Αποτελέσματα — Ελληνική Ομοσπονδία Πετάνκ</title>
<link rel="stylesheet" href="<?= h($bsHref) ?>">
<meta http-equiv="refresh" content="60">
<style>
  body { background:#f5f3ef; }
  .hpf-head { background:#7a2d12; color:#fff; }
  .badge-M{background:#e8efff;color:#2142a8;} .badge-F{background:#ffe8f2;color:#a01f5c;} .badge-MIX{background:#fff4d9;color:#8a5a00;}
</style>
</head>
<body>
<header class="hpf-head py-3 mb-4">
  <div class="container d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h1 class="h4 m-0">Ζωντανά Αποτελέσματα</h1>
    <span class="small opacity-75">Αυτόματη ανανέωση κάθε 60″</span>
  </div>
</header>
<div class="container pb-5">
<?php if (!$tour): ?>
  <div class="row g-3">
  <?php
    $all = tour_all($pdo);
    if (!$all) { echo '<p class="text-muted">Δεν υπάρχουν διαθέσιμες διοργανώσεις.</p>'; }
    foreach ($all as $t):
      $cat = (string)($t['category'] ?? 'ALL'); $cl = tour_category_label($cat);
      $stTxt = $t['status']==='finished'?'Ολοκληρώθηκε':($t['status']==='running'?'Σε εξέλιξη':'Προετοιμασία');
  ?>
    <div class="col-12 col-md-6 col-lg-4">
      <div class="card h-100 shadow-sm">
        <div class="card-body">
          <h2 class="h6"><?= h($t['name']) ?> <?php if ($cl!==''): ?><span class="badge badge-<?= h($cat) ?>"><?= h($cl) ?></span><?php endif; ?></h2>
          <p class="small text-muted mb-2"><?= h($t['gamecode']) ?> · <?= h($stTxt) ?> · <?= (int)$t['team_count'] ?> ομάδες</p>
          <a class="btn btn-sm btn-outline-dark" href="<?= h(url('results.php?t=' . (int)$t['id'])) ?>">Αποτελέσματα →</a>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php else:
    $cat = (string)($tour['category'] ?? 'ALL'); $cl = tour_category_label($cat);
    $standings = tour_standings($pdo, $tid);
    $rounds    = tour_rounds($pdo, $tid);
    $labels    = tour_team_labels($pdo, $tid);
    $game      = db_one($pdo, "SELECT name FROM `{$T['games']}` WHERE gamecode=?", [$tour['gamecode']]);
?>
  <a class="btn btn-sm btn-link px-0 mb-2" href="<?= h(url('results.php')) ?>">← Όλες οι διοργανώσεις</a>
  <h2 class="h4"><?= h($tour['name']) ?> <?php if ($cl!==''): ?><span class="badge badge-<?= h($cat) ?>"><?= h($cl) ?></span><?php endif; ?></h2>
  <p class="text-muted"><?= h($game['name'] ?? $tour['gamecode']) ?></p>

  <?php
    // Αγώνες σε εξέλιξη τώρα (εκκρεμείς, όχι ρεπό) — σε όλους τους γύρους/φάσεις.
    $live = [];
    foreach ($rounds as $r) {
        foreach (tour_matches($pdo, $tid, (int)$r['round_no']) as $m) {
            if (($m['status'] ?? '') === 'pending' && (int)$m['is_bye'] !== 1 && $m['away_team_id'] !== null) {
                $m['_round'] = $r; $live[] = $m;
            }
        }
    }
    usort($live, static fn($a, $b) => ((int)($a['court_no'] ?? 0)) <=> ((int)($b['court_no'] ?? 0)));
  ?>
  <div class="card shadow-sm mb-4 border-danger">
    <div class="card-header fw-semibold text-bg-danger">🔴 Αγώνες σε εξέλιξη τώρα</div>
    <div class="table-responsive">
      <table class="table table-sm m-0 align-middle">
        <tbody>
        <?php foreach ($live as $m):
          $rn=(int)$m['_round']['round_no']; $phase=$m['_round']['phase']??'swiss'; $stage=(string)($m['_round']['stage']??'');
          $ttl = $phase==='swiss' ? "Γύρος $rn" : (($phase==='friendship'?'Κύπελλο Φιλίας':'Knockout').($stage!==''?' — '.$stage:''));
          $home = $labels[(int)$m['home_team_id']] ?? '—'; $away = $labels[(int)$m['away_team_id']] ?? '—';
        ?>
          <tr>
            <td class="text-center"><?php if (!empty($m['court_no'])): ?><span class="badge text-bg-dark">Γήπεδο <?= (int)$m['court_no'] ?></span><?php endif; ?></td>
            <td class="text-end w-50 fw-semibold"><?= h($home) ?></td>
            <td class="text-center">vs</td>
            <td class="w-50 fw-semibold"><?= h($away) ?></td>
            <td class="small text-muted text-nowrap"><?= h($ttl) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$live): ?><tr><td class="text-muted">Δεν υπάρχουν αγώνες σε εξέλιξη αυτή τη στιγμή.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">Κατάταξη</div>
    <div class="table-responsive">
      <table class="table table-sm table-striped m-0 align-middle">
        <thead><tr>
          <th>#</th><th>Ομάδα</th><th class="text-center">Αγ.</th><th class="text-center">Ν</th><th class="text-center">Η</th>
          <th class="text-center">Βαθ.</th><th class="text-center">Buch.</th><th class="text-center">F.B.</th><th class="text-center">Διαφ.</th>
        </tr></thead>
        <tbody>
        <?php foreach ($standings as $s): ?>
          <tr>
            <td><?= (int)$s['rank'] ?></td>
            <td><?= h($s['label']) ?></td>
            <td class="text-center"><?= (int)$s['played'] ?></td>
            <td class="text-center"><?= (int)$s['wins'] ?></td>
            <td class="text-center"><?= (int)$s['losses'] ?></td>
            <td class="text-center fw-semibold"><?= (int)$s['points'] ?></td>
            <td class="text-center"><?= (int)$s['buchholz'] ?></td>
            <td class="text-center"><?= (int)$s['fine_buchholz'] ?></td>
            <td class="text-center"><?= ($s['diff']>0?'+':'') . (int)$s['diff'] ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$standings): ?><tr><td colspan="9" class="text-muted">—</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php foreach (array_reverse($rounds) as $r): $rn=(int)$r['round_no']; $ms = tour_matches($pdo,$tid,$rn);
    $phase=$r['phase']??'swiss'; $stage=(string)($r['stage']??'');
    $title = $phase==='swiss' ? "Γύρος $rn" : (($phase==='friendship'?'Κύπελλο Φιλίας':'Knockout') . ($stage!==''?' — '.$stage:''));
  ?>
  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between">
      <span class="fw-semibold"><?= h($title) ?></span>
      <span class="badge <?= $r['status']==='completed'?'text-bg-success':'text-bg-secondary' ?>"><?= $r['status']==='completed'?'Ολοκληρώθηκε':'Σε εξέλιξη' ?></span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm m-0 align-middle">
        <tbody>
        <?php foreach ($ms as $m):
          $home = $m['home_team_id']!==null ? ($labels[(int)$m['home_team_id']] ?? '—') : '—';
          $courtCell = !empty($m['court_no']) ? '<span class="badge text-bg-light border">Γ.'.(int)$m['court_no'].'</span>' : '';
          if ((int)$m['is_bye']===1): ?>
            <tr><td class="text-center"><?= $courtCell ?></td><td class="text-end w-50"><?= h($home) ?></td><td class="text-center"><span class="badge text-bg-info">Bye</span></td><td class="w-50">—</td></tr>
          <?php else:
            $away = $m['away_team_id']!==null ? ($labels[(int)$m['away_team_id']] ?? '—') : '—';
            $played = $m['status']==='played';
            $hs=(int)$m['home_score']; $as=(int)$m['away_score'];
            $hw = $played && $hs>$as; $aw = $played && $as>$hs;
          ?>
            <tr>
              <td class="text-center"><?= $courtCell ?></td>
              <td class="text-end w-50 <?= $hw?'fw-bold':'' ?>"><?= h($home) ?></td>
              <td class="text-center text-nowrap"><?= $played ? ($hs.' : '.$as) : 'vs' ?></td>
              <td class="w-50 <?= $aw?'fw-bold':'' ?>"><?= h($away) ?></td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$rounds): ?><p class="text-muted">Δεν έχουν κληρωθεί γύροι ακόμη.</p><?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>

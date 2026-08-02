<?php
// Ρυθμίσεις διοργάνωσης (γήπεδα, φάσεις, βαθμολογία) — ξεχωριστά από τη Γραμματεία.
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
        if ($op === 'save') {
            $rp = trim((string)($_POST['rounds_planned'] ?? ''));
            tour_update_settings($pdo, $id, [
                'name'              => trim((string)($_POST['name'] ?? $tour['name'])),
                'rounds_planned'    => $rp === '' ? null : (int)$rp,
                'courts'            => max(0, (int)($_POST['courts'] ?? 0)),
                'court_from'        => max(0, (int)($_POST['court_from'] ?? 0)),
                'court_to'          => max(0, (int)($_POST['court_to'] ?? 0)),
                'ko_size'           => in_array((int)($_POST['ko_size'] ?? 0), [0, 8, 16], true) ? (int)$_POST['ko_size'] : 0,
                'friendship_cup'    => isset($_POST['friendship_cup']) ? 1 : 0,
                'win_points'        => (int)($_POST['win_points'] ?? 1),
                'draw_points'       => (int)($_POST['draw_points'] ?? 0),
                'loss_points'       => (int)($_POST['loss_points'] ?? 0),
                'bye_score_for'     => (int)($_POST['bye_score_for'] ?? 13),
                'bye_score_against' => (int)($_POST['bye_score_against'] ?? 7),
            ]);
            flash_set('success', 'Οι ρυθμίσεις αποθηκεύτηκαν.');
        } elseif ($op === 'delete') {
            tour_delete($pdo, $id);
            flash_set('success', 'Η διοργάνωση διαγράφηκε.');
            redirect(url('admin/tournaments.php'));
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect(url('admin/tournament_settings.php?id=' . $id));
}

$cat      = (string)($tour['category'] ?? 'ALL');
$catLabel = tour_category_label($cat);
$koSize   = (int)($tour['ko_size'] ?? 0);

render_header('Ρυθμίσεις: ' . $tour['name'], 'admin', 'tournaments');
?>
<div class="main__header">
    <div>
        <h2 class="main__title">⚙ Ρυθμίσεις — <?= h($tour['name']) ?>
            <?php if ($catLabel !== ''): ?><span class="badge badge--<?= h($cat) ?>"><?= h($catLabel) ?></span><?php endif; ?>
        </h2>
        <p class="main__sub">Πρωτάθλημα <code><?= h($tour['gamecode']) ?></code></p>
    </div>
    <div>
        <a class="btn btn--ghost btn--sm" href="<?= h(url('admin/tournament_view.php?id=' . $id)) ?>">← Διαχείριση</a>
    </div>
</div>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="op" value="save">

    <div class="card">
        <h3 class="card__title">Γενικά</h3>
        <div class="field">
            <label class="field__label">Όνομα</label>
            <input class="input" type="text" name="name" value="<?= h($tour['name']) ?>" required>
        </div>
        <div class="field">
            <label class="field__label">Σύνολο γηπέδων</label>
            <input class="input" type="number" min="0" max="200" name="courts" value="<?= (int)$tour['courts'] ?>">
            <span class="field__hint">Το συνολικό πλήθος γηπέδων της διοργάνωσης. 0 = χωρίς όριο (σειριακή αρίθμηση).</span>
        </div>
        <div class="grid grid--2">
            <div class="field">
                <label class="field__label">Γήπεδα του ταμπλό — από</label>
                <input class="input" type="number" min="0" max="200" name="court_from" value="<?= (int)($tour['court_from'] ?? 0) ?>">
            </div>
            <div class="field">
                <label class="field__label">έως</label>
                <input class="input" type="number" min="0" max="200" name="court_to" value="<?= (int)($tour['court_to'] ?? 0) ?>">
            </div>
        </div>
        <p class="field__hint">Εύρος γηπέδων για ΑΥΤΟ το ταμπλό — π.χ. Άνδρες 1–15, Γυναίκες 16–25. Αν μείνει 0–0, χρησιμοποιείται το σύνολο γηπέδων. Στα MIX αφήστε το κενό (παίζουν όλοι σε όλα).</p>
        <div class="field">
            <label class="field__label">Προγραμματισμένοι γύροι Ελβετικού</label>
            <input class="input" type="number" min="0" max="30" name="rounds_planned" value="<?= $tour['rounds_planned'] !== null ? (int)$tour['rounds_planned'] : 5 ?>">
            <span class="field__hint">Προεπιλογή 5 γύροι. Μετά τον τελευταίο γύρο δεν δημιουργείται νέος — προχωράτε στη φάση knockout.</span>
        </div>
    </div>

    <div class="card">
        <h3 class="card__title">2η φάση — Knockout</h3>
        <div class="field">
            <label class="field__label">Κυρίως ταμπλό (knockout)</label>
            <select class="select" name="ko_size">
                <option value="0"  <?= $koSize===0?'selected':'' ?>>Χωρίς knockout</option>
                <option value="8"  <?= $koSize===8?'selected':'' ?>>TOP-8 (Προημιτελικά → Ημιτελικά → Τελικοί)</option>
                <option value="16" <?= $koSize===16?'selected':'' ?>>TOP-16 (Φάση 16 → 8 → 4 → Τελικοί)</option>
            </select>
            <span class="field__hint">Ζευγάρωμα 1ου γύρου: 1–<?= $koSize?:'Ν' ?>, 2–<?= $koSize?($koSize-1):'Ν-1' ?>, … Στους ημιτελικούς προκύπτει Μικρός &amp; Μεγάλος Τελικός.</span>
        </div>
        <div class="field">
            <label class="field__label" style="flex-direction:row;align-items:center;gap:8px;display:flex;">
                <input type="checkbox" name="friendship_cup" value="1" <?= (int)$tour['friendship_cup']===1?'checked':'' ?>>
                Κύπελλο Φιλίας (θέσεις 17–32 της γενικής κατάταξης)
            </label>
        </div>
    </div>

    <div class="card">
        <h3 class="card__title">Βαθμολογία &amp; Ρεπό</h3>
        <div class="grid grid--3">
            <div class="field"><label class="field__label">Βαθμοί νίκης</label><input class="input" type="number" name="win_points" value="<?= (int)$tour['win_points'] ?>"></div>
            <div class="field"><label class="field__label">Βαθμοί ισοπαλίας</label><input class="input" type="number" name="draw_points" value="<?= (int)$tour['draw_points'] ?>"></div>
            <div class="field"><label class="field__label">Βαθμοί ήττας</label><input class="input" type="number" name="loss_points" value="<?= (int)$tour['loss_points'] ?>"></div>
            <div class="field"><label class="field__label">Ρεπό — πόντοι υπέρ</label><input class="input" type="number" name="bye_score_for" value="<?= (int)$tour['bye_score_for'] ?>"></div>
            <div class="field"><label class="field__label">Ρεπό — πόντοι κατά</label><input class="input" type="number" name="bye_score_against" value="<?= (int)$tour['bye_score_against'] ?>"></div>
        </div>
    </div>

    <button class="btn" type="submit">💾 Αποθήκευση ρυθμίσεων</button>
</form>

<div class="card mt-24">
    <h3 class="card__title">Επικίνδυνη ζώνη</h3>
    <form method="post" data-confirm="Οριστική διαγραφή της διοργάνωσης και όλων των γύρων/αγώνων;">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="delete">
        <button class="btn btn--danger" type="submit">Διαγραφή διοργάνωσης</button>
    </form>
</div>

<?php render_footer(); ?>

<?php
// Εύρεση src/bootstrap.php ανεβαίνοντας φακέλους — ανθεκτικό σε διαφορετικά deployments.
for ($d = __DIR__; $d !== dirname($d); $d = dirname($d)) {
    if (is_file($d . '/src/bootstrap.php')) { require $d . '/src/bootstrap.php'; break; }
}
unset($d);
require PUBLIC_ROOT . '/assets/layout.php';
require_admin();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'create') {
        $name     = trim((string)($_POST['name'] ?? ''));
        $gamecode = trim((string)($_POST['gamecode'] ?? ''));
        if ($name === '' || $gamecode === '') {
            flash_set('error', 'Απαιτούνται όνομα και πρωτάθλημα.');
        } else {
            $g = db_one($pdo, "SELECT gametype FROM `{$T['games']}` WHERE gamecode=?", [$gamecode]);
            $gametype = (string)($g['gametype'] ?? 'Doubles');
            $ids = tour_create_for_game($pdo, $name, $gamecode, $gametype);
            if (count($ids) > 1) {
                flash_set('success', 'Δημιουργήθηκαν 2 ξεχωριστά ταμπλό: Άνδρες & Γυναίκες.');
                redirect(url('admin/tournaments.php'));
            }
            flash_set('success', 'Η διοργάνωση δημιουργήθηκε.');
            redirect(url('admin/tournament_view.php?id=' . $ids[0]));
        }
    }

    if ($op === 'delete') {
        tour_delete($pdo, (int)($_POST['id'] ?? 0));
        flash_set('success', 'Η διοργάνωση διαγράφηκε.');
    }
    redirect(url('admin/tournaments.php'));
}

$tours = tour_all($pdo);
$games = db_all($pdo, "SELECT gamecode, name, gametype, status FROM `{$T['games']}` ORDER BY gameid DESC");

// map gamecode => game info για εμφάνιση
$gameName = [];
$gameType = [];
foreach ($games as $g) { $gameName[$g['gamecode']] = $g['name']; $gameType[$g['gamecode']] = $g['gametype']; }

render_header('Διοργανώσεις', 'admin', 'tournaments');
?>
<div class="main__header">
    <div>
        <h2 class="main__title">Διοργανώσεις</h2>
        <p class="main__sub">Δημιουργία πρωταθλημάτων αγώνων με <strong>Ελβετικό Σύστημα</strong> — αυτόματη λήψη ομάδων, κληρώσεις γύρων και κατάταξη με κριτήρια Buchholz.</p>
    </div>
</div>

<div class="card">
    <h3 class="card__title">Νέα Διοργάνωση</h3>
    <p class="card__subtitle">Αν το είδος είναι <strong>Μικτό (Mixed)</strong> δημιουργείται ένα ενιαίο ταμπλό. Σε <strong>Διπλέτες/Τριπλέτες</strong> δημιουργούνται αυτόματα <strong>2 ξεχωριστά ταμπλό</strong> (Άνδρες &amp; Γυναίκες) που τρέχουν παράλληλα.</p>
    <form method="post" class="row">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="create">
        <div class="field" style="flex:2">
            <label class="field__label">Όνομα *</label>
            <input class="input" type="text" name="name" placeholder="π.χ. Πανελλήνιο 3vs3 2026" required>
        </div>
        <div class="field" style="flex:2">
            <label class="field__label">Πρωτάθλημα (πηγή ομάδων) *</label>
            <select class="select" name="gamecode" required>
                <option value="">— επιλέξτε —</option>
                <?php foreach ($games as $g): $tlabel = $g['gametype']==='Mixed'?'Μικτό':($g['gametype']==='Triplets'?'Τριπλέτες':($g['gametype']==='Doubles'?'Διπλέτες':$g['gametype'])); ?>
                    <option value="<?= h($g['gamecode']) ?>"><?= h($g['gamecode']) ?> — <?= h($g['name']) ?> [<?= h($tlabel) ?>]<?= $g['status']==='Y'?' ✓':'' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="flex:0; align-self:flex-end;">
            <button class="btn" type="submit">+ Δημιουργία</button>
        </div>
    </form>
</div>

<div class="card">
    <?php if (!$tours): ?>
        <p class="muted">Δεν υπάρχουν διοργανώσεις ακόμη.</p>
    <?php else: ?>
    <table class="table">
        <thead><tr><th>#</th><th>Όνομα</th><th>Ταμπλό</th><th>Πρωτάθλημα</th><th>Ομάδες</th><th>Γύροι</th><th>Κατάσταση</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($tours as $t): $cat = (string)($t['category'] ?? 'ALL'); $catLabel = tour_category_label($cat); ?>
            <tr>
                <td><?= (int)$t['id'] ?></td>
                <td><strong><?= h($t['name']) ?></strong></td>
                <td><?php if ($catLabel !== ''): ?><span class="badge badge--<?= h($cat) ?>"><?= h($catLabel) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td><code><?= h($t['gamecode']) ?></code><br><span class="muted"><?= h($gameName[$t['gamecode']] ?? '—') ?></span></td>
                <td><?= (int)$t['team_count'] ?></td>
                <td><?= (int)$t['round_count'] ?></td>
                <td>
                    <?php $st = $t['status']; ?>
                    <span class="badge <?= $st==='finished'?'badge--off':($st==='running'?'badge--on':'') ?>">
                        <?= $st==='finished'?'Ολοκληρώθηκε':($st==='running'?'Σε εξέλιξη':'Προετοιμασία') ?>
                    </span>
                </td>
                <td class="actions">
                    <a class="btn btn--sm" href="<?= h(url('admin/tournament_view.php?id=' . $t['id'])) ?>">Διαχείριση</a>
                    <form method="post" style="display:inline" data-confirm="Διαγραφή διοργάνωσης και όλων των γύρων/αγώνων;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="op" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn--sm btn--danger" type="submit">Διαγραφή</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
<?php render_footer(); ?>

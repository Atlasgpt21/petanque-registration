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
            $nid = tour_create($pdo, $name, $gamecode);
            flash_set('success', 'Η διοργάνωση δημιουργήθηκε.');
            redirect(url('admin/tournament_view.php?id=' . $nid));
        }
    }

    if ($op === 'delete') {
        tour_delete($pdo, (int)($_POST['id'] ?? 0));
        flash_set('success', 'Η διοργάνωση διαγράφηκε.');
    }
    redirect(url('admin/tournaments.php'));
}

$tours = tour_all($pdo);
$games = db_all($pdo, "SELECT gamecode, name, status FROM `{$T['games']}` ORDER BY gameid DESC");

// map gamecode => game name για εμφάνιση
$gameName = [];
foreach ($games as $g) { $gameName[$g['gamecode']] = $g['name']; }

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
    <form method="post" class="row">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="create">
        <div class="field" style="flex:2">
            <label class="field__label">Όνομα *</label>
            <input class="input" type="text" name="name" placeholder="π.χ. Πανελλήνιο 2vs2 — Τελική Φάση" required>
        </div>
        <div class="field" style="flex:2">
            <label class="field__label">Πρωτάθλημα (πηγή ομάδων) *</label>
            <select class="select" name="gamecode" required>
                <option value="">— επιλέξτε —</option>
                <?php foreach ($games as $g): ?>
                    <option value="<?= h($g['gamecode']) ?>"><?= h($g['gamecode']) ?> — <?= h($g['name']) ?><?= $g['status']==='Y'?' ✓':'' ?></option>
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
        <thead><tr><th>#</th><th>Όνομα</th><th>Πρωτάθλημα</th><th>Ομάδες</th><th>Γύροι</th><th>Κατάσταση</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($tours as $t): ?>
            <tr>
                <td><?= (int)$t['id'] ?></td>
                <td><strong><?= h($t['name']) ?></strong></td>
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
                    <form method="post" style="display:inline" onsubmit="return confirm('Διαγραφή διοργάνωσης και όλων των γύρων/αγώνων;');">
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

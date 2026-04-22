<?php
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/public/assets/layout.php';
require_admin();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];
$readonly = is_readonly_external();
$metaTable = $GLOBALS['CONFIG']['game_meta_table'] ?? 'app_game_meta';
$action = $_GET['action'] ?? '';
$editId = (int)($_GET['id'] ?? 0);
$edit = null;

if ($readonly && ($action === 'new')) {
    flash_set('warning', 'Η δημιουργία πρωταθλημάτων γίνεται στο κεντρικό σύστημα. Εδώ μπορείτε μόνο να ορίσετε την προθεσμία δηλώσεων.');
    redirect(url('admin/championships.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'toggle') {
        if ($readonly) {
            flash_set('error', 'Η κατάσταση του πρωταθλήματος αλλάζει από το κεντρικό σύστημα.');
            redirect(url('admin/championships.php'));
        }
        $id = (int)($_POST['gameid'] ?? 0);
        $status = $_POST['status'] === 'Y' ? 'Y' : 'N';
        $pdo->prepare("UPDATE `{$T['games']}` SET status=? WHERE gameid=?")->execute([$status, $id]);
        flash_set('success', 'Η κατάσταση ενημερώθηκε.');
        redirect(url('admin/championships.php'));
    }

    if ($op === 'save') {
        $id       = (int)($_POST['gameid'] ?? 0);
        $deadline = trim((string)($_POST['registration_deadline'] ?? '')) ?: null;

        if ($readonly) {
            // Διαβάζουμε το υπάρχον πρωτάθλημα και ενημερώνουμε ΜΟΝΟ το deadline
            // στον δικό μας πίνακα app_game_meta. Δεν αγγίζουμε το games.
            $g = db_one($pdo, "SELECT * FROM `{$T['games']}` WHERE gameid=?", [$id]);
            if (!$g) {
                flash_set('error', 'Το πρωτάθλημα δεν βρέθηκε.');
                redirect(url('admin/championships.php'));
            }
            try {
                $pdo->prepare("INSERT INTO `{$metaTable}` (gamecode, registration_deadline) VALUES (?,?) ON DUPLICATE KEY UPDATE registration_deadline=VALUES(registration_deadline)")
                    ->execute([$g['gamecode'], $deadline]);
                flash_set('success', 'Η προθεσμία δηλώσεων αποθηκεύτηκε.');
                redirect(url('admin/championships.php'));
            } catch (PDOException $e) {
                flash_set('error', 'Σφάλμα: ' . $e->getMessage());
                redirect(url('admin/championships.php'));
            }
        }

        // Standalone mode: full CRUD στον πίνακα games (όταν games είναι δικός μας).
        $name     = trim((string)($_POST['name'] ?? ''));
        $gamecode = trim((string)($_POST['gamecode'] ?? ''));
        $gametype = (string)($_POST['gametype'] ?? 'Doubles');
        $category = trim((string)($_POST['category'] ?? 'Πρωτάθλημα'));
        $status   = $_POST['status'] === 'Y' ? 'Y' : 'N';
        $start    = trim((string)($_POST['startdate'] ?? '')) ?: null;
        $end      = trim((string)($_POST['enddate'] ?? '')) ?: null;
        $errors = [];
        if ($name === '' || $gamecode === '') { $errors[] = 'Απαιτούνται όνομα και κωδικός.'; }
        if (!in_array($gametype, ['Doubles','Triplets','Mixed','Intercup'], true)) { $errors[] = 'Μη έγκυρος τύπος.'; }
        if (!$errors) {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE `{$T['games']}` SET name=?, gamecode=?, gametype=?, category=?, status=?, startdate=?, enddate=?, registration_deadline=? WHERE gameid=?")
                        ->execute([$name, $gamecode, $gametype, $category, $status, $start, $end, $deadline, $id]);
                    flash_set('success', 'Το πρωτάθλημα ενημερώθηκε.');
                } else {
                    $pdo->prepare("INSERT INTO `{$T['games']}` (name, gamecode, gametype, category, status, startdate, enddate, registration_deadline) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$name, $gamecode, $gametype, $category, $status, $start, $end, $deadline]);
                    flash_set('success', 'Το πρωτάθλημα προστέθηκε.');
                }
                redirect(url('admin/championships.php'));
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') { $errors[] = 'Ο κωδικός πρωταθλήματος υπάρχει ήδη.'; }
                else { $errors[] = $e->getMessage(); }
            }
        }
        foreach ($errors as $e) flash_set('error', $e);
        $edit = compact('id','name','gamecode','gametype','category','status','start','end','deadline');
        $edit['gameid'] = $id; $edit['startdate'] = $start; $edit['enddate'] = $end; $edit['registration_deadline'] = $deadline;
        $action = 'edit';
    }
}

if ($action === 'edit' && $editId > 0 && !$edit) {
    $edit = db_one($pdo, "SELECT * FROM `{$T['games']}` WHERE gameid=?", [$editId]);
}
if ($action === 'new' && !$edit && !$readonly) { $edit = ['gameid' => 0, 'gametype' => 'Doubles', 'status' => 'N', 'category' => 'Πρωτάθλημα']; }

$games = db_all($pdo, "SELECT * FROM `{$T['games']}` ORDER BY gameid DESC");

render_header('Πρωταθλήματα', 'admin', 'championships');
?>
<div class="main__header">
    <div>
        <h2 class="main__title">Πρωταθλήματα</h2>
        <?php if ($readonly): ?><p class="main__sub">Προβολή από κεντρικό σύστημα — μπορείτε να ορίσετε μόνο <strong>προθεσμία δηλώσεων</strong>.</p><?php endif; ?>
    </div>
    <?php if (!$edit && !$readonly): ?>
        <a class="btn" href="<?= h(url('admin/championships.php?action=new')) ?>">+ Νέο Πρωτάθλημα</a>
    <?php endif; ?>
</div>

<?php if ($edit): ?>
<div class="card">
    <h3 class="card__title"><?= $edit['gameid'] ? 'Επεξεργασία' : 'Νέο' ?> Πρωτάθλημα</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="save">
        <input type="hidden" name="gameid" value="<?= (int)$edit['gameid'] ?>">
        <?php if ($readonly && $edit['gameid']): ?>
            <div class="row">
                <div class="field"><label class="field__label">Όνομα</label><input class="input" type="text" value="<?= h($edit['name'] ?? '') ?>" disabled></div>
                <div class="field"><label class="field__label">Κωδικός</label><input class="input" type="text" value="<?= h($edit['gamecode'] ?? '') ?>" disabled></div>
                <div class="field"><label class="field__label">Τύπος</label><input class="input" type="text" value="<?= h(gametype_label($edit['gametype'] ?? '')) ?>" disabled></div>
            </div>
            <div class="row">
                <div class="field"><label class="field__label">Έναρξη</label><input class="input" type="text" value="<?= h($edit['startdate'] ?? '') ?>" disabled></div>
                <div class="field"><label class="field__label">Λήξη</label><input class="input" type="text" value="<?= h($edit['enddate'] ?? '') ?>" disabled></div>
                <div class="field"><label class="field__label">Κατάσταση</label><input class="input" type="text" value="<?= $edit['status']==='Y'?'Ενεργό':'Ανενεργό' ?>" disabled></div>
            </div>
            <div class="row">
                <div class="field"><label class="field__label">Προθεσμία Δηλώσεων <span class="muted">(μόνο αυτό αποθηκεύεται)</span></label>
                    <input class="input" type="datetime-local" name="registration_deadline"
                           value="<?= !empty($edit['registration_deadline']) ? h(date('Y-m-d\TH:i', strtotime($edit['registration_deadline']))) : '' ?>">
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <div class="field"><label class="field__label">Όνομα *</label><input class="input" type="text" name="name" value="<?= h($edit['name'] ?? '') ?>" required></div>
                <div class="field"><label class="field__label">Κωδικός *</label><input class="input" type="text" name="gamecode" value="<?= h($edit['gamecode'] ?? '') ?>" required></div>
            </div>
            <div class="row">
                <div class="field"><label class="field__label">Τύπος</label>
                    <select class="select" name="gametype">
                        <?php foreach (['Doubles'=>'Ντουμπλέτες','Triplets'=>'Τριπλέτες','Mixed'=>'Μεικτό Ντουμπλέτες','Intercup'=>'Διασυλλογικό'] as $k=>$v): ?>
                            <option value="<?= h($k) ?>" <?= ($edit['gametype']??'')===$k?'selected':'' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label class="field__label">Κατηγορία</label><input class="input" type="text" name="category" value="<?= h($edit['category'] ?? 'Πρωτάθλημα') ?>"></div>
                <div class="field"><label class="field__label">Κατάσταση</label>
                    <select class="select" name="status">
                        <option value="N" <?= ($edit['status']??'N')==='N'?'selected':'' ?>>Ανενεργό</option>
                        <option value="Y" <?= ($edit['status']??'N')==='Y'?'selected':'' ?>>Ενεργό</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="field"><label class="field__label">Έναρξη</label><input class="input" type="date" name="startdate" value="<?= h($edit['startdate'] ?? '') ?>"></div>
                <div class="field"><label class="field__label">Λήξη</label><input class="input" type="date" name="enddate" value="<?= h($edit['enddate'] ?? '') ?>"></div>
                <div class="field"><label class="field__label">Προθεσμία Δηλώσεων</label>
                    <input class="input" type="datetime-local" name="registration_deadline"
                           value="<?= !empty($edit['registration_deadline']) ? h(date('Y-m-d\TH:i', strtotime($edit['registration_deadline']))) : '' ?>">
                </div>
            </div>
        <?php endif; ?>
        <button class="btn" type="submit">Αποθήκευση</button>
        <a class="btn btn--ghost" href="<?= h(url('admin/championships.php')) ?>">Ακύρωση</a>
    </form>
</div>
<?php else: ?>
<div class="card">
    <table class="table">
        <thead><tr><th>ID</th><th>Κωδ.</th><th>Όνομα</th><th>Τύπος</th><th>Κατάσταση</th><th>Προθεσμία</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($games as $g): ?>
            <tr>
                <td><?= (int)$g['gameid'] ?></td>
                <td><code><?= h($g['gamecode']) ?></code></td>
                <td><?= h($g['name']) ?></td>
                <td><span class="badge"><?= h(gametype_label($g['gametype'])) ?></span></td>
                <td>
                    <?php if ($readonly): ?>
                        <span class="badge <?= $g['status']==='Y'?'badge--on':'badge--off' ?>"><?= $g['status']==='Y'?'Ενεργό':'Ανενεργό' ?></span>
                    <?php else: ?>
                        <form method="post" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="op" value="toggle">
                            <input type="hidden" name="gameid" value="<?= (int)$g['gameid'] ?>">
                            <input type="hidden" name="status" value="<?= $g['status']==='Y'?'N':'Y' ?>">
                            <button class="badge <?= $g['status']==='Y'?'badge--on':'badge--off' ?>" type="submit" style="cursor:pointer;border:0;">
                                <?= $g['status']==='Y'?'Ενεργό':'Ανενεργό' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </td>
                <td><?= $g['registration_deadline'] ? h(date('d/m/Y H:i', strtotime($g['registration_deadline']))) : '—' ?></td>
                <td class="actions">
                    <a class="btn btn--sm btn--ghost" href="<?= h(url('admin/championships.php?action=edit&id=' . $g['gameid'])) ?>">
                        <?= $readonly ? 'Προθεσμία' : 'Επεξεργασία' ?>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php render_footer(); ?>

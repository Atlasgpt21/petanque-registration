<?php
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/public/assets/layout.php';
require_admin();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];
$action = $_GET['action'] ?? '';
$editId = (int)($_GET['id'] ?? 0);
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';
    if ($op === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $clubcode = trim((string)($_POST['clubcode'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $newpw    = (string)($_POST['password'] ?? '');
        $active   = isset($_POST['active']) ? 1 : 0;
        $mustChange = isset($_POST['must_change']) ? 1 : 0;

        $errors = [];
        if ($clubcode === '' || $username === '') $errors[] = 'Κωδικός συλλόγου και όνομα χρήστη απαιτούνται.';
        // Έλεγχος ότι ο σύλλογος υπάρχει
        $club = db_one($pdo, "SELECT * FROM `{$T['clubs']}` WHERE clubcode=?", [$clubcode]);
        if (!$club) $errors[] = 'Ο σύλλογος δεν βρέθηκε.';
        if ($id === 0 && $newpw === '') $errors[] = 'Απαιτείται κωδικός για νέο λογαριασμό.';
        if ($newpw !== '' && strlen($newpw) < 6) $errors[] = 'Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες.';

        if (!$errors) {
            try {
                if ($id > 0) {
                    if ($newpw !== '') {
                        $pdo->prepare("UPDATE `{$T['club_users']}` SET clubcode=?, username=?, password_hash=?, active=?, must_change=? WHERE id=?")
                            ->execute([$clubcode, $username, password_hash($newpw, PASSWORD_DEFAULT), $active, $mustChange, $id]);
                    } else {
                        $pdo->prepare("UPDATE `{$T['club_users']}` SET clubcode=?, username=?, active=?, must_change=? WHERE id=?")
                            ->execute([$clubcode, $username, $active, $mustChange, $id]);
                    }
                    flash_set('success', 'Ο λογαριασμός ενημερώθηκε.');
                } else {
                    $pdo->prepare("INSERT INTO `{$T['club_users']}` (clubcode, username, password_hash, active, must_change) VALUES (?,?,?,?,?)")
                        ->execute([$clubcode, $username, password_hash($newpw, PASSWORD_DEFAULT), $active, $mustChange]);
                    flash_set('success', 'Ο λογαριασμός δημιουργήθηκε.');
                }
                redirect(url('admin/club_users.php'));
            } catch (PDOException $e) {
                $errors[] = $e->getCode() === '23000' ? 'Το username υπάρχει ήδη.' : $e->getMessage();
            }
        }
        foreach ($errors as $e) flash_set('error', $e);
        $edit = ['id'=>$id,'clubcode'=>$clubcode,'username'=>$username,'active'=>$active,'must_change'=>$mustChange];
        $action = 'edit';
    }
    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM `{$T['club_users']}` WHERE id=?")->execute([$id]);
        flash_set('success', 'Ο λογαριασμός διαγράφηκε.');
        redirect(url('admin/club_users.php'));
    }
}

if ($action === 'edit' && $editId > 0 && !$edit) { $edit = db_one($pdo, "SELECT * FROM `{$T['club_users']}` WHERE id=?", [$editId]); }
if ($action === 'new'  && !$edit) { $edit = ['id'=>0, 'active'=>1, 'must_change'=>1]; }

$users = db_all($pdo, "SELECT u.*, c.name AS club_name FROM `{$T['club_users']}` u LEFT JOIN `{$T['clubs']}` c ON c.clubcode=u.clubcode ORDER BY u.username");
$clubs = db_all($pdo, "SELECT clubcode, name FROM `{$T['clubs']}` ORDER BY name");

render_header('Λογαριασμοί Συλλόγων', 'admin', 'club_users');
?>
<div class="main__header">
    <div><h2 class="main__title">Λογαριασμοί Συλλόγων</h2></div>
    <?php if (!$edit): ?><a class="btn" href="<?= h(url('admin/club_users.php?action=new')) ?>">+ Νέος Λογαριασμός</a><?php endif; ?>
</div>
<?php if ($edit): ?>
<div class="card">
    <h3 class="card__title"><?= $edit['id'] ? 'Επεξεργασία' : 'Νέος' ?> Λογαριασμός</h3>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="save">
        <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
        <div class="row">
            <div class="field"><label class="field__label">Σύλλογος *</label>
                <select class="select" name="clubcode" required>
                    <option value="">— Επιλέξτε —</option>
                    <?php foreach ($clubs as $c): ?>
                        <option value="<?= h($c['clubcode']) ?>" <?= ($edit['clubcode']??'')===$c['clubcode']?'selected':'' ?>>
                            <?= h($c['clubcode']) ?> — <?= h($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label class="field__label">Όνομα χρήστη *</label>
                <input class="input" type="text" name="username" value="<?= h($edit['username'] ?? '') ?>" required></div>
        </div>
        <div class="field">
            <label class="field__label"><?= $edit['id'] ? 'Νέος κωδικός (αφήστε κενό για διατήρηση)' : 'Κωδικός *' ?></label>
            <input class="input" type="password" name="password" <?= $edit['id'] ? '' : 'required' ?> minlength="6">
        </div>
        <div class="field">
            <label><input type="checkbox" name="active" value="1" <?= !empty($edit['active'])?'checked':'' ?>> Ενεργός</label>
        </div>
        <div class="field">
            <label><input type="checkbox" name="must_change" value="1" <?= !empty($edit['must_change'])?'checked':'' ?>> Να αλλάξει κωδικό στην πρώτη είσοδο</label>
        </div>
        <button class="btn" type="submit">Αποθήκευση</button>
        <a class="btn btn--ghost" href="<?= h(url('admin/club_users.php')) ?>">Ακύρωση</a>
    </form>
</div>
<?php else: ?>
<div class="card">
    <table class="table">
        <thead><tr><th>Username</th><th>Σύλλογος</th><th>Ενεργός</th><th>Τελευταία σύνδεση</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><strong><?= h($u['username']) ?></strong></td>
                <td><?= h($u['club_name'] ?? '—') ?> <span class="muted">(<?= h($u['clubcode']) ?>)</span></td>
                <td><span class="badge <?= $u['active']?'badge--on':'badge--off' ?>"><?= $u['active']?'Ενεργός':'Ανενεργός' ?></span></td>
                <td><?= $u['last_login'] ? h(date('d/m/Y H:i', strtotime($u['last_login']))) : '<span class="muted">—</span>' ?></td>
                <td class="actions">
                    <a class="btn btn--sm btn--ghost" href="<?= h(url('admin/club_users.php?action=edit&id=' . $u['id'])) ?>">Επεξεργασία</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Διαγραφή λογαριασμού;');">
                        <?= csrf_field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                        <button class="btn btn--sm btn--danger" type="submit">Διαγραφή</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php render_footer(); ?>

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
        $id = (int)($_POST['clubid'] ?? 0);
        $data = [
            'clubcode'  => trim((string)($_POST['clubcode'] ?? '')),
            'name'      => trim((string)($_POST['name'] ?? '')),
            'shortname' => trim((string)($_POST['shortname'] ?? '')) ?: null,
            'city'      => trim((string)($_POST['city'] ?? '')) ?: null,
            'phone'     => trim((string)($_POST['phone'] ?? '')) ?: null,
            'email'     => trim((string)($_POST['email'] ?? '')) ?: null,
            'active'    => $_POST['active'] === 'Y' ? 'Y' : 'N',
        ];
        if ($data['clubcode'] === '' || $data['name'] === '') {
            flash_set('error', 'Κωδικός και όνομα είναι υποχρεωτικά.');
        } else {
            try {
                if ($id > 0) {
                    $pdo->prepare("UPDATE `{$T['clubs']}` SET clubcode=?, name=?, shortname=?, city=?, phone=?, email=?, active=? WHERE clubid=?")
                        ->execute([$data['clubcode'], $data['name'], $data['shortname'], $data['city'], $data['phone'], $data['email'], $data['active'], $id]);
                    flash_set('success', 'Ο σύλλογος ενημερώθηκε.');
                } else {
                    $pdo->prepare("INSERT INTO `{$T['clubs']}` (clubcode, name, shortname, city, phone, email, active) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$data['clubcode'], $data['name'], $data['shortname'], $data['city'], $data['phone'], $data['email'], $data['active']]);
                    flash_set('success', 'Ο σύλλογος προστέθηκε.');
                }
                redirect(url('admin/clubs.php'));
            } catch (PDOException $e) {
                flash_set('error', $e->getCode() === '23000' ? 'Ο κωδικός υπάρχει ήδη.' : $e->getMessage());
            }
        }
    }
    if ($op === 'delete') {
        $id = (int)($_POST['clubid'] ?? 0);
        $c = db_one($pdo, "SELECT * FROM `{$T['clubs']}` WHERE clubid=?", [$id]);
        if ($c) {
            $hasPlayers = (int)db_one($pdo, "SELECT COUNT(*) c FROM `{$T['players']}` WHERE clubcode=?", [$c['clubcode']])['c'];
            if ($hasPlayers > 0) {
                flash_set('error', 'Δεν μπορεί να διαγραφεί — υπάρχουν αθλητές.');
            } else {
                $pdo->prepare("DELETE FROM `{$T['clubs']}` WHERE clubid=?")->execute([$id]);
                flash_set('success', 'Ο σύλλογος διαγράφηκε.');
            }
        }
        redirect(url('admin/clubs.php'));
    }
}

if ($action === 'edit' && $editId > 0) {
    $edit = db_one($pdo, "SELECT * FROM `{$T['clubs']}` WHERE clubid=?", [$editId]);
}
if ($action === 'new') { $edit = ['clubid' => 0, 'active' => 'Y']; }

$clubs = db_all($pdo, "SELECT * FROM `{$T['clubs']}` ORDER BY name");

render_header('Σύλλογοι', 'admin', 'clubs');
?>
<div class="main__header">
    <div><h2 class="main__title">Σύλλογοι</h2></div>
    <?php if (!$edit): ?><a class="btn" href="<?= h(url('admin/clubs.php?action=new')) ?>">+ Νέος Σύλλογος</a><?php endif; ?>
</div>
<?php if ($edit): ?>
<div class="card">
    <h3 class="card__title"><?= $edit['clubid'] ? 'Επεξεργασία' : 'Νέος' ?> Σύλλογος</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="save">
        <input type="hidden" name="clubid" value="<?= (int)$edit['clubid'] ?>">
        <div class="row">
            <div class="field"><label class="field__label">Κωδικός *</label><input class="input" type="text" name="clubcode" value="<?= h($edit['clubcode'] ?? '') ?>" required></div>
            <div class="field"><label class="field__label">Όνομα *</label><input class="input" type="text" name="name" value="<?= h($edit['name'] ?? '') ?>" required></div>
            <div class="field"><label class="field__label">Συντομογραφία</label><input class="input" type="text" name="shortname" value="<?= h($edit['shortname'] ?? '') ?>"></div>
        </div>
        <div class="row">
            <div class="field"><label class="field__label">Πόλη</label><input class="input" type="text" name="city" value="<?= h($edit['city'] ?? '') ?>"></div>
            <div class="field"><label class="field__label">Τηλέφωνο</label><input class="input" type="text" name="phone" value="<?= h($edit['phone'] ?? '') ?>"></div>
            <div class="field"><label class="field__label">Email</label><input class="input" type="email" name="email" value="<?= h($edit['email'] ?? '') ?>"></div>
            <div class="field"><label class="field__label">Ενεργός</label>
                <select class="select" name="active">
                    <option value="Y" <?= ($edit['active']??'Y')==='Y'?'selected':'' ?>>Ναι</option>
                    <option value="N" <?= ($edit['active']??'Y')==='N'?'selected':'' ?>>Όχι</option>
                </select>
            </div>
        </div>
        <button class="btn" type="submit">Αποθήκευση</button>
        <a class="btn btn--ghost" href="<?= h(url('admin/clubs.php')) ?>">Ακύρωση</a>
    </form>
</div>
<?php else: ?>
<div class="card">
    <table class="table">
        <thead><tr><th>Κωδ.</th><th>Όνομα</th><th>Sh.</th><th>Πόλη</th><th>Email</th><th>Κατ.</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($clubs as $c): ?>
            <tr>
                <td><code><?= h($c['clubcode']) ?></code></td>
                <td><strong><?= h($c['name']) ?></strong></td>
                <td><?= h($c['shortname'] ?? '—') ?></td>
                <td><?= h($c['city'] ?? '—') ?></td>
                <td><?= h($c['email'] ?? '—') ?></td>
                <td><span class="badge <?= $c['active']==='Y'?'badge--on':'badge--off' ?>"><?= $c['active']==='Y'?'Ενεργός':'Ανενεργός' ?></span></td>
                <td class="actions">
                    <a class="btn btn--sm btn--ghost" href="<?= h(url('admin/clubs.php?action=edit&id=' . $c['clubid'])) ?>">Επεξεργασία</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Διαγραφή;');">
                        <?= csrf_field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="clubid" value="<?= (int)$c['clubid'] ?>">
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

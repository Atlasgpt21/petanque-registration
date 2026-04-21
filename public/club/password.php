<?php
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/public/assets/layout.php';
require_club();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];
$u = $_SESSION['user'];
$first = isset($_GET['first']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old = (string)($_POST['old'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    $row = db_one($pdo, "SELECT * FROM `{$T['club_users']}` WHERE id=?", [$u['id']]);
    $errors = [];
    if (!$row || !password_verify($old, $row['password_hash'])) { $errors[] = 'Ο παλιός κωδικός είναι λάθος.'; }
    if (strlen($new) < 8) { $errors[] = 'Ο νέος κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.'; }
    if ($new !== $confirm) { $errors[] = 'Οι νέοι κωδικοί δεν ταιριάζουν.'; }
    if (!$errors) {
        $pdo->prepare("UPDATE `{$T['club_users']}` SET password_hash=?, must_change=0 WHERE id=?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $u['id']]);
        $_SESSION['user']['must_change'] = false;
        flash_set('success', 'Ο κωδικός αλλάχθηκε.');
        redirect(url('club/dashboard.php'));
    }
    foreach ($errors as $e) flash_set('error', $e);
}

render_header('Αλλαγή κωδικού', 'club', 'password');
?>
<div class="main__header">
    <div>
        <h2 class="main__title">Αλλαγή κωδικού</h2>
        <?php if ($first): ?><p class="main__sub">Για λόγους ασφάλειας, πρέπει να αλλάξετε τον αρχικό κωδικό.</p><?php endif; ?>
    </div>
</div>
<div class="card" style="max-width:480px">
    <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label class="field__label">Τρέχων κωδικός</label><input class="input" type="password" name="old" required></div>
        <div class="field"><label class="field__label">Νέος κωδικός</label><input class="input" type="password" name="new" required minlength="8"></div>
        <div class="field"><label class="field__label">Επιβεβαίωση</label><input class="input" type="password" name="confirm" required minlength="8"></div>
        <button class="btn" type="submit">Αποθήκευση</button>
    </form>
</div>
<?php render_footer(); ?>

<?php
require __DIR__ . '/../src/bootstrap.php';

// Ήδη συνδεδεμένος;
if (is_club()) { redirect(url('club/dashboard.php')); }
if (is_admin()) { redirect(url('admin/dashboard.php')); }

$error = '';
$activeTab = 'club';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $activeTab = $_POST['role'] ?? 'club';
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Συμπληρώστε όνομα χρήστη και κωδικό.';
    } elseif ($activeTab === 'admin') {
        if (login_admin($GLOBALS['PDO'], $GLOBALS['T'], $username, $password)) {
            redirect(url('admin/dashboard.php'));
        }
        $error = 'Λανθασμένα στοιχεία διαχειριστή.';
    } else {
        if (login_club($GLOBALS['PDO'], $GLOBALS['T'], $username, $password)) {
            if ($_SESSION['user']['must_change']) {
                redirect(url('club/password.php?first=1'));
            }
            redirect(url('club/dashboard.php'));
        }
        $error = 'Λανθασμένα στοιχεία συλλόγου.';
    }
}
?>
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <title>Είσοδος — Petanque Registration</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="<?= h(url('assets/style.css')) ?>">
</head>
<body class="auth">
<div class="auth__card">
    <h1 class="auth__title">Δηλώσεις Συμμετοχής</h1>
    <p class="auth__sub">Συνδεθείτε για να δηλώσετε τους αθλητές του συλλόγου σας.</p>

    <div class="auth__tabs">
        <button type="button" class="auth__tab <?= $activeTab==='club'?'auth__tab--active':'' ?>" data-auth-tab="club">Σύλλογος</button>
        <button type="button" class="auth__tab <?= $activeTab==='admin'?'auth__tab--active':'' ?>" data-auth-tab="admin">Διαχειριστής</button>
    </div>

    <?php if ($error): ?>
        <div class="flash flash--error"><?= h($error) ?></div>
    <?php endif; ?>

    <div data-auth-panel="club" <?= $activeTab==='club'?'':'hidden' ?>>
        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="role" value="club">
            <div class="field">
                <label class="field__label">Όνομα χρήστη συλλόγου</label>
                <input class="input" type="text" name="username" autofocus>
            </div>
            <div class="field">
                <label class="field__label">Κωδικός</label>
                <input class="input" type="password" name="password">
            </div>
            <button class="btn btn--block" type="submit">Είσοδος ως σύλλογος</button>
        </form>
    </div>

    <div data-auth-panel="admin" <?= $activeTab==='admin'?'':'hidden' ?>>
        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="role" value="admin">
            <div class="field">
                <label class="field__label">Όνομα χρήστη admin</label>
                <input class="input" type="text" name="username">
            </div>
            <div class="field">
                <label class="field__label">Κωδικός</label>
                <input class="input" type="password" name="password">
            </div>
            <button class="btn btn--accent btn--block" type="submit">Είσοδος ως διαχειριστής</button>
        </form>
    </div>
</div>

<script src="<?= h(url('assets/app.js')) ?>"></script>
</body>
</html>

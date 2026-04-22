<?php
// Εύρεση src/bootstrap.php ανεβαίνοντας φακέλους — ανθεκτικό σε διαφορετικά deployments.
for ($d = __DIR__; $d !== dirname($d); $d = dirname($d)) {
    if (is_file($d . '/src/bootstrap.php')) { require $d . '/src/bootstrap.php'; break; }
}
unset($d);

// Ήδη συνδεδεμένος;
if (is_club())  { redirect(url('club/dashboard.php')); }
if (is_admin()) { redirect(url('admin/dashboard.php')); }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Συμπληρώστε όνομα χρήστη και κωδικό.';
    } else {
        // Ενιαία φόρμα: δοκιμάζει πρώτα admin, μετά club. Ο διαχωρισμός
        // γίνεται βάσει του πού υπάρχει ο λογαριασμός (app_admins vs
        // app_club_users vs legacy users).
        if (login_admin($GLOBALS['PDO'], $GLOBALS['T'], $username, $password)) {
            redirect(url('admin/dashboard.php'));
        }
        if (login_club($GLOBALS['PDO'], $GLOBALS['T'], $username, $password)) {
            if ($_SESSION['user']['must_change'] ?? false) {
                redirect(url('club/password.php?first=1'));
            }
            redirect(url('club/dashboard.php'));
        }
        $error = 'Λανθασμένα στοιχεία σύνδεσης.';
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
    <p class="auth__sub">Συνδεθείτε με τα στοιχεία που χρησιμοποιείτε στο κεντρικό σύστημα της ΕΟΠ.</p>

    <?php if ($error): ?>
        <div class="flash flash--error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="field">
            <label class="field__label">Όνομα χρήστη</label>
            <input class="input" type="text" name="username" autofocus value="<?= h($_POST['username'] ?? '') ?>">
        </div>
        <div class="field">
            <label class="field__label">Κωδικός</label>
            <input class="input" type="password" name="password">
        </div>
        <button class="btn btn--block" type="submit">Σύνδεση</button>
    </form>
</div>

<script src="<?= h(url('assets/app.js')) ?>"></script>
</body>
</html>

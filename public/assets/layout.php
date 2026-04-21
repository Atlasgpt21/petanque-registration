<?php
// Shared layout helpers (header + sidebar + footer) για club & admin.
// Χρήση:
//   require APP_ROOT . '/public/assets/layout.php';
//   render_header('Τίτλος', 'club', 'dashboard');
//     ...html...
//   render_footer();

function render_header(string $title, string $role, string $active = ''): void
{
    $u = current_user();
    $brandTitle = $role === 'admin' ? 'Διαχείριση' : ($u['clubname'] ?? 'Σύλλογος');
    $brandSub   = $role === 'admin' ? 'Admin panel' : ('Κωδ. ' . ($u['clubcode'] ?? '—'));

    $clubLinks = [
        ['dashboard',  'Αρχική',               'club/dashboard.php'],
        ['athletes',   'Αθλητές',              'club/athletes.php'],
        ['teams',      'Δηλώσεις Ομάδων',      'club/teams.php'],
        ['password',   'Αλλαγή κωδικού',       'club/password.php'],
    ];
    $adminLinks = [
        ['dashboard',     'Αρχική',             'admin/dashboard.php'],
        ['championships', 'Πρωταθλήματα',        'admin/championships.php'],
        ['clubs',         'Σύλλογοι',           'admin/clubs.php'],
        ['club_users',    'Λογαριασμοί',        'admin/club_users.php'],
        ['players',       'Αθλητές',            'admin/players.php'],
        ['all_teams',     'Όλες οι δηλώσεις',   'admin/all_teams.php'],
        ['export',        'Εξαγωγή CSV',        'admin/export.php'],
    ];
    $links = $role === 'admin' ? $adminLinks : $clubLinks;

    $cssHref = url('assets/style.css');
    $jsHref  = url('assets/app.js');
    $logoutHref = url('logout.php');

    echo '<!doctype html>';
    echo '<html lang="el"><head><meta charset="utf-8"><title>' . h($title) . '</title>';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<link rel="stylesheet" href="' . h($cssHref) . '"></head><body>';
    echo '<div class="app">';
    echo '<aside class="sidebar">';
    echo '<div class="sidebar__brand">';
    echo '<h1 class="sidebar__title">' . h($brandTitle) . '</h1>';
    echo '<p class="sidebar__subtitle">' . h($brandSub) . '</p>';
    echo '</div>';
    echo '<nav class="sidebar__nav">';
    foreach ($links as [$slug, $label, $path]) {
        $cls = 'sidebar__link' . ($slug === $active ? ' sidebar__link--active' : '');
        echo '<a class="' . $cls . '" href="' . h(url($path)) . '">' . h($label) . '</a>';
    }
    echo '</nav>';
    echo '<div class="sidebar__footer">';
    echo 'Συνδεδεμένος ως<br><span class="sidebar__user">' . h($u['username'] ?? '') . '</span><br>';
    echo '<a href="' . h($logoutHref) . '" style="color:#d9a27e;">Αποσύνδεση →</a>';
    echo '</div>';
    echo '</aside>';
    echo '<main class="main">';
    echo render_flash();
}

function render_footer(): void
{
    $jsHref  = url('assets/app.js');
    echo '</main></div>';
    echo '<script src="' . h($jsHref) . '"></script>';
    echo '</body></html>';
}

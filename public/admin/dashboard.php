<?php
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/public/assets/layout.php';
require_admin();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];

$totals = [
    'clubs'   => (int)db_one($pdo, "SELECT COUNT(*) c FROM `{$T['clubs']}`")['c'],
    'players' => (int)db_one($pdo, "SELECT COUNT(*) c FROM `{$T['players']}`")['c'],
    'games'   => (int)db_one($pdo, "SELECT COUNT(*) c FROM `{$T['games']}`")['c'],
    'teams'   => (int)db_one($pdo, "SELECT COUNT(*) c FROM `{$T['teams']}`")['c'],
];

$active = db_all($pdo, "SELECT * FROM `{$T['games']}` WHERE status='Y' ORDER BY gameid DESC");

// Σύνοψη δηλώσεων ανά ενεργό πρωτάθλημα
$summary = [];
foreach ($active as $g) {
    $summary[$g['gamecode']] = [
        'clubs' => (int)db_one($pdo, "SELECT COUNT(*) c FROM `{$T['aa']}` WHERE gamecode=? AND gamestatus='Y'", [$g['gamecode']])['c'],
        'teams' => (int)db_one($pdo, "SELECT COUNT(*) c FROM `{$T['teams']}` WHERE gamecode=? AND status='Y'", [$g['gamecode']])['c'],
        'players' => (int)db_one($pdo, "SELECT COUNT(*) c FROM `{$T['games2']}` WHERE gamecode=? AND checkstatus='Y'", [$g['gamecode']])['c'],
    ];
}

render_header('Πίνακας Διαχείρισης', 'admin', 'dashboard');
?>
<div class="main__header">
    <div>
        <h2 class="main__title">Πίνακας Διαχείρισης</h2>
        <p class="main__sub">Επισκόπηση συστήματος</p>
    </div>
</div>

<div class="grid grid--4 mb-16">
    <div class="stat"><div class="stat__label">Σύλλογοι</div><div class="stat__value"><?= $totals['clubs'] ?></div></div>
    <div class="stat"><div class="stat__label">Αθλητές</div><div class="stat__value"><?= $totals['players'] ?></div></div>
    <div class="stat"><div class="stat__label">Πρωταθλήματα</div><div class="stat__value"><?= $totals['games'] ?></div></div>
    <div class="stat"><div class="stat__label">Σύνολο Ομάδων</div><div class="stat__value"><?= $totals['teams'] ?></div></div>
</div>

<div class="card">
    <h3 class="card__title">Ενεργά Πρωταθλήματα</h3>
    <?php if (!$active): ?>
        <p class="muted">Κανένα ενεργό πρωτάθλημα. <a href="<?= h(url('admin/championships.php')) ?>">Ενεργοποιήστε ένα</a>.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Κωδικός</th><th>Όνομα</th><th>Τύπος</th><th>Προθεσμία</th><th>Σύλλογοι</th><th>Ομάδες</th><th>Παίκτες</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($active as $g): ?>
                <tr>
                    <td><strong><?= h($g['gamecode']) ?></strong></td>
                    <td><?= h($g['name']) ?></td>
                    <td><span class="badge"><?= h(gametype_label($g['gametype'])) ?></span></td>
                    <td><?= $g['registration_deadline'] ? h(date('d/m/Y H:i', strtotime($g['registration_deadline']))) : '<span class="muted">—</span>' ?></td>
                    <td><?= $summary[$g['gamecode']]['clubs'] ?></td>
                    <td><?= $summary[$g['gamecode']]['teams'] ?></td>
                    <td><?= $summary[$g['gamecode']]['players'] ?></td>
                    <td class="actions">
                        <a class="btn btn--sm btn--ghost" href="<?= h(url('admin/all_teams.php?g=' . urlencode($g['gamecode']))) ?>">Δηλώσεις →</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php render_footer(); ?>

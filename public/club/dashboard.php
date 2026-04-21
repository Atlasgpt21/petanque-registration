<?php
require __DIR__ . '/../../src/bootstrap.php';
require APP_ROOT . '/public/assets/layout.php';
require_club();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];
$club = $_SESSION['user'];

// Ενεργό(ά) πρωτάθλημα(α)
$games = db_all($pdo, "SELECT * FROM `{$T['games']}` WHERE status='Y' ORDER BY gameid DESC");

// Στατιστικά συλλόγου
$totalPlayers = (int)db_one($pdo, "SELECT COUNT(*) c FROM `{$T['players']}` WHERE clubcode=? AND active='Y'", [$club['clubcode']])['c'];
$activeGamecode = $games[0]['gamecode'] ?? null;
$myTeams = 0; $myDecls = 0;
if ($activeGamecode) {
    $myTeams = (int)db_one($pdo, "SELECT COUNT(*) c FROM `{$T['teams']}` WHERE clubcode=? AND gamecode=? AND status='Y'", [$club['clubcode'], $activeGamecode])['c'];
    $myDecls = (int)db_one($pdo, "SELECT COUNT(*) c FROM `{$T['games2']}` WHERE clubcode=? AND gamecode=? AND checkstatus='Y'", [$club['clubcode'], $activeGamecode])['c'];
}

render_header('Αρχική — ' . $club['clubname'], 'club', 'dashboard');
?>
<div class="main__header">
    <div>
        <h2 class="main__title">Καλωσορίσατε, <?= h($club['clubname']) ?></h2>
        <p class="main__sub">Κωδικός συλλόγου: <?= h($club['clubcode']) ?></p>
    </div>
</div>

<div class="grid grid--3 mb-16">
    <div class="stat">
        <div class="stat__label">Αθλητές Συλλόγου</div>
        <div class="stat__value"><?= $totalPlayers ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Δηλωμένες Ομάδες</div>
        <div class="stat__value"><?= $myTeams ?></div>
    </div>
    <div class="stat">
        <div class="stat__label">Δηλωμένοι Παίκτες</div>
        <div class="stat__value"><?= $myDecls ?></div>
    </div>
</div>

<div class="card">
    <h3 class="card__title">Ενεργά Πρωταθλήματα</h3>
    <?php if (empty($games)): ?>
        <p class="muted">Δεν υπάρχει ενεργό πρωτάθλημα αυτή τη στιγμή.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Κωδικός</th><th>Όνομα</th><th>Τύπος</th><th>Έναρξη</th><th>Προθεσμία</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($games as $g): ?>
                <?php $open = is_registration_open($g); ?>
                <tr>
                    <td><strong><?= h($g['gamecode']) ?></strong></td>
                    <td><?= h($g['name']) ?></td>
                    <td><span class="badge"><?= h(gametype_label($g['gametype'])) ?></span></td>
                    <td><?= h($g['startdate'] ?? '—') ?></td>
                    <td>
                        <?php if (!empty($g['registration_deadline'])): ?>
                            <?= h(date('d/m/Y H:i', strtotime($g['registration_deadline']))) ?>
                        <?php else: ?>
                            <span class="muted">χωρίς προθεσμία</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <?php if ($open): ?>
                            <a class="btn btn--sm" href="<?= h(url('club/teams.php?g=' . urlencode($g['gamecode']))) ?>">Δήλωση Ομάδων →</a>
                        <?php else: ?>
                            <span class="badge badge--off">Κλειστό</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3 class="card__title">Οδηγίες</h3>
    <ol>
        <li>Καταχωρίστε ή επιβεβαιώστε τη λίστα των <a href="<?= h(url('club/athletes.php')) ?>">Αθλητών</a> του συλλόγου σας.</li>
        <li>Μεταβείτε στις <a href="<?= h(url('club/teams.php')) ?>">Δηλώσεις Ομάδων</a> και συνθέστε τις ομάδες ανά κατηγορία (Άνδρες / Γυναίκες / Μεικτό).</li>
        <li>Κάθε αθλητής μπορεί να δηλωθεί σε <strong>μία μόνο ομάδα</strong> ανά πρωτάθλημα.</li>
        <li>Η φόρμα κλειδώνει αυτόματα στην προθεσμία που ορίζει ο διαχειριστής.</li>
    </ol>
</div>
<?php render_footer(); ?>

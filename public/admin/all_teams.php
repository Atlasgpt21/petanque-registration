<?php
// Εύρεση src/bootstrap.php ανεβαίνοντας φακέλους — ανθεκτικό σε διαφορετικά deployments.
for ($d = __DIR__; $d !== dirname($d); $d = dirname($d)) {
    if (is_file($d . '/src/bootstrap.php')) { require $d . '/src/bootstrap.php'; break; }
}
unset($d);
require PUBLIC_ROOT . '/assets/layout.php';
require_admin();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];

// Επιλογή πρωταθλήματος
$gamecode = $_GET['g'] ?? null;
if (!$gamecode) {
    $g = db_one($pdo, "SELECT gamecode FROM `{$T['games']}` WHERE status='Y' ORDER BY gameid DESC LIMIT 1");
    $gamecode = $g['gamecode'] ?? null;
}
$game = $gamecode ? db_one($pdo, "SELECT * FROM `{$T['games']}` WHERE gamecode=?", [$gamecode]) : null;
$games = db_all($pdo, "SELECT gameid, gamecode, name, status FROM `{$T['games']}` ORDER BY gameid DESC");

$teams = [];
if ($game) {
    $teams = db_all($pdo, "
        SELECT t.*, c.name AS club_name
        FROM `{$T['teams']}` t
        LEFT JOIN `{$T['clubs']}` c ON c.clubcode=t.clubcode
        WHERE t.gamecode=? AND t.status='Y'
        ORDER BY c.name, t.category, t.teamname
    ", [$game['gamecode']]);
}

render_header('Όλες οι Δηλώσεις', 'admin', 'all_teams');
?>
<div class="main__header">
    <div>
        <h2 class="main__title">Όλες οι Δηλώσεις</h2>
        <?php if ($game): ?><p class="main__sub"><strong><?= h($game['name']) ?></strong> · <?= h(gametype_label($game['gametype'])) ?></p><?php endif; ?>
    </div>
</div>
<div class="card">
    <form method="get" class="row">
        <div class="field"><label class="field__label">Πρωτάθλημα</label>
            <select class="select" name="g" onchange="this.form.submit()">
                <?php foreach ($games as $gg): ?>
                    <option value="<?= h($gg['gamecode']) ?>" <?= $gamecode===$gg['gamecode']?'selected':'' ?>>
                        <?= h($gg['gamecode']) ?> — <?= h($gg['name']) ?> <?= $gg['status']==='Y'?' ✓':'' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>
<?php if ($game): ?>
<div class="card">
    <h3 class="card__title">Σύνολο ομάδων: <?= count($teams) ?></h3>
    <?php if (!$teams): ?>
        <p class="muted">Καμία δήλωση για αυτό το πρωτάθλημα.</p>
    <?php else: ?>
        <table class="table">
            <thead><tr><th>Σύλλογος</th><th>Ομάδα</th><th>Κατηγορία</th><th>Παίκτες</th></tr></thead>
            <tbody>
            <?php foreach ($teams as $t):
                $codes = explode('-', $t['playercodes']);
                $in = implode(',', array_fill(0, count($codes), '?'));
                $plist = db_all($pdo, "SELECT * FROM `{$T['players']}` WHERE playercode IN ($in)", $codes);
                usort($plist, function($a,$b) use ($codes) { return array_search($a['playercode'], $codes) <=> array_search($b['playercode'], $codes); });
            ?>
                <tr>
                    <td><strong><?= h($t['club_name'] ?? $t['clubcode']) ?></strong><br><span class="muted"><?= h($t['clubcode']) ?></span></td>
                    <td><code><?= h($t['teamname']) ?></code></td>
                    <td><span class="badge badge--<?= h($t['category']) ?>">
                        <?= $t['category']==='M'?'Άνδρες':($t['category']==='F'?'Γυναίκες':'Μεικτό') ?>
                    </span></td>
                    <td>
                        <?php foreach ($plist as $pl): ?>
                            <?= h($pl['lastname']) ?> <?= h($pl['firstname']) ?> <span class="muted">(<?= h($pl['playercode']) ?>)</span><br>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php render_footer(); ?>

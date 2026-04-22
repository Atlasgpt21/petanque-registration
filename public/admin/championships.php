<?php
// Εύρεση src/bootstrap.php ανεβαίνοντας φακέλους — ανθεκτικό σε διαφορετικά deployments.
for ($d = __DIR__; $d !== dirname($d); $d = dirname($d)) {
    if (is_file($d . '/src/bootstrap.php')) { require $d . '/src/bootstrap.php'; break; }
}
unset($d);
require PUBLIC_ROOT . '/assets/layout.php';
require_admin();

$pdo = $GLOBALS['PDO']; $T = $GLOBALS['T'];
$readonly = is_readonly_external();
$metaTable = $GLOBALS['CONFIG']['game_meta_table'] ?? 'app_game_meta';
$appGamesTable = $T['app_games'] ?? 'app_games';

// Offset που εφαρμόζεται στο games_v VIEW για να διακρίνουμε τα app_games rows
// από τα υπάρχοντα games rows. Δες sql/migration_hostinger.sql (C4).
const APP_GAMES_OFFSET = 1000000;

function gv_is_app(int $gameid): bool { return $gameid >= APP_GAMES_OFFSET; }
function gv_app_id(int $gameid): int { return $gameid - APP_GAMES_OFFSET; }
function gv_view_id(int $appId): int { return $appId + APP_GAMES_OFFSET; }

$action = $_GET['action'] ?? '';
$editId = (int)($_GET['id'] ?? 0);
$edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'toggle') {
        $id = (int)($_POST['gameid'] ?? 0);
        $status = ($_POST['status'] ?? '') === 'Y' ? 'Y' : 'N';
        if (!gv_is_app($id)) {
            flash_set('error', 'Η κατάσταση πρωταθλημάτων της άλλης εφαρμογής αλλάζει μόνο από εκεί.');
            redirect(url('admin/championships.php'));
        }
        $pdo->prepare("UPDATE `{$appGamesTable}` SET status=? WHERE gameid=?")
            ->execute([$status, gv_app_id($id)]);
        flash_set('success', 'Η κατάσταση ενημερώθηκε.');
        redirect(url('admin/championships.php'));
    }

    if ($op === 'save') {
        $id       = (int)($_POST['gameid'] ?? 0);
        $deadline = trim((string)($_POST['registration_deadline'] ?? '')) ?: null;

        // Legacy games row (πίνακας `games` από την άλλη εφαρμογή): μόνο deadline.
        if ($id > 0 && !gv_is_app($id)) {
            $g = db_one($pdo, "SELECT * FROM `{$T['games']}` WHERE gameid=?", [$id]);
            if (!$g) {
                flash_set('error', 'Το πρωτάθλημα δεν βρέθηκε.');
                redirect(url('admin/championships.php'));
            }
            try {
                $pdo->prepare("INSERT INTO `{$metaTable}` (gamecode, registration_deadline) VALUES (?,?) ON DUPLICATE KEY UPDATE registration_deadline=VALUES(registration_deadline)")
                    ->execute([$g['gamecode'], $deadline]);
                flash_set('success', 'Η προθεσμία δηλώσεων αποθηκεύτηκε.');
            } catch (PDOException $e) {
                flash_set('error', 'Σφάλμα: ' . $e->getMessage());
            }
            redirect(url('admin/championships.php'));
        }

        // app_games: πλήρες CRUD.
        $name     = trim((string)($_POST['name'] ?? ''));
        $gamecode = trim((string)($_POST['gamecode'] ?? ''));
        $gametype = (string)($_POST['gametype'] ?? 'Doubles');
        $category = trim((string)($_POST['category'] ?? 'Πρωτάθλημα'));
        $status   = ($_POST['status'] ?? 'N') === 'Y' ? 'Y' : 'N';
        $start    = trim((string)($_POST['startdate'] ?? '')) ?: null;
        $end      = trim((string)($_POST['enddate'] ?? '')) ?: null;
        $errors = [];
        if ($name === '' || $gamecode === '') { $errors[] = 'Απαιτούνται όνομα και κωδικός.'; }
        if (!in_array($gametype, ['Doubles','Triplets','Mixed','Intercup'], true)) { $errors[] = 'Μη έγκυρος τύπος.'; }
        if (!$errors) {
            try {
                if ($id > 0 && gv_is_app($id)) {
                    $pdo->prepare("UPDATE `{$appGamesTable}` SET name=?, gamecode=?, gametype=?, category=?, status=?, startdate=?, enddate=? WHERE gameid=?")
                        ->execute([$name, $gamecode, $gametype, $category, $status, $start, $end, gv_app_id($id)]);
                    flash_set('success', 'Το πρωτάθλημα ενημερώθηκε.');
                } else {
                    $pdo->prepare("INSERT INTO `{$appGamesTable}` (name, gamecode, gametype, category, status, startdate, enddate) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$name, $gamecode, $gametype, $category, $status, $start, $end]);
                    flash_set('success', 'Το πρωτάθλημα προστέθηκε.');
                }
                // Save deadline separately στον app_game_meta (ενιαία πηγή για legacy + app).
                $pdo->prepare("INSERT INTO `{$metaTable}` (gamecode, registration_deadline) VALUES (?,?) ON DUPLICATE KEY UPDATE registration_deadline=VALUES(registration_deadline)")
                    ->execute([$gamecode, $deadline]);
                redirect(url('admin/championships.php'));
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') { $errors[] = 'Ο κωδικός πρωταθλήματος υπάρχει ήδη.'; }
                else { $errors[] = $e->getMessage(); }
            }
        }
        foreach ($errors as $e) flash_set('error', $e);
        $edit = [
            'gameid'   => $id,
            'source'   => $id > 0 && gv_is_app($id) ? 'app_games' : 'app_games',
            'name'     => $name,
            'gamecode' => $gamecode,
            'gametype' => $gametype,
            'category' => $category,
            'status'   => $status,
            'startdate' => $start,
            'enddate'   => $end,
            'registration_deadline' => $deadline,
        ];
        $action = 'edit';
    }
}

if ($action === 'edit' && $editId > 0 && !$edit) {
    $edit = db_one($pdo, "SELECT * FROM `{$T['games']}` WHERE gameid=?", [$editId]);
}
if ($action === 'new' && !$edit) {
    $edit = [
        'gameid'   => 0,
        'source'   => 'app_games',
        'gametype' => 'Doubles',
        'status'   => 'N',
        'category' => 'Πρωτάθλημα',
    ];
}

$games = db_all($pdo, "SELECT * FROM `{$T['games']}` ORDER BY gameid DESC");

render_header('Πρωταθλήματα', 'admin', 'championships');
?>
<div class="main__header">
    <div>
        <h2 class="main__title">Πρωταθλήματα</h2>
        <p class="main__sub">
            <span class="badge badge--on">Τοπικά</span> = δημιουργήθηκαν εδώ (πλήρης επεξεργασία).
            <span class="badge">Κεντρικά</span> = από την άλλη εφαρμογή (μόνο προθεσμία).
        </p>
    </div>
    <?php if (!$edit): ?>
        <a class="btn" href="<?= h(url('admin/championships.php?action=new')) ?>">+ Νέο Πρωτάθλημα</a>
    <?php endif; ?>
</div>

<?php
// Καθορισμός mode της φόρμας: 'legacy' = read-only fields + μόνο deadline
$isLegacy = $edit && (int)$edit['gameid'] > 0 && !gv_is_app((int)$edit['gameid']);
?>

<?php if ($edit): ?>
<div class="card">
    <h3 class="card__title">
        <?= $edit['gameid'] ? 'Επεξεργασία' : 'Νέο' ?> Πρωτάθλημα
        <?php if ($isLegacy): ?><span class="badge" style="margin-left:.5rem;">Κεντρικό</span>
        <?php elseif ($edit['gameid']): ?><span class="badge badge--on" style="margin-left:.5rem;">Τοπικό</span>
        <?php endif; ?>
    </h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="op" value="save">
        <input type="hidden" name="gameid" value="<?= (int)$edit['gameid'] ?>">
        <?php if ($isLegacy): ?>
            <div class="row">
                <div class="field"><label class="field__label">Όνομα</label><input class="input" type="text" value="<?= h($edit['name'] ?? '') ?>" disabled></div>
                <div class="field"><label class="field__label">Κωδικός</label><input class="input" type="text" value="<?= h($edit['gamecode'] ?? '') ?>" disabled></div>
                <div class="field"><label class="field__label">Τύπος</label><input class="input" type="text" value="<?= h(gametype_label($edit['gametype'] ?? '')) ?>" disabled></div>
            </div>
            <div class="row">
                <div class="field"><label class="field__label">Έναρξη</label><input class="input" type="text" value="<?= h($edit['startdate'] ?? '') ?>" disabled></div>
                <div class="field"><label class="field__label">Λήξη</label><input class="input" type="text" value="<?= h($edit['enddate'] ?? '') ?>" disabled></div>
                <div class="field"><label class="field__label">Κατάσταση</label><input class="input" type="text" value="<?= ($edit['status']??'')==='Y'?'Ενεργό':'Ανενεργό' ?>" disabled></div>
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
        <thead><tr><th>Πηγή</th><th>Κωδ.</th><th>Όνομα</th><th>Τύπος</th><th>Κατάσταση</th><th>Προθεσμία</th><th class="actions"></th></tr></thead>
        <tbody>
        <?php foreach ($games as $g): $isApp = gv_is_app((int)$g['gameid']); ?>
            <tr>
                <td>
                    <?php if ($isApp): ?><span class="badge badge--on">Τοπικό</span>
                    <?php else: ?><span class="badge">Κεντρικό</span>
                    <?php endif; ?>
                </td>
                <td><code><?= h($g['gamecode']) ?></code></td>
                <td><?= h($g['name']) ?></td>
                <td><span class="badge"><?= h(gametype_label($g['gametype'])) ?></span></td>
                <td>
                    <?php if ($isApp): ?>
                        <form method="post" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="op" value="toggle">
                            <input type="hidden" name="gameid" value="<?= (int)$g['gameid'] ?>">
                            <input type="hidden" name="status" value="<?= $g['status']==='Y'?'N':'Y' ?>">
                            <button class="badge <?= $g['status']==='Y'?'badge--on':'badge--off' ?>" type="submit" style="cursor:pointer;border:0;">
                                <?= $g['status']==='Y'?'Ενεργό':'Ανενεργό' ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <span class="badge <?= $g['status']==='Y'?'badge--on':'badge--off' ?>"><?= $g['status']==='Y'?'Ενεργό':'Ανενεργό' ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $g['registration_deadline'] ? h(date('d/m/Y H:i', strtotime($g['registration_deadline']))) : '—' ?></td>
                <td class="actions">
                    <a class="btn btn--sm btn--ghost" href="<?= h(url('admin/championships.php?action=edit&id=' . $g['gameid'])) ?>">
                        <?= $isApp ? 'Επεξεργασία' : 'Προθεσμία' ?>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php render_footer(); ?>

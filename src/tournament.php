<?php
declare(strict_types=1);

/**
 * Data layer για τις διοργανώσεις (Swiss). Χρησιμοποιεί το global PDO/tables
 * και τη μηχανή του src/swiss.php. Οι ομάδες διαβάζονται από τον πίνακα
 * `teams` (config `tables.teams`) — γράφουμε μόνο στους app_tournament* πίνακες.
 */

require_once __DIR__ . '/swiss.php';

/** Ονόματα πινάκων διοργάνωσης (με προαιρετικό override από config `tables`). */
function tour_tables(): array
{
    $T = $GLOBALS['T'] ?? [];
    return [
        'tournaments' => $T['tournaments']         ?? 'app_tournaments',
        'teams'       => $T['tournament_teams']    ?? 'app_tournament_teams',
        'rounds'      => $T['tournament_rounds']   ?? 'app_tournament_rounds',
        'matches'     => $T['tournament_matches']  ?? 'app_tournament_matches',
    ];
}

function tour_get(PDO $pdo, int $id): ?array
{
    $tt = tour_tables();
    return db_one($pdo, "SELECT * FROM `{$tt['tournaments']}` WHERE id=?", [$id]);
}

/** Λίστα διοργανώσεων + πλήθος ομάδων/γύρων. */
function tour_all(PDO $pdo): array
{
    $tt = tour_tables();
    return db_all($pdo, "
        SELECT t.*,
               (SELECT COUNT(*) FROM `{$tt['teams']}`  x WHERE x.tournament_id=t.id) AS team_count,
               (SELECT COUNT(*) FROM `{$tt['rounds']}` r WHERE r.tournament_id=t.id) AS round_count
        FROM `{$tt['tournaments']}` t
        ORDER BY t.id DESC
    ");
}

function tour_create(PDO $pdo, string $name, string $gamecode): int
{
    $tt = tour_tables();
    $st = $pdo->prepare("INSERT INTO `{$tt['tournaments']}` (name, gamecode) VALUES (?,?)");
    $st->execute([$name, $gamecode]);
    return (int)$pdo->lastInsertId();
}

function tour_delete(PDO $pdo, int $id): void
{
    $tt = tour_tables();
    $pdo->prepare("DELETE FROM `{$tt['matches']}` WHERE tournament_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM `{$tt['rounds']}`  WHERE tournament_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM `{$tt['teams']}`   WHERE tournament_id=?")->execute([$id]);
    $pdo->prepare("DELETE FROM `{$tt['tournaments']}` WHERE id=?")->execute([$id]);
}

function tour_teams(PDO $pdo, int $id): array
{
    $tt = tour_tables();
    return db_all($pdo, "SELECT * FROM `{$tt['teams']}` WHERE tournament_id=? ORDER BY seed IS NULL, seed, id", [$id]);
}

/**
 * Import ομάδων από το championship (πίνακας teams) που δεν υπάρχουν ήδη.
 * Επιστρέφει πλήθος νέων ομάδων.
 */
function tour_import_teams(PDO $pdo, int $id, string $gamecode): int
{
    $tt = tour_tables();
    $T  = $GLOBALS['T'];

    $rows = db_all($pdo, "
        SELECT t.teamname, t.clubcode, t.category, c.name AS club_name
        FROM `{$T['teams']}` t
        LEFT JOIN `{$T['clubs']}` c ON c.clubcode = t.clubcode
        WHERE t.gamecode=? AND t.status='Y'
        ORDER BY t.clubcode, t.category, t.teamname
    ", [$gamecode]);
    if (function_exists('hpf_decrypt_rows')) {
        $rows = hpf_decrypt_rows($rows, ['club_name']);
    }

    // Υπάρχοντα teamnames για αποφυγή διπλών.
    $existing = [];
    foreach (tour_teams($pdo, $id) as $r) {
        $existing[$r['teamname']] = true;
    }

    $seed = 0;
    foreach (db_all($pdo, "SELECT MAX(seed) AS m FROM `{$tt['teams']}` WHERE tournament_id=?", [$id]) as $r) {
        $seed = (int)($r['m'] ?? 0);
    }

    $ins = $pdo->prepare("INSERT INTO `{$tt['teams']}` (tournament_id, teamname, clubcode, label, seed) VALUES (?,?,?,?,?)");
    $added = 0;
    foreach ($rows as $row) {
        if (isset($existing[$row['teamname']])) {
            continue;
        }
        $club  = trim((string)($row['club_name'] ?? $row['clubcode'] ?? ''));
        $label = $row['teamname'] . ($club !== '' ? ' — ' . $club : '');
        $ins->execute([$id, $row['teamname'], $row['clubcode'] ?? null, $label, ++$seed]);
        $added++;
    }
    return $added;
}

function tour_delete_team(PDO $pdo, int $id, int $teamId): void
{
    $tt = tour_tables();
    $pdo->prepare("DELETE FROM `{$tt['teams']}` WHERE tournament_id=? AND id=?")->execute([$id, $teamId]);
}

function tour_set_team_withdrawn(PDO $pdo, int $id, int $teamId, bool $withdrawn): void
{
    $tt = tour_tables();
    $pdo->prepare("UPDATE `{$tt['teams']}` SET withdrawn=? WHERE tournament_id=? AND id=?")
        ->execute([$withdrawn ? 1 : 0, $id, $teamId]);
}

function tour_rounds(PDO $pdo, int $id): array
{
    $tt = tour_tables();
    return db_all($pdo, "SELECT * FROM `{$tt['rounds']}` WHERE tournament_id=? ORDER BY round_no", [$id]);
}

function tour_matches(PDO $pdo, int $id, ?int $roundNo = null): array
{
    $tt = tour_tables();
    if ($roundNo === null) {
        return db_all($pdo, "SELECT * FROM `{$tt['matches']}` WHERE tournament_id=? ORDER BY round_no, board_no", [$id]);
    }
    return db_all($pdo, "SELECT * FROM `{$tt['matches']}` WHERE tournament_id=? AND round_no=? ORDER BY board_no", [$id, $roundNo]);
}

/** Κατάταξη μέσω της μηχανής Swiss. */
function tour_standings(PDO $pdo, int $id): array
{
    $tour  = tour_get($pdo, $id);
    $teams = array_map(static function (array $r): array {
        return [
            'id'        => (int)$r['id'],
            'label'     => (string)$r['label'],
            'clubcode'  => $r['clubcode'] ?? null,
            'seed'      => $r['seed'] !== null ? (int)$r['seed'] : null,
            'withdrawn' => (int)$r['withdrawn'] === 1,
        ];
    }, tour_teams($pdo, $id));

    $cfg = [
        'win_points'  => (int)($tour['win_points']  ?? 2),
        'draw_points' => (int)($tour['draw_points'] ?? 1),
        'loss_points' => (int)($tour['loss_points'] ?? 0),
    ];
    return swiss_standings($teams, tour_matches($pdo, $id), $cfg);
}

/**
 * Δημιουργεί τον επόμενο γύρο (pairings). Επιστρέφει τον αριθμό γύρου.
 * @throws RuntimeException αν ο τρέχων γύρος δεν έχει ολοκληρωθεί ή <2 ομάδες.
 */
function tour_generate_round(PDO $pdo, int $id): int
{
    $tt   = tour_tables();
    $tour = tour_get($pdo, $id);
    if (!$tour) {
        throw new RuntimeException('Η διοργάνωση δεν βρέθηκε.');
    }

    $rounds = tour_rounds($pdo, $id);
    $lastRound = 0;
    foreach ($rounds as $r) {
        $lastRound = max($lastRound, (int)$r['round_no']);
    }
    // Ο τελευταίος γύρος πρέπει να έχει ολοκληρωθεί.
    foreach ($rounds as $r) {
        if ((int)$r['round_no'] === $lastRound && $r['status'] !== 'completed') {
            throw new RuntimeException('Ολοκληρώστε πρώτα τα αποτελέσματα του τρέχοντος γύρου.');
        }
    }

    $activeCount = 0;
    foreach (tour_teams($pdo, $id) as $t) {
        if ((int)$t['withdrawn'] === 0) { $activeCount++; }
    }
    if ($activeCount < 2) {
        throw new RuntimeException('Χρειάζονται τουλάχιστον 2 ενεργές ομάδες.');
    }

    $standings = tour_standings($pdo, $id);

    // Ιστορικό ρεπό + ζευγαριών.
    $byeHistory = [];
    $pastPairs  = [];
    foreach (tour_matches($pdo, $id) as $m) {
        if ((int)$m['is_bye'] === 1) {
            $byeHistory[(int)$m['home_team_id']] = ($byeHistory[(int)$m['home_team_id']] ?? 0) + 1;
        } elseif ($m['away_team_id'] !== null) {
            $pastPairs[swiss_pair_key((int)$m['home_team_id'], (int)$m['away_team_id'])] = true;
        }
    }

    $result   = swiss_pair_next($standings, $byeHistory, $pastPairs);
    $roundNo  = $lastRound + 1;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO `{$tt['rounds']}` (tournament_id, round_no, status) VALUES (?,?, 'paired')")
            ->execute([$id, $roundNo]);

        $insM = $pdo->prepare("INSERT INTO `{$tt['matches']}`
            (tournament_id, round_no, board_no, home_team_id, away_team_id, home_score, away_score, status, is_bye)
            VALUES (?,?,?,?,?,?,?,?,?)");

        $board = 0;
        foreach ($result['pairs'] as [$home, $away]) {
            $insM->execute([$id, $roundNo, ++$board, $home, $away, null, null, 'pending', 0]);
        }
        if ($result['bye'] !== null) {
            // Το ρεπό καταχωρείται ήδη "played" με το προκαθορισμένο σκορ.
            $bf = (int)($tour['bye_score_for'] ?? 13);
            $ba = (int)($tour['bye_score_against'] ?? 7);
            $insM->execute([$id, $roundNo, ++$board, $result['bye'], null, $bf, $ba, 'played', 1]);
        }

        if ($tour['status'] === 'setup') {
            $pdo->prepare("UPDATE `{$tt['tournaments']}` SET status='running' WHERE id=?")->execute([$id]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return $roundNo;
}

/**
 * Αποθηκεύει αποτελέσματα ενός γύρου.
 * @param array<int,array{home:?int,away:?int}> $results  map match_id => σκορ
 */
function tour_save_results(PDO $pdo, int $id, int $roundNo, array $results): void
{
    $tt = tour_tables();
    $upd = $pdo->prepare("UPDATE `{$tt['matches']}`
        SET home_score=?, away_score=?, status=?
        WHERE id=? AND tournament_id=? AND round_no=? AND is_bye=0");

    foreach (tour_matches($pdo, $id, $roundNo) as $m) {
        if ((int)$m['is_bye'] === 1) {
            continue;
        }
        $mid = (int)$m['id'];
        $r = $results[$mid] ?? null;
        $home = ($r['home'] ?? '') === '' ? null : (int)$r['home'];
        $away = ($r['away'] ?? '') === '' ? null : (int)$r['away'];
        $status = ($home !== null && $away !== null) ? 'played' : 'pending';
        $upd->execute([$home, $away, $status, $mid, $id, $roundNo]);
    }

    // Αν όλοι οι αγώνες παίχτηκαν → completed.
    $rows = tour_matches($pdo, $id, $roundNo);
    $allPlayed = $rows !== [];
    foreach ($rows as $m) {
        if ($m['status'] !== 'played') { $allPlayed = false; break; }
    }
    $pdo->prepare("UPDATE `{$tt['rounds']}` SET status=? WHERE tournament_id=? AND round_no=?")
        ->execute([$allPlayed ? 'completed' : 'paired', $id, $roundNo]);
}

/** Διαγράφει τον τελευταίο γύρο (matches + round). */
function tour_delete_last_round(PDO $pdo, int $id): void
{
    $tt = tour_tables();
    $rounds = tour_rounds($pdo, $id);
    if ($rounds === []) {
        return;
    }
    $last = 0;
    foreach ($rounds as $r) { $last = max($last, (int)$r['round_no']); }
    $pdo->prepare("DELETE FROM `{$tt['matches']}` WHERE tournament_id=? AND round_no=?")->execute([$id, $last]);
    $pdo->prepare("DELETE FROM `{$tt['rounds']}`  WHERE tournament_id=? AND round_no=?")->execute([$id, $last]);
}

/** Χάρτης team_id => label (για εμφάνιση σε ζευγάρια). */
function tour_team_labels(PDO $pdo, int $id): array
{
    $map = [];
    foreach (tour_teams($pdo, $id) as $t) {
        $map[(int)$t['id']] = (string)$t['label'];
    }
    return $map;
}

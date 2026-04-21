<?php
declare(strict_types=1);

function db_connect(array $cfg): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        (int)($cfg['port'] ?? 3306),
        $cfg['dbname'],
        $cfg['charset'] ?? 'utf8mb4'
    );

    return new PDO($dsn, $cfg['user'], $cfg['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/** Μικρό wrapper για queries που επιστρέφουν μία γραμμή */
function db_one(PDO $pdo, string $sql, array $params = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $r = $st->fetch();
    return $r === false ? null : $r;
}

/** Μικρό wrapper για queries που επιστρέφουν λίστα */
function db_all(PDO $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

-- ============================================================================
-- Migration για εγκατάσταση του module ΠΑΝΩ στην υπάρχουσα βάση της ΕΟΠ
-- (Hostinger u197488276_hpf) — ΧΩΡΙΣ καμία αλλαγή στους υπάρχοντες πίνακες.
-- ============================================================================
-- Πώς να το τρέξεις:
--   phpMyAdmin → επέλεξε τη βάση → tab SQL → paste ολόκληρο → Go.
--
-- ΤΙ ΚΑΝΕΙ (όλα μη καταστροφικά):
--   A) ΚΑΜΙΑ αλλαγή/ALTER στους υπάρχοντες πίνακες (games, games2, games3,
--      games4, clubs, sportsmen, users). Παραμένουν ΑΚΡΙΒΩΣ όπως είναι.
--   B) Δημιουργεί νέους πίνακες με prefix `app_` (όλοι CREATE IF NOT EXISTS):
--        app_game_meta     — registration_deadline ανά gamecode
--        app_aa            — δηλώσεις συλλόγου ανά πρωτάθλημα (αντί για games3)
--        app_teams         — ομάδες (αντί για games4)
--        app_games2        — player entries ανά team (αντί για games2)
--        app_club_users    — login λογαριασμοί συλλόγων
--        app_admins        — διαχειριστές
--   C) Δημιουργεί 3 VIEWs (read-only στους υπάρχοντες πίνακες):
--        clubs_v   — aliases στο `clubs` (π.χ. mitroo AS clubcode)
--        players_v — aliases στο `sportsmen` (π.χ. name AS firstname)
--        games_v   — `games` LEFT JOIN `app_game_meta` για να φέρει deadline
--   D) Δημιουργεί default admin (admin / admin1234).
--
-- Η "άλλη εφαρμογή" δεν βλέπει τίποτα από αυτά — δεν αλλάζουμε τίποτα δικό
-- της. Αντίστοιχα, το registration module γράφει ΜΟΝΟ στους app_* πίνακες.
-- ============================================================================

SET NAMES utf8mb4;

-- ============================================================================
-- B) Νέοι πίνακες (app_*)
-- ============================================================================

-- B1) Metadata για games (deadline) ----------------------------------------
CREATE TABLE IF NOT EXISTS `app_game_meta` (
    `gamecode`               VARCHAR(64)  NOT NULL,
    `registration_deadline`  DATETIME     NULL,
    `updated_at`             TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
                                           ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`gamecode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B1b) Νέα πρωταθλήματα που δημιουργούνται από αυτό το module -------------
-- Η άλλη εφαρμογή ΔΕΝ τα βλέπει (γράφει μόνο στον δικό της `games`).
-- Το `games_v` VIEW τα ενώνει με τον `games` ώστε εμείς να τα βλέπουμε ενιαία.
CREATE TABLE IF NOT EXISTS `app_games` (
    `gameid`     INT(11)      NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(255) NOT NULL,
    `gamecode`   VARCHAR(64)  NOT NULL,
    `gametype`   VARCHAR(32)  NOT NULL DEFAULT 'Doubles',
    `category`   VARCHAR(64)  NOT NULL DEFAULT 'Πρωτάθλημα',
    `status`     CHAR(1)      NOT NULL DEFAULT 'N',
    `startdate`  DATE         NULL,
    `enddate`    DATE         NULL,
    `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`gameid`),
    UNIQUE KEY `uniq_gamecode` (`gamecode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B2) Δηλώσεις συλλόγων ανά πρωτάθλημα (αντί για games3) -------------------
CREATE TABLE IF NOT EXISTS `app_aa` (
    `aaid`       INT(11)      NOT NULL AUTO_INCREMENT,
    `clubcode`   VARCHAR(32)  NOT NULL,
    `gamecode`   VARCHAR(64)  NOT NULL,
    `gamestatus` CHAR(1)      NOT NULL DEFAULT 'Y',
    `created_at` TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`aaid`),
    UNIQUE KEY `uniq_club_game` (`clubcode`, `gamecode`),
    KEY `idx_game` (`gamecode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- B3) Ομάδες (αντί για games4) ---------------------------------------------
CREATE TABLE IF NOT EXISTS `app_teams` (
    `teamid`      INT(11)      NOT NULL AUTO_INCREMENT,
    `clubcode`    VARCHAR(32)  NOT NULL,
    `gamecode`    VARCHAR(64)  NOT NULL,
    `teamname`    VARCHAR(64)  NOT NULL,
    `playercodes` VARCHAR(255) NOT NULL,
    `substitutes` VARCHAR(255) NULL,
    `category`    VARCHAR(3)   NOT NULL DEFAULT 'M',
    `status`      CHAR(1)      NOT NULL DEFAULT 'Y',
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`teamid`),
    KEY `idx_club_game_status` (`clubcode`, `gamecode`, `status`),
    KEY `idx_game_cat` (`gamecode`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Σε υπάρχουσα εγκατάσταση, πρόσθεσε τη στήλη αν λείπει (idempotent):
-- ALTER TABLE `app_teams` ADD COLUMN `substitutes` VARCHAR(255) NULL AFTER `playercodes`;

-- B4) Player entries ανά ομάδα (αντί για games2) ---------------------------
CREATE TABLE IF NOT EXISTS `app_games2` (
    `games2id`    INT(11)      NOT NULL AUTO_INCREMENT,
    `playercode1` VARCHAR(32)  NOT NULL,
    `clubcode`    VARCHAR(32)  NOT NULL,
    `gamecode`    VARCHAR(64)  NOT NULL,
    `checkstatus` CHAR(1)      NOT NULL DEFAULT 'Y',
    `teamcode`    VARCHAR(64)  NOT NULL,
    `role`        VARCHAR(16)  NOT NULL DEFAULT 'starter',
    `save`        CHAR(1)      NOT NULL DEFAULT 'Y',
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`games2id`),
    KEY `idx_game_club` (`gamecode`, `clubcode`),
    KEY `idx_player_game` (`playercode1`, `gamecode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Σε υπάρχουσα εγκατάσταση, πρόσθεσε τη στήλη αν λείπει (idempotent):
-- ALTER TABLE `app_games2` ADD COLUMN `role` VARCHAR(16) NOT NULL DEFAULT 'starter' AFTER `teamcode`;

-- B5) Login tables (μόνο για αυτό το module) -------------------------------
CREATE TABLE IF NOT EXISTS `app_club_users` (
    `id`            INT(11)      NOT NULL AUTO_INCREMENT,
    `clubcode`      VARCHAR(32)  NOT NULL,
    `username`      VARCHAR(64)  NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `must_change`   TINYINT(1)   NOT NULL DEFAULT 1,
    `active`        TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login`    TIMESTAMP    NULL DEFAULT NULL,
    `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_username` (`username`),
    KEY `idx_clubcode` (`clubcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `app_admins` (
    `id`            INT(11)      NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(64)  NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `fullname`      VARCHAR(128) NULL,
    `active`        TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login`    TIMESTAMP    NULL DEFAULT NULL,
    `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- C) VIEWs — read-only πάνω στα υπάρχοντα
-- ============================================================================

-- Σημ: Σε όλους τους VIEW κάνουμε COLLATE στα string columns σε
-- utf8mb4_unicode_ci ώστε τα JOIN με τους `app_*` πίνακες (που έχουν αυτό
-- το collation) να μη σκάνε με Error 1267 όταν η βάση έχει MariaDB
-- default utf8mb4_uca1400_ai_ci.

-- C1) Σύλλογοι: το mitroo γίνεται clubcode του module --------------------
CREATE OR REPLACE VIEW `clubs_v` AS
SELECT
    `aaid`                                                  AS `clubid`,
    `mitroo`     COLLATE utf8mb4_unicode_ci                 AS `clubcode`,
    `name`       COLLATE utf8mb4_unicode_ci                 AS `name`,
    `shortname`  COLLATE utf8mb4_unicode_ci                 AS `shortname`,
    `city`       COLLATE utf8mb4_unicode_ci                 AS `city`,
    `phone`      COLLATE utf8mb4_unicode_ci                 AS `phone`,
    `email`      COLLATE utf8mb4_unicode_ci                 AS `email`,
    (CASE WHEN `ban` = 'Y' THEN 'N' ELSE 'Y' END)
                 COLLATE utf8mb4_unicode_ci                 AS `active`
FROM `clubs`
WHERE `mitroo` IS NOT NULL AND `mitroo` <> '';

-- C2) Αθλητές: mitroo AS playercode, name AS firstname --------------------
CREATE OR REPLACE VIEW `players_v` AS
SELECT
    `aaid`                                                  AS `playerid`,
    `mitroo`     COLLATE utf8mb4_unicode_ci                 AS `playercode`,
    `clubcode`   COLLATE utf8mb4_unicode_ci                 AS `clubcode`,
    `name`       COLLATE utf8mb4_unicode_ci                 AS `firstname`,
    `lastname`   COLLATE utf8mb4_unicode_ci                 AS `lastname`,
    COALESCE(`gender`, 'M')
                 COLLATE utf8mb4_unicode_ci                 AS `gender`,
    `birthday`   COLLATE utf8mb4_unicode_ci                 AS `birthdate`,
    `idcard`     COLLATE utf8mb4_unicode_ci                 AS `licenseno`,
    `phone`      COLLATE utf8mb4_unicode_ci                 AS `phone`,
    `email`      COLLATE utf8mb4_unicode_ci                 AS `email`,
    (CASE
        WHEN `activate` = 'Y' AND (`ban` IS NULL OR `ban` <> 'Y')
            THEN 'Y' ELSE 'N'
    END)         COLLATE utf8mb4_unicode_ci                 AS `active`
FROM `sportsmen`
WHERE `mitroo` IS NOT NULL AND `mitroo` <> '';

-- C3) Users (υπάρχοντες λογαριασμοί συλλόγων) --------------------------------
-- Read-only VIEW πάνω στον `users`. Τα περισσότερα πεδία είναι AES-256-CTR
-- encrypted και αποκρυπτογραφούνται PHP-side από το login_club() fallback.
-- Το `clubcode` είναι plaintext, οπότε το WHERE μπορεί να φιλτράρει σε SQL.
-- Όλοι οι users — κράτα admins (χωρίς clubcode) και club users (με clubcode).
-- Το φιλτράρισμα γίνεται PHP-side (auth.php) αφού `type`/`lvl`/`status`
-- είναι encrypted και δεν μπορούν να φιλτραριστούν σε SQL.
CREATE OR REPLACE VIEW `users_v` AS
SELECT
    `aaid`                                                  AS `userid`,
    `clubcode`   COLLATE utf8mb4_unicode_ci                 AS `clubcode`,
    `username`   COLLATE utf8mb4_unicode_ci                 AS `username`,
    `pass`       COLLATE utf8mb4_unicode_ci                 AS `password`,
    `lvl`        COLLATE utf8mb4_unicode_ci                 AS `lvl`,
    `type`       COLLATE utf8mb4_unicode_ci                 AS `type`,
    `status`     COLLATE utf8mb4_unicode_ci                 AS `status`,
    `name`       COLLATE utf8mb4_unicode_ci                 AS `name`
FROM `users`;

-- C4) Πρωταθλήματα: UNION των υπάρχοντων `games` + των νέων `app_games`.
-- Τα νέα παίρνουν gameid + 1000000 offset για να διακρίνονται στο UI και
-- στο save path. Η στήλη `source` λέει σε ποιον πίνακα ανήκει κάθε row.
-- Σημ: χρησιμοποιούμε COLLATE για να αποφύγουμε "Illegal mix of collations"
-- όταν ο υπάρχων `games` έχει διαφορετικό collation (π.χ. MariaDB default
-- utf8mb4_uca1400_ai_ci) από τους νέους πίνακες.
CREATE OR REPLACE VIEW `games_v` AS
SELECT
    g.`gameid`                                               AS `gameid`,
    CAST('games' AS CHAR) COLLATE utf8mb4_unicode_ci         AS `source`,
    g.`name`      COLLATE utf8mb4_unicode_ci                 AS `name`,
    g.`gamecode`  COLLATE utf8mb4_unicode_ci                 AS `gamecode`,
    g.`gametype`  COLLATE utf8mb4_unicode_ci                 AS `gametype`,
    g.`category`  COLLATE utf8mb4_unicode_ci                 AS `category`,
    g.`status`    COLLATE utf8mb4_unicode_ci                 AS `status`,
    g.`startdate`                                            AS `startdate`,
    g.`enddate`                                              AS `enddate`,
    m.`registration_deadline`                                AS `registration_deadline`
FROM `games` g
LEFT JOIN `app_game_meta` m
    ON m.`gamecode` = g.`gamecode` COLLATE utf8mb4_unicode_ci

UNION ALL

SELECT
    ag.`gameid` + 1000000                                    AS `gameid`,
    CAST('app_games' AS CHAR) COLLATE utf8mb4_unicode_ci     AS `source`,
    ag.`name`     COLLATE utf8mb4_unicode_ci                 AS `name`,
    ag.`gamecode` COLLATE utf8mb4_unicode_ci                 AS `gamecode`,
    ag.`gametype` COLLATE utf8mb4_unicode_ci                 AS `gametype`,
    ag.`category` COLLATE utf8mb4_unicode_ci                 AS `category`,
    ag.`status`   COLLATE utf8mb4_unicode_ci                 AS `status`,
    ag.`startdate`                                           AS `startdate`,
    ag.`enddate`                                             AS `enddate`,
    m.`registration_deadline`                                AS `registration_deadline`
FROM `app_games` ag
LEFT JOIN `app_game_meta` m
    ON m.`gamecode` = ag.`gamecode` COLLATE utf8mb4_unicode_ci;

-- ============================================================================
-- D) Default admin (username=admin, password=admin1234)
-- ============================================================================
INSERT INTO `app_admins` (`username`, `password_hash`, `fullname`, `active`)
VALUES ('admin',
        '$2y$10$p1T2OC.oYfAaZFfVGBN0OeGVfNPxDV.9uscG6KdAGe80scjDDpZUy',
        'Διαχειριστής',
        1)
ON DUPLICATE KEY UPDATE `username` = `username`;

-- ============================================================================
-- E) Διοργανώσεις (Ελβετικό Σύστημα) — app_tournament* πίνακες.
--    Ταυτόσημοι με το sql/tournaments.sql· επαναλαμβάνονται εδώ ώστε το
--    integration migration να στήνει και τη μηχανή αγώνων. Καμία αλλαγή στους
--    υπάρχοντες πίνακες — η μηχανή διαβάζει ομάδες από `app_teams`.
-- ============================================================================
CREATE TABLE IF NOT EXISTS `app_tournaments` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(255) NOT NULL,
  `gamecode`       VARCHAR(64)  NOT NULL,
  `format`         VARCHAR(16)  NOT NULL DEFAULT 'swiss',
  `status`         VARCHAR(16)  NOT NULL DEFAULT 'setup',
  `rounds_planned` INT(11)      NULL,
  `win_points`     INT(11)      NOT NULL DEFAULT 2,
  `draw_points`    INT(11)      NOT NULL DEFAULT 1,
  `loss_points`    INT(11)      NOT NULL DEFAULT 0,
  `bye_score_for`  INT(11)      NOT NULL DEFAULT 13,
  `bye_score_against` INT(11)   NOT NULL DEFAULT 7,
  `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gamecode` (`gamecode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `app_tournament_teams` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `tournament_id` INT(11)      NOT NULL,
  `teamname`      VARCHAR(64)  NOT NULL,
  `clubcode`      VARCHAR(32)  NULL,
  `label`         VARCHAR(255) NOT NULL,
  `seed`          INT(11)      NULL,
  `withdrawn`     TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tournament_team` (`tournament_id`,`teamname`),
  KEY `idx_tournament` (`tournament_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `app_tournament_rounds` (
  `id`            INT(11)     NOT NULL AUTO_INCREMENT,
  `tournament_id` INT(11)     NOT NULL,
  `round_no`      INT(11)     NOT NULL,
  `status`        VARCHAR(16) NOT NULL DEFAULT 'paired',
  `created_at`    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tournament_round` (`tournament_id`,`round_no`),
  KEY `idx_tournament` (`tournament_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `app_tournament_matches` (
  `id`            INT(11)     NOT NULL AUTO_INCREMENT,
  `tournament_id` INT(11)     NOT NULL,
  `round_no`      INT(11)     NOT NULL,
  `board_no`      INT(11)     NOT NULL,
  `home_team_id`  INT(11)     NOT NULL,
  `away_team_id`  INT(11)     NULL,
  `home_score`    INT(11)     NULL,
  `away_score`    INT(11)     NULL,
  `status`        VARCHAR(16) NOT NULL DEFAULT 'pending',
  `is_bye`        TINYINT(1)  NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tournament_round` (`tournament_id`,`round_no`),
  KEY `idx_home` (`home_team_id`),
  KEY `idx_away` (`away_team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- ΠΡΟΑΙΡΕΤΙΚΟ: λογαριασμοί συλλόγων (βγάλε τα σχόλια αν τους θες).
-- Password για όλους: test1234
-- Για να φτιάξεις δικούς σου, πήγαινε admin → Λογαριασμοί Συλλόγων.
-- ============================================================================
-- INSERT INTO `app_club_users` (`clubcode`,`username`,`password_hash`,`must_change`,`active`) VALUES
-- ('000003','gal', '$2y$10$M3mPMu9Yg0IFfTYU0HnaI.T0NV4JVG8QRQuaoazXOBVBtUTseCz.S',0,1)
-- ON DUPLICATE KEY UPDATE `password_hash`=VALUES(`password_hash`);

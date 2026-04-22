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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
    `category`    VARCHAR(3)   NOT NULL DEFAULT 'M',
    `status`      CHAR(1)      NOT NULL DEFAULT 'Y',
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`teamid`),
    KEY `idx_club_game_status` (`clubcode`, `gamecode`, `status`),
    KEY `idx_game_cat` (`gamecode`, `category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- B4) Player entries ανά ομάδα (αντί για games2) ---------------------------
CREATE TABLE IF NOT EXISTS `app_games2` (
    `games2id`    INT(11)      NOT NULL AUTO_INCREMENT,
    `playercode1` VARCHAR(32)  NOT NULL,
    `clubcode`    VARCHAR(32)  NOT NULL,
    `gamecode`    VARCHAR(64)  NOT NULL,
    `checkstatus` CHAR(1)      NOT NULL DEFAULT 'Y',
    `teamcode`    VARCHAR(64)  NOT NULL,
    `save`        CHAR(1)      NOT NULL DEFAULT 'Y',
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`games2id`),
    KEY `idx_game_club` (`gamecode`, `clubcode`),
    KEY `idx_player_game` (`playercode1`, `gamecode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

-- C1) Σύλλογοι: το mitroo γίνεται clubcode του module --------------------
CREATE OR REPLACE VIEW `clubs_v` AS
SELECT
    `aaid`                                                  AS `clubid`,
    `mitroo`                                                AS `clubcode`,
    `name`                                                  AS `name`,
    `shortname`                                             AS `shortname`,
    `city`                                                  AS `city`,
    `phone`                                                 AS `phone`,
    `email`                                                 AS `email`,
    CASE WHEN `ban` = 'Y' THEN 'N' ELSE 'Y' END             AS `active`
FROM `clubs`
WHERE `mitroo` IS NOT NULL AND `mitroo` <> '';

-- C2) Αθλητές: mitroo AS playercode, name AS firstname --------------------
CREATE OR REPLACE VIEW `players_v` AS
SELECT
    `aaid`                                                  AS `playerid`,
    `mitroo`                                                AS `playercode`,
    `clubcode`                                              AS `clubcode`,
    `name`                                                  AS `firstname`,
    `lastname`                                              AS `lastname`,
    COALESCE(`gender`, 'M')                                 AS `gender`,
    `birthday`                                              AS `birthdate`,
    `idcard`                                                AS `licenseno`,
    `phone`                                                 AS `phone`,
    `email`                                                 AS `email`,
    CASE
        WHEN `activate` = 'Y' AND (`ban` IS NULL OR `ban` <> 'Y')
            THEN 'Y' ELSE 'N'
    END                                                     AS `active`
FROM `sportsmen`
WHERE `mitroo` IS NOT NULL AND `mitroo` <> '';

-- C3) Πρωταθλήματα: games LEFT JOIN app_game_meta για deadline -----------
CREATE OR REPLACE VIEW `games_v` AS
SELECT
    g.`gameid`,
    g.`name`,
    g.`gamecode`,
    g.`gametype`,
    g.`category`,
    g.`status`,
    g.`startdate`,
    g.`enddate`,
    m.`registration_deadline`
FROM `games` g
LEFT JOIN `app_game_meta` m ON m.`gamecode` = g.`gamecode`;

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
-- ΠΡΟΑΙΡΕΤΙΚΟ: λογαριασμοί συλλόγων (βγάλε τα σχόλια αν τους θες).
-- Password για όλους: test1234
-- Για να φτιάξεις δικούς σου, πήγαινε admin → Λογαριασμοί Συλλόγων.
-- ============================================================================
-- INSERT INTO `app_club_users` (`clubcode`,`username`,`password_hash`,`must_change`,`active`) VALUES
-- ('000003','gal', '$2y$10$M3mPMu9Yg0IFfTYU0HnaI.T0NV4JVG8QRQuaoazXOBVBtUTseCz.S',0,1)
-- ON DUPLICATE KEY UPDATE `password_hash`=VALUES(`password_hash`);

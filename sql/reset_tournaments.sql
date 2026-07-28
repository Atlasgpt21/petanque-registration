-- ============================================================================
-- ΕΠΙΔΙΟΡΘΩΣΗ / ΕΠΑΝΑΔΗΜΙΟΥΡΓΙΑ πινάκων module «Διοργανώσεις»
-- ============================================================================
-- Τρέξε ΜΟΝΟ αν οι πίνακες app_tournament* έχουν λάθος/παλιά δομή
-- (π.χ. σφάλμα: Unknown column 'x.tournament_id').
--
-- ΠΡΟΣΟΧΗ: διαγράφει ΜΟΝΟ τα δεδομένα των διοργανώσεων (πρωταθλήματα αγώνων,
-- γύροι, κληρώσεις, σκορ). ΔΕΝ αγγίζει καθόλου τους πίνακες με τις δηλώσεις/
-- συλλόγους/αθλητές (games, teams/app_teams, players, clubs κ.λπ.).
--
-- Χρήση: phpMyAdmin → tab SQL → επικόλληση όλου του αρχείου → Go.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `app_tournament_matches`;
DROP TABLE IF EXISTS `app_tournament_rounds`;
DROP TABLE IF EXISTS `app_tournament_teams`;
DROP TABLE IF EXISTS `app_tournaments`;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE `app_tournaments` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(255) NOT NULL,
  `gamecode`       VARCHAR(64)  NOT NULL,
  `category`       VARCHAR(8)   NOT NULL DEFAULT 'ALL',
  `format`         VARCHAR(16)  NOT NULL DEFAULT 'swiss',
  `status`         VARCHAR(16)  NOT NULL DEFAULT 'setup',
  `rounds_planned` INT(11)      NULL,
  `win_points`     INT(11)      NOT NULL DEFAULT 2,
  `draw_points`    INT(11)      NOT NULL DEFAULT 1,
  `loss_points`    INT(11)      NOT NULL DEFAULT 0,
  `bye_score_for`  INT(11)      NOT NULL DEFAULT 13,
  `bye_score_against` INT(11)   NOT NULL DEFAULT 7,
  `courts`         INT(11)      NOT NULL DEFAULT 0,
  `ko_size`        INT(11)      NOT NULL DEFAULT 0,
  `friendship_cup` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gamecode` (`gamecode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `app_tournament_teams` (
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

CREATE TABLE `app_tournament_rounds` (
  `id`            INT(11)     NOT NULL AUTO_INCREMENT,
  `tournament_id` INT(11)     NOT NULL,
  `round_no`      INT(11)     NOT NULL,
  `phase`         VARCHAR(16) NOT NULL DEFAULT 'swiss',
  `stage`         VARCHAR(24) NULL,
  `status`        VARCHAR(16) NOT NULL DEFAULT 'paired',
  `created_at`    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tournament_round` (`tournament_id`,`round_no`),
  KEY `idx_tournament` (`tournament_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `app_tournament_matches` (
  `id`            INT(11)     NOT NULL AUTO_INCREMENT,
  `tournament_id` INT(11)     NOT NULL,
  `round_no`      INT(11)     NOT NULL,
  `board_no`      INT(11)     NOT NULL,
  `court_no`      INT(11)     NULL,
  `phase`         VARCHAR(16) NOT NULL DEFAULT 'swiss',
  `stage`         VARCHAR(24) NULL,
  `home_team_id`  INT(11)     NULL,
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

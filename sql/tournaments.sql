-- ============================================================================
-- Petanque Tournaments — Swiss system engine (πρωταθλήματα αγώνων)
-- ============================================================================
-- Χρήση:
--   mysql -u root -p petanque < sql/tournaments.sql
-- ή, στο Hostinger integration setup (βάση ΕΟΠ), τρέξε το ίδιο αρχείο μέσα από
-- phpMyAdmin → tab SQL. Όλοι οι πίνακες έχουν prefix `app_tournament*` και
-- δημιουργούνται με CREATE TABLE IF NOT EXISTS, οπότε είναι ασφαλές να τρέξει
-- πάνω σε υπάρχουσα βάση (καμία αλλαγή στους υπάρχοντες πίνακες).
--
-- Η μηχανή διοργανώσεων ΔΙΑΒΑΖΕΙ ομάδες από τον πίνακα `teams` (ή `app_teams`
-- στο integration setup — δες config `tables.teams`) και ΓΡΑΦΕΙ αποκλειστικά
-- στους δικούς της πίνακες παρακάτω.
-- ============================================================================

SET NAMES utf8mb4;

-- ----------------------------------------------------------------------------
-- Διοργάνωση (ένα row ανά "πρωτάθλημα αγώνων"). Συνδέεται με ένα championship
-- μέσω του `gamecode` ώστε να τραβάμε αυτόματα τις δηλωμένες ομάδες.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_tournaments` (
  `id`             INT(11)      NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(255) NOT NULL,
  `gamecode`       VARCHAR(64)  NOT NULL,               -- link στο championship (games.gamecode)
  `category`       VARCHAR(8)   NOT NULL DEFAULT 'ALL', -- ALL | M | F | MIX (φύλο/κατηγορία ταμπλό)
  `format`         VARCHAR(16)  NOT NULL DEFAULT 'swiss',
  `status`         VARCHAR(16)  NOT NULL DEFAULT 'setup', -- setup | running | finished
  `rounds_planned` INT(11)      NULL,                    -- προγραμματισμένοι γύροι Ελβετικού (προαιρετικό)
  `win_points`     INT(11)      NOT NULL DEFAULT 2,      -- βαθμοί ανά νίκη
  `draw_points`    INT(11)      NOT NULL DEFAULT 1,      -- βαθμοί ανά ισοπαλία
  `loss_points`    INT(11)      NOT NULL DEFAULT 0,      -- βαθμοί ανά ήττα
  `bye_score_for`  INT(11)      NOT NULL DEFAULT 13,     -- πόντοι υπέρ σε ρεπό (bye)
  `bye_score_against` INT(11)   NOT NULL DEFAULT 7,      -- πόντοι κατά σε ρεπό (bye)
  `courts`         INT(11)      NOT NULL DEFAULT 0,      -- πλήθος γηπέδων (0 = χωρίς όριο)
  `ko_size`        INT(11)      NOT NULL DEFAULT 0,      -- knockout κυρίως ταμπλό: 0 | 8 | 16
  `friendship_cup` TINYINT(1)   NOT NULL DEFAULT 0,      -- Κύπελλο Φιλίας (θέσεις 17–32)
  `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gamecode` (`gamecode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Συμμετέχουσες ομάδες της διοργάνωσης (import από `teams` ή χειροκίνητα).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_tournament_teams` (
  `id`            INT(11)      NOT NULL AUTO_INCREMENT,
  `tournament_id` INT(11)      NOT NULL,
  `teamname`      VARCHAR(64)  NOT NULL,                -- αναφορά στο teams.teamname
  `clubcode`      VARCHAR(32)  NULL,
  `label`         VARCHAR(255) NOT NULL,                -- εμφανιζόμενο όνομα
  `seed`          INT(11)      NULL,                    -- αρχική κατάταξη/σειρά
  `withdrawn`     TINYINT(1)   NOT NULL DEFAULT 0,      -- αποχώρησε
  `created_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tournament_team` (`tournament_id`,`teamname`),
  KEY `idx_tournament` (`tournament_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Γύροι (rounds) της διοργάνωσης.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_tournament_rounds` (
  `id`            INT(11)     NOT NULL AUTO_INCREMENT,
  `tournament_id` INT(11)     NOT NULL,
  `round_no`      INT(11)     NOT NULL,
  `phase`         VARCHAR(16) NOT NULL DEFAULT 'swiss',  -- swiss | ko | friendship
  `stage`         VARCHAR(24) NULL,                      -- π.χ. R16 | QF | SF | F (για knockout)
  `status`        VARCHAR(16) NOT NULL DEFAULT 'paired', -- paired | completed
  `created_at`    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tournament_round` (`tournament_id`,`round_no`),
  KEY `idx_tournament` (`tournament_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Αγώνες (matches) ανά γύρο. away_team_id NULL + is_bye=1 σημαίνει ρεπό.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_tournament_matches` (
  `id`            INT(11)     NOT NULL AUTO_INCREMENT,
  `tournament_id` INT(11)     NOT NULL,
  `round_no`      INT(11)     NOT NULL,
  `board_no`      INT(11)     NOT NULL,                 -- σειρά αγώνα στον γύρο
  `court_no`      INT(11)     NULL,                     -- ανατεθειμένο γήπεδο/πίστα
  `phase`         VARCHAR(16) NOT NULL DEFAULT 'swiss', -- swiss | ko | friendship
  `stage`         VARCHAR(24) NULL,                     -- π.χ. R16 | QF | SF | F
  `home_team_id`  INT(11)     NULL,
  `away_team_id`  INT(11)     NULL,                     -- NULL όταν is_bye=1 ή TBD
  `home_score`    INT(11)     NULL,
  `away_score`    INT(11)     NULL,
  `status`        VARCHAR(16) NOT NULL DEFAULT 'pending', -- pending | played
  `is_bye`        TINYINT(1)  NOT NULL DEFAULT 0,
  `created_at`    TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tournament_round` (`tournament_id`,`round_no`),
  KEY `idx_home` (`home_team_id`),
  KEY `idx_away` (`away_team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

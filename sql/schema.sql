-- ============================================================================
-- Petanque Registration - Schema
-- ============================================================================
-- Use: mysql -u root petanque < sql/schema.sql
-- Designed to be *compatible* with the existing tables described by the user
-- (games, games2, aa, teams) and to add only the new `club_users` / `admins`
-- tables. All CREATE statements use IF NOT EXISTS so running this on an
-- existing database is safe.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- Championships
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `games` (
  `gameid`     INT(11) NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(255) NOT NULL,
  `gamecode`   VARCHAR(16)  NOT NULL UNIQUE,
  `gametype`   ENUM('Doubles','Triplets','Mixed','Intercup') NOT NULL DEFAULT 'Doubles',
  `category`   VARCHAR(64)  NOT NULL DEFAULT 'Πρωτάθλημα',
  `status`     CHAR(1)      NOT NULL DEFAULT 'N', -- 'Y' = ενεργό
  `startdate`  DATE NULL,
  `enddate`    DATE NULL,
  `registration_deadline` DATETIME NULL,          -- νέο πεδίο (lock φόρμας)
  PRIMARY KEY (`gameid`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Clubs
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `clubs` (
  `clubid`   INT(11) NOT NULL AUTO_INCREMENT,
  `clubcode` VARCHAR(16)  NOT NULL UNIQUE,         -- π.χ. '000003'
  `name`     VARCHAR(255) NOT NULL,                -- π.χ. 'Α.Σ. ΓΑΛΑΤΣΙΟΥ'
  `shortname` VARCHAR(32) NULL,                    -- π.χ. 'GAL'
  `city`     VARCHAR(128) NULL,
  `phone`    VARCHAR(32)  NULL,
  `email`    VARCHAR(128) NULL,
  `active`   CHAR(1) NOT NULL DEFAULT 'Y',
  PRIMARY KEY (`clubid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Players / Athletes
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `players` (
  `playerid`   INT(11) NOT NULL AUTO_INCREMENT,
  `playercode` VARCHAR(16) NOT NULL UNIQUE,        -- π.χ. '000227'
  `clubcode`   VARCHAR(16) NOT NULL,
  `firstname`  VARCHAR(128) NOT NULL,
  `lastname`   VARCHAR(128) NOT NULL,
  `gender`     ENUM('M','F') NOT NULL,
  `birthdate`  DATE NULL,
  `licenseno`  VARCHAR(32) NULL,                   -- αρ. δελτίου
  `phone`      VARCHAR(32) NULL,
  `email`      VARCHAR(128) NULL,
  `active`     CHAR(1) NOT NULL DEFAULT 'Y',
  PRIMARY KEY (`playerid`),
  KEY `idx_club` (`clubcode`),
  KEY `idx_gender` (`gender`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Club registrations per championship (ένα row ανά σύλλογο ανά πρωτάθλημα)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `aa` (
  `aaid`          INT(11) NOT NULL AUTO_INCREMENT,
  `clubcode`      VARCHAR(16) NOT NULL,
  `gamecode`      VARCHAR(16) NOT NULL,
  `gamestatus`    CHAR(1) NOT NULL DEFAULT 'Y',  -- 'Y' = ενεργή δήλωση
  `currentrating` INT(11) NULL,
  `gamesserial`   VARCHAR(32) NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`aaid`),
  UNIQUE KEY `uniq_club_game` (`clubcode`,`gamecode`),
  KEY `idx_gamecode` (`gamecode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Team compositions (μία γραμμή ανά ομάδα ανά σύλλογο ανά πρωτάθλημα)
-- playercodes = dash-separated π.χ. '000002-000021' (Doubles) ή
-- '000002-000021-000019' (Triples)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teams` (
  `teamid`      INT(11) NOT NULL AUTO_INCREMENT,
  `teamname`    VARCHAR(32) NOT NULL,             -- π.χ. 'GAL1', 'GAL1w', 'GALmix1'
  `playercodes` VARCHAR(128) NOT NULL,            -- dash-separated playercodes
  `gamecode`    VARCHAR(16) NOT NULL,
  `status`      CHAR(1) NOT NULL DEFAULT 'Y',
  `clubcode`    VARCHAR(16) NOT NULL,
  `teams`       VARCHAR(32) NULL,                 -- reserved (συμβατό με υπάρχον)
  `category`    ENUM('M','F','MIX') NOT NULL DEFAULT 'M',
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`teamid`),
  UNIQUE KEY `uniq_team` (`teamname`,`gamecode`),
  KEY `idx_club_game` (`clubcode`,`gamecode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Individual player history per championship
-- teamcode: ath1..athN (άνδρες), ath1w..athNw (γυναίκες), mix1..mixN (μεικτό)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `games2` (
  `games2id`    INT(11) NOT NULL AUTO_INCREMENT,
  `playercode1` VARCHAR(16) NOT NULL,
  `clubcode`    VARCHAR(16) NOT NULL,
  `gamecode`    VARCHAR(16) NOT NULL,
  `checkstatus` CHAR(1) NOT NULL DEFAULT 'Y',
  `teamcode`    VARCHAR(16) NOT NULL,             -- π.χ. 'ath1', 'ath1w', 'mix1'
  `save`        CHAR(1) NOT NULL DEFAULT 'Y',
  `chars`       VARCHAR(16) NULL,
  `place`       INT(11) NULL,
  `teams`       VARCHAR(32) NULL,
  PRIMARY KEY (`games2id`),
  UNIQUE KEY `uniq_player_game` (`playercode1`,`gamecode`),
  KEY `idx_game` (`gamecode`),
  KEY `idx_club_game` (`clubcode`,`gamecode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Club login accounts (ΝΕΟ)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `club_users` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `clubcode`      VARCHAR(16) NOT NULL,
  `username`      VARCHAR(64) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `must_change`   TINYINT(1) NOT NULL DEFAULT 1,
  `active`        TINYINT(1) NOT NULL DEFAULT 1,
  `last_login`    DATETIME NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_clubcode` (`clubcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Admin accounts (ΝΕΟ)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
  `id`            INT(11) NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(64) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `fullname`      VARCHAR(128) NULL,
  `active`        TINYINT(1) NOT NULL DEFAULT 1,
  `last_login`    DATETIME NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

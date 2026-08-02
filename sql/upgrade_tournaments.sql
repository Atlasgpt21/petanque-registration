-- ============================================================================
-- Αναβάθμιση υπάρχουσας εγκατάστασης «Διοργανώσεις» στη νέα έκδοση
-- (κατηγορίες Ανδρών/Γυναικών, γήπεδα, knockout TOP-8/16, Κύπελλο Φιλίας).
--
-- Τρέξ' το ΜΟΝΟ αν είχες ήδη εγκαταστήσει τους πίνακες app_tournament* από
-- προηγούμενη έκδοση. Σε νέα εγκατάσταση δεν χρειάζεται — το sql/schema.sql
-- ή το sql/tournaments.sql τα δημιουργεί ήδη με τις νέες στήλες.
--
-- Ασφαλές να τρέξει πολλές φορές (MariaDB / Hostinger).
-- ============================================================================

ALTER TABLE `app_tournaments`
  ADD COLUMN IF NOT EXISTS `category`       VARCHAR(8)  NOT NULL DEFAULT 'ALL' AFTER `gamecode`,
  ADD COLUMN IF NOT EXISTS `courts`         INT(11)     NOT NULL DEFAULT 0 AFTER `bye_score_against`,
  ADD COLUMN IF NOT EXISTS `court_from`     INT(11)     NOT NULL DEFAULT 0 AFTER `courts`,
  ADD COLUMN IF NOT EXISTS `court_to`       INT(11)     NOT NULL DEFAULT 0 AFTER `court_from`,
  ADD COLUMN IF NOT EXISTS `ko_size`        INT(11)     NOT NULL DEFAULT 0 AFTER `court_to`,
  ADD COLUMN IF NOT EXISTS `friendship_cup` TINYINT(1)  NOT NULL DEFAULT 0 AFTER `ko_size`;

-- Ταυτότητα ομάδας βάσει του μοναδικού teamid της πηγής (ΟΧΙ teamname, που δεν
-- είναι μοναδικό ανά σύλλογο). ΣΗΜΕΙΩΣΗ: αν ο πίνακας app_tournament_teams είχε
-- ήδη δεδομένα με το παλιό unique key (tournament_id, teamname), προτείνεται
-- καθαρή επαναδημιουργία με το sql/reset_tournaments.sql.
ALTER TABLE `app_tournament_teams`
  ADD COLUMN IF NOT EXISTS `src_teamid` INT(11) NULL AFTER `tournament_id`;

ALTER TABLE `app_tournament_rounds`
  ADD COLUMN IF NOT EXISTS `phase` VARCHAR(16) NOT NULL DEFAULT 'swiss' AFTER `round_no`,
  ADD COLUMN IF NOT EXISTS `stage` VARCHAR(24) NULL AFTER `phase`;

ALTER TABLE `app_tournament_matches`
  ADD COLUMN IF NOT EXISTS `court_no` INT(11)     NULL AFTER `board_no`,
  ADD COLUMN IF NOT EXISTS `phase`    VARCHAR(16) NOT NULL DEFAULT 'swiss' AFTER `court_no`,
  ADD COLUMN IF NOT EXISTS `stage`    VARCHAR(24) NULL AFTER `phase`,
  MODIFY COLUMN `home_team_id` INT(11) NULL;

-- =====================================================================
--  database/upgrade.sql  —  bring an EXISTING canteen up to date
-- =====================================================================
--  schema.sql starts with DROP DATABASE, which is right for a fresh demo
--  install and catastrophic for a canteen that is already taking orders.
--  So anything added to the schema *after* the first release goes here
--  instead, as a statement that is safe to run on a live database.
--
--      php database/install.php --upgrade
--
--  Rules for this file:
--    * additive only — no DROP, no DELETE, no ALTER that loses values
--    * every statement must be safe to run twice
--    * keep each table's definition identical to schema.sql, so a fresh
--      install and an upgraded one end up with the same database. To
--      check:  SHOW CREATE TABLE login_attempts;  on both.
--
--  The install script selects the database before running this, so there
--  is deliberately no USE statement here — the name is configurable.
-- =====================================================================

-- -------------------------------------------------------------
--  Added after the first release. Without it the app still runs —
--  login throttling just switches itself off — but the canteen is
--  then leaving the door open to password guessing.
-- -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email        VARCHAR(150) NOT NULL,
    ip           VARCHAR(45)  NOT NULL,          -- fits IPv6
    attempted_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY idx_la_email (email, attempted_at),
    KEY idx_la_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- HITSZCS "Study Buddy" pot — MySQL schema
-- Run this inside phpMyAdmin (cPanel > phpMyAdmin) after selecting your
-- new database. See the setup steps for the exact click path.

CREATE TABLE IF NOT EXISTS pot_members (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(40)  NOT NULL,
  token      CHAR(32)     NOT NULL,
  created_at BIGINT       NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

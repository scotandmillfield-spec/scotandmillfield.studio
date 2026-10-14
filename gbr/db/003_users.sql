-- =====================================================================================
-- 003 – Benutzer und Anmeldung
--  * Passwörter nur als Hash (PHP password_hash, Argon2id bzw. Bcrypt) – nie im Klartext.
--  * failed_count / locked_until: nach 5 Fehlversuchen 15 Minuten Sperre.
--  * booking_log merkt sich, welcher Benutzer eine Buchung geändert hat.
-- =====================================================================================

CREATE TABLE users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username       VARCHAR(60)  NOT NULL,
  display_name   VARCHAR(120) NOT NULL DEFAULT '',
  password_hash  VARCHAR(255) NOT NULL,
  active         TINYINT(1)   NOT NULL DEFAULT 1,
  failed_count   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until   DATETIME     NULL,
  last_login_at  DATETIME     NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE booking_log
  ADD COLUMN user_id INT UNSIGNED NULL AFTER action,
  ADD CONSTRAINT fk_blog_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL;

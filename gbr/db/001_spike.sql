-- Spike schema: chart of accounts + journal, enough to prove browser -> PHP -> MySQL.
-- Amounts are DECIMAL (never FLOAT) so cents add up exactly.
CREATE TABLE accounts (
  nr        VARCHAR(10)  NOT NULL,
  name      VARCHAR(120) NOT NULL,
  art       ENUM('ertrag','aufwand','aktiv','passiv') NOT NULL,
  PRIMARY KEY (nr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bookings (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  no          INT UNSIGNED NOT NULL,                 -- running journal number
  booking_date DATE        NOT NULL,
  soll        VARCHAR(10)  NOT NULL,
  haben       VARCHAR(10)  NOT NULL,
  amount      DECIMAL(12,2) NOT NULL,
  text        VARCHAR(255) NOT NULL DEFAULT '',
  beleg       VARCHAR(60)  NOT NULL DEFAULT '',
  locked      TINYINT(1)   NOT NULL DEFAULT 0,
  clarify     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_no (no),
  KEY ix_date (booking_date),
  CONSTRAINT fk_b_soll  FOREIGN KEY (soll)  REFERENCES accounts(nr),
  CONSTRAINT fk_b_haben FOREIGN KEY (haben) REFERENCES accounts(nr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- neutral demo accounts (SKR03 numbers, no company data)
INSERT INTO accounts (nr,name,art) VALUES
 ('1200','Bank','aktiv'),
 ('4200','Raumkosten','aufwand'),
 ('4920','Telekommunikation','aufwand'),
 ('8000','Umsatzerlöse','ertrag');

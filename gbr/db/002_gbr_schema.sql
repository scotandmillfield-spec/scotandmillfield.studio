-- =====================================================================================
-- GbR – vollständiges Datenbankschema (MySQL 8 / MariaDB 10.6+, InnoDB, utf8mb4)
--
-- Grundsätze
--  * Geldbeträge sind DECIMAL, nie FLOAT. Buchhaltung: 2 Nachkommastellen.
--    Importierte Rohdaten (GEMA, Label-Reports) behalten die volle Genauigkeit der Quelle.
--  * Fremdschlüssel sichern die Verknüpfungen (Konto, Storno, Carryover, Rechnungszeilen).
--  * Regeln, die die App heute im Browser prüft, prüft künftig auch die API;
--    die Datenbank sichert zusätzlich das Wichtigste ab (CHECK, UNIQUE, FK).
--  * Regeln, die MySQL 8 nicht als CHECK erlaubt (Bezug auf Auto-Increment-Spalten oder auf
--    Spalten mit kaskadierenden Fremdschlüsseln), prüft die API – z. B. „Statement nicht in sich selbst übertragen“.
--  * Schema-Änderungen nur als NEUE Datei (003_…, 004_…); eingespielte Dateien nie mehr ändern.
--    Stand abrufbar über api.php?action=health → schema_version.
--  * Ersetzt die beiden Spike-Tabellen (accounts, bookings) – darin waren nur Testdaten.
-- =====================================================================================

DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS accounts;

-- -------------------------------------------------------------------------------------
-- 1. Stammdaten & Einstellungen
-- -------------------------------------------------------------------------------------

-- Einstellungen als Schlüssel/Wert, z. B. 'issuer' = Briefkopf (Name, Adresse, IBAN, BIC,
-- Steuer-Nr., Ort, Grußformel) als JSON. Hält sensible Daten aus dem Code heraus.
CREATE TABLE settings (
  k           VARCHAR(64)  NOT NULL,
  v           JSON         NOT NULL,
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Labels / Vertragspartner (Empfänger von Rechnungen). name_key = normalisierter Name
-- (klein, nur a-z0-9) – darüber verbindet die App Statements und Carryover eines Labels.
CREATE TABLE labels (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(160) NOT NULL,
  name_key    VARCHAR(160) NOT NULL,
  address     TEXT         NULL,                     -- Rechnungsanschrift (mehrzeilig)
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_labels_key (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 2. Buchhaltung: Kontenplan, Journal, Anlagen
-- -------------------------------------------------------------------------------------

CREATE TABLE accounts (
  nr          VARCHAR(10)  NOT NULL,                 -- SKR03-Nummer, z. B. 1200
  name        VARCHAR(120) NOT NULL,
  art         ENUM('ertrag','aufwand','aktiv','passiv') NOT NULL,
  PRIMARY KEY (nr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assets (
  id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  name            VARCHAR(160)  NOT NULL,
  cost            DECIMAL(12,2) NOT NULL,             -- Anschaffungskosten
  useful_life     TINYINT UNSIGNED NOT NULL,          -- Nutzungsdauer in Jahren
  activation_date DATE          NOT NULL,             -- Aktivierungsdatum (monatsgenaue AfA)
  afa_account     VARCHAR(10)   NOT NULL,             -- z. B. 4830 / 4860
  asset_account   VARCHAR(10)   NOT NULL,             -- z. B. 0420
  created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT ck_assets_cost CHECK (cost > 0),
  CONSTRAINT ck_assets_life CHECK (useful_life >= 1),
  CONSTRAINT fk_assets_afa   FOREIGN KEY (afa_account)   REFERENCES accounts(nr),
  CONSTRAINT fk_assets_asset FOREIGN KEY (asset_account) REFERENCES accounts(nr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal. Jede Buchung: Soll an Haben, positiver Betrag.
--  locked     = festgeschrieben (nur noch per Storno korrigierbar)
--  clarify    = „Klären“: Beleg im Umlauf, wird beim Festschreiben übersprungen
--  irrelevant = „privat“: zählt nicht in BWA / Salden
--  storno_of  = Storno verweist auf die stornierte Buchung
--  bank_key   = Erkennungsschlüssel aus dem Kontoauszug-Import (verhindert Doppelimport)
CREATE TABLE bookings (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  no            INT UNSIGNED  NOT NULL,                -- laufende Journalnummer
  booking_date  DATE          NOT NULL,
  soll          VARCHAR(10)   NOT NULL,
  haben         VARCHAR(10)   NOT NULL,
  amount        DECIMAL(12,2) NOT NULL,
  text          VARCHAR(255)  NOT NULL DEFAULT '',
  beleg         VARCHAR(60)   NOT NULL DEFAULT '',
  source        ENUM('manual','bank','storno','afa','import') NOT NULL DEFAULT 'manual',
  locked        TINYINT(1)    NOT NULL DEFAULT 0,
  locked_at     DATETIME      NULL,
  clarify       TINYINT(1)    NOT NULL DEFAULT 0,
  irrelevant    TINYINT(1)    NOT NULL DEFAULT 0,
  storno_of     INT UNSIGNED  NULL,
  asset_id      INT UNSIGNED  NULL,                    -- AfA-Buchung zu einer Anlage
  bank_key      VARCHAR(190)  NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_bookings_no (no),
  UNIQUE KEY uq_bookings_bank (bank_key),
  KEY ix_bookings_date (booking_date),
  CONSTRAINT ck_bookings_amount CHECK (amount > 0),
  CONSTRAINT ck_bookings_sides  CHECK (soll <> haben),
  CONSTRAINT ck_bookings_lock   CHECK (NOT (locked = 1 AND clarify = 1)),   -- „Klären“ ist nie festgeschrieben
  CONSTRAINT fk_bookings_soll   FOREIGN KEY (soll)      REFERENCES accounts(nr),
  CONSTRAINT fk_bookings_haben  FOREIGN KEY (haben)     REFERENCES accounts(nr),
  CONSTRAINT fk_bookings_storno FOREIGN KEY (storno_of) REFERENCES bookings(id),
  CONSTRAINT fk_bookings_asset  FOREIGN KEY (asset_id)  REFERENCES assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Protokoll jeder Änderung am Journal (Nachvollziehbarkeit, GoBD-Gedanke):
-- Aktion + vollständiger Stand der Buchung nach der Änderung (bei delete: davor).
CREATE TABLE booking_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id  INT UNSIGNED    NOT NULL,                -- bewusst ohne FK: bleibt nach Löschung erhalten
  action      ENUM('create','update','delete','lock','storno','clarify','private') NOT NULL,
  snapshot    JSON            NOT NULL,
  at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_blog_booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 3. Royalties, Statements, Rechnungen, Carryover
-- -------------------------------------------------------------------------------------

-- Abrechnung eines Labels (aus PDF-Import oder manuell angelegt).
--  status        open = abzurechnen, paid = bezahlt, carry = Carryover (zurückgehalten)
--  carried_into  Carryover ist in der Rechnung dieses Statements enthalten
--  link_only     Zuordnung nachträglich (ohne eigene Rechnungszeile)
--  import_key    Label|Statement-Nr. bzw. Label|Jahr-Halbjahr – erneuter PDF-Import ersetzt statt doppelt
CREATE TABLE statements (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  label_id          INT UNSIGNED  NOT NULL,
  statement_no      VARCHAR(60)   NOT NULL DEFAULT '',
  period_text       VARCHAR(40)   NOT NULL,             -- wie angezeigt: „H2 2025“, „01.2026“, „Q3 2025“
  period_year       SMALLINT      NOT NULL,
  period_half       ENUM('H1','H2') NULL,
  period_sort       INT           NOT NULL,             -- Jahr*100 + Monat/Quartal/Halbjahr, zum Sortieren
  period_start      DATE          NULL,
  period_end        DATE          NULL,
  payable           DECIMAL(12,2) NULL,                 -- auszuzahlender Betrag laut Statement
  carryover_stated  DECIMAL(12,2) NOT NULL DEFAULT 0,   -- im Statement selbst ausgewiesener Übertrag
  currency          CHAR(3)       NOT NULL DEFAULT 'EUR',
  status            ENUM('open','paid','carry') NOT NULL DEFAULT 'open',
  source            ENUM('pdf','manual') NOT NULL DEFAULT 'pdf',
  carried_into      INT UNSIGNED  NULL,
  link_only         TINYINT(1)    NOT NULL DEFAULT 0,
  import_key        VARCHAR(190)  NULL,
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_statements_import (import_key),
  KEY ix_statements_label (label_id, period_sort),
  CONSTRAINT fk_statements_label FOREIGN KEY (label_id)     REFERENCES labels(id),
  CONSTRAINT fk_statements_carry FOREIGN KEY (carried_into) REFERENCES statements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rechnung zu einem Statement (höchstens eine je Statement).
CREATE TABLE invoices (
  id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  statement_id      INT UNSIGNED  NOT NULL,
  invoice_no        VARCHAR(40)   NOT NULL,             -- z. B. SM-2026-004
  invoice_date      DATE          NOT NULL,
  recipient_name    VARCHAR(160)  NOT NULL,
  recipient_address TEXT          NULL,
  greeting          VARCHAR(160)  NOT NULL DEFAULT '',
  intro             TEXT          NULL,                 -- eigener Einleitungstext (sonst Vorschlag)
  total             DECIMAL(12,2) NOT NULL,
  currency          CHAR(3)       NOT NULL DEFAULT 'EUR',
  created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invoices_no (invoice_no),
  UNIQUE KEY uq_invoices_statement (statement_id),
  CONSTRAINT fk_invoices_statement FOREIGN KEY (statement_id) REFERENCES statements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rechnungszeilen: main = Royalties des Statements, carry = Carryover eines anderen
-- Statements desselben Labels, extra = frei ergänzte Zeile.
CREATE TABLE invoice_lines (
  id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  invoice_id         INT UNSIGNED  NOT NULL,
  pos                SMALLINT UNSIGNED NOT NULL,
  kind               ENUM('main','carry','extra') NOT NULL,
  carry_statement_id INT UNSIGNED  NULL,
  description        VARCHAR(255)  NOT NULL,
  amount             DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lines_pos (invoice_id, pos),
  CONSTRAINT ck_lines_carry CHECK ((kind = 'carry') = (carry_statement_id IS NOT NULL)),
  CONSTRAINT fk_lines_invoice FOREIGN KEY (invoice_id)         REFERENCES invoices(id)   ON DELETE CASCADE,
  CONSTRAINT fk_lines_carry   FOREIGN KEY (carry_statement_id) REFERENCES statements(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Royalties je Track und Halbjahr (Reiter „Royalties“).
CREATE TABLE royalty_tracks (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(160) NOT NULL,
  name_key    VARCHAR(160) NOT NULL,
  color       CHAR(7)      NOT NULL DEFAULT '#5DB1E6',
  PRIMARY KEY (id),
  UNIQUE KEY uq_rtracks_key (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE royalty_entries (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  track_id      INT UNSIGNED  NOT NULL,
  period_year   SMALLINT      NOT NULL,
  period_half   ENUM('H1','H2') NOT NULL,
  amount        DECIMAL(14,4) NOT NULL,
  currency      CHAR(3)       NOT NULL DEFAULT 'EUR',
  label_id      INT UNSIGNED  NULL,
  statement_id  INT UNSIGNED  NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rentries_period (track_id, period_year, period_half),   -- Re-Import ersetzt
  CONSTRAINT fk_rentries_track     FOREIGN KEY (track_id)     REFERENCES royalty_tracks(id) ON DELETE CASCADE,
  CONSTRAINT fk_rentries_label     FOREIGN KEY (label_id)     REFERENCES labels(id)         ON DELETE SET NULL,
  CONSTRAINT fk_rentries_statement FOREIGN KEY (statement_id) REFERENCES statements(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 4. Streaming-Analytics (Spotify for Artists, Tageswerte je Single)
-- -------------------------------------------------------------------------------------

CREATE TABLE singles (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(160) NOT NULL,
  name_key      VARCHAR(160) NOT NULL,
  release_date  DATE         NOT NULL,
  color         CHAR(7)      NOT NULL DEFAULT '#5DB1E6',
  visible       TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_singles_key (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE single_daily (
  single_id   INT UNSIGNED NOT NULL,
  day         DATE         NOT NULL,
  streams     INT UNSIGNED NOT NULL,
  PRIMARY KEY (single_id, day),                       -- Re-Upload überschreibt je Tag
  CONSTRAINT fk_sdaily_single FOREIGN KEY (single_id) REFERENCES singles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 5. GEMA-Detailreports (je Ausschüttungstermin)
-- -------------------------------------------------------------------------------------

CREATE TABLE gema_payouts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  payout_date  DATE         NOT NULL,                  -- Ausschüttungstermin
  files        JSON         NULL,                      -- Namen der importierten Dateien
  imported_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gema_date (payout_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Eine Zeile je Position des Detailreports, Spalten wie im GEMA-CSV.
-- Beträge mit 10 Nachkommastellen, wie von der GEMA geliefert.
CREATE TABLE gema_lines (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payout_id            INT UNSIGNED  NOT NULL,
  abrechnungsnr        VARCHAR(20)   NOT NULL DEFAULT '',
  spartencode          VARCHAR(10)   NOT NULL DEFAULT '',
  sparte               VARCHAR(20)   NOT NULL DEFAULT '',   -- Spartenkürzel, z. B. MOD S VR
  berufsgruppe         VARCHAR(4)    NOT NULL DEFAULT '',
  verteilungskategorie VARCHAR(40)   NOT NULL DEFAULT '',
  betrag               DECIMAL(20,10) NOT NULL DEFAULT 0,
  ausfallzuschlag      DECIMAL(20,10) NOT NULL DEFAULT 0,
  sonstige_zuschlaege  DECIMAL(20,10) NOT NULL DEFAULT 0,
  nicht_prog_anteil    DECIMAL(20,10) NOT NULL DEFAULT 0,
  gebucht              DECIMAL(20,10) NOT NULL DEFAULT 0,
  kommission_satz      DECIMAL(9,4)   NULL,
  kommission           DECIMAL(20,10) NULL,
  rueck_nachverrechnung VARCHAR(40)  NOT NULL DEFAULT '',
  anteil_pct           DECIMAL(13,10) NULL,
  werknummer           VARCHAR(20)   NOT NULL DEFAULT '',
  fassung              VARCHAR(10)   NOT NULL DEFAULT '',
  titel                VARCHAR(255)  NOT NULL DEFAULT '',
  iswc                 VARCHAR(20)   NOT NULL DEFAULT '',
  isrc                 VARCHAR(20)   NOT NULL DEFAULT '',
  komponisten          VARCHAR(255)  NOT NULL DEFAULT '',
  bearbeiter           VARCHAR(255)  NOT NULL DEFAULT '',
  lizenznehmer         VARCHAR(160)  NOT NULL DEFAULT '',
  katalognummer        VARCHAR(80)   NOT NULL DEFAULT '',
  traegerart           VARCHAR(10)   NOT NULL DEFAULT '',
  land                 VARCHAR(8)    NOT NULL DEFAULT '',
  menge                VARCHAR(20)   NOT NULL DEFAULT '',
  lizenzwert           DECIMAL(20,10) NULL,
  nutzungsjahr         SMALLINT      NULL,
  quartal_von          TINYINT       NULL,
  quartal_bis          TINYINT       NULL,
  sender               VARCHAR(160)  NOT NULL DEFAULT '',
  auffuehrungen        VARCHAR(20)   NOT NULL DEFAULT '',
  ausstrahlungen       VARCHAR(20)   NOT NULL DEFAULT '',
  filmtitel            VARCHAR(255)  NOT NULL DEFAULT '',
  titel_sendung        VARCHAR(255)  NOT NULL DEFAULT '',
  verteilart           VARCHAR(10)   NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY ix_gema_payout (payout_id),
  KEY ix_gema_sparte (payout_id, sparte),
  KEY ix_gema_werk (werknummer),
  CONSTRAINT fk_gema_payout FOREIGN KEY (payout_id) REFERENCES gema_payouts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 6. Label-Reports (Stream-Analyzer, je importierter Datei)
-- -------------------------------------------------------------------------------------

CREATE TABLE label_reports (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  file_key        VARCHAR(190) NOT NULL,             -- Dateiname ohne Endung: gleiche Datei = ersetzen
  file_name       VARCHAR(255) NOT NULL,
  label_id        INT UNSIGNED NULL,                 -- frei zugeordnetes Label
  currency        CHAR(3)      NOT NULL DEFAULT 'EUR',
  declared_total  DECIMAL(20,10) NULL,               -- „Total“-Zeile der Datei, für den Summenabgleich
  imported_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_lreports_file (file_key),
  CONSTRAINT fk_lreports_label FOREIGN KEY (label_id) REFERENCES labels(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE label_report_lines (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id     INT UNSIGNED  NOT NULL,
  period        VARCHAR(20)   NOT NULL DEFAULT '',   -- Abrechnungsperiode laut Datei (z. B. 2026-01)
  customer_no   VARCHAR(40)   NOT NULL DEFAULT '',
  account_name  VARCHAR(160)  NOT NULL DEFAULT '',
  contract      VARCHAR(40)   NOT NULL DEFAULT '',
  clearing_code VARCHAR(40)   NOT NULL DEFAULT '',
  isrc          VARCHAR(20)   NOT NULL DEFAULT '',   -- Product_ID
  ean           VARCHAR(20)   NOT NULL DEFAULT '',
  artist        VARCHAR(255)  NOT NULL DEFAULT '',
  title         VARCHAR(255)  NOT NULL DEFAULT '',   -- Release
  track_title   VARCHAR(255)  NOT NULL DEFAULT '',
  retailer      VARCHAR(120)  NOT NULL DEFAULT '',
  sale_type     VARCHAR(60)   NOT NULL DEFAULT '',   -- Stream, Download, Creation, Platform Promotion …
  territory     VARCHAR(8)    NOT NULL DEFAULT '',
  income        DECIMAL(24,12) NOT NULL DEFAULT 0,   -- Income_Price
  share_pct     DECIMAL(9,4)  NOT NULL DEFAULT 100,
  rate_pct      DECIMAL(9,4)  NULL,                  -- Royalty_Rate_%
  quantity      BIGINT        NOT NULL DEFAULT 0,
  net           DECIMAL(24,12) NOT NULL DEFAULT 0,   -- Net_Revenue (kann negativ sein: Platform Promotion)
  PRIMARY KEY (id),
  KEY ix_lrl_report (report_id),
  KEY ix_lrl_isrc (isrc),
  KEY ix_lrl_retailer (report_id, retailer),
  CONSTRAINT fk_lrl_report FOREIGN KEY (report_id) REFERENCES label_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------------------------------
-- 7. Startdaten: Kontenplan wie in der App (SKR03-basiert) – ohne Firmendaten
-- -------------------------------------------------------------------------------------

INSERT INTO accounts (nr, name, art) VALUES
 ('0420','Betriebs- und Geschäftsausstattung','aktiv'),
 ('1200','Bank','aktiv'),
 ('1210','Gesellschafter 1','aktiv'),
 ('1220','Gesellschafter 2','aktiv'),
 ('4200','Raumkosten','aufwand'),
 ('4240','Energiekosten','aufwand'),
 ('4360','Versicherungen','aufwand'),
 ('4660','Reisekosten','aufwand'),
 ('4830','Abschreibungen Sachanlagen','aufwand'),
 ('4860','Abschreibungen GWG','aufwand'),
 ('4920','Telekommunikation','aufwand'),
 ('4964','Lizenzaufwand','aufwand'),
 ('4970','Bankgebühren','aufwand'),
 ('8000','Umsatzerlöse','ertrag'),
 ('8603','Erstattungen','ertrag');

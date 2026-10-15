-- =====================================================================================
-- 005 – Belegarchiv: Kontoauszüge und Belege zu Buchungen
--  * documents: jede Datei genau einmal (sha256 eindeutig) – PDF oder Bild.
--    Wird nie überschrieben; löschen nur, solange sie mit keiner Buchung verbunden ist.
--  * booking_documents: welche Datei zu welcher Buchung gehört (n:m – ein Kontoauszug
--    gehört zu vielen Buchungen, eine Rechnung kann mehrere Buchungen belegen).
--  * booking_log: Verbinden/Lösen eines Belegs wird protokolliert.
-- =====================================================================================

CREATE TABLE documents (
  id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  kind        ENUM('bank','beleg') NOT NULL,      -- Kontoauszug oder Beleg (Rechnung, Quittung …)
  filename    VARCHAR(160)  NOT NULL,
  mime        VARCHAR(60)   NOT NULL,
  bytes       INT UNSIGNED  NOT NULL,
  sha256      CHAR(64)      NOT NULL,
  content     MEDIUMBLOB    NOT NULL,
  created_by  INT UNSIGNED  NULL,
  created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_documents_sha (sha256),
  CONSTRAINT fk_documents_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE booking_documents (
  booking_id   INT UNSIGNED NOT NULL,
  document_id  INT UNSIGNED NOT NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (booking_id, document_id),
  KEY ix_bdoc_document (document_id),
  CONSTRAINT fk_bdoc_booking  FOREIGN KEY (booking_id)  REFERENCES bookings(id)  ON DELETE CASCADE,
  CONSTRAINT fk_bdoc_document FOREIGN KEY (document_id) REFERENCES documents(id),
  CONSTRAINT fk_bdoc_user     FOREIGN KEY (created_by)  REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE booking_log
  MODIFY action ENUM('create','update','delete','lock','storno','clarify','private','attach','detach') NOT NULL;

-- =====================================================================================
-- 004 – Rechnungen auf dem Server + PDF-Archiv
--  * statements: Zeitraum-Anfang/-Ende als Text wie in der Abrechnung („01.07.2025“),
--    Währung als Zeichen wie in der App (€, £, $).
--  * invoice_pdfs: jede erzeugte Rechnung als PDF, nur anhängen – nie überschreiben.
--    sha256 belegt, dass die Datei unverändert ist. Bleibt erhalten, auch wenn die
--    Abrechnung gelöscht wird (statement_id wird dann NULL).
-- =====================================================================================

ALTER TABLE statements
  MODIFY period_start VARCHAR(20) NULL,
  MODIFY period_end   VARCHAR(20) NULL,
  MODIFY currency     VARCHAR(8)  NOT NULL DEFAULT '€';

ALTER TABLE invoices
  MODIFY currency     VARCHAR(8)  NOT NULL DEFAULT '€';

CREATE TABLE invoice_pdfs (
  id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  invoice_no    VARCHAR(40)   NOT NULL,
  statement_id  INT UNSIGNED  NULL,
  filename      VARCHAR(120)  NOT NULL,
  bytes         INT UNSIGNED  NOT NULL,
  sha256        CHAR(64)      NOT NULL,
  pdf           MEDIUMBLOB    NOT NULL,
  created_by    INT UNSIGNED  NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_pdfs_no (invoice_no),
  KEY ix_pdfs_statement (statement_id),
  CONSTRAINT fk_pdfs_statement FOREIGN KEY (statement_id) REFERENCES statements(id) ON DELETE SET NULL,
  CONSTRAINT fk_pdfs_user      FOREIGN KEY (created_by)   REFERENCES users(id)      ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tienda B2B — acceso mayorista con aprobación del admin
-- Ejecutar UNA vez sobre britech_v2.
--  - El cliente nace MINORISTA. Puede solicitar acceso mayorista.
--  - El admin aprueba/rechaza. Aprobado => mayorista_aprobado = 1.
--  - Con acceso aprobado, el cliente elige en la tienda cómo navegar
--    (precios minoristas o mayoristas) — eso vive en la sesión, no en la BD.
-- ============================================================

USE britech_v2;

ALTER TABLE cliente
  ADD COLUMN mayorista_aprobado TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;

CREATE TABLE IF NOT EXISTS solicitud_mayorista (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  cliente_id   INT NOT NULL,
  estado       ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
  mensaje      VARCHAR(255) NULL,          -- razón opcional del cliente
  creado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resuelto_en  DATETIME NULL,
  resuelto_por INT NULL,                    -- usuario admin que resolvió
  CONSTRAINT fk_solic_cliente FOREIGN KEY (cliente_id)   REFERENCES cliente(id),
  CONSTRAINT fk_solic_admin   FOREIGN KEY (resuelto_por) REFERENCES usuario(id)
) ENGINE=InnoDB;

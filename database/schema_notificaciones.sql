-- ============================================================
-- Notificaciones del panel admin (bandeja: leídas / no leídas).
-- Ejecutar UNA vez sobre britech_v2.
--  - Se crea una fila por EVENTO relevante (pedido nuevo, comprobante subido,
--    solicitud mayorista). El admin la ve en la campana y la marca leída.
--  - ir = clave de la vista del panel a la que lleva el aviso.
--  - ref_id = id del pedido/solicitud/etc (para futuros deep-links).
-- ============================================================

USE britech_v2;

CREATE TABLE IF NOT EXISTS notificacion (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  tipo      VARCHAR(30) NOT NULL,
  titulo    VARCHAR(200) NOT NULL,
  ir        VARCHAR(30) NULL,
  ref_id    INT NULL,
  leida     TINYINT(1) NOT NULL DEFAULT 0,
  creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notif_leida (leida, creado_en)
) ENGINE=InnoDB;

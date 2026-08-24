-- ============================================================
-- Pagos por transferencia (sin pasarela) + config de la tienda
-- Ejecutar UNA vez sobre britech_v2 (después de schema_tienda.sql).
--  - El pedido gana un ESTADO DE PAGO independiente del estado de envío.
--  - El cliente sube un comprobante (queda 'en_revision'); el admin aprueba
--    o rechaza. Sin pasarela: pago al contado por transferencia.
--  - config_tienda: datos de transferencia (alias/titular/CBU) editables desde
--    el panel, que el cliente ve en el checkout.
-- ============================================================

USE britech_v2;

ALTER TABLE pedido
  ADD COLUMN estado_pago ENUM('pendiente','en_revision','pagado','rechazado')
      NOT NULL DEFAULT 'pendiente' AFTER estado,
  ADD COLUMN comprobante_url VARCHAR(255) NULL AFTER estado_pago;

CREATE TABLE IF NOT EXISTS config_tienda (
  clave VARCHAR(50) PRIMARY KEY,
  valor VARCHAR(255) NULL
) ENGINE=InnoDB;

INSERT INTO config_tienda (clave, valor) VALUES
  ('pago_alias',   ''),
  ('pago_titular', ''),
  ('pago_cbu',     ''),
  ('pago_banco',   '')
ON DUPLICATE KEY UPDATE clave = clave;

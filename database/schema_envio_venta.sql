-- ============================================================
-- Envío también para ventas del POS (además de pedidos de la tienda).
-- Ejecutar UNA vez sobre britech_v2 (después de schema_moto_barrios.sql).
--
--  - Hasta ahora un envío colgaba solo de un pedido (tienda). Los vendedores
--    cargan ventas por el POS y algunas también necesitan envío. Un envío pasa
--    a pertenecer a UN pedido O a UNA venta (nunca a ambos).
--  - pedido_id pasa a NULL-able; se agrega venta_id NULL-able. Ambos UNIQUE
--    (MySQL permite varios NULL en un índice único → un envío por pedido/venta).
-- ============================================================

USE britech_v2;

ALTER TABLE envio
  MODIFY COLUMN pedido_id INT NULL,
  ADD COLUMN venta_id INT NULL AFTER pedido_id,
  ADD CONSTRAINT fk_envio_venta FOREIGN KEY (venta_id) REFERENCES venta(id),
  ADD CONSTRAINT uq_envio_venta UNIQUE (venta_id);

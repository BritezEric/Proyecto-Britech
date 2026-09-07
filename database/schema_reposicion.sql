-- ============================================================
-- Reposición / Pedido a proveedor
-- Ejecutar UNA vez sobre britech_v2 (extiende producto).
-- Agrega el COSTO del producto (lo que nos cuesta al proveedor) y el
-- UMBRAL de stock mínimo que dispara la alerta de reposición.
--   - costo: precio de compra al proveedor (DECIMAL, nunca FLOAT).
--   - stock_minimo: cuando inventario.cantidad <= stock_minimo (y > 0),
--     el producto aparece en la pantalla de Reposición. 0 = sin alerta.
-- No hay tabla de orden de compra: la solicitud se arma al momento y se
-- envía por WhatsApp al proveedor (el admin lee y acepta).
-- ============================================================

USE britech_v2;

ALTER TABLE producto
  ADD COLUMN costo        DECIMAL(12,2) NULL AFTER precio_anterior,
  ADD COLUMN stock_minimo INT NOT NULL DEFAULT 0 AFTER costo;

-- Semilla para la demo: un umbral y un costo de ejemplo a algunos productos.
UPDATE producto SET stock_minimo = 5 WHERE stock_minimo = 0 AND activo = 1;

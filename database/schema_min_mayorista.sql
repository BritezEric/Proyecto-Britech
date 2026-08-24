-- ============================================================
-- Cantidad mínima de compra mayorista por producto
-- Ejecutar UNA vez sobre britech_v2.
-- Aplica solo cuando el cliente navega/compra en modo MAYORISTA.
-- ============================================================

USE britech_v2;

ALTER TABLE producto
  ADD COLUMN min_mayorista INT NOT NULL DEFAULT 1 AFTER es_sobre_pedido;

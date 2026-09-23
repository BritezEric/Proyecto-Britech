-- ============================================================
-- Mercado Pago (Checkout Pro): guarda el id del pago aprobado.
-- Ejecutar UNA vez sobre britech_v2 (después de schema_pagos.sql).
--  - El pago online sigue usando la columna estado_pago existente
--    ('pendiente' → 'pagado'/'rechazado'/'en_revision').
--  - mp_payment_id = id del pago en Mercado Pago (trazabilidad + idempotencia).
-- ============================================================

USE britech_v2;

ALTER TABLE pedido
  ADD COLUMN mp_payment_id VARCHAR(40) NULL AFTER comprobante_url;

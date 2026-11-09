-- ============================================================
-- Link público de seguimiento: un token aleatorio por envío.
-- Permite ver la moto + destino + ETA SIN login y sin exponer datos del cliente.
-- Ejecutar UNA vez sobre britech_v2 (después de schema_tracking.sql).
-- ============================================================

USE britech_v2;

ALTER TABLE envio
  ADD COLUMN token VARCHAR(40) NULL UNIQUE AFTER tracking;

-- Token para los envíos que ya existen.
UPDATE envio SET token = LOWER(HEX(RANDOM_BYTES(16))) WHERE token IS NULL;

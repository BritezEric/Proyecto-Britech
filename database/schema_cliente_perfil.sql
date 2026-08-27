-- ============================================================
-- Perfil del cliente más completo: provincia + código postal.
-- Ejecutar UNA vez sobre britech_v2.
--  - Con esto la dirección guardada del cliente queda completa y el checkout se
--    autocompleta entero (antes solo teníamos dirección + localidad).
-- ============================================================

USE britech_v2;

ALTER TABLE cliente
  ADD COLUMN provincia VARCHAR(80) NULL AFTER localidad,
  ADD COLUMN cp        VARCHAR(10) NULL AFTER provincia;

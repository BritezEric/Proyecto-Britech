-- ============================================================
-- Envíos v2 — datos completos de entrega + link de seguimiento
-- Ejecutar UNA vez sobre britech_v2 (después de schema_envios.sql).
--  - Agrega a 'envio' todos los datos de entrega (obligatorios en el checkout
--    cuando el pedido es con envío, no en retiro en local).
--  - Agrega a 'empresa_envio' la URL pública de seguimiento del correo, con el
--    placeholder {tracking} que se reemplaza por el nº de seguimiento.
-- ============================================================

USE britech_v2;

ALTER TABLE envio
  ADD COLUMN destinatario VARCHAR(150) NULL AFTER empresa_envio_id,
  ADD COLUMN telefono     VARCHAR(30)  NULL AFTER destinatario,
  ADD COLUMN numero       VARCHAR(20)  NULL AFTER direccion,
  ADD COLUMN referencia   VARCHAR(200) NULL AFTER numero,
  ADD COLUMN provincia    VARCHAR(80)  NULL AFTER localidad,
  ADD COLUMN cp           VARCHAR(10)  NULL AFTER provincia;

-- URL pública de seguimiento por correo. {tracking} = nº de seguimiento.
ALTER TABLE empresa_envio
  ADD COLUMN url_tracking VARCHAR(255) NULL AFTER es_retiro;

UPDATE empresa_envio SET url_tracking = 'https://www.andreani.com/#!/informacionEnvio/{tracking}'          WHERE nombre = 'Andreani';
UPDATE empresa_envio SET url_tracking = 'https://www1.oca.com.ar/OEPTrackingWeb/Tracking.aspx?numero={tracking}' WHERE nombre = 'OCA';
UPDATE empresa_envio SET url_tracking = 'https://www.correoargentino.com.ar/formularios/oal'                 WHERE nombre = 'Correo Argentino';

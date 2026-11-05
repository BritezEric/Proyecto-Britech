-- ============================================================
-- Reportes y Control Operativo — columnas e índices de apoyo.
-- Ejecutar UNA vez sobre britech_v2. Es re-ejecutable (si algo ya existe,
-- lo saltea). Solo AGREGA columnas nulas e índices: no modifica ni borra datos,
-- ni toca claves primarias o foráneas.
--
-- Por qué:
--  - envio.asignado_en: momento en que el envío recibió repartidor. Sin este
--    dato no se puede saber cuánto esperó un envío antes de ser asignado.
--    Lo completa EnvioRepository al derivar (si la columna existe).
--    Los envíos asignados antes de este script quedan en NULL ("sin dato").
--  - pedido.actualizado_en: última modificación del pedido (MySQL la pone sola
--    con ON UPDATE). Sirve para detectar pedidos "sin actualizarse". Los pedidos
--    viejos quedan en NULL y el reporte usa la fecha de creación.
--  - Índices por fecha para cajas, anulaciones y reclamos.
-- ============================================================

USE britech_v2;

DROP PROCEDURE IF EXISTS britech_agregar_columna;
DROP PROCEDURE IF EXISTS britech_crear_indice;
DELIMITER //
CREATE PROCEDURE britech_agregar_columna(IN p_tabla VARCHAR(64), IN p_columna VARCHAR(64), IN p_definicion VARCHAR(255))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla AND COLUMN_NAME = p_columna) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_tabla, '` ADD COLUMN `', p_columna, '` ', p_definicion);
    PREPARE st FROM @sql;
    EXECUTE st;
    DEALLOCATE PREPARE st;
  END IF;
END //
CREATE PROCEDURE britech_crear_indice(IN p_tabla VARCHAR(64), IN p_indice VARCHAR(64), IN p_columnas VARCHAR(255))
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla AND INDEX_NAME = p_indice) THEN
    SET @sql = CONCAT('ALTER TABLE `', p_tabla, '` ADD INDEX `', p_indice, '` (', p_columnas, ')');
    PREPARE st FROM @sql;
    EXECUTE st;
    DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;

CALL britech_agregar_columna('envio',  'asignado_en',    'DATETIME NULL DEFAULT NULL AFTER repartidor_id');
CALL britech_agregar_columna('pedido', 'actualizado_en', 'DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER creado_en');

CALL britech_crear_indice('venta',  'ix_venta_creado',   'creado_en, estado');
CALL britech_crear_indice('pedido', 'ix_pedido_creado',  'creado_en, estado');
CALL britech_crear_indice('envio',  'ix_envio_estado',   'estado, creado_en');
CALL britech_crear_indice('caja',   'ix_caja_abierta',   'abierta_en');
CALL britech_crear_indice('venta_anulacion', 'ix_anulacion_creado', 'creado_en');
CALL britech_crear_indice('reclamo', 'ix_reclamo_creado', 'creado_en, estado');

DROP PROCEDURE IF EXISTS britech_agregar_columna;
DROP PROCEDURE IF EXISTS britech_crear_indice;

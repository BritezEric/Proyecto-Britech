-- ============================================================
-- Reporte de Localidades — índices de apoyo (solo agrega índices).
-- Ejecutar UNA vez sobre britech_v2. Es re-ejecutable: si un índice ya
-- existe, lo saltea. NO modifica ni borra datos.
--
-- Por qué: el reporte filtra ventas y pedidos por fecha (creado_en) y antes
-- no había índice por fecha → cada consulta recorría la tabla entera.
-- Las relaciones con envio (venta_id / pedido_id) ya tienen índice UNIQUE.
-- ============================================================

USE britech_v2;

DROP PROCEDURE IF EXISTS britech_crear_indice;
DELIMITER //
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

CALL britech_crear_indice('venta',  'ix_venta_creado',  'creado_en, estado');
CALL britech_crear_indice('pedido', 'ix_pedido_creado', 'creado_en, estado');
CALL britech_crear_indice('envio',  'ix_envio_estado',  'estado, creado_en');

DROP PROCEDURE IF EXISTS britech_crear_indice;

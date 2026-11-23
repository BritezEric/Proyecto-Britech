-- ============================================================
-- Cierre de caja (v2): arqueo por forma de pago + gastos de caja
-- Ejecutar sobre britech_v2, DESPUÉS de schema_caja.sql. Es re-ejecutable
-- (si algo ya existe, lo saltea). Solo AGREGA: no borra datos ni toca claves.
--
-- 1) caja_movimiento admite 'gasto' (pago chico hecho con el efectivo de la caja:
--    resta del efectivo esperado y además queda registrado en `gasto`).
-- 2) caja.conteo_efectivo: el detalle de billetes/monedas contados en el arqueo.
--    caja.cerrada_por: quién la cerró (un admin puede cerrar la caja olvidada de otro).
-- 3) caja_cierre_forma: foto (snapshot) de los totales por forma de pago al
--    momento del cierre — esperado vs. declarado y su diferencia. Es la fuente
--    del reporte de cierre: si después se anula una venta, el cierre no cambia.
-- ============================================================

USE britech_v2;

-- Agregar un valor al ENUM no cambia los datos existentes (y repetirlo es inocuo).
ALTER TABLE caja_movimiento
  MODIFY tipo ENUM('retiro','ingreso','gasto') NOT NULL;

DROP PROCEDURE IF EXISTS britech_agregar_columna;
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
DELIMITER ;

CALL britech_agregar_columna('caja_movimiento', 'gasto_id',        'INT NULL AFTER motivo');
CALL britech_agregar_columna('caja',            'conteo_efectivo', 'JSON NULL AFTER monto_contado');   -- {"20000": 3, "1000": 5, ...}
-- Quién cerró la caja: el vendedor, o un admin si la cerró por él (cierre administrativo).
-- NULL en las cajas cerradas antes de este script.
CALL britech_agregar_columna('caja',            'cerrada_por',     'INT NULL AFTER cerrada_en');

DROP PROCEDURE IF EXISTS britech_agregar_columna;

CREATE TABLE IF NOT EXISTS caja_cierre_forma (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  caja_id       INT NOT NULL,
  tipo_pago_id  INT NOT NULL,
  nombre        VARCHAR(50) NOT NULL,                -- nombre de la forma al cerrar
  operaciones   INT NOT NULL DEFAULT 0,              -- cantidad de cobros con esa forma
  esperado      DECIMAL(12,2) NOT NULL DEFAULT 0,    -- según el sistema (en efectivo: el esperado físico)
  declarado     DECIMAL(12,2) NOT NULL DEFAULT 0,    -- lo que contó / verificó el vendedor
  diferencia    DECIMAL(12,2) NOT NULL DEFAULT 0,    -- declarado - esperado
  CONSTRAINT fk_ccf_caja      FOREIGN KEY (caja_id)      REFERENCES caja(id) ON DELETE CASCADE,
  CONSTRAINT fk_ccf_tipo_pago FOREIGN KEY (tipo_pago_id) REFERENCES tipo_pago(id),
  UNIQUE KEY uq_ccf (caja_id, tipo_pago_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

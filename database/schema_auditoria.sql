-- ============================================================
-- Auditoría: registro de quién hizo qué, cuándo y qué cambió.
-- Guarda cambios campo a campo (precio, stock, rol…) con su valor anterior y
-- nuevo, y eventos de operaciones críticas (login, venta anulada, caja…).
--
-- Re-ejecutable y no destructivo: crea lo que falta, no borra tablas ni datos.
-- Se aplica sobre la base que esté en uso:
--   mysql -u root britech_original < database/schema_auditoria.sql
-- (instalar.sql lo ejecuta solo, sobre britech_v2).
-- ============================================================

CREATE TABLE IF NOT EXISTS auditoria (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario_id     INT NULL,                       -- quién lo hizo (NULL = sistema / sin sesión)
  entidad        VARCHAR(40) NOT NULL,           -- módulo: producto, stock, venta, usuario, sesion…
  entidad_id     INT NULL,                       -- id del registro afectado
  accion         VARCHAR(30) NOT NULL,           -- crear, editar, baja, anular, login, salida…
  campo          VARCHAR(50) NULL,               -- qué campo cambió; NULL en eventos
  valor_anterior VARCHAR(255) NULL,
  valor_nuevo    VARCHAR(255) NULL,
  detalle        VARCHAR(255) NULL,              -- descripción de la operación
  ip             VARCHAR(45) NULL,               -- desde dónde se hizo
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_entidad (entidad, entidad_id),
  INDEX ix_fecha (creado_en),
  INDEX ix_usuario (usuario_id),
  INDEX ix_accion (accion)
) ENGINE=InnoDB;

-- Bases que ya tenían la tabla sin la columna ip: se agrega (nula, sin tocar datos).
DROP PROCEDURE IF EXISTS britech_auditoria_ip;
DELIMITER //
CREATE PROCEDURE britech_auditoria_ip()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria' AND COLUMN_NAME = 'ip') THEN
    ALTER TABLE auditoria ADD COLUMN ip VARCHAR(45) NULL AFTER detalle;
  END IF;
END //
DELIMITER ;
CALL britech_auditoria_ip();
DROP PROCEDURE britech_auditoria_ip;

-- El historial es de solo lectura: la base rechaza cualquier UPDATE o DELETE,
-- venga del sistema, de phpMyAdmin o de un script. Solo se pueden agregar filas.
DROP TRIGGER IF EXISTS auditoria_sin_editar;
CREATE TRIGGER auditoria_sin_editar BEFORE UPDATE ON auditoria FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La auditoria es de solo lectura: no se puede modificar.';

DROP TRIGGER IF EXISTS auditoria_sin_borrar;
CREATE TRIGGER auditoria_sin_borrar BEFORE DELETE ON auditoria FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La auditoria es de solo lectura: no se puede borrar.';

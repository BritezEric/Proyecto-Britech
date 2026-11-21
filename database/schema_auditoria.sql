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

-- ============================================================
-- Ampliación (centro de control): dispositivo, integridad y alertas.
-- Todo re-ejecutable: agrega solo lo que falta, nunca borra ni modifica filas.
-- ============================================================

-- auditoria: navegador/dispositivo de quien hizo la acción + hash encadenado.
--   agente        → User-Agent recortado (para detectar accesos desde dispositivos nuevos).
--   hash_anterior → hash de la fila previa de la cadena (al momento de registrar).
--   hash          → HMAC-SHA256(hash_anterior + contenido de la fila) con AUDIT_HMAC_KEY del .env.
--   Las filas viejas quedan con hash NULL (la cadena arranca en la primera fila nueva).
DROP PROCEDURE IF EXISTS britech_auditoria_v2;
DELIMITER //
CREATE PROCEDURE britech_auditoria_v2()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria' AND COLUMN_NAME = 'agente') THEN
    ALTER TABLE auditoria ADD COLUMN agente VARCHAR(255) NULL AFTER ip;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria' AND COLUMN_NAME = 'hash') THEN
    ALTER TABLE auditoria ADD COLUMN hash_anterior CHAR(64) NULL AFTER creado_en,
                          ADD COLUMN hash CHAR(64) NULL AFTER hash_anterior;
  END IF;
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria' AND INDEX_NAME = 'ix_hash') THEN
    ALTER TABLE auditoria ADD INDEX ix_hash (hash);
  END IF;
  -- Consultas frecuentes del detector de alertas y de las secciones (módulo + acción + fecha).
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria' AND INDEX_NAME = 'ix_entidad_accion_fecha') THEN
    ALTER TABLE auditoria ADD INDEX ix_entidad_accion_fecha (entidad, accion, creado_en);
  END IF;
END //
DELIMITER ;
CALL britech_auditoria_v2();
DROP PROCEDURE britech_auditoria_v2;

-- Alertas de actividades que requieren revisión (las genera el backend, nunca la pantalla).
-- Una alerta NO acusa a nadie: usuario_id es la persona relacionada con la actividad.
--   auditoria_id → la operación de auditoría que la disparó.
--   entidad / entidad_id → registro relacionado (venta, caja, producto, usuario…).
--   clave → única: evita alertas duplicadas por la misma situación.
--   Revisión: revisada / revisada_por / revisada_en / nota_revision (la alerta nunca se borra).
CREATE TABLE IF NOT EXISTS auditoria_alerta (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  auditoria_id   BIGINT NULL,
  tipo           VARCHAR(40) NOT NULL,
  nivel          ENUM('informativa','advertencia','critica') NOT NULL,
  usuario_id     INT NULL,
  titulo         VARCHAR(150) NOT NULL,
  descripcion    VARCHAR(500) NOT NULL,
  entidad        VARCHAR(40) NULL,
  entidad_id     INT NULL,
  clave          VARCHAR(160) NOT NULL,
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revisada       TINYINT(1) NOT NULL DEFAULT 0,
  revisada_por   INT NULL,
  revisada_en    DATETIME NULL,
  nota_revision  VARCHAR(255) NULL,
  UNIQUE KEY uq_alerta_clave (clave),
  INDEX ix_alerta_estado (revisada, nivel, creado_en),
  INDEX ix_alerta_fecha (creado_en),
  INDEX ix_alerta_usuario (usuario_id),
  INDEX ix_alerta_tipo (tipo),
  CONSTRAINT fk_alerta_auditoria FOREIGN KEY (auditoria_id) REFERENCES auditoria (id),
  CONSTRAINT fk_alerta_usuario   FOREIGN KEY (usuario_id)   REFERENCES usuario (id),
  CONSTRAINT fk_alerta_revisor   FOREIGN KEY (revisada_por) REFERENCES usuario (id)
) ENGINE=InnoDB;

-- Las alertas no se borran, y lo único que se puede cambiar es marcarlas como revisadas
-- (una sola vez, con quién y cuándo). Cualquier otro cambio lo rechaza la base.
DROP TRIGGER IF EXISTS auditoria_alerta_sin_borrar;
CREATE TRIGGER auditoria_alerta_sin_borrar BEFORE DELETE ON auditoria_alerta FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las alertas de auditoria no se pueden borrar.';

DROP TRIGGER IF EXISTS auditoria_alerta_solo_revision;
DELIMITER //
CREATE TRIGGER auditoria_alerta_solo_revision BEFORE UPDATE ON auditoria_alerta FOR EACH ROW
BEGIN
  IF NOT (OLD.id <=> NEW.id AND OLD.auditoria_id <=> NEW.auditoria_id AND OLD.tipo <=> NEW.tipo
          AND OLD.nivel <=> NEW.nivel AND OLD.usuario_id <=> NEW.usuario_id AND OLD.titulo <=> NEW.titulo
          AND OLD.descripcion <=> NEW.descripcion AND OLD.entidad <=> NEW.entidad
          AND OLD.entidad_id <=> NEW.entidad_id AND OLD.clave <=> NEW.clave AND OLD.creado_en <=> NEW.creado_en) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Las alertas de auditoria solo se pueden marcar como revisadas.';
  END IF;
  IF OLD.revisada = 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'La alerta ya fue revisada: su revision no se puede cambiar.';
  END IF;
  IF NEW.revisada <> 1 OR NEW.revisada_por IS NULL OR NEW.revisada_en IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Para revisar una alerta hacen falta revisada = 1, quien y cuando.';
  END IF;
END //
DELIMITER ;

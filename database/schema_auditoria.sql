-- ============================================================
-- Auditoría: registro de quién cambió qué y cuándo.
-- Guarda cambios campo a campo (precio, nombre, descripción, costo…) y eventos
-- de operaciones críticas (venta anulada, pago aprobado, cambio de estado…).
-- Ejecutar UNA vez sobre britech_v2.
-- ============================================================

USE britech_v2;

CREATE TABLE IF NOT EXISTS auditoria (
  id             BIGINT AUTO_INCREMENT PRIMARY KEY,
  usuario_id     INT NULL,                       -- quién lo hizo (NULL = sistema)
  entidad        VARCHAR(40) NOT NULL,           -- producto, cliente, proveedor, venta, pedido…
  entidad_id     INT NULL,                       -- id del registro afectado
  accion         VARCHAR(30) NOT NULL,           -- crear, editar, baja, anular, precio_masivo, estado_pago…
  campo          VARCHAR(50) NULL,               -- qué campo cambió (para 'editar'); NULL en eventos
  valor_anterior VARCHAR(255) NULL,
  valor_nuevo    VARCHAR(255) NULL,
  detalle        VARCHAR(255) NULL,              -- texto libre del evento
  creado_en      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_entidad (entidad, entidad_id),
  INDEX ix_fecha (creado_en),
  INDEX ix_usuario (usuario_id),
  INDEX ix_accion (accion)
) ENGINE=InnoDB;

-- ============================================================
-- Empleados: pagos de sueldo como GASTO etiquetado al usuario
-- Ejecutar UNA vez sobre britech_v2 (después de schema_gastos.sql).
--  - Un pago de sueldo NO es una tabla nueva: es un 'gasto' con usuario_id
--    (a quién se le pagó) y periodo ('YYYY-MM', el mes que cubre el sueldo).
--    Así cae solo en "total invertido" y queda registrado en finanzas.
-- ============================================================

USE britech_v2;

ALTER TABLE gasto
  ADD COLUMN usuario_id INT NULL AFTER producto_id,
  ADD COLUMN periodo    VARCHAR(7) NULL AFTER usuario_id,
  ADD CONSTRAINT fk_gasto_usuario FOREIGN KEY (usuario_id) REFERENCES usuario(id);

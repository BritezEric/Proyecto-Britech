-- ============================================================
-- Datos fiscales del cliente (para facturar compras mayoristas).
-- Todos OPCIONALES: hay clientes que compran sin factura (no están en AFIP).
-- Ejecutar UNA vez sobre britech_v2.
-- ============================================================

USE britech_v2;

ALTER TABLE cliente
  ADD COLUMN razon_social  VARCHAR(150) NULL AFTER nombre,
  ADD COLUMN cuit          VARCHAR(20)  NULL AFTER documento,
  ADD COLUMN condicion_iva VARCHAR(40)  NULL AFTER cuit;

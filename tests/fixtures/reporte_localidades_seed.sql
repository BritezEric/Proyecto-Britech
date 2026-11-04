-- ============================================================
-- Datos de PRUEBA del Reporte de Localidades.
-- SOLO para una base de prueba (ej: britech_reportes_test). El runner
-- tests/reporte_localidades_test.php se niega a correr sobre otra base.
-- Usa ids 9xxx para no pisar datos; es re-ejecutable (borra y vuelve a cargar).
--
-- Usuarios de prueba (contraseñas solo de test; el runner las hashea):
--   admin@britech.local      / admin1234            (admin del seed del proyecto)
--   vendedor.a@britech.test  / vendedor-test-1234   (vendedor)
--   vendedor.b@britech.test  / vendedor-test-1234   (vendedor)
--   cliente.test@britech.test/ cliente-test-1234    (cliente de la tienda)
--
-- Valores esperados (octubre 2026, 1 al 27, base "entrega", admin) en el runner.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM envio          WHERE id BETWEEN 9000 AND 9999;
DELETE FROM venta_detalle  WHERE id BETWEEN 9000 AND 9999;
DELETE FROM pedido_detalle WHERE id BETWEEN 9000 AND 9999;
DELETE FROM venta          WHERE id BETWEEN 9000 AND 9999;
DELETE FROM pedido         WHERE id BETWEEN 9000 AND 9999;
DELETE FROM repartidor     WHERE id = 9001;
DELETE FROM empresa_envio  WHERE id = 9005;
DELETE FROM cliente        WHERE id BETWEEN 9000 AND 9999;
DELETE FROM usuario        WHERE id BETWEEN 9000 AND 9999;
SET FOREIGN_KEY_CHECKS = 1;

-- Vendedores (el hash lo pone el runner con password_hash()).
INSERT INTO usuario (id, nombre, email, password_hash, rol_id, activo, email_verificado) VALUES
  (9010, 'Vendedor A', 'vendedor.a@britech.test', 'x', (SELECT id FROM rol WHERE nombre = 'vendedor'), 1, 1),
  (9011, 'Vendedor B', 'vendedor.b@britech.test', 'x', (SELECT id FROM rol WHERE nombre = 'vendedor'), 1, 1);

-- Clientes: 9020 con localidad Formosa; 9021 sin localidad; 9030 cuenta de tienda.
INSERT INTO cliente (id, nombre, email, localidad, provincia, lista_precio_id, activo) VALUES
  (9020, 'Ana Test',   'ana@britech.test',  'Formosa', 'Formosa', 1, 1),
  (9021, 'Beto Test',  NULL,                NULL,      NULL,      1, 1),
  (9030, 'Cliente Tienda', 'cliente.test@britech.test', NULL, NULL, 1, 1);

INSERT INTO empresa_envio (id, nombre, costo_base, es_retiro, es_moto, activo) VALUES
  (9005, 'Moto Express (test)', 0, 0, 1, 1);
INSERT INTO repartidor (id, nombre, telefono, activo) VALUES (9001, 'Repartidor Test', NULL, 1);

-- ---------- Ventas del POS (octubre) ----------
-- V1: vendedor A, mostrador sin envío. 10000 - 1000 de descuento = 9000.
INSERT INTO venta (id, numero, cliente_id, usuario_id, subtotal, descuento, total, estado, creado_en) VALUES
  (9001, 'T-V9001', 1,    9010, 10000, 1000, 9000,  'registrada', '2026-10-05 10:00:00'),
-- V2: vendedor A, envío Moto al barrio 3 (Centro), 2500 de envío (venta.total lo incluye).
  (9002, 'T-V9002', 9020, 9010, 20000, 0,    22500, 'registrada', '2026-10-06 11:00:00'),
-- V3: vendedor B, Correo Argentino a "Formosa", envío pendiente (demorado).
  (9003, 'T-V9003', 3,    9011, 15000, 0,    18500, 'registrada', '2026-10-07 12:00:00'),
-- V4: vendedor A, ANULADA (no suma importe).
  (9004, 'T-V9004', 1,    9010, 8000,  0,    8000,  'anulada',    '2026-10-08 13:00:00'),
-- V5: septiembre (período anterior), vendedor A, Correo a "Formosa".
  (9005, 'T-V9005', 9020, 9010, 10000, 0,    13500, 'registrada', '2026-09-10 10:00:00');

INSERT INTO venta_detalle (id, venta_id, producto_id, cantidad, precio_unitario, subtotal) VALUES
  (9001, 9001, 1, 2, 5000,  10000),
  (9002, 9002, 2, 1, 20000, 20000),
  (9003, 9003, 1, 3, 5000,  15000),
  (9004, 9004, 3, 1, 8000,  8000),
  (9005, 9005, 1, 2, 5000,  10000);

-- ---------- Pedidos de la tienda (pedido.total = solo productos) ----------
INSERT INTO pedido (id, numero, cliente_id, estado, estado_pago, metodo_pago, total, creado_en) VALUES
  (9001, 'T-P9001', 9020, 'entregado',  'pagado',      'transferencia', 30000, '2026-10-10 09:00:00'),
  (9002, 'T-P9002', 3,    'pendiente',  'pendiente',   'transferencia', 12000, '2026-10-12 09:00:00'),
  (9003, 'T-P9003', 9021, 'cancelado',  'rechazado',   'transferencia', 5000,  '2026-10-14 09:00:00'),
  (9004, 'T-P9004', 9021, 'preparando', 'en_revision', 'transferencia', 7000,  '2026-10-15 09:00:00'),
  (9005, 'T-P9005', 1,    'entregado',  'pagado',      'transferencia', 4000,  '2026-10-16 09:00:00'),
  (9006, 'T-P9006', 3,    'entregado',  'pagado',      'transferencia', 6000,  '2026-09-12 09:00:00');

-- P1 tiene DOS productos: debe contarse como UNA operación.
-- P2 usa un precio histórico (6000) distinto del de V1/V3 (5000).
INSERT INTO pedido_detalle (id, pedido_id, producto_id, cantidad, precio_unitario, subtotal) VALUES
  (9001, 9001, 2, 1, 20000, 20000),
  (9002, 9001, 3, 1, 10000, 10000),
  (9003, 9002, 1, 2, 6000,  12000),
  (9004, 9003, 3, 1, 5000,  5000),
  (9005, 9004, 1, 1, 7000,  7000),
  (9006, 9005, 3, 1, 4000,  4000),
  (9007, 9006, 2, 1, 6000,  6000);

-- ---------- Envíos (1 por venta/pedido como máximo) ----------
INSERT INTO envio (id, pedido_id, venta_id, empresa_envio_id, barrio_id, repartidor_id, destinatario, direccion, numero,
                   localidad, provincia, cp, costo, estado, creado_en) VALUES
  (9001, NULL, 9002, 9005, 3,    9001, 'Ana', 'Calle 1', '100', 'Centro de la ciudad', NULL, NULL, 2500, 'entregado', '2026-10-06 11:00:00'),
  (9002, NULL, 9003, 2,    NULL, NULL, 'Juan', 'Calle 2', '200', 'Formosa', 'Formosa', '3600', 3500, 'pendiente', '2026-10-07 12:00:00'),
  -- misma localidad con otro formato (mayúsculas/espacios): debe agruparse con "Formosa".
  (9003, 9001, NULL, 3,    NULL, NULL, 'Ana', 'Calle 3', '300', ' formosa ', 'Formosa', '3600', 4800, 'entregado', '2026-10-10 09:00:00'),
  (9004, 9002, NULL, 9005, 1,    NULL, 'Juan', 'Calle 4', '400', 'Circuito 5', NULL, NULL, 5000, 'pendiente', '2026-10-12 09:00:00'),
  -- envío de un pedido CANCELADO: cuenta como cancelado aunque el envío diga pendiente.
  (9005, 9003, NULL, 4,    NULL, NULL, 'Beto', 'Calle 5', '500', 'Clorinda', 'Formosa', '3610', 5200, 'pendiente', '2026-10-14 09:00:00'),
  -- envío SIN localidad cargada → "Sin localidad identificada".
  (9006, 9004, NULL, 2,    NULL, NULL, 'Beto', 'Calle 6', '600', NULL, NULL, NULL, 3500, 'despachado', '2026-10-15 09:00:00'),
  -- retiro en local → zona "Retiro / venta en el local", no cuenta como entrega.
  (9007, 9005, NULL, 1,    NULL, NULL, NULL, 'Retiro en local', NULL, NULL, NULL, NULL, 0, 'entregado', '2026-10-16 09:00:00'),
  -- septiembre: "FORMOSA" en mayúsculas → misma zona para la variación contra el período anterior.
  (9008, NULL, 9005, 2,    NULL, NULL, 'Ana', 'Calle 1', '100', 'FORMOSA', 'Formosa', '3600', 3500, 'entregado', '2026-09-10 10:00:00'),
  (9009, 9006, NULL, 9005, 3,    9001, 'Juan', 'Calle 4', '400', 'Centro de la ciudad', NULL, NULL, 2500, 'entregado', '2026-09-12 09:00:00');

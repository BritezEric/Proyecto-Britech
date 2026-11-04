-- ============================================================
-- Datos de PRUEBA de los Reportes Operativos (todo el 20/10/2026).
-- SOLO para una base de prueba (ej: britech_reportes_test). El runner
-- tests/reportes_operativos_test.php se niega a correr sobre otra base.
-- Usa ids 91xx para no pisar datos; es re-ejecutable (borra y vuelve a cargar).
-- Requiere database/schema_reportes_operativos.sql aplicado (envio.asignado_en).
-- Los valores esperados (calculados a mano) están en el runner.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM reclamo_mensaje WHERE reclamo_id BETWEEN 9100 AND 9199;
DELETE FROM reclamo         WHERE id BETWEEN 9100 AND 9199;
DELETE FROM envio           WHERE id BETWEEN 9100 AND 9199;
DELETE FROM venta_anulacion WHERE id BETWEEN 9100 AND 9199;
DELETE FROM pago            WHERE id BETWEEN 9100 AND 9199;
DELETE FROM venta_detalle   WHERE id BETWEEN 9100 AND 9199;
DELETE FROM pedido_detalle  WHERE id BETWEEN 9100 AND 9199;
DELETE FROM venta           WHERE id BETWEEN 9100 AND 9199;
DELETE FROM pedido          WHERE id BETWEEN 9100 AND 9199;
DELETE FROM caja_movimiento WHERE id BETWEEN 9100 AND 9199;
DELETE FROM caja            WHERE id BETWEEN 9100 AND 9199;
DELETE FROM repartidor      WHERE id = 9140;
DELETE FROM empresa_envio   WHERE id IN (9150, 9151);
DELETE FROM barrio          WHERE id IN (9130, 9131);
DELETE FROM cliente         WHERE id IN (9120, 9121);
DELETE FROM usuario         WHERE id IN (9110, 9111);
SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO usuario (id, nombre, email, password_hash, rol_id, activo, email_verificado) VALUES
  (9110, 'Cajero Uno', 'cajero.uno@britech.test', 'x', (SELECT id FROM rol WHERE nombre = 'vendedor'), 1, 1),
  (9111, 'Cajera Dos', 'cajera.dos@britech.test', 'x', (SELECT id FROM rol WHERE nombre = 'vendedor'), 1, 1);
INSERT INTO cliente (id, nombre, email, lista_precio_id, activo) VALUES
  (9120, 'Carla Operativa', 'carla@britech.test', 1, 1),
  (9121, 'Diego Operativo', NULL, 1, 1);
INSERT INTO barrio (id, nombre, costo, activo) VALUES (9130, 'Barrio Test Norte', 3000, 1), (9131, 'Barrio Test Sur', 2000, 1);
INSERT INTO repartidor (id, nombre, telefono, activo) VALUES (9140, 'Repartidor Operativo', NULL, 1);
INSERT INTO empresa_envio (id, nombre, costo_base, es_retiro, es_moto, activo) VALUES
  (9150, 'Moto (test op)', 0, 0, 1, 1),
  (9151, 'Retiro (test op)', 0, 1, 0, 1);

-- ---------- Cajas ----------
-- 9101: Cajero Uno. Esperado = 1000 + efectivo 9500 − retiro 500 = 10000; contó 9500 → faltante −500.
-- 9102: Cajera Dos. Esperado = 500 + efectivo 3000 = 3500; contó 3700 → sobrante +200.
-- 9103: Cajero Uno, sigue ABIERTA. Esperado en vivo = 200 + 800 = 1000.
INSERT INTO caja (id, usuario_id, monto_apertura, abierta_en, estado, monto_contado, monto_esperado, diferencia, cerrada_en) VALUES
  (9101, 9110, 1000, '2026-10-20 08:00:00', 'cerrada', 9500, 10000, -500, '2026-10-20 14:00:00'),
  (9102, 9111,  500, '2026-10-20 08:30:00', 'cerrada', 3700,  3500,  200, '2026-10-20 15:00:00'),
  (9103, 9110,  200, '2026-10-20 18:00:00', 'abierta', NULL,  NULL,  NULL, NULL);
INSERT INTO caja_movimiento (id, caja_id, tipo, monto, motivo, usuario_id, creado_en) VALUES
  (9101, 9101, 'retiro', 500, 'Pago a proveedor', 9110, '2026-10-20 11:00:00');

-- ---------- Ventas del POS ----------
INSERT INTO venta (id, numero, cliente_id, usuario_id, caja_id, subtotal, descuento, total, estado, creado_en) VALUES
  (9101, 'T-V9101', 9120, 9110, 9101, 6000, 0, 6000, 'registrada', '2026-10-20 09:10:00'),
  (9102, 'T-V9102', 9121, 9110, 9101, 5000, 0, 5000, 'registrada', '2026-10-20 09:40:00'),  -- pago dividido
  (9103, 'T-V9103', 9120, 9110, 9101, 2000, 0, 2000, 'anulada',    '2026-10-20 10:00:00'),
  (9104, 'T-V9104', 9121, 9111, 9102, 3000, 0, 3000, 'registrada', '2026-10-20 10:30:00'),
  (9105, 'T-V9105', 9120, 9111, 9102, 1000, 0, 4000, 'registrada', '2026-10-20 12:00:00'),  -- con envío moto
  (9106, 'T-V9106', 9121, 9110, 9103,  800, 0,  800, 'registrada', '2026-10-20 18:30:00');
INSERT INTO venta_detalle (id, venta_id, producto_id, cantidad, precio_unitario, subtotal) VALUES
  (9101, 9101, 1, 1, 6000, 6000), (9102, 9102, 1, 1, 5000, 5000), (9103, 9103, 1, 1, 2000, 2000),
  (9104, 9104, 1, 1, 3000, 3000), (9105, 9105, 1, 1, 1000, 1000), (9106, 9106, 1, 1, 800, 800);
INSERT INTO pago (id, venta_id, tipo_pago_id, monto) VALUES
  (9101, 9101, (SELECT id FROM tipo_pago WHERE nombre = 'Efectivo'), 6000),
  (9102, 9102, (SELECT id FROM tipo_pago WHERE nombre = 'Efectivo'), 3500),
  (9103, 9102, (SELECT id FROM tipo_pago WHERE nombre = 'Transferencia'), 1500),
  (9104, 9103, (SELECT id FROM tipo_pago WHERE nombre = 'Efectivo'), 2000),
  (9105, 9104, (SELECT id FROM tipo_pago WHERE nombre = 'Efectivo'), 3000),
  (9106, 9105, (SELECT id FROM tipo_pago WHERE nombre = 'Transferencia'), 4000),
  (9107, 9106, (SELECT id FROM tipo_pago WHERE nombre = 'Efectivo'), 800);
INSERT INTO venta_anulacion (id, venta_id, usuario_id, motivo, creado_en) VALUES
  (9101, 9103, 9111, 'Error de carga', '2026-10-20 10:05:00');

-- ---------- Pedidos de la tienda ----------
INSERT INTO pedido (id, numero, cliente_id, estado, estado_pago, metodo_pago, mp_payment_id, total, creado_en) VALUES
  (9101, 'T-P9101', 9120, 'preparando', 'pagado',      'transferencia', 'MP-TEST-1', 10000, '2026-10-20 09:00:00'),
  (9102, 'T-P9102', 9121, 'pendiente',  'pagado',      'transferencia', NULL,         7000, '2026-10-20 09:30:00'),
  (9103, 'T-P9103', 9120, 'pendiente',  'pendiente',   'transferencia', NULL,         5000, '2026-10-20 13:00:00'),
  (9104, 'T-P9104', 9121, 'cancelado',  'pagado',      'transferencia', NULL,         4000, '2026-10-20 13:30:00'),
  (9105, 'T-P9105', 9120, 'preparando', 'pagado',      'efectivo',      NULL,         6000, '2026-10-20 14:00:00'),
  (9106, 'T-P9106', 9121, 'preparando', 'pagado',      'transferencia', 'MP-TEST-2',  3000, '2026-10-20 10:00:00'),
  (9107, 'T-P9107', 9120, 'entregado',  'pagado',      'transferencia', NULL,         2000, '2026-10-20 11:00:00'),
  (9108, 'T-P9108', 9121, 'pendiente',  'en_revision', 'transferencia', NULL,         1500, '2026-10-20 15:00:00');
INSERT INTO pedido_detalle (id, pedido_id, producto_id, cantidad, precio_unitario, subtotal) VALUES
  (9101, 9101, 1, 2, 5000, 10000), (9102, 9102, 1, 1, 7000, 7000), (9103, 9103, 1, 1, 5000, 5000),
  (9104, 9104, 1, 1, 4000, 4000), (9105, 9105, 1, 1, 6000, 6000), (9106, 9106, 1, 1, 3000, 3000),
  (9107, 9107, 1, 1, 2000, 2000), (9108, 9108, 1, 1, 1500, 1500);

-- ---------- Envíos ----------
INSERT INTO envio (id, pedido_id, venta_id, empresa_envio_id, barrio_id, repartidor_id, asignado_en, destinatario, direccion, numero,
                   localidad, costo, estado, creado_en) VALUES
  (9101, 9101, NULL, 9150, 9131, NULL, NULL,                  'Carla', 'Calle Sur',   '10', 'Barrio Test Sur',   2000, 'pendiente', '2026-10-20 09:00:00'),
  (9102, 9102, NULL, 9151, NULL, NULL, NULL,                  NULL,    'Retiro en local', NULL, NULL,                 0, 'pendiente', '2026-10-20 09:30:00'),
  (9103, 9103, NULL, 9150, 9130, NULL, NULL,                  'Carla', 'Calle Norte', '20', 'Barrio Test Norte', 3000, 'pendiente', '2026-10-20 13:00:00'),
  (9105, 9105, NULL, 9151, NULL, NULL, NULL,                  NULL,    'Retiro en local', NULL, NULL,                 0, 'despachado', '2026-10-20 14:00:00'),
  (9106, 9106, NULL, 9150, 9130, 9140, '2026-10-20 10:45:00', 'Diego', 'Calle Norte', '30', 'Barrio Test Norte', 3000, 'en_camino', '2026-10-20 10:00:00'),
  (9107, 9107, NULL, 9150, 9131, 9140, NULL,                  'Carla', 'Calle Sur',   '40', 'Barrio Test Sur',   2000, 'entregado', '2026-10-20 11:00:00'),
  (9108, NULL, 9105, 9150, 9130, 9140, '2026-10-20 12:30:00', 'Carla', 'Calle Norte', '50', 'Barrio Test Norte', 3000, 'pendiente', '2026-10-20 12:00:00'),
  -- envío de una venta ANULADA: cuenta como cancelado aunque diga pendiente.
  (9109, NULL, 9103, 9150, 9131, NULL, NULL,                  'Carla', 'Calle Sur',   '60', 'Barrio Test Sur',   2000, 'pendiente', '2026-10-20 14:00:00');

-- ---------- Reclamos ----------
INSERT INTO reclamo (id, numero, cliente_id, pedido_id, asunto, estado, creado_en, actualizado_en) VALUES
  (9101, 'R-9101', 9120, 9107, 'Producto con falla',   'abierto',     '2026-10-20 12:00:00', NULL),
  (9102, 'R-9102', 9121, 9106, 'Demora en la entrega', 'en_revision', '2026-10-20 12:30:00', '2026-10-20 13:00:00'),
  (9103, 'R-9103', 9120, 9101, 'Demora en el envío',   'en_revision', '2026-10-20 13:00:00', '2026-10-20 13:10:00'),
  (9104, 'R-9104', 9121, 9102, 'Factura incorrecta',   'resuelto',    '2026-10-20 14:00:00', '2026-10-20 15:00:00'),
  (9105, 'R-9105', 9120, 9105, 'Quiero otro color',    'rechazado',   '2026-10-20 15:00:00', '2026-10-20 15:30:00');
INSERT INTO reclamo_mensaje (reclamo_id, autor, usuario_id, mensaje, creado_en) VALUES
  (9101, 'cliente', NULL, 'No enciende.', '2026-10-20 12:00:00'),
  (9102, 'cliente', NULL, '¿Cuándo llega?', '2026-10-20 12:30:00'),
  (9102, 'staff', 9111, 'Sale hoy a la tarde.', '2026-10-20 13:00:00'),
  (9103, 'cliente', NULL, 'Sigue sin llegar.', '2026-10-20 13:00:00'),
  (9103, 'staff', 9111, 'Lo estamos revisando.', '2026-10-20 13:10:00'),
  (9103, 'cliente', NULL, '¿Novedades?', '2026-10-20 16:00:00'),
  (9104, 'cliente', NULL, 'La factura tiene otro CUIT.', '2026-10-20 14:00:00'),
  (9104, 'staff', 9111, 'Ya la corregimos.', '2026-10-20 15:00:00'),
  (9105, 'cliente', NULL, 'Lo quiero en rojo.', '2026-10-20 15:00:00');

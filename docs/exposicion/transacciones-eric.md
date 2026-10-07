# Transacciones — Eric

## Qué es
Las **operaciones que mueven plata y stock**, siempre de forma **transaccional**
(o pasa todo, o no pasa nada). Cuatro flujos:
1. **Ventas (POS)** — venta en el local: descuenta stock, registra pago, liga a la caja.
2. **Compras** — orden de compra al proveedor + recepción de mercadería (suma stock).
3. **Pedidos online (checkout)** — el cliente compra en la tienda.
4. **Pago con Mercado Pago** — cobro online del pedido y confirmación automática.

El concepto central que defiendo: **transacción de base de datos** (`beginTransaction`
/ `commit` / `rollBack`) para que el stock y los registros nunca queden a medias.

## Archivos que tengo que saber

### 1) Ventas (POS)
- [`database/schema_ventas.sql`](../../database/schema_ventas.sql) — tablas base:
  `venta`, `venta_detalle`, `inventario`, `movimiento_inventario`, `pago`.
- [`app/Controllers/VentaController.php`](../../app/Controllers/VentaController.php)
  `crear()`, `listar()`, `ticket()`, `anular()`.
- [`app/Services/VentaService.php`](../../app/Services/VentaService.php) — el corazón:
  `registrar()` calcula precios/stock en backend, **exige caja abierta**, descuenta
  stock, todo dentro de una transacción; al terminar dispara `stock_bajo` si algún
  producto quedó en su mínimo. `anular()` reintegra el stock.
- [`app/Repositories/VentaRepository.php`](../../app/Repositories/VentaRepository.php)
  `crear()` (guarda la venta con su `caja_id`), detalle, pagos.
- [`app/Repositories/InventarioRepository.php`](../../app/Repositories/InventarioRepository.php)
  `descontar()` (venta) y `aumentar()` (recepción/anulación) + historial de movimientos.
- Frontend: [`public/assets/js/pos.js`](../../public/assets/js/pos.js) — buscar
  producto, carrito, cobrar, ticket.

### 2) Compras
- [`database/schema_compras.sql`](../../database/schema_compras.sql) — `orden_compra`
  (estado: enviada/parcial/recibida/anulada) + `orden_compra_detalle` (con
  `cantidad_recibida` por línea).
- [`app/Controllers/CompraController.php`](../../app/Controllers/CompraController.php)
  `listar/detalle/datosNueva/crear/recibir/anular`.
- [`app/Services/CompraService.php`](../../app/Services/CompraService.php)
  `crear()` (arma la orden), `recibir()` (recepción total o **parcial**, suma stock
  con `InventarioRepository::aumentar`), `anular()`. Todo transaccional. Al recibir
  dispara la notificación `compra_recibida`.
- [`app/Repositories/CompraRepository.php`](../../app/Repositories/CompraRepository.php)
- Frontend: [`public/assets/js/admin-compras.js`](../../public/assets/js/admin-compras.js)

### 3) Pedidos online (checkout)
- [`database/schema_tienda.sql`](../../database/schema_tienda.sql) — `pedido` + `pedido_detalle`.
- [`database/schema_pagos.sql`](../../database/schema_pagos.sql) — `estado_pago`,
  `comprobante_url`, `config_tienda` (alias/CBU).
- [`database/schema_metodo_pago.sql`](../../database/schema_metodo_pago.sql) — método elegido.
- [`app/Controllers/PedidoController.php`](../../app/Controllers/PedidoController.php)
  `crear()`/`mis()` (cliente) + `admin*` (gestión del staff) + `subirComprobante()`.
- [`app/Services/PedidoService.php`](../../app/Services/PedidoService.php)
  `crear()`: recalcula precios en backend, valida envío, arma el pedido + su envío
  en una transacción, avisa `pedido_nuevo` y manda el mail de confirmación.
- [`app/Repositories/PedidoRepository.php`](../../app/Repositories/PedidoRepository.php)
- Frontend: [`public/assets/js/tienda.js`](../../public/assets/js/tienda.js)
  (checkout, `confirmarPedido`) y [`public/assets/js/tienda-home.js`](../../public/assets/js/tienda-home.js) (ficha del producto).

### 4) Pago con Mercado Pago (Checkout Pro)
- [`database/schema_mercadopago.sql`](../../database/schema_mercadopago.sql) — `pedido.mp_payment_id`.
- [`app/Services/MercadoPagoService.php`](../../app/Services/MercadoPagoService.php)
  cliente REST (crea la **preferencia** de pago y **consulta** el pago).
- [`app/Controllers/PagoController.php`](../../app/Controllers/PagoController.php)
  `iniciar()` (arma el link de pago), `confirmar()` (vuelta del cliente) y
  `webhook()` (aviso de MP). Los dos verifican el pago **re-consultándolo a MP**.
- Frontend: en `tienda.js`, `mostrarPagoTransferencia()` (redirige a MP) y
  `procesarRetornoPago()` (pantallas de resultado éxito/pendiente/rechazado).
- Doc completo: [`docs/modulos/mercadopago.md`](../modulos/mercadopago.md).

### Rutas API — [`routes/api.php`](../../routes/api.php)
| Método | Ruta | Qué |
|---|---|---|
| POST | `/api/ventas` | registrar venta POS (línea 57) |
| POST | `/api/ventas/anular` | anular con reintegro (línea 60) |
| POST | `/api/admin/compras` · `/recibir` · `/anular` | órdenes de compra (151-153) |
| POST | `/api/tienda/pedidos` | crear pedido online (línea 181) |
| POST | `/api/tienda/pago/iniciar` · `/confirmar` · `/webhook` | pago Mercado Pago |

## Lo que sí o sí tengo que poder explicar
- **Transacción de BD**: en `VentaService::registrar()` y `CompraService::recibir()`
  uso `beginTransaction()`; si algo falla, `rollBack()` deshace todo. Ejemplo: si al
  guardar el detalle falla, no queda una venta sin líneas ni el stock descontado a medias.
- **Precio y stock en el backend**: el navegador manda solo `producto_id` y
  `cantidad`; el precio y la validación de stock los calcula el Service. Así nadie
  cambia el precio desde el front.
- **Recepción parcial de compras**: cada línea tiene `cantidad_recibida`; se puede
  recibir en varias tandas y la orden pasa a `parcial` hasta completar.
- **Seguridad del pago MP**: el monto lo fija el backend al crear la preferencia; la
  confirmación **re-consulta el pago a Mercado Pago** con nuestro token, nunca confía
  en lo que llega por la URL del navegador.

## Preguntas típicas y respuesta corta
- **¿Por qué una venta necesita caja abierta?** Para poder cuadrar el turno después
  (lo usa el cierre de caja de Franco).
- **¿Qué pasa con el stock al anular?** Se **reintegra** (vuelve a sumar), también
  dentro de una transacción.
- **¿Cómo se descuenta el stock?** `InventarioRepository::descontar()` + un registro
  en `movimiento_inventario` para tener historial.
- **¿El pedido online descuenta stock?** No: es una **solicitud** que el staff
  gestiona; el stock se toca en la venta/recepción, no al crear el pedido.

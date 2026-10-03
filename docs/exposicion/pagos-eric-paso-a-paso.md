# Pagos y registros de pago — guía paso a paso (Eric)

> **Para qué sirve este documento:** explicar, paso a paso y con los archivos
> exactos, cómo construimos los **pagos** de Britech: el **registro de pagos**, la
> **conexión con Mercado Pago** y las **vistas de los distintos pagos** (cliente y
> staff). Pensado para presentar y subir a ClickUp como recurso.

---

## 0. Panorama — hay dos "mundos" de pago

```
                       ┌─────────────────────────────┐
   VENTA EN EL LOCAL   │  POS (pos.html)             │
   (presencial)        │  paga: efectivo / transfer. │──► tabla  pago (venta_id, tipo, monto)
                       └─────────────────────────────┘        + la venta se liga a la caja

                       ┌─────────────────────────────┐
   COMPRA ONLINE       │  Tienda (tienda.html)       │
   (a distancia)       │  paga:                      │
                       │   • Transferencia manual    │──► sube comprobante → staff aprueba
                       │   • Mercado Pago (Checkout)  │──► MP cobra → se confirma solo
                       └─────────────────────────────┘        pedido.estado_pago
```

- **POS**: el pago se registra en la tabla `pago` (una venta puede tener su medio y monto).
- **Online**: el pedido tiene un **estado de pago** propio (`estado_pago`) que avanza
  según cómo pagó: a mano (transferencia + comprobante) o automático (Mercado Pago).

---

## 1. Modelo de datos (los "registros de pago")

### 1.1. Pago del POS — `database/schema_ventas.sql`
```sql
CREATE TABLE tipo_pago ( id, nombre, activo );         -- seed: 'Efectivo', 'Transferencia'
CREATE TABLE pago (
  id, venta_id, tipo_pago_id, monto                    -- qué medio y cuánto, por venta
);
```

### 1.2. Pago del pedido online
- `database/schema_pagos.sql` → agrega al `pedido`:
  - `estado_pago` ENUM(`pendiente`, `en_revision`, `pagado`, `rechazado`)
  - `comprobante_url` (la imagen/PDF que sube el cliente)
  - tabla `config_tienda` (alias / CBU / titular / banco que ve el cliente)
- `database/schema_metodo_pago.sql` → `pedido.metodo_pago` (`transferencia` | `efectivo` | `mercadopago`…)
- `database/schema_mercadopago.sql` → `pedido.mp_payment_id` (id del pago en Mercado Pago)

**Idea clave:** el "registro del pago online" **no es una tabla aparte**, son
columnas del propio `pedido`. Así cada pedido sabe **cómo**, **en qué estado** y
**con qué comprobante / id de MP** se pagó.

---

## 2. Pago en el POS (venta presencial) — paso a paso

1. El vendedor arma la venta y elige el **tipo de pago** (Efectivo / Transferencia).
2. [`app/Services/VentaService.php`](../../app/Services/VentaService.php) → `registrar()`:
   dentro de **una transacción de BD** crea la `venta`, su detalle, **descuenta stock**
   y registra el `pago` (tipo + monto). Exige **caja abierta** y liga la venta a esa caja.
3. [`app/Repositories/VentaRepository.php`](../../app/Repositories/VentaRepository.php)
   inserta la fila en `pago`.

> Defensa: si algo falla, `rollBack()` → no queda una venta sin pago ni stock a medias.

---

## 3. Pago online MANUAL (transferencia + comprobante) — paso a paso

Es el flujo por defecto cuando **no** hay Mercado Pago configurado.

1. En el checkout, el cliente elige **Transferencia**.
2. El front pide `GET /api/tienda/pago-info` →
   [`app/Controllers/ConfigController.php`](../../app/Controllers/ConfigController.php)
   `pagoInfo()` devuelve alias/CBU/titular (de `config_tienda`).
3. El cliente transfiere y **sube el comprobante** (foto o PDF) →
   `POST /api/tienda/comprobante` →
   [`app/Controllers/PedidoController.php`](../../app/Controllers/PedidoController.php)
   `subirComprobante()`: valida el archivo, lo guarda en
   `public/uploads/comprobantes/` y deja `estado_pago = 'en_revision'`.
4. Se crea una **notificación** al staff (tipo `comprobante`).
5. En el panel, el staff abre el pedido y **aprueba** o **rechaza** el pago →
   `POST /api/admin/pedidos/pago` → `estado_pago = 'pagado' | 'rechazado'`.

Estados: `pendiente → (sube comprobante) en_revision → (staff) pagado / rechazado`.

---

## 4. Conexión con Mercado Pago (Checkout Pro) — paso a paso

Si cargás el **Access Token** en el `.env`, el pago online se cobra por Mercado
Pago y se confirma **solo**. Doc extendido: [`docs/modulos/mercadopago.md`](../modulos/mercadopago.md).

### Paso 1 — Credenciales (config)
- `.env`: `MERCADOPAGO_ACCESS_TOKEN=APP_USR-...` (o `TEST-...` para probar).
- [`config/config.php`](../../config/config.php) lo expone en el bloque `mercadopago`.
- Es **opcional**: si queda vacío, `pago-info` devuelve `mp:false` y se usa el flujo manual.

### Paso 2 — Cliente HTTP de MP
[`app/Services/MercadoPagoService.php`](../../app/Services/MercadoPagoService.php) — sin SDK, dos operaciones:
```php
crearPreferencia($pedidoId, $numero, $monto, $email)  // arma el "carrito" de pago → init_point
consultarPago($paymentId)                             // ¿ese pago está approved? (verificación)
```
Detalles que resolvimos: usa el **bundle de certificados** de composer (evita el
error TLS de Windows) y **omite `notification_url` en localhost** (MP la rechaza).

### Paso 3 — Iniciar el pago
[`app/Controllers/PagoController.php`](../../app/Controllers/PagoController.php) → `iniciar()`:
```php
// valida que el pedido sea del cliente y no esté pagado
$monto = pedido.total + envio.costo;          // ← el monto lo calcula el BACKEND
$link  = MercadoPagoService->crearPreferencia(...);   // devuelve init_point
return { init_point };                         // el front redirige a esa URL
```

### Paso 4 — El cliente paga en Mercado Pago
El front hace `window.location = init_point`. El cliente paga en el sitio de MP
(cuenta de MP, transferencia o tarjeta).

### Paso 5 — Confirmación (dos caminos, misma lógica)
- **Vuelta del cliente** (`back_urls`): MP lo manda a
  `tienda.html?pago=exito|pendiente|error&payment_id=...`. El front llama
  `POST /api/tienda/pago/confirmar`.
- **Webhook** (`POST /api/tienda/pago/webhook`): aviso server-to-server de MP
  (camino de producción).

Ambos terminan en `PagoController::procesar($paymentId)`, que **re-consulta el pago
a MP** con nuestro token:
```php
approved             → estado_pago = 'pagado'  (+ guarda mp_payment_id, notifica, email)
rejected / cancelled → estado_pago = 'rechazado'
pending / in_process → estado_pago = 'en_revision'
```
Es **idempotente**: si el pedido ya está `pagado`, no re-procesa.

### Paso 6 — Rutas ([`routes/api.php`](../../routes/api.php))
| Método | Ruta | Quién |
|---|---|---|
| POST | `/api/tienda/pago/iniciar` | cliente — crea la preferencia |
| POST | `/api/tienda/pago/confirmar` | vuelta del cliente |
| POST | `/api/tienda/pago/webhook` | Mercado Pago (público) |

---

## 5. Las vistas de los diferentes pagos

### 5.1. Cliente (tienda) — [`public/assets/js/tienda.js`](../../public/assets/js/tienda.js)
1. **Elección de método** en el checkout (transferencia / efectivo; o MP si está activo).
2. **Transferencia manual**: `mostrarPagoTransferencia()` muestra alias/CBU (copiables)
   + la zona para subir el comprobante.
3. **Mercado Pago**: si `pago-info` trae `mp:true`, en vez de mostrar CBU, llama a
   `iniciar` y **redirige** al checkout de MP.
4. **Pantallas de resultado** (al volver de MP): `procesarRetornoPago()` +
   `mostrarResultadoPago()` abren el modal `#modal-pago-resultado` con la variante:
   - ✓ **Pago acreditado** (verde)
   - ⏳ **Pago pendiente** (ámbar)
   - ✕ **Pago rechazado** (rojo)
   El estado real lo decide el backend (no el `?pago` de la URL).

### 5.2. Staff (panel) — [`public/assets/js/admin.js`](../../public/assets/js/admin.js)
En el **detalle del pedido** hay un bloque **Pago** que muestra:
- **Método** (Transferencia / Efectivo / Mercado Pago).
- **Estado del pago** como badge (pendiente / en revisión / pagado / rechazado).
- El **comprobante** ("Ver comprobante") **o**, si se cobró por MP,
  "💳 Cobrado con Mercado Pago · pago #id".
- Botones **Aprobar / Rechazar** (para el flujo manual) →
  `POST /api/admin/pedidos/pago`.

---

## 6. Estados del pago (para el pizarrón)

```
                 sube comprobante              staff aprueba
   pendiente ───────────────────► en_revision ──────────────► pagado
      │                               │  staff rechaza
      │ Mercado Pago (approved)       └──────────────────────► rechazado
      └───────────────────────────────────────────────────►  pagado
            MP (pending) → en_revision   MP (rejected) → rechazado
```

---

## 7. Seguridad (lo que hay que poder defender)
- **El monto lo fija el backend** al crear la preferencia; el navegador no lo puede tocar.
- **Verificación server-to-server**: nunca confiamos en lo que llega por la URL o el
  webhook; re-consultamos el pago a MP con nuestro token. Un `payment_id` falso no sirve.
- **El Access Token es secreto**: va en `.env` (gitignored), nunca al front ni al repo.
- **SQL preparado** (PDO) en todos los repositorios.
- **El comprobante** se valida por contenido (imagen o PDF) y por tamaño.

---

## 8. Cómo demostrarlo (guion para la presentación)
1. Cargar el **Access Token de prueba** (`TEST-...`) en `.env` y reiniciar el server.
2. En la tienda, iniciar sesión, agregar un producto y hacer checkout → **Mercado Pago**.
3. Pagar con una **tarjeta de prueba** de MP (titular `APRO` para aprobar).
4. Mostrar la **pantalla de resultado** (✓ acreditado) al volver.
5. En el panel → Pedidos → el pedido quedó **pagado** con "💳 Cobrado con Mercado Pago".
6. Repetir con un pedido por **transferencia manual**: subir comprobante → aprobar.

---

## 9. Recursos — archivos clave
| Capa | Archivo |
|---|---|
| Config | `config/config.php`, `.env.example` |
| Schemas | `database/schema_ventas.sql` (pago/tipo_pago), `schema_pagos.sql`, `schema_metodo_pago.sql`, `schema_mercadopago.sql` |
| MP (servicio) | `app/Services/MercadoPagoService.php` |
| Controlador pago online | `app/Controllers/PagoController.php` |
| Pedido / comprobante / estado | `app/Controllers/PedidoController.php`, `app/Repositories/PedidoRepository.php` |
| Pago POS | `app/Services/VentaService.php`, `app/Repositories/VentaRepository.php` |
| Config tienda (alias/CBU) | `app/Controllers/ConfigController.php` |
| Front cliente | `public/assets/js/tienda.js`, `public/tienda.html` |
| Front staff | `public/assets/js/admin.js` |
| Rutas | `routes/api.php` |

---

## 10. Preguntas típicas y respuesta corta
- **¿Dónde se guarda el pago de un pedido online?** En columnas del propio `pedido`
  (`estado_pago`, `metodo_pago`, `comprobante_url`, `mp_payment_id`). El del POS, en `pago`.
- **¿Cómo sabés que un pago de MP es real?** Re-consultando el pago a MP con el token;
  solo si viene `approved` lo marco `pagado`.
- **¿Qué pasa si el webhook no llega (localhost)?** La confirmación entra por la
  vuelta del cliente; en producción (HTTPS) el webhook es el camino principal.
- **¿Por qué el monto se calcula en el backend?** Para que nadie pague menos
  cambiando el precio desde el navegador.
- **¿Es obligatorio Mercado Pago?** No: si no hay token, el checkout usa el flujo
  manual de transferencia + comprobante sin cambios.

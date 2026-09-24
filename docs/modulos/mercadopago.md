# Módulo: Pago online con Mercado Pago (Checkout Pro)

> Estado: **construido** (2026-09-22). Opcional: se activa cargando el access
> token en `.env`. Sin token, el checkout usa el flujo manual (alias/CBU +
> comprobante) sin cambios.

## Problema
El pago online era manual: el cliente veía el alias/CBU, transfería, subía un
comprobante y el admin lo aprobaba a mano. Se quiso cobrar de verdad, con
acreditación automática, usando **Mercado Pago**.

## Decisión
**Checkout Pro** vía la API REST (sin el SDK: son dos endpoints). El backend crea
una **preferencia** con el monto que él mismo calcula y manda al cliente al
`init_point` de MP. MP cobra y avisa; el backend **re-consulta el pago a MP** con
el access token antes de marcarlo pagado. Nunca se confía en el navegador.

Es **opcional como Auth0**: si `MERCADOPAGO_ACCESS_TOKEN` está vacío, `pago-info`
devuelve `mp:false` y el checkout sigue con transferencia manual.

## Flujo
1. **Crear pedido** (igual que antes): el cliente confirma la compra → se crea el
   pedido `pendiente`.
2. **Iniciar pago** — `POST /api/tienda/pago/iniciar {pedido_id}` (cliente):
   valida que el pedido sea suyo y no esté pagado, calcula el monto
   (productos + envío) y crea la preferencia. Devuelve `init_point`; el front
   redirige ahí.
3. **Cobro en MP**: el cliente paga en el checkout de Mercado Pago.
4. **Confirmación** (dos vías, ambas terminan en el mismo `procesar()`):
   - **Vuelta del cliente** (`back_url`): MP redirige a una de tres URLs según el
     resultado — `tienda.html?pago=exito | pendiente | error` (+ `payment_id`).
     El front llama `POST /api/tienda/pago/confirmar {payment_id}` para verificar
     y muestra la **pantalla de resultado** correspondiente (`#modal-pago-resultado`:
     acreditado ✓ / pendiente ⏳ / rechazado ✕). El `?pago` solo elige la pantalla
     inicial; el estado real lo decide el backend.
   - **Webhook** (`POST /api/tienda/pago/webhook`, público): notificación
     server-to-server de MP.
   En ambos, el backend consulta el pago a MP: si `approved` → pedido `pagado`
   (guarda `mp_payment_id`) + notificación; si `rejected/cancelled` → `rechazado`;
   si pendiente → `en_revision`. Es **idempotente** (no re-procesa un pedido ya
   pagado).

## Seguridad
- El **monto lo fija el backend** al crear la preferencia; el navegador no puede
  alterarlo.
- La confirmación **re-consulta el pago a MP** con nuestro access token: un
  webhook o `payment_id` falso no sirve (MP devuelve 404 o un pago que no es
  `approved`). Por eso no hace falta validar la firma del webhook para este alcance.
  <!-- ponytail: si se quiere endurecer, validar la cabecera x-signature de MP. -->
- El access token es secreto: va en `.env` (gitignored), nunca al front ni al repo.

## Localhost vs producción
En `localhost` el webhook de MP no llega (no hay URL pública): la confirmación se
apoya en la vuelta del cliente. En producción, con HTTPS, el webhook es el camino
principal. Ambos hacen lo mismo.

## API
| Método | Ruta | Quién |
|---|---|---|
| POST | `/api/tienda/pago/iniciar`   | cliente — crea preferencia, devuelve `init_point` |
| POST | `/api/tienda/pago/confirmar` | vuelta del cliente — confirma por `payment_id` |
| POST | `/api/tienda/pago/webhook`   | Mercado Pago (público) |

## Archivos
- `config/config.php` (bloque `mercadopago`), `.env.example` (`MERCADOPAGO_ACCESS_TOKEN`)
- `database/schema_mercadopago.sql` (columna `pedido.mp_payment_id`)
- `app/Services/MercadoPagoService.php` — cliente REST (crear preferencia / consultar pago)
- `app/Controllers/PagoController.php` — iniciar / confirmar / webhook
- `app/Repositories/PedidoRepository.php::pagarConMercadoPago()`
- `app/Controllers/ConfigController.php::pagoInfo()` (flag `mp`)
- Front: `public/assets/js/tienda.js` (redirección al init_point + `procesarRetornoPago()`)

## Pendiente / futuro
- Validar la firma (`x-signature`) del webhook para producción.
- Mostrar en la tienda un estado "pago pendiente de acreditación" más explícito.

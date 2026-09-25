# Notificaciones — Tobi

## Qué es
El sistema de **avisos del panel** (la campana 🔔 arriba a la derecha). Cuando pasa
algo que el equipo tiene que ver —entra un pedido, suben un comprobante, baja el
stock, se recibe mercadería, llega un reclamo o una solicitud mayorista— se crea
una **notificación**. El staff la ve en la campana, con un contador de no leídas, y
al tocarla lo lleva a la sección correspondiente. Se pueden marcar como leídas.

## Archivos que tengo que saber

### Base de datos
- [`database/schema_notificaciones.sql`](../../database/schema_notificaciones.sql)
  Tabla `notificacion`: `tipo`, `titulo`, `ir` (a qué sección va), `ref_id` (id del
  pedido/reclamo/etc.), `leida`, `creado_en`.

### Backend
- [`app/Controllers/NotificacionController.php`](../../app/Controllers/NotificacionController.php)
  Tres acciones: `listar()` (las no leídas + contador), `leer()` (marca una),
  `leerTodas()`. Todas chequean `Session::esStaff()` (admin o vendedor).
- [`app/Repositories/NotificacionRepository.php`](../../app/Repositories/NotificacionRepository.php)
  El SQL: `crear()`, `ultimas()`, `contarNoLeidas()`, `marcarLeida()`,
  `marcarTodasLeidas()`, y `existeNoLeida(tipo, refId)` — que sirve para **no
  repetir** el mismo aviso.

### Frontend (panel)
- [`public/assets/js/admin.js`](../../public/assets/js/admin.js) — sección
  **"Novedades / avisos (campana...)"** (desde la línea ~1070):
  - `NOTI_IC` (línea 1071): el emoji de cada tipo de aviso.
  - `cargarNotificaciones()` (línea ~1080): pide las notis y pinta la campana + el badge.
  - Refresco automático cada 60 s: `setInterval(cargarNotificaciones, 60000)` (línea ~1217).
- El HTML de la campana y el panel están en `public/admin.html` (`#noti-panel`, badge).

### Rutas API — [`routes/api.php`](../../routes/api.php) (líneas 66-68)
| Método | Ruta | Acción |
|---|---|---|
| GET | `/api/admin/notificaciones` | listar no leídas + contador |
| POST | `/api/admin/notificaciones/leer` | marcar una como leída |
| POST | `/api/admin/notificaciones/leer-todas` | marcar todas |

## Dónde se DISPARAN las notificaciones
Esto es clave: la notificación **se guarda desde el módulo que produce el evento**
(no desde el mío), llamando a `NotificacionRepository->crear(tipo, titulo, ir, refId)`.
Los 6 tipos y de dónde salen:

| Tipo | Se crea en | Cuándo |
|---|---|---|
| `pedido_nuevo` | `app/Services/PedidoService.php:142` | el cliente confirma un pedido online |
| `comprobante` | `app/Controllers/PedidoController.php:98` y `app/Controllers/PagoController.php:104` | sube comprobante / se aprueba el pago por Mercado Pago |
| `stock_bajo` | `app/Services/VentaService.php:191` | una venta deja un producto en/bajo su stock mínimo |
| `compra_recibida` | `app/Services/CompraService.php:140` | se recibe mercadería de una orden de compra |
| `reclamo` | `app/Services/ReclamoService.php:44` | un cliente abre un reclamo |
| `solicitud` | `app/Services/MayoristaService.php:44` | alguien pide cuenta mayorista |

## Flujo (para explicarlo)
1. Pasa un evento (ej. un pedido nuevo) → ese servicio llama a `crear(...)`.
2. La noti queda en la tabla `notificacion` con `leida = 0`.
3. El panel, cada 60 s (o al abrir la campana), pega a `GET /api/admin/notificaciones`.
4. Se muestra el contador y la lista. Al tocar una → va a su sección (`ir`) y se
   marca leída con `POST .../leer`.

## Preguntas típicas y respuesta corta
- **¿Cómo evitás avisos repetidos?** Con `existeNoLeida(tipo, refId)`: antes de
  crear un `stock_bajo` de un producto, chequeo que no haya ya uno sin leer para ese
  mismo producto.
- **¿Quién puede ver las notificaciones?** Solo staff (`Session::esStaff()` = admin
  o vendedor). Un cliente nunca las ve.
- **¿Por qué guardar `ir` y `ref_id`?** Para que al hacer clic la campana lleve
  directo a la sección (`ir`) y al item (`ref_id`), sin buscar a mano.
- **¿Se actualiza sola?** Sí, un `setInterval` la refresca cada 60 segundos.

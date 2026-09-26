# Notificaciones — Tobi

## Qué es
El sistema de **avisos** con campana 🔔, en **dos frentes**: el **panel** (staff) y
la **tienda** (cliente). Cada evento del sistema genera una **notificación** dirigida
a quien corresponde: al staff (pedido nuevo, stock bajo, compra recibida, cierre de
caja, reclamo…) y/o al cliente (pedido confirmado, pago aprobado, envío en camino,
respuesta a su reclamo…). Cada persona ve su propia campana con contador de no
leídas; al tocar un aviso lo lleva a la sección/pedido y se marca leído.

## Archivos que tengo que saber

### Base de datos
- [`database/schema_notificaciones_v2.sql`](../../database/schema_notificaciones_v2.sql)
  Tabla `notificacion` **por destinatario**: cada fila es un aviso PARA UNA persona
  → `usuario_id` (staff) **o** `cliente_id` (cliente). Campos: `tipo`, `nivel`
  (info/exito/alerta/error), `titulo`, `ir`, `ref_id`, `leida`, `creado_en`.
  Así "leída" es **por persona** (antes era global). Avisar a un rol o a todo el
  staff = **fan-out**: se inserta una fila por cada usuario.

### Backend
- [`app/Controllers/NotificacionController.php`](../../app/Controllers/NotificacionController.php)
  **Dos bandejas**: staff (`listar/leer/leerTodas`, por `Session::usuarioId()`) y
  cliente (`listarCliente/leerCliente/leerTodasCliente`, por `Session::cliente()`).
  Cada uno ve y marca leídas **solo las suyas**.
- [`app/Repositories/NotificacionRepository.php`](../../app/Repositories/NotificacionRepository.php)
  El corazón. Para **crear** avisos según destinatario:
  `crear()` (todo el staff, fan-out), `crearRol($rolId, …)`, `crearUsuario($id, …)`,
  `crearCliente($id, …)`. Para **leer**: `paraUsuario/contarUsuario/marcar…Usuario`
  y `paraCliente/contarCliente/marcar…Cliente`. `existeNoLeida(tipo,refId)` evita
  repetir el mismo aviso (ej. `stock_bajo`).

### Frontend — campana del **panel** (staff)
- [`public/assets/js/admin.js`](../../public/assets/js/admin.js), sección
  **"Novedades / avisos (campana...)"**: `NOTI_IC` (emojis por tipo),
  `cargarNotificaciones()` (pide + pinta + badge), refresco cada 60 s.
- HTML en `public/admin.html` (`#noti-panel`, badge).

### Frontend — campana de la **tienda** (cliente)
- [`public/assets/js/tienda.js`](../../public/assets/js/tienda.js), sección
  **"Notificaciones del cliente (campana)"**: `NOTI_IC_CLI`, `cargarNotisCliente()`,
  refresco cada 60 s. Se muestra solo con cliente logueado; los avisos con nivel se
  colorean (barra izquierda verde/ámbar/rojo).
- HTML en `public/tienda.html` (`#btn-noti-cli`, `#noti-cli-panel`), estilos en
  `public/assets/css/tienda.css`.

### Rutas API — [`routes/api.php`](../../routes/api.php)
| Método | Ruta | Quién |
|---|---|---|
| GET/POST | `/api/admin/notificaciones` · `/leer` · `/leer-todas` | staff |
| GET/POST | `/api/tienda/notificaciones` · `/leer` · `/leer-todas` | cliente |

## Dónde se DISPARAN las notificaciones
Esto es clave: la notificación **se guarda desde el módulo que produce el evento**
(no desde el mío), llamando a `NotificacionRepository->crear(tipo, titulo, ir, refId)`.
Los 6 tipos y de dónde salen:

**Al staff** (bandeja del panel):

| Tipo | Se crea en | Cuándo |
|---|---|---|
| `pedido_nuevo` | `PedidoService` | el cliente confirma un pedido online |
| `comprobante` | `PedidoController` / `PagoController` | sube comprobante / se aprueba el pago MP |
| `stock_bajo` | `VentaService` | una venta deja un producto en/bajo su stock mínimo |
| `compra_creada` / `compra_recibida` / `compra_anulada` | `CompraService` | ciclo de la orden de compra |
| `venta_anulada` | `VentaService` | se anula una venta |
| `caja_cierre` | `CajaService` (a rol admin) | se cierra una caja (alerta si no cuadra) |
| `reclamo` | `ReclamoService` | un cliente abre un reclamo |
| `solicitud` | `MayoristaService` | alguien pide cuenta mayorista |

**Al cliente** (campana de la tienda):

| Tipo | Se crea en | Cuándo |
|---|---|---|
| `pedido_confirmado` | `PedidoService` | se registra su pedido |
| `pago_aprobado` | `PagoController` | Mercado Pago acredita su pago |
| `pedido_estado` | `PedidoController` | el staff cambia el estado del pedido |
| `envio_estado` | `PedidoController` | despachado / en camino / entregado |
| `reclamo_respuesta` / `reclamo_estado` | `ReclamoService` | el staff responde o cambia el estado del reclamo |
| `mayorista_resuelta` | `MayoristaService` | se aprueba/rechaza su solicitud mayorista |

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
- **¿Cómo mando un aviso a un rol o a todo el staff?** Fan-out: `crear()` /
  `crearRol()` insertan **una fila por cada usuario** destinatario. Así cada uno
  tiene su propia copia y su propio "leída".
- **¿Los clientes reciben notificaciones?** Sí, ahora tienen su **campana en la
  tienda** (`crearCliente()`), además del email en eventos clave.
- **¿Por qué una fila por persona en vez de una global?** Para que "leída" sea por
  persona: si un vendedor marca leída, no le desaparece a los demás.
- **¿Para qué el `nivel`?** Colorea el aviso (info/éxito/alerta/error) y se lee de
  un vistazo.
- **¿Por qué guardar `ir` y `ref_id`?** Para que al tocar el aviso lleve directo a
  la sección (`ir`) y al item (`ref_id`).
- **¿Se actualiza sola?** Sí, un `setInterval` la refresca cada 60 segundos (panel y tienda).

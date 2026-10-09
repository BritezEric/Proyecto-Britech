# Módulo: Notificaciones

> Estado: **construido y probado** (v2, 2026-09-25). Canal in-app + email.

## Problema
El sistema tiene que avisar cosas a las personas correctas: al **staff** (entró un
pedido, bajó el stock, se recibió mercadería, se cerró una caja, llegó un reclamo…)
y al **cliente** (su pedido se confirmó, se aprobó el pago, el envío va en camino,
le respondieron el reclamo…). Cada persona debe ver **sus** avisos y marcarlos
leídos de forma independiente.

## Modelo de datos (`schema_notificaciones_v2.sql`)
Tabla **`notificacion`**, una fila **por destinatario** (fan-out):
- `usuario_id` (staff) **o** `cliente_id` (cliente) — uno de los dos.
- `tipo`, `nivel` (`info` / `exito` / `alerta` / `error`), `titulo`.
- `ir` (sección/vista destino al tocar el aviso), `ref_id` (id del pedido/reclamo/…).
- `leida`, `creado_en`.

Avisar a un rol o a todo el staff = **insertar una fila por cada usuario**. Así la
marca de "leída" es **por persona** (antes era global).

## Cómo funciona
1. **Generación**: el servicio que produce el evento llama al repositorio con el
   destinatario correcto:
   - `crear(tipo, titulo, ir, refId, nivel)` → a todo el staff (fan-out).
   - `crearRol(rolId, …)` → a un rol (ej. solo admins).
   - `crearUsuario(usuarioId, …)` → a un staff puntual.
   - `crearCliente(clienteId, …)` → a un cliente de la tienda.
   - `existeNoLeida(tipo, refId)` evita repetir el mismo aviso (ej. `stock_bajo`).
2. **Campana del panel** (staff): pide `GET /api/admin/notificaciones` (sus no
   leídas + contador), refresca cada 60 s; al tocar un aviso va a su sección y lo
   marca leído.
3. **Campana de la tienda** (cliente): igual, con `GET /api/tienda/notificaciones`;
   los avisos se colorean por nivel.
4. **Email** (hitos del cliente): además del aviso in-app, se manda correo en
   pago aprobado, reclamo respondido y envío despachado/entregado, con
   `AvisoService::email(clienteId, asunto, cuerpo)` (no rompe si el correo falla).

## Eventos cubiertos
**Staff:** `pedido_nuevo`, `comprobante`, `stock_bajo`, `compra_creada` /
`compra_recibida` / `compra_anulada`, `venta_anulada`, `caja_cierre`, `reclamo`,
`solicitud`.
**Cliente:** `pedido_confirmado`, `pago_aprobado`, `pedido_estado`, `envio_estado`,
`reclamo_respuesta` / `reclamo_estado`, `mayorista_resuelta`.

## API
| Método | Ruta | Quién |
|---|---|---|
| GET | `/api/admin/notificaciones` | staff — listar + contador |
| POST | `/api/admin/notificaciones/leer` | staff — marcar una |
| POST | `/api/admin/notificaciones/leer-todas` | staff — marcar todas |
| GET | `/api/tienda/notificaciones` | cliente — listar + contador |
| POST | `/api/tienda/notificaciones/leer` | cliente — marcar una |
| POST | `/api/tienda/notificaciones/leer-todas` | cliente — marcar todas |

## Archivos
- `database/schema_notificaciones_v2.sql`
- `app/Repositories/NotificacionRepository.php`, `app/Controllers/NotificacionController.php`
- `app/Services/AvisoService.php` (email), `app/Core/Mailer.php`
- Panel: `public/assets/js/admin.js` (sección campana) — Tienda: `public/assets/js/tienda.js` (campana cliente) + estilos en los CSS respectivos.

## Pendiente / futuro
- Notificaciones push reales del navegador (Web Push / VAPID).
- Preferencias por usuario (silenciar ciertos tipos).

> Guía de defensa por integrante: `docs/exposicion/notificaciones-tobi.md`.

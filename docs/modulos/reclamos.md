# Módulo: Reclamos de clientes

> Estado: **construido y probado** (2026-09-18).

## Problema
Un cliente puede tener un problema con un pedido (llegó dañado, falta un ítem…).
Necesita un canal para **abrir un reclamo** y seguirlo, y el negocio necesita
**gestionarlo** (responder, cambiar el estado) desde el panel.

## Modelo de datos (`schema_reclamos.sql`)
- **reclamo**: `numero` (R-000001), `cliente_id`, `pedido_id`, `asunto`,
  `estado` (abierto → en_revision → resuelto/rechazado), fechas.
- **reclamo_mensaje**: hilo de conversación — `autor` (cliente/staff), `usuario_id`
  (si responde staff), `mensaje`. La descripción inicial se guarda como el primer
  mensaje del cliente.

## Cómo funciona
1. **Abrir** (cliente, tienda) — desde *Mis pedidos*, botón **📣 Reclamar** en el
   pedido → asunto + descripción. El reclamo queda ligado a ese pedido (se valida
   que el pedido sea del cliente).
2. **Seguimiento** (cliente) — *Mi cuenta → 📣 Mis reclamos*: lista con estado y un
   **hilo** donde puede seguir escribiendo mientras el reclamo esté abierto.
3. **Gestión** (staff, panel) — vista **Reclamos** (visible para admin y vendedor):
   lista con filtro por estado, **hilo de mensajes**, respuesta y **cambio de estado**.
4. **Aviso** — al abrirse un reclamo se crea una notificación `reclamo` en la campana
   del panel (lleva a la vista Reclamos).

## Acceso
- Cliente: `/api/tienda/reclamos*` (valida `Session::cliente()` y la propiedad del pedido/reclamo).
- Staff (admin o vendedor): `/api/admin/reclamos*` (`Session::esStaff`).

## API
| Método | Ruta | Quién |
|---|---|---|
| POST | `/api/tienda/reclamos` | cliente — abrir |
| GET  | `/api/tienda/reclamos` | cliente — mis reclamos |
| GET  | `/api/tienda/reclamos/detalle?id=` | cliente — hilo (propio) |
| POST | `/api/tienda/reclamos/mensaje` | cliente — responder |
| GET  | `/api/admin/reclamos` | staff — listado |
| GET  | `/api/admin/reclamos/detalle?id=` | staff — hilo |
| POST | `/api/admin/reclamos/mensaje` | staff — responder |
| POST | `/api/admin/reclamos/estado` | staff — cambiar estado |

## Archivos
- `database/schema_reclamos.sql`
- `app/Repositories/ReclamoRepository.php`, `app/Services/ReclamoService.php`, `app/Controllers/ReclamoController.php`
- Tienda: `public/assets/js/tienda.js` (+ modales en `tienda.html`, estilos en `tienda.css`)
- Panel: `public/assets/js/admin-reclamos.js` (+ vista en `admin.html`, estilos en `admin.css`)

## Pendiente / futuro
- Adjuntar fotos al reclamo (hoy solo texto).
- Notificar al cliente por correo cuando el staff responde o cambia el estado.

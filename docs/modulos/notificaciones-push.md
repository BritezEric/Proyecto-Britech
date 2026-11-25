# Notificaciones push del navegador (Pusher Beams)

Cada aviso que hoy aparece en la **campana** (panel o tienda) también llega como
notificación del navegador/escritorio a esa misma persona, aunque no tenga la página abierta.

## Cómo funciona
- **Backend:** `NotificacionRepository::insertar()` (el único lugar por donde pasan todos
  los avisos, igual que Telegram) llama a `App\Services\BeamsService`.
  - Staff → usuario de Beams `u-<id>` (abre `admin.html#<sección>` al hacer clic).
  - Cliente → `c-<id>` (abre `tienda.html`).
  - Los push se juntan y salen **al final del pedido HTTP**, una llamada por mensaje para
    todos sus destinatarios: avisar a todo el staff no frena la venta.
  - Sin librerías: cURL a la API de Beams y JWT HS256 firmado con la Secret Key.
- **Frontend:** `public/assets/js/beams.js` (incluido en panel, POS, tienda y login) +
  `public/service-worker.js` (tiene que estar en la raíz).
  - Con sesión: si el permiso ya está dado, registra el navegador; si no, muestra el botón
    **"🔔 Activar notificaciones"** (se puede descartar con ✕).
  - Sin sesión / al salir: desvincula el navegador (deja de recibir avisos de esa cuenta).

## Seguridad
- `GET /api/beams/token?user_id=…` firma el token **solo** para el usuario de la sesión
  (401 sin sesión, 403 si pide otro id). Nadie puede suscribirse a avisos ajenos.
- La Secret Key vive solo en `.env` (nunca en git ni en el JavaScript).

## Configuración (`.env`)
```
BEAMS_INSTANCE_ID=...   # dashboard.pusher.com → Beams → la instancia
BEAMS_SECRET_KEY=...    # pestaña "Keys" de la instancia
```
Si alguna queda vacía, todo queda apagado y el sistema funciona igual que antes.

Requiere HTTPS o `127.0.0.1`/`localhost`, y que el navegador permita notificaciones.

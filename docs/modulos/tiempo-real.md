# Tiempo real con WebSockets

## 1. Qué se agregó
Britech actualiza la información en pantalla **en el momento**, sin recargar la página:

| Dónde | Qué se actualiza solo |
|---|---|
| Panel (staff) | La **campana** de avisos, al instante. |
| Panel › Inicio, Pedidos, Envíos, Productos, Gastos, Solicitudes, Repartos, Mi panel, Auditoría | La **vista abierta** se recarga cuando otro usuario cambia algo (venta, pedido, envío, stock, alerta). |
| Panel › Mapa de envíos | La **moto** se mueve cuando el repartidor manda su posición. |
| Tienda (cliente) | Su **campana** y el **seguimiento** de SU pedido en el mapa. |
| App del repartidor | El **envío asignado** aparece apenas el admin se lo deriva. |

Antes estas pantallas consultaban al servidor cada 3 a 60 segundos (*polling*). Ese refresco
sigue existiendo como respaldo: si el WebSocket no está conectado, todo funciona como antes.

## 2. Qué es un WebSocket y para qué sirve
- **El problema:** en una página web normal, el navegador pregunta y el servidor responde.
  El servidor no puede avisarle nada al navegador por su cuenta.
- **Qué cambia:** un **WebSocket** es una conexión que queda **abierta** entre el navegador y el servidor.
  Así el servidor puede **empujar** un evento en cuanto pasa algo.
- **Para qué sirve:** actualizar pantallas en tiempo real con menos tráfico que preguntar cada pocos segundos.

Se usa solo mientras el usuario tiene el sistema abierto. Los avisos con la página cerrada
son trabajo de las notificaciones push (`docs/modulos/notificaciones-push.md`).
Son dos sistemas distintos que se complementan.

## 3. Cómo funciona
```
 Navegador ──(1) GET /api/tiempo-real/ticket──► Backend PHP (sesión)  → ticket firmado (60 s)
 Navegador ──(2) ws://…:8090/?ticket=…──────► Servidor WebSocket (bin/websocket.php)
                                               valida el ticket → grupos: u:5, staff, admin…
 Operación (venta, pedido, envío…) en el backend
   └─(3) TiempoReal::emitir(['staff'], 'cambio', …) ─► puerto interno 127.0.0.1:8091
                                               └─(4) reenvía SOLO a las conexiones de esos grupos
```
1. **Ticket.** El navegador pide un ticket a la API. El backend lo arma con la identidad de la
   **sesión** (staff o cliente) o del **token del repartidor**, y lo firma con HMAC-SHA256
   (`WS_SECRET`). El ticket vence a los 60 segundos.
2. **Conexión.** El navegador abre el WebSocket con ese ticket. El servidor verifica la firma y
   el vencimiento. Después mete la conexión en sus grupos:
   - el personal: `u:<id>`, `c:<id>` o `r:<id>`;
   - `staff`, si es del personal;
   - `admin`, si es administrador.
3. **Publicación.** Cuando pasa algo en el backend, `App\Services\TiempoReal` encola el evento. Al
   terminar el pedido HTTP lo manda por el **puerto interno**, que solo escucha en `127.0.0.1` y
   exige `WS_SECRET`.
4. **Entrega.** El servidor reenvía el evento a las conexiones de los grupos destino, sin
   repetir: cada pestaña recibe un aviso.

### Eventos
| Evento | Lo recibe | Datos | Se emite en |
|---|---|---|---|
| `notificacion` | la persona del aviso (`u:` o `c:`) | tipo, título, nivel, ir | `NotificacionRepository::insertar()` |
| `cambio` | `staff` (pedidos, envíos, stock, productos) o `admin` (el resto) + quien hizo la operación | módulo, acción, id (**sin detalles**) | `Auditoria::guardar()` (todas las operaciones auditadas) |
| `alerta` | `admin` | nivel, título | `AlertaService` (solo alertas nuevas) |
| `envio` | `staff`, el repartidor del envío, el cliente dueño del pedido | id del envío/pedido, acción, estado | `EnvioRepository::derivar()` / `cambiarEstadoPorId()` |
| `envio_posicion` | `staff` (con el id del repartidor) y cada cliente con un envío en camino (**solo su pedido**) | lat, lng, pedido_id | `TrackingController::posicion()` |
| `bienvenida`, `pong`, `error` | la propia conexión | — | servidor WebSocket |

Del navegador, el servidor solo acepta `{"tipo":"ping"}` (el latido). Todo lo que **modifica datos**
sigue yendo por la API HTTP, que valida permisos. El navegador no puede elegir grupos ni mandar
eventos a otros.

## 4. Cómo se comunica con el backend
- **Navegador → backend:** `GET /api/tiempo-real/ticket` (con la cookie de sesión o con `?token=`
  en el caso del repartidor).
- **Backend → servidor WebSocket:** JSON por línea al puerto interno `127.0.0.1:WS_PORT_INTERNO`,
  con `WS_SECRET`. Si el servidor está apagado, se ignora: **una operación nunca falla por esto**.
- **Admin → estado:** `GET /api/admin/tiempo-real/estado` devuelve quién está conectado (sujeto,
  rol, desde cuándo, IP). Solo lo puede ver el admin.

## 5. Archivos nuevos
| Archivo | Función |
|---|---|
| `bin/websocket.php` | Servidor WebSocket (Workerman). Valida tickets, maneja grupos, latido, límites, conexiones y desconexiones, y abre el puerto interno. |
| `app/Services/TiempoReal.php` | Firma y valida tickets, encola y publica eventos (`emitir`, `cambio`) y consulta el estado. |
| `app/Controllers/TiempoRealController.php` | Endpoints del ticket y del estado de conexiones. |
| `public/assets/js/tiempo-real.js` | Cliente del navegador (`BritechRT`): ticket, conexión, reconexión con espera creciente, latido, eventos. |
| `INICIAR-WEBSOCKET.bat` | Arranca el servidor WebSocket en Windows. |
| `tests/tiempo_real_test.mjs` | Pruebas automáticas (13 casos). |

## 6. Archivos existentes modificados
| Archivo | Por qué |
|---|---|
| `composer.json` / `composer.lock` | Dependencia `workerman/workerman`. |
| `config/config.php`, `.env.example` | Sección `tiempo_real` y variables `WS_*`. |
| `routes/api.php` | Rutas del ticket y del estado. |
| `app/Repositories/NotificacionRepository.php` | Emite `notificacion` a la persona del aviso. |
| `app/Services/Auditoria.php` | Emite `cambio` después de cada registro de auditoría. |
| `app/Services/AlertaService.php` | Emite `alerta` a los admins cuando se crea una alerta nueva. |
| `app/Repositories/EnvioRepository.php` | Emite `envio` al asignar o cambiar el estado de un envío. |
| `app/Controllers/TrackingController.php`, `app/Repositories/TrackingRepository.php` | Emiten `envio_posicion` a staff y a cada cliente con un envío en camino. |
| `public/assets/js/admin.js`, `admin-mapa.js`, `public/admin.html`, `admin.css` | Se conecta al iniciar, refresca la vista visible, el mapa y la campana, y muestra el punto verde "en vivo" en la campana. |
| `public/assets/js/tienda.js`, `public/tienda.html` | Campana y seguimiento del cliente. |
| `public/repartidor.html` | Envío asignado en vivo. |
| `INICIAR-SERVIDOR.bat` | Abre el servidor WebSocket solo, si está configurado. |

El polling no se eliminó: se usa como respaldo cuando no hay conexión (campana cada 60 s) y baja a
un refresco de seguridad cada 30 s cuando la conexión está activa (mapa, seguimiento, repartidor).

## 7. Base de datos
**No se agregaron tablas.** Las conexiones viven en la memoria del servidor WebSocket: son
efímeras, y se reconstruyen solas cuando el navegador se reconecta. Los eventos salen de las
tablas que ya existen (`notificacion`, `auditoria`, `auditoria_alerta`, `envio`, `pedido`,
`repartidor`). Para la prueba de seguimiento hace falta `schema_tracking.sql` (ya existente).

## 8. Dependencias
- `workerman/workerman` ^5.2 (Composer). Es PHP puro y corre en Windows y en Linux sin extensiones extra.
  Composer también instala `workerman/coroutine`, que Workerman necesita.

## 9. Variables de entorno (`.env`)
| Variable | Para qué |
|---|---|
| `WS_SECRET` | Firma de los tickets y clave del puerto interno. **Vacía = tiempo real apagado.** Se genera con `php -r "echo bin2hex(random_bytes(32));"` y nunca va a git. |
| `WS_HOST` | Dónde escucha el WebSocket: `127.0.0.1` (por defecto) o `0.0.0.0` para aceptar conexiones de la red. |
| `WS_PORT` | Puerto del WebSocket (8090). |
| `WS_PORT_INTERNO` | Puerto interno del backend (8091). Solo escucha en `127.0.0.1`. |
| `WS_URL` | URL pública en producción, por ejemplo `wss://tu-dominio/ws`. Vacía = `ws://<host>:<puerto>`. |
| `WS_ORIGINS` | Orígenes permitidos, separados por coma (por ejemplo `https://tu-dominio`). Vacía = cualquiera. |

## 10. Iniciar y detener
- **Windows:** `INICIAR-SERVIDOR.bat` ya lo abre en otra ventana ("Britech - tiempo real").
  También se puede abrir solo con `INICIAR-WEBSOCKET.bat`.
  Para detenerlo: **Ctrl+C** o cerrar esa ventana.
- **Consola:** `php bin/websocket.php start`.
  En Linux además: `start -d` (en segundo plano), `status` y `stop`.

## 11. Cómo comprobar que funciona
1. Levantá la app y el WebSocket, y entrá al panel.
2. Mirá la **campana**: tiene que aparecer un **punto verde** abajo a la izquierda (conectado).
   En la consola del navegador, `BritechRT.conectado()` tiene que devolver `true`.
3. En otra ventana, entrá como vendedor y hacé una venta: la campana del admin sube sola.
   Si el admin tiene abierto Inicio o Pedidos, la vista se recarga sola.
4. En `GET /api/admin/tiempo-real/estado` se ven las conexiones activas.
5. Cerrá la ventana del WebSocket: el punto verde desaparece y la app sigue funcionando con el refresco periódico.
   Al volver a abrirla, se reconecta solo en pocos segundos.

**Prueba automática.** Se corre sobre una base `*_test` con el servidor web y el WebSocket levantados:
```
php bin/websocket.php start
DB_NAME=britech_ws_test php -S 127.0.0.1:8125 -t public public/index.php
BRITECH_TEST_URL=http://127.0.0.1:8125 node tests/tiempo_real_test.mjs
```
Los datos de prueba se definen en el encabezado del archivo. Necesita Node 22 o superior.

## 12. Seguridad
- **Identidad:** sale de la sesión o del token del repartidor; el navegador no la puede elegir.
- **Tickets:** se firman con HMAC y vencen a los 60 s. Un ticket inválido, adulterado o vencido recibe 401.
- **Quién recibe qué:** lo decide el backend al emitir:
  - el cliente solo recibe sus avisos y la posición de SU pedido;
  - las alertas de auditoría van solo a los admins;
  - los avisos de `cambio` llevan módulo e id, nunca detalles.
- **Puerto interno:** solo escucha en `127.0.0.1` y exige `WS_SECRET`.
- **Abusos:** tope de 60 mensajes por minuto por conexión, mensajes de hasta 2 KB, hasta 10
  conexiones por persona, y cierre de las conexiones que no responden el latido en 70 s.
- **Robustez:** cualquier error en un callback se captura y se registra; nunca apaga el servidor.

## 13. Producción (requisitos)
- **Proceso permanente:** correr `bin/websocket.php` siempre encendido (systemd o supervisor en
  Linux, `start -d`) en el mismo servidor que el backend PHP, porque el puerto interno es local.
- **Servidor web:** usar **HTTPS** y publicar el WebSocket como **WSS** detrás de nginx (proxy de
  `/ws` hacia `127.0.0.1:8090`, con `Upgrade`/`Connection`). Después configurar `WS_URL=wss://…/ws`
  y `WS_ORIGINS=https://…`.
- **Firewall:** no exponer `WS_PORT_INTERNO` a internet.
- **Más de un proceso:** si alguna vez se necesitan varios procesos o servidores, las conexiones
  deberían compartirse con un intermediario (por ejemplo Redis pub/sub). Con un proceso alcanza
  para muchos cientos de conexiones.

## 14. Limitaciones conocidas
- **Operaciones que se deshacen:** un evento `cambio` se envía al terminar el pedido HTTP. Si una
  operación se deshace (rollback), puede llegar un aviso de refresco de más: la pantalla
  recarga y no ve cambios. No muestra datos falsos.
- **Seguimiento público:** el link sin login (`seguimiento.html`) no tiene identidad de usuario,
  así que sigue con el refresco periódico.
- **Eventos perdidos:** los eventos que pasaron mientras un navegador estaba desconectado no se
  reenvían. Al reconectarse, la pantalla recarga sus datos (evento `reconectado`).

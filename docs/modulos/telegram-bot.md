# Bot de Telegram — avisos del sistema y asistente

Bot interno de Telegram para el equipo (vendedores y administradores). Cumple dos funciones:

1. **Avisos automáticos del sistema** (ventas, stock, caja, pedidos, envíos, reclamos, etc.),
   reenviados por Telegram a cada persona del staff según su rol.
2. **Asistente**: el staff vinculado puede preguntarle al bot por **texto o nota de voz** y
   obtener respuestas usando el mismo cerebro del asistente de voz del POS (n8n + Gemini/Groq).

El bot interno es para el **equipo**. Los avisos al **cliente** van por WhatsApp (Cloud API) y
correo, no por Telegram. Telegram no banea bots, así que es el canal ideal para lo interno.

> Rama: `traking`. Commits principales: `5a5d8de` → `d7f7bf6`.

---

## 1. Arquitectura y archivos

El bot se apoya en el sistema de notificaciones que ya existía (`NotificacionRepository`, fan-out
por persona/rol) y le agrega una capa de reenvío a Telegram.

| Archivo | Qué hace |
|---|---|
| `app/Services/TelegramService.php` | Cliente de la Bot API de Meta/Telegram: enviar mensajes, procesar updates (vinculación + asistente), transcribir audios, etiquetas de categoría. |
| `app/Repositories/NotificacionRepository.php` | En `insertar()` (el embudo único de toda notificación), si el aviso es para un usuario staff, lo reenvía a su Telegram. **Un solo enganche cubre todos los avisos, presentes y futuros.** |
| `app/Repositories/UsuarioRepository.php` | Métodos Telegram: guardar/buscar código de vinculación, guardar `chat_id`, buscar usuario por `chat_id`. |
| `app/Controllers/TelegramController.php` | `vincular()` (da el código al panel), `estado()` (¿vinculado?), `webhook()` (recibe los mensajes en el server). |
| `app/Services/ResumenService.php` | Arma el resumen de caja (diario/semanal) y lo manda a los admin. |
| `public/admin.html` + `public/assets/js/admin.js` | Botón **"Conectar Telegram"** en el pie del menú + modal con el código. |
| `database/schema_telegram.sql` | Columnas `usuario.telegram_chat_id` y `usuario.telegram_codigo` (idempotente). |
| `tools/telegram-sync.php` | Polling local (sin webhook): escucha los mensajes del bot y los procesa. |
| `tools/telegram-webhook.php` | Configura el webhook del bot en el server (`set` / `info` / `delete`). |
| `tools/resumen.php` | Dispara el resumen de caja (`dia` / `semana`). Para programar. |
| `tests/telegram_test.php` | Pruebas (vinculación, no-op sin token, aislamiento). 5 OK. |

Rutas (en `routes/api.php`):

- `GET  /api/admin/telegram/vincular` — (staff) genera el código y devuelve el link del bot.
- `GET  /api/admin/telegram/estado` — (staff) dice si el usuario ya está vinculado.
- `POST /api/telegram/webhook` — público; lo llama Telegram en el server.

---

## 2. Configuración (`.env`)

```
TG_TOKEN=   # token que da @BotFather al crear el bot
TG_BOT=     # usuario del bot sin @ (ej. britech_avisos_bot) — para armar el link de vinculación
```

Si `TG_TOKEN` queda vacío, **todo el bot queda desactivado (no-op)**: nada se rompe, los avisos
siguen cayendo en la campana in-app como siempre.

El asistente reusa las claves del asistente de voz (ya en `.env`): `GEMINI_API_KEY`,
`GROQ_API_KEY` (transcripción) y `VOZ_N8N_URL` (el cerebro). Ver `docs/modulos/` del asistente de voz.

### Crear el bot (una vez)

1. En Telegram, chat con **@BotFather** → `/newbot`.
2. Nombre (display) y luego **username** (tiene que terminar en `bot`, ej. `britech_avisos_bot`).
3. BotFather devuelve el **token**. Va en `TG_TOKEN`; el username en `TG_BOT`.
4. Validar: `php -r 'require "config/config.php"; ...getMe...'` o mandarle algo al bot.

El bot actual es **@britech_avisos_bot**.

---

## 3. Vinculación de un usuario

Cada persona del staff vincula **su** Telegram una vez:

1. En el panel (`admin.html`) → pie del menú → **Conectar Telegram**.
2. El modal muestra un **código de 8 caracteres** (con botón Copiar) y el link del bot.
3. Abrir el bot, presionar **Iniciar / Start**, y **pegar el código** en el chat.
4. El bot responde *"listo <nombre>"* y guarda el `chat_id` del usuario.

El bot acepta el código de dos formas: `/start CODIGO` (deep link) **o el código pegado solo**.
Así funciona aunque el usuario toque START sin el link (el deep link no siempre abre en todos los
navegadores).

Para que la vinculación se complete **el bot tiene que estar recibiendo mensajes**:

- **Local**: correr el polling → `php tools/telegram-sync.php --loop` (queda escuchando).
- **Server**: configurar el webhook una vez (ver sección 6), y ya no hace falta polling.

---

## 4. Avisos automáticos (catálogo)

Todo aviso de staff que crea el sistema se reenvía a Telegram **ya ruteado por rol** (a quién le
toca lo decide `NotificacionRepository`). Cada mensaje va encabezado por una **etiqueta de categoría**
para entenderlo de un vistazo:

| Tipo | Etiqueta | Cuándo |
|---|---|---|
| `pedido_nuevo` | 🛒 Pedido nuevo | compra online nueva |
| `comprobante` / `pago_aprobado` | 💳 Pago | pago MP aprobado / comprobante por revisar |
| `venta_nueva` | 🧾 Venta | **cada venta del local (POS)** → a los admin |
| `venta_anulada` | 🧾 Venta anulada | anulación de venta |
| `stock_bajo` | ⚠️ Stock bajo | producto en o bajo su mínimo |
| `caja_cierre` / `caja_cierre_detalle` | 💰 Caja | cierre de caja (admin + el vendedor que cerró) |
| `caja_resumen` | 💰 Resumen de caja | resumen diario/semanal (ver sección 5) |
| `compra_creada` / `compra_recibida` / `compra_anulada` | 🏷️ Compra | órdenes de compra |
| `envio_asignado` | 📦 Envío | cadete auto-asignado a un pedido de moto |
| `envio_entregado` | 📦 Entregado | el cadete marcó el envío como entregado |
| `reclamo` | 📣 Reclamo | reclamo nuevo del cliente |
| `solicitud` | 👤 Mayorista | pedido de cuenta mayorista |
| otros | 🔔 Sistema | cualquier otro aviso |

Las etiquetas se mapean en `TelegramService::etiqueta()` según el `tipo` del aviso. Para cambiarlas
a texto plano (sin emoji), editar ese mapa.

---

## 5. Resumen de caja (diario y semanal)

`ResumenService` junta las ventas del período (local + online pagado) y arma un resumen con:

- cantidad de ventas y total vendido,
- desglose por medio de pago,
- total de ventas online pagadas.

Se manda a los **admin** (campana + Telegram, etiqueta 💰 Resumen de caja). Reusa
`ReporteOperativoRepository::pagos()`.

Disparar a mano:

```
php tools/resumen.php dia       # consolidado del día (correr al cierre, ej. 23:00)
php tools/resumen.php semana    # últimos 7 días (correr domingo a la noche)
```

Para que salga solo hay que **programarlo**:

- **Windows (local)**: Programador de tareas → acción `php C:\laragon\www\Proyecto Britech\tools\resumen.php dia` a las 23:00 (y otra `semana` los domingos).
- **Server (Docker/Linux)**: cron, p. ej.
  ```
  0 23 * * *   php /app/tools/resumen.php dia
  0 23 * * 0   php /app/tools/resumen.php semana
  ```

---

## 6. El asistente (texto y audio)

El staff **ya vinculado** puede escribirle al bot o mandarle una **nota de voz**:

- **Texto** → va directo al cerebro.
- **Audio** → el bot lo descarga de Telegram (`getFile` + descarga), lo transcribe con Gemini/Groq
  (`VozService::transcribir`) y lo trata como texto. Antes de responder te muestra lo que entendió (🎤).

La pregunta va al cerebro del asistente de voz (`VozService::interpretar` → webhook de n8n), que
responde consultando la API de Britech. Hay **memoria de conversación por chat** (archivo temporal,
últimos 20 turnos) para que las repreguntas tengan contexto.

**Seguridad**: el bot **solo responde a usuarios vinculados**. El cerebro puede además registrar
ventas/compras (igual que la voz del POS), por eso no se responde a cualquiera. Si se quiere que por
Telegram sea solo consulta (sin acciones), hay que limitarlo en el cerebro (n8n).

**Requisito**: el asistente necesita **n8n levantado** (`VOZ_N8N_URL`). Si n8n está caído, el bot
transcribe el audio pero responde *"asistente no disponible"*. La transcripción funciona siempre que
`GEMINI_API_KEY` o `GROQ_API_KEY` estén seteadas.

---

## 7. Local vs server

| | Local (desarrollo) | Server (producción / expo) |
|---|---|---|
| Recibir mensajes del bot | `php tools/telegram-sync.php --loop` (polling) | **webhook**: `php tools/telegram-webhook.php set https://TU-DOMINIO/api/telegram/webhook` |
| Cerebro n8n | túnel SSH al puerto 5679 | contenedor Docker de n8n (`http://n8n:5678`) |
| Resúmenes | `tools/resumen.php` a mano o Task Scheduler | cron |

El webhook necesita una URL **HTTPS pública** (ej. el dominio fijo de ngrok del server). Una vez
seteado, no hace falta polling. Para volver a local: `php tools/telegram-webhook.php delete`.

---

## 8. Pruebas

```
DB_HOST=127.0.0.1 DB_NAME=britech_v2 php tests/telegram_test.php
```

Cubre: sin token el bot es no-op (no manda nada), `procesarUpdate` tolera updates raros, vinculación
por `/start CODIGO` y por código pegado solo, y que un código inválido no vincula a nadie. El test
fuerza `TG_TOKEN` vacío para no mandar nada real por la red.

---

## 9. Operación y problemas comunes

- **El bot no manda nada** → `TG_TOKEN` vacío, o el usuario no está vinculado (`telegram_chat_id` NULL).
- **No vincula al pegar el código** → el polling (local) o el webhook (server) no está corriendo.
- **El asistente dice "no disponible"** → n8n caído (`VOZ_N8N_URL` no responde).
- **No transcribe el audio** → faltan `GEMINI_API_KEY` / `GROQ_API_KEY`.
- **El token nunca se commitea**: vive solo en `.env` (gitignored).

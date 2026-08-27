# Módulo: Envíos, Moto Express y Repartidores

> Estado: **CONSTRUIDO Y PROBADO** (2026-08-27).
> Amplía el módulo de Envíos con **entrega local en moto por barrio**, un
> **gestor de repartidores** con su paga, un **tablero de derivación (Repartos)**,
> **métodos de pago en el checkout**, **envío también desde el POS** y utilidades
> operativas (ticket imprimible + mensaje de WhatsApp pre-armado).

---

## 1. Alcance entregado

### Moto Express (envío local por barrio)
- Nuevo medio de envío **"Moto Express"** (`empresa_envio.es_moto = 1`). Cuando el
  cliente lo elige en el checkout, en vez de pedir la dirección completa se elige un
  **barrio** (cada barrio tiene un **precio fijo**) y luego la **calle + altura**.
- Los barrios son un **ABM** (tabla `barrio`): nombre + precio + activo. Semilla:
  *Circuito 5* ($5.000), *La Nueva Formosa* ($5.000), *Centro de la ciudad* ($2.500).
- El precio del barrio es el **costo del envío** que paga el cliente **y** lo que
  cobra el repartidor por esa entrega.

### Métodos de pago en el checkout (tienda)
- El checkout de la tienda pasó a **2 pasos**: (1) entrega, (2) **método de pago**.
- Métodos: **Transferencia** (flujo completo: alias/CBU + subir comprobante),
  **Mercado Pago** y **Tarjeta** (registran la intención y muestran "coordinamos el
  pago" — todavía **sin gateway real**). Se guarda en `pedido.metodo_pago`.

### Repartidores + Repartos (derivación)
- **Gestor de Repartidores** (ABM: nombre + teléfono + activo). No son usuarios del
  sistema (no tienen login), solo se asignan a envíos.
- **Vista "Repartos"** (tablero operativo): lista de **envíos sin asignar** (de la
  tienda **y** del POS) con un desplegable para **derivar** cada uno a un repartidor,
  y tarjetas por repartidor con la cantidad de envíos **a repartir**.
- **Detalle del repartidor:** envíos **activos** (a repartir) con el producto
  comprado, dirección y **quién recibe**; botones **Marcar salida** / **Entregado**;
  **paga del día** por barrio (solo entregados) con gráfico; **ticket imprimible**
  y **mensaje de WhatsApp** pre-armado con los envíos a hacer.
- **Ticket / mensaje conciso:** solo lo que el repartidor necesita — **dónde**
  (barrio + calle/altura + referencia), **a quién entregar** (el `destinatario` del
  envío, no el titular de la cuenta; cae al cliente si está vacío) y **qué lleva**
  (productos). Sin número de pedido, costo ni totales de plata. El mensaje de
  WhatsApp usa **etiquetas de texto** (`Dirección:` / `A quién entregar:` /
  `Producto:`), no emojis, para que se vea igual en cualquier teléfono.

### Envío desde el POS
- En el cobro del POS, el vendedor puede activar **"🛵 Envío a domicilio"**: elige
  medio (incl. Moto Express + barrio, con botones) o dirección. El **repartidor se
  asigna después** desde Repartos.
- El **costo del envío se suma al total** de la venta (el ticket sube) y el pago
  debe cubrir productos + envío.

### Utilidades del panel
- **Dashboard:** botones de acceso rápido a **Envíos**, **Repartos** (con **badge**
  del número de envíos sin asignar) e **Ir al POS**; KPI **"Página hoy"** (ventas de
  la tienda online del día). Envíos y Repartos salieron del sidebar (se entra por el
  dashboard); Empleados, Repartidores y Barrios viven dentro de **Tablas**.
- **Gestor de Envíos:** columna **Comprobante** (Recibido / Sin comprobante / no
  aplica, con color) y botón **"Ver detalle"** destacado.

---

## 2. Modelo de datos

Scripts: [`database/schema_moto_barrios.sql`](../../database/schema_moto_barrios.sql),
[`database/schema_envio_venta.sql`](../../database/schema_envio_venta.sql),
[`database/schema_metodo_pago.sql`](../../database/schema_metodo_pago.sql).

**Tablas nuevas**
- `barrio` — `id, nombre, costo DECIMAL(12,2), activo, creado_en`.
- `repartidor` — `id, nombre, telefono, activo, creado_en`.

**Cambios en tablas existentes**
- `empresa_envio` — `+ es_moto TINYINT(1)` (marca el medio Moto Express).
- `pedido` — `+ metodo_pago VARCHAR(20)` (`transferencia | mercadopago | tarjeta`).
- `envio`:
  - `+ barrio_id` (FK `barrio`) — barrio elegido en el checkout (moto).
  - `+ repartidor_id` (FK `repartidor`) — quién se hace cargo (lo asigna el admin).
  - `+ venta_id` (FK `venta`, UNIQUE) y `pedido_id` pasa a **NULL-able**: un envío
    pertenece a **un pedido (tienda) O una venta (POS)**, nunca a ambos.

> Regla: los reportes de repartidor (paga, activos, sin asignar) anclan en `envio`
> y hacen `LEFT JOIN` a `pedido` **y** `venta` con `COALESCE` (número, cliente,
> total, productos), e `INNER JOIN barrio` (solo envíos de moto tienen barrio).

---

## 3. Reglas de negocio

1. **Precio del barrio = paga del repartidor.** La paga de un repartidor en un día
   es la suma de los `barrio.costo` de sus envíos en estado **entregado** ese día.
2. **No se marca "entregado" sin pago confirmado.** Si el envío es de un **pedido**
   (tienda), su `pedido.estado_pago` debe ser `pagado`. Las **ventas del POS** se
   cobran al momento → siempre habilitadas.
3. **En el POS, el envío suma al total** de la venta (sube el ticket) y el pago debe
   cuadrar con productos + envío. El costo queda registrado para logística/paga.
4. **Precio siempre del backend.** El costo de envío se recalcula en el servidor
   (barrio o costo base), nunca se confía en el navegador.
5. **"Hoy" según MySQL.** Los cortes por fecha usan `CURDATE()` de MySQL (no `date()`
   de PHP) para evitar el desfase de zona horaria cerca de medianoche.

---

## 4. Backend (capas nuevas / tocadas)

- **Repositorios:** `BarrioRepository`, `RepartidorRepository` (ABM + reportes:
  `enviosDerivados`, `enviosActivos`, `sinAsignar`, `pagaPorDia`, `serieDias`),
  `EnvioRepository` (`crear` con `venta_id`/`barrio_id`, `derivar`,
  `cambiarEstadoPorId`, join de barrio/repartidor en `dePedido`).
- **Servicios:** `BarrioService`, `RepartidorService` (ABM + `detalle` con paga y
  activos + `derivar` + `cambiarEstadoEnvio` con la validación de pago),
  `PedidoService` (rama moto: barrio + dirección), `VentaService` (`resolverEnvio`:
  valida + calcula costo, lo suma al total, inserta el envío ligado a la venta).
- **Controladores:** `BarrioController`, `RepartidorController` (ABM + `detalle` +
  `barrios` público + `sinAsignar` + `derivar` + `estado`); `PedidoController`
  (detalle incluye repartidores; `adminEnvio` acepta `repartidor_id`).

### Endpoints (todos admin salvo los públicos marcados)
```
GET  /api/tienda/barrios                 (público, checkout)
GET  /api/admin/barrios | /barrios/guardar | /barrios/baja
GET  /api/admin/repartidores | /repartidores/detalle | /repartidores/guardar | /repartidores/baja
GET  /api/admin/envios/sin-asignar
POST /api/admin/envios/derivar           { envio_id, repartidor_id }
POST /api/admin/envios/estado            { envio_id, estado }
```

---

## 5. Frontend

- **Tienda** (`tienda.js` / `tienda.css`): checkout en 2 pasos; barrio como
  **tarjetas** que colapsan al elegir (con "cambiar" para volver) + dirección;
  paso de pago con acción principal grande y "volver" discreto.
- **POS** (`pos.js` / `pos.css`): sección de envío en el cobro; **retiro** no muestra
  formulario; barrios como **botones**; el total y el ticket incluyen el envío.
- **Admin** (`admin.js`, `admin-repartidores.js`): gestores de Barrios y
  Repartidores (framework de ABM por config); vista **Repartos**; detalle del
  repartidor con activos, ticket imprimible (ventana `window.print`) y **link
  `wa.me`** con el mensaje pre-cargado; accesos + badge en el dashboard; columna
  Comprobante en Envíos; **formularios compactos** (tipografía chica y legible,
  2 columnas, mejor jerarquía).
- **Navegación:** Envíos y Repartos se entran desde los **botones del dashboard**
  y cada vista tiene un **"‹ Panel"** arriba a la izquierda para volver. Empleados,
  Repartidores y Barrios viven dentro de **Tablas**.

---

## 6. Pendientes / decisiones abiertas

- **Gateway de pago real** para Mercado Pago / Tarjeta (hoy solo registran la
  intención).
- **Zona horaria** PHP (UTC) vs MySQL (local): parcheado en el reporte de
  repartidores; revisar otras métricas por fecha (dashboard, empleados).
- **Normalización de teléfono** para WhatsApp: best-effort a formato AR (`549…`); el
  admin confirma el contacto antes de enviar.

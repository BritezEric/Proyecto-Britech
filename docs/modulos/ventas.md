# Módulo: Ventas (POS)

> Estado: **APROBADO** — decisiones cerradas.
> Base de datos implementada: ver [ventas-modelo-datos.md](ventas-modelo-datos.md) y `database/schema_ventas.sql`.
> Documentación viva: se actualiza a medida que construimos.

---

## 1. Objetivo

Permitir que un **vendedor** registre una **venta física** (mostrador / POS) de
principio a fin: elegir productos, aplicar el precio correcto según el cliente,
cobrar, **descontar el stock** y **emitir un comprobante (ticket)**.

Es el primer módulo funcional de Britech. Todo lo demás (stock, precios,
clientes, productos) lo construimos en su versión **mínima** solo para que la
venta funcione de punta a punta, y luego lo ampliamos.

---

## 2. Alcance

**Incluye (v1):**
- Venta física en el POS.
- Buscar producto por nombre o por **código de barras** (scanner).
- Carrito: agregar/quitar productos, cambiar cantidades.
- Precio automático según tipo de cliente (minorista / mayorista).
- Descuentos.
- Uno o varios **medios de pago** (efectivo, transferencia).
- Verificación y **descuento de stock** al confirmar.
- Registro de la venta.
- **Ticket interno** (no fiscal) imprimible / PDF.

**NO incluye todavía (etapas siguientes):**
- Facturación fiscal **AFIP** (se suma al final de este módulo).
- Venta online / pedidos / envíos (otro módulo).
- Devoluciones (se evalúa después de tener la venta básica).
- Caja / arqueo (existe en tu proyecto viejo; lo sumamos después).

---

## 3. Actores

| Actor | Rol en el módulo |
|---|---|
| **Vendedor** | Usa el POS para registrar ventas. |
| **Administrador** | Puede vender y además anular ventas, ver todas las ventas. |
| **Cliente** | No opera el POS. Es *a quién* se le vende. Puede ser "Consumidor Final". |

---

## 4. Requisitos funcionales (RF)

- **RF1** — El vendedor puede buscar un producto por nombre, SKU o código de barras.
- **RF2** — Al escanear un código de barras, el producto se agrega al carrito automáticamente.
- **RF3** — El sistema muestra el **stock disponible** de cada producto.
- **RF4** — El vendedor puede cambiar la cantidad de cada línea del carrito.
- **RF5** — El sistema aplica el **precio según el tipo de cliente** (minorista/mayorista).
- **RF6** — El vendedor puede aplicar descuentos **por línea** (a un producto) **y sobre el total** de la venta.
- **RF7** — El vendedor selecciona un cliente. Por defecto: **Consumidor Final**.
- **RF8** — El vendedor selecciona uno o más **medios de pago** y el monto de cada uno.
- **RF9** — Al confirmar, el sistema **verifica stock**, **registra la venta**, **descuenta stock** y **genera el ticket**, todo o nada (transacción).
- **RF10** — El sistema genera un **número de venta** único.
- **RF11** — El ticket se puede **imprimir** y/o **descargar en PDF**.
- **RF12** — **Solo el administrador** puede **anular** una venta (reintegra stock). Sin límite de tiempo, pero toda anulación exige: **motivo obligatorio**, **confirmación explícita**, y se registra **quién**, **cuándo**, la **venta original** y los **movimientos de stock** generados.
- **RF13** — Se puede **listar y buscar** ventas (con paginación).

---

## 5. Requisitos no funcionales (RNF)

- **RNF1 — Simplicidad:** código claro y legible antes que sofisticado.
- **RNF2 — Seguridad:** PDO con prepared statements, control de permisos en backend, CSRF en formularios.
- **RNF3 — Consistencia:** el descuento de stock y el registro de venta ocurren dentro de **una transacción**. Si algo falla, no se descuenta stock ni queda una venta a medias.
- **RNF4 — Rapidez de uso:** el POS debe permitir vender rápido (escanear → Enter → siguiente). Poca fricción de teclado/mouse.
- **RNF5 — Velocidad:** el listado de ventas y la búsqueda de productos usan paginación e índices.
- **RNF6 — UI/UX:** el usuario no debería navegar tanto ni hacer tantos clicks. La interfaz debe entenderse de forma rápida.

---

## 6. Reglas de negocio (RN)

- **RN1** — El **precio se congela** en la línea de la venta. Si mañana cambia el precio del producto, las ventas viejas no se alteran.
- **RN2** — El precio aplicado depende del **tipo de cliente**: minorista → lista minorista; mayorista → lista mayorista.
- **RN3** — No se puede vender más cantidad que el **stock disponible**. **Excepción:** si el producto es **sobre pedido**, sí se puede vender sin stock; esa línea **no descuenta stock**, queda marcada como `sobre_pedido` y genera una necesidad de aprovisionamiento (el módulo de compras la resuelve después).
- **RN4** — Una venta confirmada **descuenta stock** mediante un **movimiento de inventario** de tipo egreso (no se edita un número suelto).
- **RN5** — Anular una venta genera un **movimiento de inventario de reingreso** y marca la venta como `anulada` (no se borra: queda el registro).
- **RN6** — La suma de los **pagos** debe igualar el **total** de la venta.
- **RN7** — Toda venta genera **un comprobante** (por ahora, ticket interno; más adelante, factura AFIP).

---

## 7. Flujo principal (mejorado respecto a la propuesta original)

```text
1. Vendedor abre el POS  (ya está logueado → sabemos quién vende)
        ↓
2. Busca o ESCANEA un producto
        ↓
3. Producto se agrega al carrito
   - Sistema muestra stock disponible
   - Sistema calcula precio según el cliente actual
        ↓
4. (repite 2-3 para más productos, ajusta cantidades, descuentos)
        ↓
5. Selecciona cliente  (por defecto: Consumidor Final)
   - Si cambia el cliente, se recalculan los precios (minorista/mayorista)
        ↓
6. Selecciona medio(s) de pago e ingresa montos
        ↓
7. Confirma la venta
        ↓
   ┌─ TRANSACCIÓN (todo o nada) ──────────────┐
   │ 7a. Verifica stock de cada producto      │
   │ 7b. Registra venta + detalle + pagos     │
   │ 7c. Descuenta stock (movimientos egreso) │
   │ 7d. Genera número y comprobante          │
   └──────────────────────────────────────────┘
        ↓
8. Muestra/imprime el TICKET
        ↓
9. (más adelante) Si corresponde → integración AFIP
```

**Mejoras respecto al flujo original:**
- Se agrupó "registrar venta + actualizar stock + generar comprobante" dentro de **una sola transacción** (paso 7). Esto evita el bug clásico: que se descuente stock pero la venta no se guarde, o al revés.
- "Verificar stock" va **dentro** de la transacción (justo antes de descontar), no antes de armar todo. Así evitamos que dos vendedores vendan la última unidad al mismo tiempo.
- El precio se decide al agregar y **se recalcula si cambia el cliente**.

---

## 8. Flujos alternativos / excepciones

- **A1 — Producto sin stock (no sobre pedido):** el sistema avisa y no permite agregarlo.
- **A1b — Producto sobre pedido:** se agrega sin descontar stock; la línea queda como `sobre_pedido`.
- **A2 — Código de barras no encontrado:** el sistema avisa "producto no encontrado" y no agrega nada.
- **A3 — Pago insuficiente:** si la suma de pagos ≠ total, no deja confirmar.
- **A4 — Stock se agotó entre agregar y confirmar:** la verificación del paso 7a lo detecta y cancela la confirmación con un mensaje claro.
- **A5 — Anulación:** el admin anula → se reintegra stock y la venta queda marcada como anulada.

---

## 9. Modelo de datos (rebanada mínima para Ventas)

> Detalle completo con tipos y claves en [ventas-modelo-datos.md](ventas-modelo-datos.md).
> Implementado en `database/schema_ventas.sql` (base `britech_v2`).

| Entidad | Para qué | Campos clave |
|---|---|---|
| `rol` / `usuario` | Quién vende (vendedor/admin) | usuario: id, nombre, email, password_hash, rol_id, activo |
| `cliente` | A quién se le vende | id, nombre, documento, lista_precio_id |
| `producto` | Qué se vende | id, sku, **codigo_barras**, nombre, **es_sobre_pedido**, activo |
| `lista_precio` + `precio` | Precio según cliente | lista (Minorista/Mayorista); precio: producto_id, lista_precio_id, precio |
| `inventario` | Existencia actual por producto | producto_id, cantidad |
| `movimiento_inventario` | Historial de stock (ledger) | producto_id, tipo (ingreso/egreso/ajuste), cantidad, motivo, venta_id, usuario_id |
| `venta` | La transacción | id, numero, cliente_id, usuario_id, subtotal, descuento, total, estado (registrada/anulada) |
| `venta_detalle` | Líneas del carrito | id, venta_id, producto_id, cantidad, **precio_unitario (foto)**, descuento_linea, **estado (normal/sobre_pedido)**, subtotal |
| `tipo_pago` | Catálogo de medios | Efectivo, Transferencia, ... |
| `pago` | Cómo se pagó | id, venta_id, tipo_pago_id, monto |
| `comprobante` | Ticket / (luego) factura | id, venta_id, tipo, numero, fecha, *(campos AFIP nulos por ahora)* |
| `venta_anulacion` | Registro de anulaciones | id, venta_id, usuario_id (admin), motivo, fecha |

Son 14 tablas para un POS funcional, en lugar de las 43 del proyecto viejo. El resto se suma cuando cada módulo lo necesite.

### Relaciones
- `venta` **N:1** `cliente` · `venta` **N:1** `usuario` (vendedor).
- `venta` **1:N** `venta_detalle` · `venta_detalle` **N:1** `producto`.
- `venta` **1:N** `pago` · `pago` **N:1** `tipo_pago`.
- `venta` **1:1** `comprobante` · `venta` **1:1** `venta_anulacion` (solo si se anuló).
- Cada línea confirmada crea un `movimiento_inventario` (egreso) que referencia la venta.
- `cliente` **N:1** `lista_precio` · `producto` **1:N** `precio` (uno por lista).

---

## 10. Detalles definidos

### Anulación de ventas
✅ Se hace en **código PHP** (`VentaService`), dentro de una transacción, no con
trigger de base de datos. Motivo: la lógica queda a la vista y es aprendible. Los
triggers se aprenden aparte más adelante. Solo el admin puede anular.

### Medios de pago (RF8)
Efectivo y transferencia para empezar. Las **tarjetas** se sumarán después: como
el medio vive en la tabla `tipo_pago`, agregar tarjetas será **cargar filas, sin
tocar código**.

### Tickets (RF11)
- **v1:** ticket interno en **HTML imprimible** + **PDF** (Dompdf).
- Diseñado con **formato térmico 58/80mm** desde ya; como todavía no hay impresora
  térmica, por ahora se imprime/descarga en **PDF**, listo para térmica cuando la haya.
- Contenido: datos del comercio, fecha/hora, número, cliente, productos, cantidades,
  precios, descuentos, subtotal, total, medio(s) de pago.

### AFIP
- **Fuera del alcance de v1.** El `comprobante` ya tiene lugar para los campos
  fiscales (CAE, punto de venta, tipo) en null. Cuando la venta funcione, sumamos
  AFIP como sub-etapa final (condición fiscal, certificados y homologación; se
  documenta aparte).

### Código de barras
- **Leer:** un scanner USB funciona como teclado → solo necesitamos un campo que,
  al recibir el código + Enter, busque el producto. **Sin librería.**
- **Generar/imprimir** códigos (para productos sin uno): más adelante, con
  `picqer/php-barcode-generator`. *(Todavía no lo necesitamos.)*

---

## 11. Validaciones

| Dato | Validación |
|---|---|
| Cantidad | Entero > 0, ≤ stock disponible (salvo sobre pedido) |
| Descuento | ≥ 0, no puede dejar el total negativo |
| Cliente | Debe existir (o ser Consumidor Final) |
| Medio de pago | La suma de pagos = total (RN6) |
| Código de barras | Debe corresponder a un producto activo |
| Producto | Debe estar activo |

Todas las validaciones se hacen **en el backend** (aunque también haya ayudas en el frontend).

---

## 12. Errores posibles

- Stock insuficiente al confirmar → mensaje claro, no se guarda nada.
- Producto/código inexistente → aviso, no agrega.
- Pagos que no cuadran → no deja confirmar.
- Falla de base de datos a mitad → la transacción hace *rollback*, no queda venta ni movimiento a medias.
- Doble clic en "Confirmar" → protección contra doble envío (token / bloqueo del botón).

---

## 13. Seguridad

- Solo usuarios con rol **vendedor** o **admin** acceden al POS (verificado en backend con middleware).
- Anular venta: **solo admin**.
- PDO + prepared statements en todas las consultas.
- CSRF token en el formulario de confirmación.
- Registro en `auditoria` de: venta creada, venta anulada.

---

## 14. Pruebas (checklist para cuando esté programado)

- [ ] Vender 1 producto con efectivo → stock baja en 1, ticket correcto.
- [ ] Vender varios productos, cambiar cantidades.
- [ ] Escanear código de barras → agrega el producto correcto.
- [ ] Cambiar cliente de minorista a mayorista → recalcula precios.
- [ ] Pago mixto (efectivo + transferencia) que suma el total.
- [ ] Intentar vender más que el stock → lo rechaza (salvo sobre pedido).
- [ ] Anular una venta → stock se reintegra, queda marcada anulada.
- [ ] Dos ventas del mismo producto casi simultáneas → no venden stock inexistente.

---

## 15. Decisiones (todas cerradas)

1. ✅ **Descuentos:** por línea **y** sobre el total. (RF6)
2. ✅ **Anulación:** solo admin, sin límite de tiempo, con motivo + confirmación + registro; en **código PHP** (no trigger). (RF12)
3. ✅ **Venta sin stock:** no se permite, **salvo productos sobre pedido**. (RN3)
4. ✅ **Medios de pago:** efectivo + transferencia; tarjetas después (solo cargar en `tipo_pago`).
5. ✅ **Ticket:** layout térmico 58/80mm diseñado; por ahora se imprime en PDF.

*No quedan preguntas abiertas: el spec del módulo Ventas está aprobado.*

---

## 16. Progreso de construcción

- ✅ **Paso 1 — Buscar / escanear producto** (2026-08-19).
  - Backend: `GET /api/productos/buscar?q=&lista=` → busca por código de barras
    exacto o nombre/SKU, devuelve stock y precio según la lista del cliente.
    Archivos: `ProductoRepository::buscar()`, `ProductoController::buscar()`.
  - Frontend: `public/pos.html` + `assets/js/pos.js` + `assets/css/pos.css`
    (buscador en vivo con debounce, foco automático para escanear, estados de
    stock). Probado en el navegador.
- ✅ **Paso 2 — Carrito, cantidades y precio según cliente** (2026-08-19).
  - Backend: `GET /api/clientes` (listar clientes con su lista) y
    `GET /api/productos/precios?ids=&lista=` (recalcular precios al cambiar cliente).
    Archivos: `ClienteRepository/Controller`, `ProductoRepository::preciosDe()`.
  - Frontend: capa AJAX reutilizable `assets/js/api.js` (patrón **Module**);
    carrito en memoria (agregar por clic o Enter/escaneo, cambiar cantidad, quitar),
    selector de cliente que **recalcula precios** (RN2), total en vivo.
  - Probado en el navegador: carrito, cantidades y cambio minorista→mayorista OK.
- ✅ **Paso 3 — Confirmar venta (backend)** (2026-08-19).
  - `VentaService` (patrón **Service Layer**): valida stock/pago, calcula precios
    en el backend (nunca confía en el frontend), y guarda todo dentro de **una
    transacción** (venta + detalle + pagos + stock/movimientos + comprobante).
  - `GET /api/tipos-pago`, `POST /api/ventas`. Nuevos: `VentaRepository`,
    `InventarioRepository`, `TipoPagoRepository`, `ValidacionException`.
  - Probado: venta OK, stock insuficiente (rollback), pago que no coincide.
- ✅ **Paso 3 (frontend) — Pantalla de cobro** (2026-08-19).
  - Modal de cobro (elegir medio de pago), `api.post('/api/ventas')`, manejo de
    errores, anti doble-clic. Venta completa desde la pantalla, probada.
- ✅ **Paso 4 — Ticket** (2026-08-19).
  - Ticket interno con formato térmico + botón Imprimir (CSS `@media print` que
    imprime solo el ticket). Probado: venta V-000002 con su ticket.
- ⬜ Paso 3b — Descuentos (línea + total) + pago mixto.
- ⬜ Ticket en PDF (Dompdf) — opcional, más adelante.

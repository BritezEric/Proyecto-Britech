# Módulo: Reposición (pedido de compra a proveedores)

> Estado: **construido y probado** (2026-09-07).

## Problema
Cuando el stock de un producto baja, hay que pedirle más al proveedor. Antes eso
era manual (mirar el stock, buscar el teléfono, escribir el mensaje). Este módulo
lo automatiza: detecta los faltantes, los agrupa por proveedor y arma el mensaje
de WhatsApp listo para enviar. El admin **lee y acepta** (o cambia de proveedor).

## Modelo de datos
`schema_reposicion.sql` agrega dos columnas a **producto**:

| Columna | Tipo | Para qué |
|---|---|---|
| `costo` | DECIMAL(12,2) NULL | Precio de compra al proveedor. Estima el total del pedido. |
| `stock_minimo` | INT NOT NULL DEFAULT 0 | Umbral que dispara la alerta. `0` = sin alerta. |

Ya existían: `producto.proveedor_id` (a quién se le compra) y `proveedor.telefono`
(a dónde mandar el WhatsApp).

`schema_reposicion_pedidos.sql` agrega la tabla **reposicion_pedido** (historial):
una fila por producto pedido, con `producto_id`, `proveedor_id`, `cantidad` y
`creado_en`. No es una orden de compra formal: solo registra qué se pidió y cuándo,
para **no volver a avisar** un producto ya pedido hace poco.

## Cómo funciona
1. **Detección** — `ProductoRepository::stockBajo()` trae los productos activos, no
   "sobre pedido", con `stock_minimo > 0` y `cantidad <= stock_minimo`, junto con su
   proveedor y teléfono.
2. **API** — `GET /api/admin/reposicion` (solo admin) devuelve `items` (cada uno con
   `sugerido = stock_minimo * 2 - stock`, mínimo 1) y la lista de `proveedores` activos.
3. **Pantalla** (`admin-reposicion.js`, vista *Reposición*) — agrupa los ítems por
   proveedor. Por cada grupo: cantidad editable, costo, subtotal y **total estimado**.
   El admin puede **cambiar el proveedor** de un producto (se reagrupa al instante) y
   tocar **Enviar pedido por WhatsApp**, que abre `wa.me` con el mensaje pre-cargado
   (conciso, con etiquetas de texto: producto + cantidad).
4. **Acceso** — botón *Reposición* en el dashboard con un **badge** que muestra cuántos
   productos faltan (`dashboard.reposicion_faltantes`).
5. **Historial / no re-avisar** — al enviar, `POST /api/admin/reposicion` guarda el
   pedido en `reposicion_pedido`. El `index` marca cada ítem con `dias_ultimo_pedido`
   (días desde el último pedido, dentro de una ventana de 7 días). En la pantalla, esos
   productos muestran un chip **"Pedido hace Xd"** y arrancan con cantidad **0**, así no
   se re-avisan salvo que el admin suba la cantidad a mano.

## Alcance / decisiones
- **Costo por producto** (columna simple), no lista de precios de costo.
- **Umbral por producto** (`stock_minimo`), no un número global.
- **Historial liviano** (`reposicion_pedido`): registra qué se pidió y cuándo, para no
  re-avisar. No es una orden de compra formal (sin estado recibido/pendiente, sin costos
  congelados). La ventana de "reciente" está fija en 7 días (constante en el repo).
- El mensaje **no incluye precios** (el costo es interno; el proveedor pone su precio).

## Archivos
- `database/schema_reposicion.sql`, `database/schema_reposicion_pedidos.sql`
- `app/Repositories/ProductoRepository.php` (`stockBajo`, columnas en crear/actualizar)
- `app/Repositories/ReposicionRepository.php` (`registrar`, `diasUltimoPedido`)
- `app/Services/ProductoService.php` (validación de `costo` y `stock_minimo`)
- `app/Controllers/ReposicionController.php` + rutas `GET`/`POST /api/admin/reposicion`
- `app/Repositories/DashboardRepository.php` (`reposicionFaltantes`) + dashboard
- `public/assets/js/admin-reposicion.js`, vista en `admin.html`, estilos en `admin.css`
- Form de producto (`admin.js`): campos **Costo** y **Stock mínimo**

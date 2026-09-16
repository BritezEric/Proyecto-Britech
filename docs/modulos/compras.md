# Módulo: Compras (orden de compra + recepción de mercadería)

> Estado: **construido y probado** (2026-09-16). Parte del **Módulo de Procesos**.

## Problema
El sistema descuenta stock al vender, pero también hay que **reponerlo**: pedirle
mercadería al proveedor y, cuando llega, sumarla al inventario. Este módulo cubre
ese lado: se arma una **orden de compra**, se envía al proveedor y, cuando llega la
mercadería, se **registra la recepción** (total o parcial) y el stock sube solo.

## Modelo de datos (`schema_compras.sql`)
**orden_compra**

| Columna | Tipo | Para qué |
|---|---|---|
| `numero` | VARCHAR(20) | `OC-000001` (se setea tras crear). |
| `proveedor_id` | INT NULL | A quién se le compra. |
| `estado` | ENUM | `enviada` → `parcial` → `recibida`, o `anulada`. |
| `total_estimado` | DECIMAL(12,2) | Suma `cantidad * costo_unitario`. |
| `observacion` | VARCHAR(250) NULL | Nota libre. |
| `usuario_id` | INT | Quién la creó. |
| `creado_en` / `recibido_en` | DATETIME | Alta / cuándo se completó. |

**orden_compra_detalle**: una fila por producto (`cantidad`, `costo_unitario`,
`cantidad_recibida` acumulada). No es una orden de compra contable formal (sin
impuestos ni remito): es lo justo para pedir y recepcionar.

## Cómo funciona
1. **Crear orden** — dos caminos (decisión de alcance: *ambos*):
   - **Desde Reposición**: botón *Crear orden de compra* en cada grupo de proveedor
     arma la orden con los faltantes ya cargados.
   - **Manual** (vista Compras → *+ Nueva orden*): elegir proveedor, buscar productos
     (reusa `/api/productos/buscar`, que ahora trae `costo` para prellenar), fijar
     cantidad y costo. El total se calcula solo.
2. **Recepción** (`CompraService::recibir`) — total o **parcial**: cada línea acumula
   `cantidad_recibida` (nunca más que lo pedido). Por cada recepción: sube stock
   (`InventarioRepository::aumentar`, upsert) + deja un `movimiento_inventario`
   (`ingreso`, referencia a la OC en el motivo) + notificación `compra_recibida`.
   El estado avanza a `parcial` o `recibida` según el total. Todo transaccional.
3. **Anular** — solo si no está recibida. No revierte el stock ya recibido (la
   mercadería que llegó es real).

## Notificaciones (Pilar del Módulo de Procesos)
- `stock_bajo`: al **vender**, si un producto queda `<= stock_minimo`, se crea un aviso
  (con dedupe: no repite si ya hay uno sin leer para ese producto). Lleva a *Reposición*.
- `compra_recibida`: al recibir mercadería. Lleva a *Compras*.
- La campana ya renderiza cualquier tipo; solo se sumaron íconos y los disparadores.

## API (todo solo-admin)
| Método | Ruta | Qué hace |
|---|---|---|
| GET  | `/api/admin/compras` | Lista paginada (filtro `?estado=`). |
| GET  | `/api/admin/compras/detalle?id=` | Cabecera + líneas. |
| GET  | `/api/admin/compras/nueva` | Proveedores activos (alta manual). |
| POST | `/api/admin/compras` | Crea la orden. |
| POST | `/api/admin/compras/recibir` | Registra recepción (total/parcial). |
| POST | `/api/admin/compras/anular` | Anula una orden no recibida. |

## Archivos
- `database/schema_compras.sql`
- `app/Repositories/CompraRepository.php`
- `app/Services/CompraService.php` (transaccional, patrón de `VentaService`)
- `app/Controllers/CompraController.php` + rutas en `routes/api.php`
- `app/Repositories/InventarioRepository.php` (`aumentar`)
- `app/Repositories/ProductoRepository.php` (`enAlerta`; `costo` en `buscar`)
- `app/Repositories/NotificacionRepository.php` (`existeNoLeida` para dedupe)
- `app/Services/VentaService.php` (aviso `stock_bajo` tras vender)
- `public/assets/js/admin-compras.js`, vista en `admin.html`, estilos en `admin.css`
- `public/assets/js/admin-reposicion.js` (botón *Crear orden de compra*)

## Pendiente / futuro
- Recepción por remito con nº de comprobante; costos que actualicen `producto.costo`.
- Orden de compra imprimible/PDF (se apoya en el mismo detalle).

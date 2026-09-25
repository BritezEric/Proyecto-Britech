# Salidas del Sistema — Franco

## Qué es
El **Módulo 5 (Salidas del Sistema)**: todo lo que el sistema **devuelve para tomar
decisiones**. Tres bloques:
1. **Dashboard / reportes gerenciales** — KPIs y gráficos del negocio (ventas del
   día/mes, físicas vs online, ventas por categoría/vendedor, top productos, stock
   bajo, finanzas).
2. **Cierre de caja (arqueo)** — el vendedor abre la caja, registra movimientos y
   la cierra; el sistema calcula lo esperado vs lo contado y la diferencia. Es la
   "salida" clásica del turno.
3. **PDF de empleados** — reporte imprimible de rendimiento y sueldos (Dompdf).

## Archivos que tengo que saber

### 1) Dashboard / reportes
- [`app/Controllers/DashboardController.php`](../../app/Controllers/DashboardController.php)
  `resumen()` arma todos los KPIs; `serie()` devuelve la serie de ventas para el
  gráfico según el período (semana/mes/año).
- [`app/Repositories/DashboardRepository.php`](../../app/Repositories/DashboardRepository.php)
  **Todo el SQL de los reportes.** Cada método es una consulta: `ventasHoy()`,
  `ventasMes()`, `ticketPromedioMes()`, `topProductos()`, `ventasPorCategoria()`,
  `ventasPorVendedor()`, `serieVentas()`, `serieFinanzas()`, `comparativaMes()`,
  `sinMovimiento()`, `totales()`, etc. (es el archivo más importante de tu parte).
- [`public/assets/js/admin.js`](../../public/assets/js/admin.js) — el dashboard visual:
  - `renderInicio()` (línea ~907): pide `/api/admin/dashboard` y pinta todo.
  - Gráficos de barras: `renderSerie()` y `renderBarrasFin()` (líneas ~873-905).
  - `hbarList()` (barras horizontales): top productos, vendedores, categorías, stock.

### 2) Cierre de caja
- [`database/schema_caja.sql`](../../database/schema_caja.sql)
  Tablas `caja` (apertura, estado, monto_contado, esperado, diferencia, cierre) y
  `caja_movimiento` (retiros/ingresos). También agrega `caja_id` a `venta`.
- [`app/Services/CajaService.php`](../../app/Services/CajaService.php) — la lógica:
  `abrir()` (una caja abierta por vendedor), `movimiento()`, y `cerrar()` que
  calcula: **esperado = apertura + efectivo − retiros + ingresos**, y
  **diferencia = contado − esperado**.
- [`app/Repositories/CajaRepository.php`](../../app/Repositories/CajaRepository.php)
  SQL de la caja + `resumen()` (totales por forma de pago del turno).
- [`app/Controllers/CajaController.php`](../../app/Controllers/CajaController.php)
  `estado/abrir/movimiento/cerrar` (staff) + `adminListar/adminDetalle` (historial admin).
- Frontend:
  - [`public/assets/js/pos.js`](../../public/assets/js/pos.js) — módulo **CAJA**
    (desde línea ~511): `cargarCaja()`, `abrirModalCaja()`, apertura/movimientos/cierre
    con la diferencia en vivo.
  - [`public/assets/js/admin-cajas.js`](../../public/assets/js/admin-cajas.js) —
    historial de cajas en el panel, con la diferencia coloreada (ok/mal).

### 3) PDF de empleados
- [`app/Controllers/EmpleadoController.php`](../../app/Controllers/EmpleadoController.php)
  `pdf()` genera el reporte con **Dompdf**.
- [`public/assets/js/admin-empleados.js`](../../public/assets/js/admin-empleados.js)
  la vista que lo dispara.

### Rutas API — [`routes/api.php`](../../routes/api.php)
| Método | Ruta | Qué |
|---|---|---|
| GET | `/api/admin/dashboard` | KPIs del panel (líneas 64) |
| GET | `/api/admin/dashboard/serie` | serie para el gráfico (línea 65) |
| GET | `/api/caja/estado` | estado de la caja del vendedor (línea 131) |
| POST | `/api/caja/abrir` · `/api/caja/movimiento` · `/api/caja/cerrar` | operar la caja (132-134) |
| GET | `/api/admin/cajas` · `/api/admin/cajas/detalle` | historial de cajas (135-136) |
| GET | `/api/admin/empleados/pdf` | reporte PDF (línea 97) |

## El cálculo del cierre (memorizalo, te lo van a preguntar)
```
esperado   = monto_apertura + ventas_en_efectivo − retiros + ingresos
diferencia = monto_contado (lo que hay físico) − esperado
```
- Diferencia **0** = caja cuadra. Positiva = sobra. Negativa = falta.
- Las ventas con tarjeta/transferencia **no** entran en el efectivo esperado.

## Preguntas típicas y respuesta corta
- **¿De dónde salen los números del dashboard?** De consultas SQL directas en
  `DashboardRepository`; el Controller solo las junta y arma el JSON.
- **¿Por qué las ventas se ligan a una caja (`caja_id`)?** Para que al cerrar el
  turno se sepa qué ventas entraron en esa caja y calcular el efectivo esperado.
- **¿Puede un vendedor tener dos cajas abiertas?** No, `abrir()` lo impide (una
  abierta por usuario).
- **¿Cómo se hace el PDF?** Con la librería **Dompdf** (ya está en el proyecto):
  se arma un HTML y se convierte a PDF en `EmpleadoController::pdf()`.
- **¿Qué relación tiene esto con Transacciones (Eric)?** Las **ventas** son de él;
  acá esas ventas se **resumen y cuadran** en el cierre. Yo muestro/reporto lo que
  las transacciones generaron.

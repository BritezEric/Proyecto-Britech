# Módulo: Finanzas (Gastos + Totales del negocio)

> Construido el 2026-08-24. Gestión financiera básica y profesional: registrar
> gastos del negocio (compras a proveedores + gastos generales) y ver los
> **totales** (ingresos, invertido, balance) en el dashboard.

## 1. Gastos (ABM)
Sección **Gastos** del panel admin (solo admin). Reusa el mismo framework de ABM
que las tablas maestras (config por entidad en `admin.js`), así que trae
**búsqueda, filtros y paginación** gratis.

- Campos: **fecha**, **concepto** (qué se compró/pagó), **proveedor** (opcional),
  **monto**, **observación**, activo (baja lógica).
- Filtros: por **estado** y por **proveedor**. Búsqueda por concepto/observación.
- El pie de la tabla muestra el **total de lo listado** (suma con los filtros
  aplicados) — ej. total gastado con un proveedor.
- Tabla `gasto` ([schema_gastos.sql](../../database/schema_gastos.sql)), FK opcional
  a `proveedor` y a `producto`.

### Compra de stock (suma inventario)
Un gasto puede indicar **producto + cantidad**. Al **crear** ese gasto, la cantidad
se **suma al inventario** del producto y se registra un movimiento `ingreso`
("Compra a proveedor (gasto)"), todo en una **transacción** (gasto + stock, todo o
nada). Si no se cargan producto/cantidad, es un gasto general (alquiler, servicios).
La columna "Suma stock" del listado muestra `producto ×cantidad`.

> Simplificación (ponytail): el stock se suma **una sola vez, al crear** la compra.
> Editar o dar de baja el gasto **no** re-ajusta el inventario (evita duplicar o
> dejar stock negativo). Para corregir stock se usa el ABM de Productos.

Capas (patrón del proyecto): `GastoController` → `GastoService` (validaciones:
concepto obligatorio, monto > 0, fecha por defecto hoy) → `GastoRepository`
(SQL preparado, listado + `SUM`).

## 1b. Ubicación: dentro del dashboard (no en el sidebar)
Los gastos **no** son un ítem del menú lateral: viven en el **dashboard**, sección
**Finanzas**. Ahí hay un panel **"Gastos recientes"** con los últimos gastos y dos
botones: **"+ Nuevo gasto"** (abre el alta) y **"Ver todos"** (abre el ABM completo
con búsqueda/filtros/paginación). La card "Total invertido" también lleva al ABM.

## 1c. Gráficos del dashboard (finanzas + ventas)
- **Ingresos vs Gastos · 6 meses** (barras agrupadas, verde vs rojo):
  `DashboardRepository::serieFinanzas` (ingresos = ventas físicas + online; gastos
  = suma de `gasto`).
- **Ventas por categoría · 90 días** (barras horizontales): `ventasPorCategoria`.
- Más los ya existentes (físicas vs online rotable por semana/mes/año, top
  productos, por vendedor, stock bajo, sin movimiento).
El dashboard quedó organizado en secciones: **💰 Finanzas · 📊 Ventas · 📦 Inventario**.

## 2. Totales del negocio (dashboard)
Fila **"Totales del negocio"** en el dashboard (`DashboardRepository::totales`):
- **Clientes totales** (registrados).
- **Ventas físicas** (POS): cantidad + monto histórico (solo `registrada`).
- **Ventas online** (pedidos no cancelados): cantidad + monto.
- **Total generado** = ventas físicas + online (ingresos).
- **Total invertido** = suma de gastos activos (card clickeable → sección Gastos).
- **Balance** = generado − invertido (verde si positivo, rojo si negativo).

## 3. Rutas
`GET /api/admin/gastos` (listado + suma) · `POST /api/admin/gastos/guardar` ·
`POST /api/admin/gastos/baja`. El dashboard suma `totales` a `/api/admin/dashboard`.

## 4. Probado
- Alta de gastos (compra a proveedor + gasto general), validación (monto 0 → 422),
  listado con suma y proveedor, filtros.
- Totales del dashboard cuadran: generado 3.865.000 − invertido 630.000 =
  balance 3.235.000. Sin errores de consola.

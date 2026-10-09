# Módulo: Dashboard y reportes (Salidas del Sistema)

> Estado: **construido** (actualizado 2026-09-22 con gráficos). Cubre la parte de
> "Salidas del Sistema" (Módulo 5): información del negocio para tomar decisiones.

## Problema
El administrador necesita ver, de un vistazo, cómo va el negocio: cuánto se vendió,
qué se vende más, qué stock está bajo, cómo van las finanzas y qué requiere acción.

## Qué muestra
El panel de inicio (`vista-inicio`) arma el dashboard con datos reales:
- **KPIs**: ventas de hoy (local), ventas online de hoy, físicas y online del mes,
  comparativa vs. mes anterior, ticket promedio y clientes (+ nuevos del mes).
- **Totales del negocio**: clientes, ventas físicas y online, total generado,
  total invertido (gastos) y balance.
- **Gráficos**:
  - Barras **Ingresos vs. Gastos** (6 meses).
  - Barras **Ventas: físicas vs. online** (rotable: semana / mes / año).
  - **Dona** de ventas del mes (físicas vs. online).
  - Barras horizontales: **ventas por categoría**, **por vendedor**, **top
    productos** y **stock bajo** (coloreado por severidad).
- **Requiere acción**: pedidos pendientes, solicitudes mayoristas, productos sin stock.
- **Listas**: stock bajo y productos sin ventas en 30 días.
- **Accesos rápidos** a Envíos, Repartos, Reposición, Compras, Cajas, Reclamos y POS.

## Cómo funciona
- `GET /api/admin/dashboard` devuelve todos los indicadores; el controlador solo
  junta las consultas del repositorio y arma el JSON.
- `GET /api/admin/dashboard/serie?periodo=` devuelve la serie para el gráfico
  rotable (semana/mes/año).
- Todo el SQL de los reportes vive en `DashboardRepository` (un método por
  consulta: `ventasHoy`, `ventasMes`, `topProductos`, `ventasPorCategoria`,
  `ventasPorVendedor`, `serieVentas`, `serieFinanzas`, `comparativaMes`, etc.).
- Los cortes por fecha usan `CURDATE()` de MySQL para evitar desfases de zona horaria.

## Reportes complementarios
- **Cierre de caja** (arqueo del turno) — ver `docs/modulos/caja.md`.
- **PDF de empleados** (rendimiento + sueldos) — `EmpleadoController::pdf` con Dompdf.

## Archivos
- `app/Controllers/DashboardController.php`, `app/Repositories/DashboardRepository.php`
- Frontend: `public/assets/js/admin.js` (`renderInicio`, gráficos `renderSerie`,
  `renderBarrasFin`, `hbarList`, `donut`) + estilos en `public/assets/css/admin.css`.

## Pendiente / futuro
- Reportes gerenciales avanzados (ventas por período/cliente/producto, rentabilidad)
  exportables a PDF/Excel.
- Auditoría (log de acciones y operaciones críticas).

> Guía de defensa: `docs/exposicion/salidas-franco.md`.

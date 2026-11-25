# Módulo 5 "Salidas del Sistema" — Guión + defensa del código

Para la exposición del 12/11 y la **toma de código** (el profe le pide a cualquiera de los tres
un cambio chico: un color, una función). Dos partes: el **guión** de qué dice cada uno, y el
**mapa del código** con los lugares exactos para los cambios típicos.

---

# PARTE 1 — Guión de la exposición

Orden y quién habla. Cada slide del deck tiene las **notas del orador** (modo presentador);
esto es el resumen.

1. **Eric — Apertura:** qué es el Módulo 5 y que lo repartimos en tres.
2. **Eric — Reportes gerenciales:**
   - *Ventas por período*: total vendido entre fechas, cantidad y ticket promedio; separa local y online.
   - *Gráficos*: ventas por mes (líneas), productos (barras), categoría/localidad (torta).
   - *Productos más vendidos*: ranking por unidades y monto; el más vendido no siempre es el más facturado.
   - *Rentabilidad*: ganancia = venta − costo, y margen %.
3. **Tobías — Cierre de caja:** apertura/cierre, arqueo por forma de pago, esperado vs. contado, "cierre perfecto", comprobante PDF.
4. **Franco — Auditoría:** registra acciones y operaciones críticas; trazabilidad (valor anterior → nuevo); solo lectura; exporta a PDF/Excel.
5. **Cierre (los tres):** 5+ reportes, 3 gráficos, todo exportable, probado con datos reales, link al repo.

Regla de oro: mostrar **acción y efecto**, no leer el código. El código se defiende en la Parte 2.

---

# PARTE 2 — Mapa del código (para la toma)

## Cómo está armado el proyecto (recordar esto)

El flujo es siempre el mismo, de adelante hacia atrás:

```
public/ (HTML + JS)  →  routes/api.php  →  app/Controllers  →  app/Services  →  app/Repositories  →  MySQL
```

- **Controller**: recibe el pedido, valida permisos (`Session::esAdmin()`), responde JSON.
- **Service**: la lógica (cálculos, reglas).
- **Repository**: las consultas SQL (siempre preparadas, con `?`).
- **public/assets/js**: el front que pinta la pantalla y dibuja los gráficos.

Si el profe pide un cambio, lo primero es ubicar **en qué capa** está lo que hay que tocar.

---

## A) Reportes gerenciales — Eric

| Qué | Archivo |
|---|---|
| Lógica de los reportes (cálculos, SQL de cada uno) | `app/Services/ReporteOperativoService.php` |
| Consultas de localidades | `app/Repositories/ReporteOperativoRepository.php` |
| Rutas | `routes/api.php` → `/api/admin/reportes/operativo*` |
| Pantalla + filtros + tablas + gráficos | `public/assets/js/admin-reportes-operativos.js` |
| Paleta de colores de los gráficos | `public/assets/js/admin-reportes.js` |

**Funciones clave (Service `ReporteOperativoService.php`):**
- `opciones()` (línea ~88): arma los desplegables de filtros.
- `ventasPeriodo($f, $q)` (~906): el reporte de ventas por período.
- `rentabilidad($f, $q)` (~1129): ganancia y margen por producto.

**Funciones clave (JS `admin-reportes-operativos.js`):**
- `renderReportes()` (~116): arma toda la vista de Reportes.
- `roMostrar(rep)` (~143): cambia de pestaña (Ventas, Productos, Rentabilidad…).
- `roDibujarChart()` (~306): dibuja el gráfico (líneas / barras / torta con Chart.js).
- `roPintarTabla(i)` (~345): pinta la tabla del reporte.

**Cambios que puede pedir y dónde:**
- **"Cambiá el color del gráfico"** → `admin-reportes.js`, función `rlPaleta()` (~254). Es un array:
  ```js
  return [t.primary, t.accent, '#0ea5e9', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444', t.muted];
  ```
  Cada posición es un color de serie. Cambiás un hex (ej. `'#0ea5e9'` por `'#e11d48'`) y listo.
- **"Cambiá a cuántos productos muestra el ranking"** → en el Service, el `LIMIT` de la consulta de productos.
- **"Agregá una columna a la tabla"** → `roPintarTabla()` en el JS (la columna visual) + que el Service devuelva ese dato.

---

## B) Cierre de caja — Tobías

| Qué | Archivo |
|---|---|
| Lógica (abrir, cerrar, arqueo, esperado) | `app/Services/CajaService.php` |
| Consultas de caja | `app/Repositories/CajaRepository.php` |
| Rutas | `routes/api.php` → `/api/caja/*` y `/api/admin/cajas/*` |
| Pantalla del vendedor | `public/assets/js/caja-ui.js` |
| Pantalla admin (listado + detalle) | `public/assets/js/admin-cajas.js` |
| Estilos (badges de estado, colores) | `public/assets/css/caja.css` |

**Funciones clave (Service `CajaService.php`):**
- `abrir($usuarioId, $monto)` (~86): abre la caja con el monto inicial.
- `movimiento(...)` (~102): registra un ingreso o retiro.
- `cerrar($usuarioId, $in)` (~155): cierra la caja y calcula la diferencia.
- `cierreDelDia($fecha)` (~306): el consolidado del día.
- `esperado($apertura, $resumen)` (~360): **el cálculo del efectivo esperado**. Es:
  ```php
  return round($apertura + $resumen['efectivo'] - $resumen['retiros'] + $resumen['ingresos'], 2);
  ```
  Si piden "explicá cómo se calcula lo esperado", es esta línea: apertura + ventas en efectivo − retiros + ingresos.

**Cambios que puede pedir y dónde:**
- **"Cambiá el color del cartel de ‘cuadra / cierre perfecto’"** → `caja.css`, clase `.cx-ok` (usa `var(--ok-strong)`, verde). Cambiás esa clase (ej. el `background`/`color`), o el token verde en `tokens.css` (`--ok`, `--ok-strong`).
  - El rojo de "no cuadra" es `.cx-bad` (`--danger-*`).
- **"Cambiá el cálculo del esperado"** → `CajaService::esperado()` (~360).
- **"Mostrá otro dato en el detalle"** → `CajaService::detalle()` (~45) devuelve los datos; `admin-cajas.js` los pinta.

---

## C) Auditoría — Franco

| Qué | Archivo |
|---|---|
| Grabar los eventos (el "quién hizo qué") | `app/Services/Auditoria.php` |
| Listar / exportar | `app/Controllers/AuditoriaController.php` |
| Consultas del log | `app/Repositories/AuditoriaRepository.php` |
| Rutas | `routes/api.php` → `/api/admin/auditoria`, `/pdf`, `/excel` |
| Pantalla (tabla + filtros + resumen) | `public/assets/js/admin-auditoria.js` |

**Funciones clave (Controller `AuditoriaController.php`):**
- `listar()` (~47): devuelve el log paginado y filtrado.
- `pdf()` (~65) y `excel()` (~88): las exportaciones.

**Funciones clave (JS `admin-auditoria.js`):**
- `renderAuditoria()` (~22): arma la pantalla.
- `cargarAuditoria()` (~40): pide los datos al backend.
- `auResumen(res)` (~70): los contadores de arriba (registros, altas, modificaciones…).
- `auTabla(filas)` (~84): la tabla con el antes → después.
- `exportarAuditoria(formato)` (~126): dispara el PDF o Excel.

**Cómo se graba un evento (clave para defender):** cuando en algún Service pasa algo importante
(cambio de precio, anulación, etc.) se llama a `Auditoria::cambios(...)` / `Auditoria::evento(...)`,
que guarda usuario, fecha, qué se tocó y el valor anterior y el nuevo. Ejemplo real: en
`ConfigController::guardar()` se audita el cambio de alias/CBU.

**Cambios que puede pedir y dónde:**
- **"Agregá un tipo de acción al filtro"** → `admin-auditoria.js`, `auOpciones()` (~59) + que el backend lo acepte en `listar()`.
- **"Cambiá el color de una acción (alta/baja/modificación)"** → en `auTabla()` (~84), donde se arma el badge de cada fila (clase/color por tipo de acción).
- **"Que audite también X"** → agregar una llamada a `Auditoria::cambios(...)` en el Service donde ocurre X.

---

# PARTE 3 — Los 3 cambios más probables (chuleta)

### 1) "Cambiame un color"
- **Color general del sistema (morado):** `public/assets/css/tokens.css`, línea ~23 `--primary` (y ~197 para modo oscuro). Cambiás ese valor y cambia en todo el panel.
- **Color de un gráfico:** `admin-reportes.js`, `rlPaleta()` (~254) — el array de hex.
- **Color de un estado/badge (caja o auditoría):** la clase CSS de ese badge (`.cx-ok`, `.cx-bad` en `caja.css`), o los tokens `--ok`, `--danger` en `tokens.css`.
> Después de tocar CSS/JS: **recargá con Ctrl + F5** (el navegador cachea los archivos).

### 2) "Cambiame una función / un cálculo"
- Ubicá la capa: si es un **cálculo o regla**, está en `app/Services/…`. Si es **cómo se ve**, en `public/assets/js/…`.
- Ejemplos: el esperado de caja → `CajaService::esperado()`; el margen → `ReporteOperativoService::rentabilidad()`.
- Tras editar PHP: no hace falta reiniciar el server, se toma solo al recargar.

### 3) "Agregame/saca un dato de un reporte"
- El **dato** sale del Service (que lo calcule/consulte) → el **Controller** lo manda en el JSON → el **JS** lo pinta en la tabla/KPI. Hay que tocar las tres capas, en ese orden.

### Cómo probar que anduvo (en vivo, sin nervios)
1. Guardás el archivo.
2. En el panel, **Ctrl + F5** (recarga forzada, por la caché).
3. Entrás a la pantalla y ves el cambio. Si no aparece, 9 de 10 veces es la **caché**: Ctrl+F5 de nuevo.
4. Si es PHP y rompió, el panel muestra el error (o la consola del navegador, F12).

### Reglas del equipo que conviene decir si preguntan
- Consultas **siempre preparadas** (PDO con `?`), nunca concatenando.
- Permisos en el server: `Session::esAdmin()` / `esStaff()`.
- No se borra ni edita la auditoría: es **solo lectura**.
- Cambios de base solo con scripts `database/schema_*.sql` reejecutables.

---

**Repositorio:** https://github.com/BritezEric/Proyecto-Britech (rama `master`).

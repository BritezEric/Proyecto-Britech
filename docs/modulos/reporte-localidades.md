# Módulo: Reporte de Localidades (Salidas del Sistema)

> Estado: **construido** (2026-10-27). Parte del Módulo 5 — Salidas del Sistema.
> Panel → **Reportes** (menú lateral) o el acceso rápido **Localidades** del dashboard.

## Para qué sirve
Muestra **dónde se vende, cuánto y cómo se entrega**: ventas del POS, pedidos de la
tienda y envíos agrupados por localidad o zona. Sirve para decidir promociones por
zona, ver qué zonas crecen o caen y detectar dónde se demoran las entregas.

## Quién lo usa
| Rol | Ve | No ve |
|---|---|---|
| **admin** | Todo: indicadores, cobros, costos de envío, paga a repartidores, repartidores, exportaciones | — |
| **vendedor** | Sus ventas del POS + todos los pedidos online (ya los gestiona en "Pedidos"), por zona; productos por zona; entregas (cantidades y estados); exportaciones con esos mismos datos | Cobrado / pendiente de cobro, envío cobrado, paga y nombres de repartidores, ventas de otros vendedores |
| **cliente de la tienda / sin sesión** | Nada: 401 | — |

El alcance lo decide el **backend** según la sesión (`ReporteLocalidadService`), no un
parámetro: un vendedor que agregue `?vendedor_id=` a la URL sigue viendo solo lo suyo.
Los repartidores de Moto Express no tienen cuenta en el sistema, así que no tienen acceso.

## Cómo se arma (arquitectura)
`admin.html` / `admin-reportes.js` → `routes/api.php` → `ReporteLocalidadController`
→ `ReporteLocalidadService` → `ReporteLocalidadRepository` → PDO.

- **Repository**: todo el SQL. Parte de UNA subconsulta de *operaciones*: una fila por
  venta del POS o por pedido online, con su envío pegado. Indicadores, tabla, gráficos,
  logística, productos y exportaciones usan esa misma subconsulta, así que **los filtros se
  aplican igual en todos lados**. Todo es parametrizado (PDO, sin concatenar valores).
- **Service**: valida filtros (fechas `AAAA-MM-DD`, rango ≤ 3 años, listas cerradas), aplica
  el alcance del rol, calcula el período de comparación y las variaciones.
- **Controller**: permisos, respuestas JSON, CSV y PDF (Dompdf, en memoria, sin temporales).

## Reglas de cálculo (y por qué)
- **Sin doble conteo.** Una compra online es un `pedido`; una del mostrador es una `venta`.
  Son tablas separadas (un pedido nunca genera una venta). El envío se une 1:1
  (`envio.venta_id` y `envio.pedido_id` son UNIQUE). Un pedido con varios productos cuenta
  como **una** operación.
- **Importe = productos, sin envío.** Venta: `subtotal − descuento` (`venta.total` incluye el
  envío). Pedido: `total` (no incluye el envío). El envío cobrado se informa aparte.
- **Válidas:** ventas `registrada` y pedidos no `cancelado`. Las anuladas/canceladas se
  cuentan aparte y no suman importe. Si el pedido está cancelado, su envío cuenta como cancelado.
- **Cobrado ≠ vendido.** Cobrado = ventas del POS (se cobran al momento) + pedidos con
  `estado_pago = pagado`. Pendiente de cobro = pedidos con pago `pendiente` o `en_revision`.
- **Zona** (base "Destino del envío"):
  - envío Moto Express → **barrio** (tabla `barrio`), separado de las localidades;
  - otro medio con localidad escrita → **localidad** (se agrupa sin importar mayúsculas,
    espacios ni acentos: "Formosa", " formosa ", "FORMOSA" son la misma);
  - envío sin localidad → **"Sin localidad identificada"** (no se deduce por código postal);
  - venta sin envío o retiro en local → **"Retiro / venta en el local"**.
  - Base "Domicilio del cliente": la localidad del perfil del cliente.
- **Demorado** = envío pendiente / despachado / en camino con más de N días (N elegible,
  3 por defecto). El sistema no guarda fecha prometida ni fecha real de entrega.
- **Productos por zona**: unidades e importe con el precio guardado en cada línea (histórico).
- **Comparación**: "Período anterior" = mismos días del mes anterior si el rango empieza el
  día 1 y queda dentro de un mes; si no, el bloque inmediatamente anterior de igual duración.
  También: mismo período del año anterior u otro rango. Si el período anterior es 0 no hay %
  ("Sin datos en el período anterior").

## Pantalla
Filtros (fechas, atajos, comparación, base, canal, zona, tipo de zona, estado del pedido,
estado del envío, medio, producto, días de demora) → indicadores con su variación → gráficos
(importe por zona, pedidos y ventas, distribución, online vs POS, evolución) → tabla por zona
(ordenable, buscable, paginada) → productos por zona → comparar dos zonas → entregas por zona
y medio (+ repartidores para el admin) → aclaraciones de cálculo.

## Exportaciones
- **CSV** (`seccion=zonas|logistica|productos`): UTF-8 con BOM, separador `;`, coma decimal
  (Excel en español). Textos que empiezan con `= + - @` se escapan (CSV injection).
- **PDF** (A4 apaisado): logo, período, filtros, indicadores, gráfico de barras, tabla por
  zona (40 con más importe), entregas, productos y aclaraciones.

## Instalación / actualización
1. Copiar los archivos nuevos y modificados (lista en el PR / commit).
2. Ejecutar una vez `database/schema_reportes_localidad.sql` (solo agrega índices por fecha;
   re-ejecutable, no toca datos). El reporte funciona sin él, pero más lento con muchos datos.
3. No hay dependencias nuevas: usa Dompdf (ya instalado) y Chart.js (ya cargado en el panel).

## Pruebas
`tests/reporte_localidades_test.php` carga `tests/fixtures/reporte_localidades_seed.sql` en una
base **`*_test`** (se niega a correr en otra) y compara contra valores calculados a mano;
con `BRITECH_TEST_URL` también prueba permisos, filtros y exportaciones por HTTP:
```
DB_HOST=127.0.0.1 DB_NAME=britech_reportes_test php tests/reporte_localidades_test.php
```

## Limitaciones (datos que Britech no registra)
- No hay **coordenadas**: no se dibuja un mapa (sería inventar ubicaciones).
- La **localidad es texto libre** en el envío: errores de tipeo ("Formsa") quedan como zonas
  distintas. Para ventas del POS sin envío no hay localidad de la venta.
- No hay **fecha prometida ni fecha de entrega** → "demorado" es por antigüedad del envío.
- No hay **costo interno** de Correo / Andreani / OCA → no se calcula ganancia logística. La paga
  de Moto usa la **tarifa vigente** del barrio, no la histórica.
- El **descuento general** de una venta del POS no se reparte entre productos.
- La agrupación por localidad no distingue **provincias** con localidades del mismo nombre
  (se muestran las provincias registradas al lado).

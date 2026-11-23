# Módulo: Caja del vendedor (apertura / movimientos / cierre de caja)

> Estado: **v2 construida y probada** (2026-11-22). Parte del **Módulo de Procesos** (cierre de caja).
> v1 (2026-09-18): apertura, retiros/ingresos y arqueo solo de efectivo.

## Problema
El vendedor maneja efectivo y otros medios de pago en el mostrador. Hay que saber
con cuánto arranca, **qué pasó durante el día** (cada venta, anulación, retiro,
ingreso o gasto), **cuánto entró por cada forma de pago**, y al final del turno
**contar el efectivo real y verificar el resto de las formas** contra lo esperado
(arqueo). Sin caja no se puede vender (flujo **estricto**).

## Modelo de datos
`schema_caja.sql` (v1) + `schema_caja_cierre.sql` (v2, correr después):
- **caja**: `usuario_id`, `monto_apertura`, `abierta_en`, `estado` (abierta/cerrada),
  y al cerrar: `monto_contado`, `conteo_efectivo` (JSON billete → cantidad),
  `monto_esperado`, `diferencia`, `observacion`, `cerrada_en`, `cerrada_por` (quién la cerró).
- **caja_movimiento**: `caja_id`, `tipo` (**retiro / ingreso / gasto**), `monto`, `motivo`,
  `gasto_id` (si es gasto), `usuario_id`.
- **caja_cierre_forma** *(v2)*: foto del cierre por forma de pago —
  `tipo_pago_id`, `nombre`, `operaciones`, `esperado`, `declarado`, `diferencia`.
  Es la fuente del reporte: si después se anula una venta, el cierre no cambia.
- **venta.caja_id**: cada venta del POS se liga a la caja abierta del vendedor.

## Cómo funciona
1. **Apertura** — el vendedor abre la caja desde el POS con el cambio inicial.
   Una sola caja abierta por usuario.
2. **Venta** — `VentaService` exige caja abierta y guarda `venta.caja_id`.
3. **Movimientos de efectivo**
   - *Retiro*: sacar efectivo (ej. a tesorería). Motivo obligatorio.
   - *Ingreso*: poner efectivo (ej. más cambio).
   - *Gasto*: pago chico con el efectivo de la caja. Motivo obligatorio y **además
     se registra en Gastos** (`gasto`, con la observación “Pagado con efectivo de la caja #N”).
   - Un retiro o gasto **no puede superar el efectivo disponible** en la caja.
4. **Turno en vivo** — el modal de caja del POS muestra: total vendido, cantidad de
   ventas, ticket promedio, **totales por forma de pago** (todas las activas, aunque
   estén en 0), el desglose del efectivo y la **línea de tiempo de movimientos del día**
   (apertura, ventas con sus formas de pago, anulaciones, retiros, ingresos, gastos)
   con el efecto de cada uno en el efectivo y el **saldo acumulado**.
5. **Cierre (arqueo por forma de pago)**
   - **Efectivo**: se cuenta billete por billete (20.000 … 1, + sueltos) o se carga el
     total directo. El servidor recalcula el total desde los billetes.
     `esperado = apertura + ventas en efectivo + ingresos − retiros − gastos`.
   - **Otras formas** (transferencia, etc.): se muestra lo esperado y el vendedor
     declara lo verificado en el homebanking/posnet (por defecto = esperado).
   - Se calcula la diferencia de cada forma. **Si algo no cuadra, la observación es
     obligatoria.**
   - Se guarda la foto por forma (`caja_cierre_forma`), se audita y se avisa a los admin
     (notificación de éxito o alerta).
6. **Reporte de cierre (PDF)** — resumen, ventas por forma, efectivo, arqueo por forma,
   conteo de billetes, observación, movimientos del turno y firmas. Se descarga al
   terminar el cierre (o como reporte parcial con la caja abierta).
7. **Supervisión (admin)** — vista *Cajas*: historial con filtros (fechas, vendedor,
   estado), detalle de cada caja con todo lo anterior, y **Cierre del día (Z)**: el
   consolidado de todas las cajas de una fecha (totales por forma de pago, movimientos
   de efectivo, esperado vs. contado, cajas del día) con su PDF.

## Acceso
- Operar la caja (abrir/mover/cerrar) = **staff** (`Session::esStaff`).
- Detalle/reporte de una caja: el **dueño** de la caja o un **admin**.
- Historial y cierre del día = **solo admin**.

## API
| Método | Ruta | Acceso |
|---|---|---|
| GET  | `/api/caja/estado` | staff — caja abierta + resumen + movimientos del turno |
| POST | `/api/caja/abrir` | staff — `{monto}` |
| POST | `/api/caja/movimiento` | staff — `{tipo: retiro\|ingreso\|gasto, monto, motivo}` |
| POST | `/api/caja/cerrar` | staff — `{billetes?: {"20000":3,…}, monedas_sueltas?, contado?, declarados?: {"<tipo_pago_id>": monto}, observacion?}` |
| GET  | `/api/caja/detalle?id=` | dueño o admin |
| GET  | `/api/caja/reporte?id=` | dueño o admin — PDF |
| GET  | `/api/admin/cajas?desde=&hasta=&usuario_id=&estado=&page=` | admin — historial |
| GET  | `/api/admin/cajas/detalle?id=` | admin |
| GET  | `/api/admin/cajas/dia?fecha=YYYY-MM-DD[&pdf=1]` | admin — cierre del día (Z) |
| POST | `/api/admin/cajas/cerrar` | admin — cierre administrativo: `{caja_id, contado \| billetes, declarados?, observacion}` (observación obligatoria) |

## Cierre administrativo (caja olvidada)
Si un vendedor se va sin cerrar, el admin la cierra desde **Cajas → detalle de la caja →
"Cerrar esta caja"** (o desde el aviso amarillo "Revisar y cerrar" de la lista, que aparece
con cajas abiertas hace más de 14 h). Carga el efectivo que encuentra en el cajón, verifica
los otros medios y deja el **motivo (obligatorio)**. Usa el mismo arqueo que el vendedor,
y además:
- guarda **quién la cerró** en `caja.cerrada_por` (el detalle y el PDF dicen "cierre
  administrativo por …");
- se audita con la acción `cerrar_admin` (Auditoría → "Cierre de caja por admin");
- le llega una notificación al vendedor dueño de la caja y a los admin;
- si el vendedor y el admin intentan cerrar a la vez, gana el primero y el otro recibe
  "Esa caja ya fue cerrada por otra persona" (no se pisa el cierre).

## Archivos
- `database/schema_caja.sql`, `database/schema_caja_cierre.sql`
- `app/Repositories/CajaRepository.php`, `app/Services/CajaService.php`, `app/Controllers/CajaController.php` (incluye los PDF)
- `app/Services/VentaService.php` + `app/Repositories/VentaRepository.php` (liga `caja_id`, exige caja abierta)
- `public/assets/js/caja-ui.js` + `public/assets/css/caja.css`: piezas visuales compartidas POS/admin (badges, formas de pago con barras, libro de efectivo, línea de tiempo con filtros, arqueo). Prefijo `.cx-`.
- `public/assets/js/pos.js` (modal de caja: apertura / turno con pestañas Resumen y Movimientos / cierre en 3 pasos / resultado)
- `public/assets/js/admin-cajas.js` (lista con atajos de fecha y resumen, aviso de cajas olvidadas, detalle, cierre del día), vista en `admin.html`

## Notas
- Las cajas cerradas antes de la v2 no tienen foto por forma: el detalle muestra solo
  la fila de efectivo con lo que se guardó en su momento.
- La zona horaria de PHP se fija en `config/config.php` (`APP_TZ`, por defecto
  `America/Argentina/Buenos_Aires`) para que las fechas de PHP coincidan con las de MySQL.

## Pendiente / futuro
- Bloquear ventas en efectivo cuando no alcanza el cambio.

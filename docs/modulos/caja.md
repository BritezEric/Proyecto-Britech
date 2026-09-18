# Módulo: Caja del vendedor (apertura / cierre / arqueo)

> Estado: **construido y probado** (2026-09-18). Parte del **Módulo de Procesos** (cierre de caja).

## Problema
El vendedor maneja efectivo en el mostrador. Hay que saber con cuánto arranca,
cuánto entró por ventas, cuánto se retiró y, al final del turno, **contar el
efectivo real y compararlo con lo esperado** (arqueo). Sin caja no se puede vender
(flujo **estricto**), y cada venta queda ligada a la caja para el cierre.

## Modelo de datos (`schema_caja.sql`)
- **caja**: `usuario_id`, `monto_apertura`, `abierta_en`, `estado` (abierta/cerrada),
  y al cerrar: `monto_contado`, `monto_esperado`, `diferencia`, `observacion`, `cerrada_en`.
- **caja_movimiento**: `caja_id`, `tipo` (retiro/ingreso), `monto`, `motivo`, `usuario_id`.
- **venta.caja_id**: cada venta del POS se liga a la caja abierta del vendedor.

## Cómo funciona
1. **Apertura** — el vendedor abre la caja desde el POS con un monto inicial. Solo
   puede haber **una caja abierta por usuario** a la vez.
2. **Venta** — `VentaService` exige una caja abierta (si no, error) y guarda `venta.caja_id`.
3. **Movimientos** — retiros/ingresos de efectivo (con motivo).
4. **Cierre (arqueo)** — el sistema calcula el **efectivo esperado**:
   `apertura + ventas en efectivo − retiros + ingresos`. El vendedor ingresa el
   **efectivo contado** y se guarda la **diferencia** (contado − esperado). El cierre
   muestra los totales por forma de pago (efectivo / transferencia).
5. **Supervisión** — el admin ve el **historial de cajas** (vista *Cajas*) con el
   detalle de cada cierre: resumen + movimientos + diferencia.

## Acceso
- Operar la caja (abrir/mover/cerrar) = **staff** (`Session::esStaff`): la usa el vendedor
  desde el POS; el admin también puede.
- Historial de todas las cajas = **solo admin** (`/api/admin/cajas`).

## API
| Método | Ruta | Acceso |
|---|---|---|
| GET  | `/api/caja/estado` | staff — caja abierta + resumen del turno |
| POST | `/api/caja/abrir` | staff |
| POST | `/api/caja/movimiento` | staff — retiro/ingreso |
| POST | `/api/caja/cerrar` | staff — arqueo |
| GET  | `/api/admin/cajas` | admin — historial |
| GET  | `/api/admin/cajas/detalle?id=` | admin |

## Archivos
- `database/schema_caja.sql`
- `app/Repositories/CajaRepository.php`, `app/Services/CajaService.php`, `app/Controllers/CajaController.php`
- `app/Services/VentaService.php` + `app/Repositories/VentaRepository.php` (liga `caja_id`, exige caja abierta)
- `public/assets/js/pos.js` (barra + modal de caja en el POS), estilos en `pos.css`
- `public/assets/js/admin-cajas.js`, vista en `admin.html`, estilos en `admin.css`

## Pendiente / futuro
- Reporte de cierre imprimible (PDF), Z de caja diario, y bloquear ventas grandes en
  efectivo sin cambio suficiente (validaciones de negocio adicionales).

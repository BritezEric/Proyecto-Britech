# Módulo: Reportes y Control Operativo (Salidas del Sistema)

> Estado: **construido** (2026-11-01). Parte del Módulo 5 — Salidas del Sistema.
> Panel → **Reportes** → pestañas *Medios de pago · Cajas y anulaciones · Vendedores · Tienda online ·
> Reclamos · Envíos sin asignar · Carga por barrio* (la pestaña *Localidades* es el reporte anterior).

## Para qué sirve
Es el control del **día a día** del administrador: cuánta plata entró y por qué medio, si las cajas
cerraron bien, cómo vendió cada vendedor, qué pedidos esperan pago o preparación, qué reclamos no se
respondieron y qué envíos esperan repartidor. Todos los reportes abren en **hoy** y aceptan cualquier
período de hasta un año.

## Quién lo usa
| Rol | Acceso |
|---|---|
| **admin** | Los 7 reportes + exportaciones |
| **vendedor** | No ve las pestañas; la API le responde **403** aunque escriba la URL |
| **cliente / sin sesión** | **401** (Router) |

## Cómo se arma (arquitectura)
`admin.html` (pestañas) / `admin-reportes-operativos.js` → `routes/api.php` → `ReporteOperativoController`
→ `ReporteOperativoService` → `ReporteOperativoRepository` → PDO.

- **Repository**: SQL de solo lectura y parametrizado. Cada consulta trae filas al nivel justo para no
  duplicar importes; los "uno a muchos" (pagos, movimientos de caja, mensajes) se agrupan en subconsultas
  **antes** de unirse.
- **Service**: valida filtros (fechas AAAA-MM-DD, listas cerradas, enteros con rango), calcula
  indicadores y devuelve todos los reportes con la **misma estructura**: `kpis`, `grafico`, `tablas`, `notas`.
- **Controller**: permiso de admin, JSON, y CSV/PDF **genéricos** (sirven para los 7 por esa estructura común).
- **Frontend**: una sola pantalla genérica pinta cualquiera de los 7: filtros, tarjetas, gráfico (Chart.js ya
  cargado), tablas con búsqueda, orden y paginación, y accesos a pantallas existentes.

## Los 7 reportes
| Reporte | Tablas que usa | Reglas principales |
|---|---|---|
| **Medios de pago** | `pago`, `tipo_pago`, `venta`, `pedido`, `envio` | Solo suma lo **acreditado**: ventas registradas y pedidos con pago "pagado". Pendientes, en revisión, rechazados, anulados y cancelados se listan pero no suman. Una venta pagada con dos medios cuenta en ambos importes pero como **una** operación. Online = productos + envío. |
| **Cajas y anulaciones** | `caja`, `caja_movimiento`, `venta`, `pago`, `venta_anulacion` | Esperado = apertura + ventas en efectivo − retiros + ingresos (misma fórmula que `CajaService`). Diferencia = declarado − esperado: negativa **faltante**, positiva **sobrante**, cero **coincide**. Cajas abiertas: esperado en vivo, sin arqueo. Las anulaciones se fechan cuando se anularon; las ventas no se borran. |
| **Vendedores** | `venta`, `usuario`, `pago` | Solo POS (los pedidos online no tienen vendedor). Ticket = total ÷ ventas **válidas** (sin anuladas). % anulaciones = anuladas ÷ todas. Ranking por total vendido. |
| **Tienda online** | `pedido`, `envio`, `empresa_envio` | Cada pedido en **una** etapa: cancelado → entregado → enviado → listo para retirar → pendiente de pago → pagado a preparar → en preparación. Alerta si un pedido activo no se actualiza hace N horas (24 por defecto). |
| **Reclamos** | `reclamo`, `reclamo_mensaje`, `pedido` | **Sin responder** = activo y sin respuesta del staff o con el último mensaje del cliente. Alerta si está abierto hace N horas (48). Responder usa el módulo de reclamos (notifica al cliente). |
| **Envíos sin asignar** | `envio`, `barrio`, `repartidor` | Solo Moto Express (con barrio). Espera = creado → ahora si no tiene repartidor; creado → `asignado_en` si ya se asignó. |
| **Carga por barrio** | `envio`, `barrio`, `repartidor` | Cada envío en un grupo: pendiente (sin repartidor), asignado, en reparto, entregado, cancelado (incluye venta anulada / pedido cancelado). |

Por defecto, *Tienda online*, *Reclamos* y *Envíos sin asignar* incluyen también lo que sigue activo de días
anteriores (se puede desmarcar), porque un pedido impago de ayer sigue requiriendo acción hoy.

## Acciones (no modifican nada desde el reporte)
- Pedido → abre el detalle existente (`verPedido`), donde ya se cambian estado, pago y envío.
- Reclamo → abre el reclamo en *Reclamos* (historial y respuesta al cliente).
- Caja → abre el detalle en *Cajas*.
- Envío sin asignar → lleva al tablero de *Repartos* ("Derivar a…"). No hay asignación automática.
- Vendedor → filtra sus operaciones. Barrio → muestra sus envíos.

## Cambios en la base (`database/schema_reportes_operativos.sql`)
Re-ejecutable, solo **agrega** (no borra datos ni toca claves):
- `envio.asignado_en` (DATETIME NULL): hora de la primera asignación. La completa `EnvioRepository`
  al derivar (si la columna no existe todavía, el sistema sigue funcionando igual).
- `pedido.actualizado_en` (DATETIME NULL, `ON UPDATE CURRENT_TIMESTAMP`): última modificación del pedido.
- Índices por fecha en `venta`, `pedido`, `envio`, `caja`, `venta_anulacion` y `reclamo`.

## Exportaciones
- **CSV** (`tabla=N` elige cuál): UTF-8 con BOM, `;`, coma decimal; **todas** las filas; escapa `= + - @`.
- **PDF** (A4 apaisado): filtros, indicadores, gráfico de barras, tablas (150 filas c/u) y aclaraciones.

## Pruebas
`tests/reportes_operativos_test.php` + `tests/fixtures/reportes_operativos_seed.sql` (todo el 20/10/2026,
valores calculados a mano) en una base **`*_test`**; con `BRITECH_TEST_URL` prueba permisos, 422, CSV y PDF:
```
DB_HOST=127.0.0.1 DB_NAME=britech_reportes_test BRITECH_TEST_URL=http://127.0.0.1:8124 php tests/reportes_operativos_test.php
```

## Limitaciones (datos que Britech no registra)
- **Fecha de acreditación** de un pedido online: se usa la fecha del pedido.
- **Estados "listo para retirar" y "enviado"**: no existen en `pedido`; se deducen del estado del envío.
- **Sucursales / puntos de venta**: no existen → no hay ese filtro.
- **Fecha prevista de entrega**: no existe → no se muestra.
- Envíos asignados **antes** de aplicar el script no tienen hora de asignación ("sin hora registrada").
- El **motivo** de un reclamo es el asunto que escribe el cliente (texto libre), por eso se filtra por texto.
- Pantalla: hasta 3.000 filas por tabla (los totales usan todas; el CSV exporta todas).

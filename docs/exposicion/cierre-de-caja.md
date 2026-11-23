# Exposición — Cierre de caja

Guía para mostrar y defender el **cierre de caja** (versión 2). Tiene cuatro partes:

1. [Qué es, en dos frases](#1-qué-es-en-dos-frases)
2. [Guion de la demo](#2-guion-de-la-demo-810-min) (qué tocar y qué decir, en orden)
3. [Cómo está hecho](#3-cómo-está-hecho) (archivos, base de datos y decisiones)
4. [Preguntas típicas](#4-preguntas-típicas-y-cómo-responderlas) y [checklist antes de exponer](#5-checklist-antes-de-exponer)

Detalle técnico completo (API, tablas): [docs/modulos/caja.md](../modulos/caja.md).

---

## 1. Qué es, en dos frases

El vendedor **abre la caja** con el cambio, vende, y registra todo lo que entra y sale
de plata durante el día. Al final **cuenta el efectivo billete por billete y verifica
los demás medios de pago**: el sistema calcula cuánto *debería* haber, muestra la
diferencia y guarda el cierre con un reporte en PDF. El admin supervisa todas las
cajas, ve cuáles no cuadraron y saca el **cierre del día**.

**La idea que tiene que quedar:** el sistema sabe cuánta plata *debería* haber en el
cajón en todo momento, y el cierre compara eso con la plata *real*, por cada forma de pago.

---

## 2. Guion de la demo (8–10 min)

> Antes de empezar: mirá el [checklist](#5-checklist-antes-de-exponer). Tené abiertas dos
> pestañas: el **POS** (vendedor) y el **panel admin → Dinero e informes → Cajas**.

### Paso 1 — Sin caja no se vende (30 s)
- En el POS, el botón de arriba dice **"Abrir caja"** en rojo.
- Agregá un producto y tocá **Cobrar** → se abre la ventana de caja.
- 🗣 *"El flujo es estricto: cada venta queda atada a una caja abierta. Si no, no
  sabríamos a qué turno pertenece la plata."*

### Paso 2 — Abrir la caja (30 s)
- Escribí **$10.000** (o tocá un monto rápido). Mostrá el chip *"Lo que quedó"*: sugiere
  lo que se contó en el último cierre.
- Enter → aviso "Caja abierta". El botón ahora dice **Caja #N · $10.000** en verde.
- 🗣 *"El botón muestra en todo momento cuánto efectivo debería haber en el cajón."*

### Paso 3 — Vender con distintas formas de pago (1 min)
- Una venta **en efectivo**.
- Una venta **mixta**: parte efectivo, parte transferencia.
- Abrí la caja → pestaña **Resumen**:
  - "Cobrado por forma de pago" con barras.
  - "Efectivo": apertura + ventas en efectivo + ingresos − retiros − gastos.
- 🗣 *"La transferencia suma a lo vendido pero **no** al cajón: el efectivo esperado
  solo cuenta lo que entra físicamente."*

### Paso 4 — Movimientos del día (1 min)
- Tocá **Retiro** → $3.000, chip *"A tesorería"*, Enter.
- Tocá **Gasto** → $1.500, chip *"Artículos de limpieza"*.
- Mostrá que **el gasto también aparece en Panel → Gastos** (se registra solo).
- Intentá un retiro más grande que lo que hay → error *"No hay tanto efectivo"*.
- Pestaña **Movimientos**: la línea de tiempo con cada venta (y cómo se pagó), retiro,
  gasto, el efecto en el efectivo y **cuánto había en la caja después de cada uno**.
  Usá los filtros (Ventas / Retiros, ingresos y gastos).

### Paso 5 — El cierre en 3 pasos (2 min) ⭐ la parte central
- Tocá **Cerrar caja**.
- **1 · Contar efectivo:** cargá billetes con **+ / −**. Abajo, la barra fija muestra
  **Esperado / Contado / Resultado** en vivo. Dejá que **falten $500** a propósito.
- **2 · Otros medios:** Transferencia → **"Coincide"** (o "No coincide" y cargás lo real).
- **3 · Confirmar:** aparece en rojo *"La caja no cuadra (Falta $500)"*.
  Intentá confirmar sin observación → no deja. Elegí *"Vuelto mal dado"* → Confirmar.
- Pantalla final + **Descargar reporte de cierre** → mostrá el PDF (arqueo por forma,
  billetes contados, todos los movimientos y firmas).
- 🗣 *"El servidor vuelve a sumar los billetes: no confía en el total que manda el
  navegador. Y si no cuadra, el vendedor tiene que explicar por qué."*

### Paso 6 — El admin supervisa (2 min)
- Panel → **Cajas**: atajos de fecha, la franja de resumen ("No cuadraron: 1") y la fila
  con **Falta $500** en rojo.
- Filtro **"No cuadraron"**.
- Entrá a la caja: el veredicto con la explicación del vendedor, el arqueo por forma,
  los billetes contados y los movimientos.
- **Cierre del día** → totales de todas las cajas por forma de pago, con ‹ › para
  cambiar de día y PDF.

### Paso 7 — La caja olvidada (1 min)
- Si hay una caja abierta hace más de 14 h, aparece el **aviso amarillo**.
  *(Para la demo: dejá una caja abierta desde el día anterior.)*
- **Revisar y cerrar** → cargá el efectivo encontrado, motivo *"Se olvidó de cerrarla"*,
  confirmar dos veces.
- Mostrá el badge **"Cerrada por Administrador"** y la fila en **Auditoría**
  ("Cierre de caja por admin").
- 🗣 *"Queda registrado quién la cerró y al vendedor le llega una notificación."*

---

## 3. Cómo está hecho

Sigue la misma arquitectura en capas que todo Britech
(ver [README de exposición](README.md#arquitectura-común-la-sabe-todo-el-equipo)):

```
POS (pos.js) / Panel (admin-cajas.js)    ← pantallas, piezas compartidas en caja-ui.js + caja.css
        │  fetch /api/caja/...  /api/admin/cajas/...
routes/api.php → CajaController           ← permisos (staff / admin) y respuestas JSON / PDF
        │
CajaService                               ← reglas de negocio: esperado, arqueo, validaciones
        │
CajaRepository                            ← único lugar con SQL (PDO preparado)
        │
MySQL: caja · caja_movimiento · caja_cierre_forma · venta.caja_id
```

### Archivos (para abrirlos si preguntan)
| Capa | Archivo | Qué mirar |
|---|---|---|
| Base | [`database/schema_caja.sql`](../../database/schema_caja.sql) | tablas `caja` y `caja_movimiento`, `venta.caja_id` |
| Base | [`database/schema_caja_cierre.sql`](../../database/schema_caja_cierre.sql) | v2: `caja_cierre_forma`, `conteo_efectivo`, `cerrada_por`, tipo `gasto`. **Re-ejecutable** |
| Reglas | [`app/Services/CajaService.php`](../../app/Services/CajaService.php) | `esperado()`, `movimiento()`, `cerrar()` → `cerrarCaja()`, `cerrarComoAdmin()`, `cierreDelDia()`, `timeline()` |
| SQL | [`app/Repositories/CajaRepository.php`](../../app/Repositories/CajaRepository.php) | `totalesPorForma()`, `resumenDe()`, `cerrar()`, `esquemaListo()` |
| API + PDF | [`app/Controllers/CajaController.php`](../../app/Controllers/CajaController.php) | permisos de cada ruta, `htmlReporte()` (PDF con Dompdf) |
| POS | [`public/assets/js/pos.js`](../../public/assets/js/pos.js) | sección `CAJA`: `pintarCajaApertura/Turno/Cierre/Listo()` |
| Panel | [`public/assets/js/admin-cajas.js`](../../public/assets/js/admin-cajas.js) | `renderLista()`, `renderDetalle()`, `panelCierreAdmin()`, `renderDia()` |
| UI compartida | [`caja-ui.js`](../../public/assets/js/caja-ui.js) + [`caja.css`](../../public/assets/css/caja.css) | badges, formas con barras, libro de efectivo, línea de tiempo |
| Tests | [`tests/caja_test.php`](../../tests/caja_test.php) | 24 pruebas del ciclo completo |

### La cuenta central (saberla de memoria)

```
efectivo esperado = apertura + ventas en efectivo + ingresos − retiros − gastos
diferencia        = efectivo contado − efectivo esperado      (0 = cuadra, + sobra, − falta)
```

Ejemplo: apertura 10.000, dos ventas en efectivo de 8.000, un retiro de 3.000 y un gasto
de 1.500 → 10.000 + 16.000 − 3.000 − 1.500 = **21.500** esperado.
Se cuentan 21.000 → **falta 500**.

Las demás formas (transferencia, etc.) se arquean aparte: **esperado** = lo cobrado por
esa forma en el turno, **declarado** = lo que el vendedor verificó en el banco o posnet.

### Decisiones de diseño (el "por qué")
1. **Foto del cierre por forma de pago** (`caja_cierre_forma`). Al cerrar se guarda
   esperado/declarado/diferencia de cada forma. Si después se anula una venta, el
   cierre ya hecho **no cambia**: es un documento, como un ticket.
2. **El servidor recalcula todo.** El conteo llega como `{billete: cantidad}` y el
   backend hace la suma; si el navegador manda un "total" falso, se ignora. Lo mismo
   que hacemos con precios y stock.
3. **Transacción + "solo si sigue abierta".** El cierre se guarda dentro de una
   transacción y el `UPDATE` lleva `WHERE estado = 'abierta'`. Si el vendedor y el admin
   cierran a la vez, gana el primero y el segundo recibe un error claro: nada se pisa.
4. **Reglas de plata en el backend:** un retiro o gasto no puede superar el efectivo
   disponible; el motivo es obligatorio en retiros y gastos; si algo no cuadra, la
   observación es obligatoria.
5. **El gasto de caja también es un gasto del negocio:** se registra en `gasto` en la
   misma transacción, así aparece en Finanzas sin cargarlo dos veces.
6. **Trazabilidad:** apertura, movimientos y cierre quedan en la **Auditoría**
   (con valor esperado → contado), y los admin reciben una notificación de cada cierre.
7. **Base actualizable sin romper nada:** `schema_caja_cierre.sql` se puede correr las
   veces que haga falta. Si a alguien le falta, la app **avisa qué script correr** en
   vez de romperse (`CajaRepository::esquemaListo()`).

### Pruebas automáticas
```
DB_NAME=britech_caja_test php -S 127.0.0.1:8125 -t public server.php
DB_NAME=britech_caja_test BRITECH_TEST_URL=http://127.0.0.1:8125 php tests/caja_test.php
```
24 pruebas que recorren un turno real por HTTP (vendedor + admin): apertura, ventas
mixtas, movimientos, anulación, arqueo con billetes, foto del cierre, PDF, historial,
cierre del día, caja olvidada y permisos. Solo corre sobre una base `*_test`.

Dato para contar: para comprobar que el test **sirve**, rompimos a propósito la cuenta
(dejamos de restar los gastos) y el test lo detectó: 3 pruebas fallaron mostrando la
diferencia exacta de $1.500.

---

## 4. Preguntas típicas (y cómo responderlas)

**¿Por qué la transferencia no suma al efectivo esperado?**
Porque no entra al cajón. Se controla aparte en el paso 2 del cierre, contra el banco.

**¿Qué pasa si el vendedor miente con el total?**
No puede: el servidor suma los billetes que contó. Y la diferencia queda guardada,
auditada y notificada al admin con su explicación.

**¿Qué pasa si se anula una venta después del cierre?**
El cierre no cambia: se guardó una foto por forma de pago en `caja_cierre_forma`. (Una
mejora pendiente es que la devolución aparezca como salida en la caja abierta de ese momento.)

**¿Un vendedor puede tener dos cajas abiertas? ¿Puede ver la caja de otro?**
No y no. `abrir()` lo impide, y el detalle y el PDF solo los ve el dueño de la caja o un admin.

**¿Y si el vendedor se va sin cerrar?**
Pasadas 14 h aparece el aviso en el panel y el admin hace un **cierre administrativo**:
mismo arqueo, motivo obligatorio, queda `cerrada_por` y se notifica al vendedor.

**¿Por qué el gasto de caja se guarda en dos tablas?**
En `caja_movimiento` para la plata del cajón, y en `gasto` para las finanzas del negocio.
Se hace en la misma transacción, así nunca queda uno sin el otro.

**¿Cómo evitan inyección SQL?**
Todo el SQL está en el Repository con PDO y parámetros `?`; los filtros del panel
(fechas, vendedor, estado) se validan en el controlador.

**¿Qué pasa si dos personas cierran la misma caja a la vez?**
El `UPDATE ... WHERE estado = 'abierta'` solo afecta a una fila la primera vez; el
segundo recibe "Esa caja ya fue cerrada por otra persona" y su transacción se cancela.

**¿Cómo saben que funciona?**
`tests/caja_test.php` (24 pruebas) + el smoke test y el de auditoría del equipo pasan.

---

## 5. Checklist antes de exponer
- [ ] MySQL de Laragon prendido y `INICIAR-SERVIDOR.bat` corriendo.
- [ ] La base tiene `database/schema_caja_cierre.sql` aplicado (si no, la caja avisa).
- [ ] Usuario admin y un vendedor con contraseña conocida.
- [ ] Hay productos con stock y precio para vender.
- [ ] **No** hay una caja abierta del usuario con el que vas a mostrar la apertura
      (o cerrala antes desde el panel).
- [ ] Para el paso 7: una caja de otro vendedor abierta desde ayer (o mostralo con una
      que ya tengas olvidada).
- [ ] Los PDF se abren (Dompdf instalado con `composer install`).
- [ ] Ensayaste el paso 5 con la diferencia de $500: es el momento central de la demo.

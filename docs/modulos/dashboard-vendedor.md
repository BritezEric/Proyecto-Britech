# Módulo: Dashboard del vendedor ("Mi panel")

> Estado: **construido y probado** (2026-10-02). Rama: `DashboardVendedor`.
> Sin tablas nuevas: solo **lee** datos que ya existen (`venta`, `caja`, `cliente`).

## Problema
El vendedor entraba al panel y caía directo en *Pedidos*, sin ninguna vista de
cómo venía su trabajo. Necesita un tablero **propio e individual**: que le muestre
**solo su desempeño** (no el de otros vendedores) en el día, la semana, el mes o el año.

---

## Parte 1 — Cómo se usa (explicación sencilla)

**Cómo se entra:** desde el **POS** con el botón **📊 Mi panel** (barra de arriba, lo ven
todos los roles), o desde el menú del panel. Para volver al POS: botón **🧾 Ir al POS**.

Al iniciar sesión como **vendedor**, el panel abre directamente en **Mi panel**
(el título dice tu nombre: *Mi panel · Juan*). Cada vendedor ve **solo sus números**.
(El admin también tiene *Mi panel* en el menú y ve los suyos, por ejemplo si vende en el POS.)

Arriba a la derecha hay un selector **Día / Semana / Mes / Año** que cambia el período
de **todo** el panel a la vez:

| Opción | Qué abarca | Cada barra es… |
|---|---|---|
| Día | hoy | una hora (0h a 23h) |
| Semana | últimos 7 días | un día |
| Mes | últimas 4 semanas | una semana |
| Año | últimos 12 meses | un mes |

Los gráficos cuentan **cantidades** (cuántas ventas, cuántas compras, cuántas cajas).
Arriba de cada barra está escrito el número, y en la leyenda aparece el total del período.

1. **Tarjetas de resumen** — mis ventas, clientes atendidos (clientes identificados distintos),
   ticket promedio ($ por venta) y ventas anuladas, siempre del período elegido.

2. **📈 Frecuencia de mis ventas** — cuántas ventas hice en cada hora/día/semana/mes,
   y en rojo las que se anularon. Muestra la evolución de mi trabajo.

3. **🛒 Compras de mis clientes** — de mis ventas, cuántas fueron a **clientes identificados**
   (con nombre, que vuelven a comprar) y cuántas a **Consumidor final**.
   Al lado, **⭐ Mis clientes que más compran**: los 5 clientes que más veces me compraron.

4. **📦 Mis cajas abiertas y cerradas**
   - **Abiertas ahora**: mis cajas que todavía no cerré (sean de cuando sean).
     Si aparece una caja de hace días, me olvidé de cerrarla.
   - **Cerradas**: las que cerré en el período.
   - El gráfico compara, por hora/día/semana/mes, las aperturas que siguen abiertas vs. los cierres.

5. **🏆 Mis ventas por día (o por hora/semana/mes) · de mayor a menor** — listado de mis
   mejores momentos del período, ordenado siempre **del número más alto al más bajo**.
   Los 3 primeros llevan medalla 🥇🥈🥉. Sirve para ver qué días u horas vendo más.

---

## Parte 2 — Cómo está hecho (para el equipo)

Respeta la misma arquitectura del resto del proyecto:
`Router → Controller → Repository → PDO` en el back, y HTML + CSS + JS puro en el front
(gráficos hechos con `div`s y CSS, igual que el dashboard del admin — **sin librerías nuevas**).

### Archivos

| Capa | Archivo | Qué hace |
|---|---|---|
| Ruta | `routes/api.php` | `GET /api/vendedor/dashboard` (requiere login) |
| Controller | `app/Controllers/VendedorDashboardController.php` | Valida staff y período; toma el **id de la sesión** |
| Repository | `app/Repositories/VendedorDashboardRepository.php` | Consultas SQL (lectura), todas filtradas por `usuario_id` |
| Vista | `public/admin.html` | Botón **Mi panel** en el menú + sección `#vista-vendedor` |
| Lógica front | `public/assets/js/admin-vendedor.js` | Pide los datos y dibuja tarjetas, gráficos y listados |
| Navegación | `public/assets/js/admin.js` | `seleccionar('vendedor')`; el vendedor aterriza ahí al entrar; con `#vendedor` también el admin |
| POS | `public/pos.html` | Botón **📊 Mi panel** → `/admin.html#vendedor` (todos los roles) |
| Estilos | `public/assets/css/admin.css` | Bloque `Dashboard del vendedor` (reusa `.dash-panel`, `.barras`, `.hbar-*`, `.card`) |

### Individual: cómo se garantiza
El id del vendedor sale **siempre de la sesión** (`Session::usuarioId()`), nunca de un
parámetro del request. Así un vendedor no puede pedir el panel de otro cambiando la URL.
Cada consulta lleva `WHERE usuario_id = ?`.

### API

`GET /api/vendedor/dashboard?periodo=dia|semana|mes|ano` (si el período no es válido, usa `semana`).

Acceso: **staff** (vendedor o admin). Sin sesión de staff (incluye clientes de la tienda) el Router responde 401; además el controller verifica `Session::esStaff()` (403) como segunda barrera.

```json
{
  "ok": true,
  "periodo": "semana",
  "vendedor": "Juan",
  "resumen": { "ventas": 4, "anuladas": 1, "clientes": 2, "ticket": 3500 },
  "ventas":  [{ "label": "Jue 01", "ventas": 2, "anuladas": 1 }, ...],
  "compras": [{ "label": "Jue 01", "identificados": 2, "consumidor_final": 0 }, ...],
  "clientes_frecuentes": [{ "cliente": "Juan Perez", "compras": 2 }, ...],
  "cajas": { "abiertas": 1, "cerradas": 2,
             "serie": [{ "label": "Jue 01", "abiertas": 0, "cerradas": 1 }, ...] }
}
```

El listado "de mayor a menor" se arma en el front a partir de `ventas`
(filtra los períodos con ventas y los ordena por cantidad descendente).

### Cómo se arman los gráficos por período ("baldes")
Misma técnica que `DashboardRepository::serieVentas` (dashboard admin):

1. En PHP se crean los **baldes** vacíos del período, cada uno con su clave y etiqueta
   (ej. `2026-09-29 → "Lun 29"`), así aparecen también los momentos **sin** ventas (en 0).
2. En SQL se agrupa con una expresión que produce **la misma clave**:
   `HOUR(col)` (día), `DATE(col)` (semana), `DATE_FORMAT(col,'%x%v')` (mes, semana ISO),
   `DATE_FORMAT(col,'%Y-%m')` (año).
3. Se cuenta (`COUNT(*)`) y cada resultado se suma en su balde (`contarEn`).

> **Zona horaria:** `public/index.php` fija la zona de PHP en `America/Argentina/Buenos_Aires`
> (configurable con `APP_TIMEZONE` en el `.env`). Sin eso PHP usa UTC y MySQL la hora local,
> y los baldes por día/hora quedaban corridos 3 horas.

### Reglas de cada consulta (todas con `usuario_id` = vendedor logueado)
- **Ventas**: `venta.estado = 'registrada'`; las anuladas se cuentan aparte (`'anulada'`).
- **Compras de mis clientes**: mis ventas registradas, separadas por `cliente_id <> 1`
  (identificado) vs `cliente_id = 1` (Consumidor Final).
- **Clientes frecuentes**: mis ventas registradas agrupadas por cliente, sin Consumidor Final, top 5.
- **Cajas**: abiertas = mis cajas con `estado = 'abierta'`; cerradas = mis cajas con `cerrada_en` en el período.
- **Resumen**: ventas, anuladas, clientes distintos y ticket promedio (`AVG(total)`) del período.

### Seguridad
- Sentencias preparadas (`?`) en todas las consultas; el período se valida contra una lista fija.
- El vendedor nunca ve datos de otros vendedores.
- El front escapa todo texto con `esc()` antes de insertarlo en el HTML.

### Cómo probarlo
1. Levantar el proyecto con `INICIAR-SERVIDOR.bat` e iniciar sesión con un usuario **vendedor**.
2. Debe abrir en **Mi panel · (nombre)**. Hacer una venta en el POS → recargar: sube "Mis ventas".
3. Entrar con otro vendedor → no ve las ventas del primero.
4. Abrir/cerrar una caja desde el POS → cambian las tarjetas de cajas.
5. Probar Día / Semana / Mes / Año.

Probado (2026-10-02) contra MySQL 8.4 con datos de ejemplo: un vendedor con ventas en
distintos días (registradas y anuladas, a Consumidor Final y a clientes) y cajas abiertas y
cerradas, más una venta y una caja del admin que **no** aparecen en el panel del vendedor.

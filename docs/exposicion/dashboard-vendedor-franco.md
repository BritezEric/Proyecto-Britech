# Dashboard del vendedor ("Mi panel") — guía para explicarlo · Franco

> Todo lo que se hizo en la rama **`DashboardVendedor`**, explicado de la forma más simple
> posible para poder contarlo en la exposición: **qué se pidió, cómo se usa, cómo funciona
> por dentro, qué archivos se tocaron, qué problemas aparecieron y cómo se resolvieron.**
>
> Fecha: 02/10/2026. Documentación técnica corta del módulo:
> [`docs/modulos/dashboard-vendedor.md`](../modulos/dashboard-vendedor.md).

---

## 0. La idea en una frase (para arrancar la explicación)

> "Hicimos un **panel personal para cada vendedor**, al que entra desde el POS con el botón
> **📊 Mi panel**. Ahí ve **solo su propio desempeño**: cuántas ventas hizo, cuántos clientes
> le compraron, cómo están sus cajas y cuáles fueron sus mejores días. Puede elegir verlo
> por **día, semana, mes o año**. Está hecho con la **misma arquitectura y estilos** que el
> resto del sistema, sin agregar ninguna tecnología nueva."

---

## 1. Qué se pidió

El pedido original tenía 4 cosas:

| # | Pedido | Cómo quedó resuelto |
|---|---|---|
| 1 | 📈 **Frecuencia de ventas**: gráfico con la cantidad de ventas y su evolución | Gráfico de barras con mis ventas (y las anuladas en rojo) por hora / día / semana / mes |
| 2 | 🛒 **Frecuencia de compras** de los clientes | Gráfico con las compras de mis clientes: clientes identificados vs. "Consumidor final", + lista de los clientes que más me compran |
| 3 | 📦 **Cajas abiertas y cerradas** con las cantidades claras | Dos tarjetas grandes (abiertas ahora / cerradas) + gráfico comparativo |
| 4 | 🏆 **Listado de ventas ordenado de mayor a menor** | Lista "mis ventas por día (u hora/semana/mes)" ordenada siempre del número más alto al más bajo, con medallas 🥇🥈🥉 |

**Regla importante del pedido:** usar la misma tecnología, estructura, estilos y lógica del
proyecto. **No se agregó ninguna librería** (ni Chart.js ni nada): los gráficos están hechos
con `div` + CSS, igual que el dashboard del admin.

### Un cambio que se pidió después: que sea INDIVIDUAL
La primera versión mostraba datos de todo el local (ventas de todos y un ranking entre
vendedores). Después se pidió que **cada vendedor vea solo lo suyo**. Por eso:
- "Total del local" se sacó → ahora es **solo mis ventas**.
- El ranking entre vendedores se cambió por **mis mejores momentos** (mis ventas por día/hora…
  ordenadas de mayor a menor). Así se sigue cumpliendo "listado de mayor a menor", pero individual.
- Se agregó el período **Día** (hoy, hora por hora).

---

## 2. Cómo se usa (lo que ve el usuario)

### 2.1 Cómo se entra
Hay **tres caminos**, todos llegan a la misma pantalla:

1. **Desde el POS:** en la barra de arriba está el botón **📊 Mi panel** (al lado de "Caja").
   Lo ven todos: vendedores y admin.
2. **Al iniciar sesión como vendedor** y entrar al panel, abre directo en **Mi panel**
   (antes caía en "Pedidos").
3. **Desde el menú del panel:** opción **Mi panel** (el admin la tiene debajo de "Inicio").

Para volver al POS, en Mi panel hay un botón **🧾 Ir al POS**.

### 2.2 Qué hay en la pantalla (de arriba hacia abajo)

**Título:** *Mi panel · (nombre del vendedor)*. Así queda claro de quién son los números.

**Selector de período** (arriba a la derecha): **Día / Semana / Mes / Año**. Cambia **todo**
el panel a la vez.

| Período | Qué abarca | Cada barra del gráfico es… |
|---|---|---|
| Día | hoy | una hora (de 0h a 23h) |
| Semana | los últimos 7 días | un día |
| Mes | las últimas 4 semanas | una semana |
| Año | los últimos 12 meses | un mes |

**1) Tarjetas de resumen** (del período elegido):
- **Mis ventas:** cuántas ventas hice.
- **Clientes atendidos:** cuántos clientes *distintos* con nombre me compraron.
- **Ticket promedio:** cuánto vale, en promedio, cada venta ($).
- **Anuladas:** cuántas de mis ventas se anularon.

**2) 📈 Frecuencia de mis ventas:** barras violetas = ventas, barras rojas = anuladas.
Arriba de cada barra está el número, y en la leyenda está el total del período.
*Sirve para ver la evolución: si vendo más o menos que antes.*

**3) 🛒 Compras de mis clientes:** de mis ventas, cuántas fueron a **clientes identificados**
(gente con nombre, que vuelve a comprar) y cuántas a **Consumidor final** (venta de mostrador
sin datos). *Sirve para ver si estoy fidelizando clientes.*

**4) 📦 Mis cajas abiertas y cerradas:**
- **Abiertas ahora:** mis cajas que todavía no cerré (de cualquier fecha). Si aparece una
  caja de hace días, quiere decir que me olvidé de cerrarla.
- **Cerradas:** las que cerré dentro del período.
- Debajo, un gráfico que compara aperturas y cierres en el tiempo.

**5) 🏆 Mis ventas por día (u hora / semana / mes) · de mayor a menor:** lista de mis
mejores momentos. Siempre arriba el número más alto. Los 3 primeros tienen medalla.
*Sirve para saber qué días u horarios vendo más.*

**6) ⭐ Mis clientes que más compran:** los 5 clientes que más veces me compraron.

### 2.3 Ejemplo para contar
> "Si un vendedor elige **Semana**, ve por ejemplo: *4 ventas, 2 clientes atendidos, ticket
> promedio $3.500 y 1 anulada*. En el gráfico ve que el jueves vendió 2 y se le anuló 1, y el
> viernes vendió 2. En la lista de mayor a menor aparece primero el día que más vendió."

---

## 3. Cómo funciona por dentro (el recorrido de punta a punta)

Es el **mismo recorrido en capas** que usa todo el proyecto:

```
[Navegador]  admin-vendedor.js  →  fetch GET /api/vendedor/dashboard?periodo=semana
      │
      ▼
public/index.php        ← Front Controller: la única puerta de entrada
      │
      ▼
Router (routes/api.php) ← busca la ruta y como es "true" exige estar logueado (si no → 401)
      │
      ▼
VendedorDashboardController::resumen()   ← valida staff + período, toma MI id de la sesión
      │
      ▼
VendedorDashboardRepository              ← arma las consultas SQL (solo COUNT/AVG, lectura)
      │
      ▼
PDO → MySQL (tablas venta, caja, cliente)
      │
      ▼
JSON de vuelta  →  admin-vendedor.js dibuja tarjetas, barras y listas
```

### 3.1 Backend paso a paso

**a) La ruta** — [`routes/api.php`](../../routes/api.php) (línea ~65)
```php
$router->get('/api/vendedor/dashboard', [VendedorDashboardController::class, 'resumen'], true);
```
El `true` del final significa *"esta ruta requiere sesión"*. Si nadie inició sesión, el
Router corta y responde **401** sin llegar al controller.

**b) El controller** — [`app/Controllers/VendedorDashboardController.php`](../../app/Controllers/VendedorDashboardController.php)

Hace 3 cosas (y nada de SQL, como todos los controllers del proyecto):
1. **Chequea que sea staff** (`Session::esStaff()` → vendedor o admin) → si no, **403**.
   Es una **segunda barrera**: en la práctica el Router ya cortó antes con 401 a quien no
   tenga sesión de staff (incluidos los clientes de la tienda, que tienen otra sesión).
2. **Valida el período**: solo acepta `dia`, `semana`, `mes`, `ano`. Si llega cualquier otra
   cosa usa `semana`. (Lista fija = nadie puede meter texto raro.)
3. **Toma el id del vendedor DE LA SESIÓN** (`Session::usuarioId()`) y se lo pasa al repository.
   👉 **Esto es lo que hace que el panel sea individual y seguro:** el id nunca viene de la
   URL, así que un vendedor no puede ver el panel de otro cambiando un parámetro.

Devuelve un JSON así:
```json
{
  "ok": true, "periodo": "semana", "vendedor": "Tobias",
  "resumen": { "ventas": 4, "anuladas": 1, "clientes": 2, "ticket": 3500 },
  "ventas":  [{ "label": "Jue 01", "ventas": 2, "anuladas": 1 }, ...],
  "compras": [{ "label": "Jue 01", "identificados": 2, "consumidor_final": 0 }, ...],
  "clientes_frecuentes": [{ "cliente": "Juan Perez", "compras": 2 }],
  "cajas": { "abiertas": 1, "cerradas": 2, "serie": [ ... ] }
}
```

**c) El repository** — [`app/Repositories/VendedorDashboardRepository.php`](../../app/Repositories/VendedorDashboardRepository.php)

Es el único que habla con la base. Todas las consultas:
- son **solo de lectura** (`COUNT`, `AVG`) → no modifican nada;
- usan **sentencias preparadas** (`?`) → protegidas contra inyección SQL;
- llevan **`WHERE usuario_id = ?`** → solo datos del vendedor logueado.

| Método | Qué cuenta | Regla |
|---|---|---|
| `resumen()` (l. ~157) | Tarjetas: ventas, anuladas, clientes distintos, ticket promedio | Del período |
| `frecuenciaVentas()` (l. ~85) | Mis ventas por balde, y mis anuladas | `estado = 'registrada'` / `'anulada'` |
| `frecuenciaCompras()` (l. ~103) | Mis ventas a clientes identificados vs. Consumidor final | `cliente_id <> 1` vs `cliente_id = 1` |
| `clientesFrecuentes()` (l. ~119) | Top 5 clientes que más me compraron | Sin Consumidor final, de mayor a menor |
| `cajas()` (l. ~139) | Mis cajas abiertas ahora / cerradas en el período + gráfico | `estado` y `cerrada_en` |

> **¿Por qué `cliente_id = 1` es "Consumidor final"?** Porque en la base, el cliente con id 1
> es el cliente genérico que el POS usa cuando no se carga un cliente con nombre. Está como
> constante `CONSUMIDOR_FINAL = 1` en el repository.

### 3.2 El truco de los "baldes" (cómo se arman los gráficos)

Es la parte más interesante para explicar. Problema: si pido a MySQL *"contame las ventas
agrupadas por día"*, **los días sin ventas no aparecen** (MySQL no inventa filas en 0), y el
gráfico tendría huecos.

Solución en 3 pasos (método `baldes()`, l. ~27, la misma técnica que el dashboard del admin):

1. **PHP arma los baldes vacíos** del período, cada uno con una clave y una etiqueta, todos en 0.
   Ejemplo para Semana:
   ```
   "2026-09-26" → { label: "Sáb 26", ventas: 0 }
   "2026-09-27" → { label: "Dom 27", ventas: 0 }
   ...
   "2026-10-02" → { label: "Vie 02", ventas: 0 }
   ```
2. **MySQL cuenta agrupando con una expresión que da LA MISMA clave**:

   | Período | Expresión SQL | Ejemplo de clave |
   |---|---|---|
   | Día | `HOUR(creado_en)` | `14` |
   | Semana | `DATE(creado_en)` | `2026-10-02` |
   | Mes | `DATE_FORMAT(creado_en,'%x%v')` (semana ISO) | `202640` |
   | Año | `DATE_FORMAT(creado_en,'%Y-%m')` | `2026-10` |

3. **`contarEn()` (l. ~63) mete cada resultado en su balde.** Los que no tuvieron ventas
   quedan en 0, y el gráfico siempre muestra todas las barras.

Ejemplo de consulta real (frecuencia de ventas, período Semana):
```sql
SELECT DATE(creado_en) AS k, COUNT(*) AS n
FROM venta
WHERE estado = 'registrada' AND usuario_id = ? AND creado_en >= ?
GROUP BY k
```

### 3.3 Frontend paso a paso

**a) La pantalla** — [`public/admin.html`](../../public/admin.html): una `<section id="vista-vendedor">`
con el título, el selector de período, el botón "Ir al POS" y cajas vacías (`div`) que el JS
rellena: `vend-kpis`, `vend-ventas`, `vend-compras`, `vend-cajas`, `vend-ranking`, `vend-clientes`.
También se agregó el botón **Mi panel** en el menú y el `<script>` del módulo.

**b) La lógica** — [`public/assets/js/admin-vendedor.js`](../../public/assets/js/admin-vendedor.js)
(archivo nuevo, igual que `admin-cajas.js` o `admin-reclamos.js`):

| Función | Qué hace |
|---|---|
| `renderVendedor()` | Pide los datos a la API y dibuja todo el panel |
| `vendBarras()` | Dibuja el gráfico de barras agrupadas: la altura de cada barra es `cantidad / máximo × 100%`, y el número va arriba (atributo `data-n` + CSS) |
| `vendLeyenda()` | La leyenda con el total del período de cada serie |
| `vendPanelSerie()` | Arma un panel completo: título + leyenda + barras |
| Listener del selector | Al tocar Día/Semana/Mes/Año cambia `vendPeriodo` y vuelve a llamar `renderVendedor()` |

El **listado de mayor a menor** se arma en el navegador: toma la serie de ventas, deja solo
los períodos con ventas y los ordena con `sort((a, b) => b.ventas - a.ventas)` (descendente).

Todo texto que viene de la base se pasa por **`esc()`** antes de ponerlo en el HTML (evita que
un nombre con `<script>` rompa la página → protección XSS).

**c) La navegación** — [`public/assets/js/admin.js`](../../public/assets/js/admin.js):
- en `seleccionar()` se agregó la vista `'vendedor'` (cómo se muestra/oculta, igual que las demás);
- al iniciar: el **vendedor** aterriza en Mi panel; el **admin** también si llega con `#vendedor`
  en la dirección (que es lo que pone el botón del POS: `/admin.html#vendedor`).

**d) Los estilos** — [`public/assets/css/admin.css`](../../public/assets/css/admin.css): un bloque
"Dashboard del vendedor" al final. **Reusa** las clases del dashboard admin (`.dash-panel`,
`.barras`, `.card`, `.hbar-*`, `.periodo-toggle`) y solo agrega lo propio: el número arriba de
las barras, la lista con medallas y la versión compacta para las 24 barras del período Día.

**e) El botón del POS** — [`public/pos.html`](../../public/pos.html): un enlace
`📊 Mi panel` → `/admin.html#vendedor`, visible para todos los roles.

---

## 4. Lista de archivos (nuevos y modificados)

| Archivo | Nuevo / Modificado | Qué se hizo |
|---|---|---|
| `app/Controllers/VendedorDashboardController.php` | **Nuevo** | Endpoint del panel |
| `app/Repositories/VendedorDashboardRepository.php` | **Nuevo** | Consultas SQL del panel |
| `public/assets/js/admin-vendedor.js` | **Nuevo** | Dibujo del panel |
| `docs/modulos/dashboard-vendedor.md` | **Nuevo** | Doc técnica del módulo |
| `docs/exposicion/dashboard-vendedor-franco.md` | **Nuevo** | Esta guía |
| `routes/api.php` | Modificado | Ruta `GET /api/vendedor/dashboard` |
| `public/admin.html` | Modificado | Botón de menú, sección de la vista, script |
| `public/assets/js/admin.js` | Modificado | Vista `'vendedor'` + a dónde entra cada rol |
| `public/assets/css/admin.css` | Modificado | Estilos del panel |
| `public/pos.html` | Modificado | Botón **📊 Mi panel** |
| `public/index.php` | Modificado | Zona horaria (ver 5.1) |
| `INICIAR-SERVIDOR.bat` | Modificado | Arranque automático (ver 5.2) |
| `database/schema_*.sql` (4 archivos) | Modificado | Arreglos para instalar desde cero (ver 5.3) |

**No se creó ninguna tabla nueva**: el panel solo **lee** datos que ya existían
(`venta`, `caja`, `cliente`).

---

## 5. Problemas que aparecieron en el camino y cómo se arreglaron

### 5.1 La hora estaba corrida 3 horas
**Qué pasaba:** PHP trabajaba en hora **UTC** y MySQL en hora de **Argentina** (3 horas menos).
PHP arma los baldes ("hoy es viernes") y MySQL agrupa las ventas con su hora; después de las
21 h, PHP ya pensaba que era el día siguiente y los gráficos se desfasaban. Afectaba también al
dashboard del admin.

**Arreglo:** en [`public/index.php`](../../public/index.php) se fija la zona horaria al arrancar:
```php
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? (getenv('APP_TIMEZONE') ?: 'America/Argentina/Buenos_Aires'));
```
(Por defecto Argentina; se puede cambiar con `APP_TIMEZONE` en el `.env`.)

### 5.2 `INICIAR-SERVIDOR.bat` no andaba en esta computadora
**Qué pasaba:** tenía escrita a mano la ruta de `php-8.3.26`, y en esta PC Laragon trae la
`8.3.30`. Además faltaban el `.env`, las dependencias (`vendor/`) y la base.

**Ahora el `.bat`, al hacerle doble clic:**
1. **Busca solo** el PHP (prefiere 8.3) y el MySQL de Laragon (`C:\laragon`).
2. Si no hay `.env`, lo crea copiando `.env.example`.
3. Si no hay `vendor/`, corre `composer install`.
4. Si MySQL está apagado, lo prende y espera a que responda.
5. Lee **qué base usar del `.env` (`DB_NAME`)**:
   - si es `britech_v2` y no existe, la **crea** importando todos los `database/schema_*.sql`
     en el orden del README;
   - si es otra (como `britech_original`), la usa tal cual (y avisa si no existe);
   - si la base quedó **a medio importar**, avisa y dice cómo borrarla para empezar de nuevo.
6. Levanta el servidor en **http://127.0.0.1:8123**.

### 5.3 Los archivos `.sql` del repo no armaban una base completa
Al crear la base desde cero en MySQL 8.4 fallaban estas cosas, que en la PC original se habían
agregado a mano y nunca quedaron en los archivos:

| Archivo | Problema | Arreglo |
|---|---|---|
| `schema_envios.sql` | Faltaba la columna `empresa_envio.es_retiro` (la usa el checkout) | Se agregó, y "Retiro en local" queda marcado como retiro |
| `schema_reposicion.sql` | Faltaba `producto.precio_anterior` (precio tachado de ofertas) | Se agregó antes de `costo` |
| `schema_auth.sql` | Faltaba la tabla `intento_acceso` → **el login no andaba** | Se agregó la tabla (límite de intentos de login) |
| `schema_metodo_pago.sql` | Usaba `ADD COLUMN IF NOT EXISTS`, que **solo existe en MariaDB** | Se cambió por `ADD COLUMN` normal (anda en MySQL y MariaDB) |

> ⚠️ **Todavía faltan en los `.sql`** (están en la base original pero no en los archivos):
> la tabla `cliente_token`, la columna `cliente.email_verificado` y `categoria.imagen` /
> `categoria.orden`. Sin eso, una base creada solo con los `.sql` no permite registrar
> clientes en la tienda. Con la base original (punto 6) **no hay problema**.

---

## 6. La base de datos

- Se instaló el volcado original **`britech_v2 (1).sql`** (exportado con phpMyAdmin) en una base
  nueva llamada **`britech_original`**: 41 tablas con los datos reales (25 productos, 20
  clientes, 25+ ventas, 48 pedidos, usuarios, cajas, reclamos…).
- Se usó otra base (y no `britech_v2`) porque el volcado no borra tablas antes de crearlas, y
  ya existía una `britech_v2` armada con los `.sql` del repo.
- El proyecto se conecta a ella por el **`.env`**: `DB_NAME=britech_original`.
  (El `.env` no se sube a git porque tiene datos locales y secretos.)
- Se comparó la estructura de la base original contra lo que espera el código: **no le falta
  nada** y tiene además las tablas/columnas del punto 5.3.
- Usuario admin: `admin@britech.local` / `admin1234`. Vendedores en la base: *Vendedor Uno* y
  *Tobias* (con sus propias contraseñas). Sus ventas son de agosto → se ven en el período **Año**.

---

## 7. Cómo se guardó en git

- Rama: **`DashboardVendedor`**, creada a partir de `master` (`origin/master`).
- En la carpeta había **cambios de otros compañeros sin commitear** (Mercado Pago,
  notificaciones v2, ajustes visuales del dashboard admin…). Para **no mezclarlos**, cada
  commit lleva **solo** los cambios del dashboard: en los archivos compartidos
  (`admin.html`, `admin.js`, `admin.css`, `api.php`) se subieron únicamente las líneas propias.
  Los cambios de los demás siguen intactos en la carpeta, sin commitear.

| Commit | Qué contiene |
|---|---|
| `e6d0b8f` | Primera versión del dashboard (datos del local + ranking entre vendedores) |
| `22a7fd5` | Dashboard **individual** + período **Día** + arreglo de zona horaria |
| `41bf957` | `.bat` automático + arreglos de los `.sql` |
| `939b02d` | `.bat` usa la base que diga el `.env` |
| `be59339` | Botón **📊 Mi panel** en el POS y **🧾 Ir al POS** en el panel |
| *(este)* | Documentación |

> ⏳ **Pendiente:** subir la rama a GitHub (el `push` pide iniciar sesión en GitHub):
> ```bash
> git -C C:/laragon/www/Proyecto-Britech push -u origin DashboardVendedor
> ```

---

## 8. Cómo mostrarlo en vivo (guion de demo, ~3 minutos)

1. Doble clic en **`INICIAR-SERVIDOR.bat`** → abrir http://127.0.0.1:8123/login.html.
2. Entrar como vendedor (o admin) → ir al **POS**.
3. Mostrar el botón **📊 Mi panel** → tocarlo.
4. Explicar el título con el nombre: *"cada uno ve solo lo suyo"*.
5. Cambiar **Día → Semana → Mes → Año** y mostrar cómo cambian todos los gráficos.
6. Señalar los números arriba de las barras y los totales en la leyenda.
7. Mostrar las cajas: *"si queda una abierta de días anteriores, se ve acá"*.
8. Mostrar la lista **de mayor a menor** con las medallas.
9. Volver con **🧾 Ir al POS**, hacer una venta, volver a Mi panel (período **Día**) → la venta
   aparece en la hora actual. *(Prueba en vivo de que lee datos reales.)*
10. (Opcional) Entrar con otro vendedor → no ve las ventas del primero.

---

## 9. Preguntas que pueden hacer (y cómo responderlas)

**¿Por qué no usaron una librería de gráficos como Chart.js?**
> Porque la consigna era no agregar tecnologías nuevas. El proyecto ya tenía gráficos hechos
> con `div` y CSS en el dashboard del admin, así que reutilizamos esa misma técnica y sus
> estilos. Además es más liviano: no hay que descargar nada extra.

**¿Cómo garantizan que un vendedor no vea los datos de otro?**
> El id del vendedor sale de la **sesión del servidor**, no de la URL. Aunque alguien modifique
> el pedido en el navegador, el backend siempre usa el id de quien inició sesión, y todas las
> consultas tienen `WHERE usuario_id = ?`.

**¿Qué pasa si alguien manda un período inventado, tipo `?periodo=hola`?**
> El controller lo compara con una lista fija (`dia`, `semana`, `mes`, `ano`). Si no está,
> usa `semana`. Ese texto nunca llega al SQL.

**¿Cómo se protegen de la inyección SQL?**
> Con **sentencias preparadas** de PDO: los valores (id, fecha) viajan separados de la consulta
> con `?`, nunca se pegan dentro del texto SQL.

**¿Por qué aparecen los días sin ventas en 0, si MySQL no los devuelve?**
> Por los **baldes**: PHP crea primero todos los días/horas del período en 0, y después MySQL
> solo completa los que tuvieron ventas. Así el gráfico no tiene huecos.

**¿Qué es "Consumidor final"?**
> El cliente genérico del POS (id 1) para ventas de mostrador sin datos del comprador. Lo
> separamos para distinguir las ventas a clientes identificados, que son los que vuelven.

**¿Las ventas anuladas cuentan?**
> En "Mis ventas" no: solo cuentan las registradas. Las anuladas se muestran aparte (en rojo
> y en su tarjeta) para que el vendedor también vea lo que se le cayó.

**¿Por qué la caja "abierta" no depende del período?**
> Porque una caja abierta es un estado *actual*: si quedó abierta de hace 3 días, igual hay que
> verla (seguramente alguien se olvidó de cerrarla). Las cerradas sí se cuentan por período.

**¿El panel modifica algo en la base?**
> No. Son solo lecturas (`SELECT` con `COUNT`/`AVG`). No se creó ninguna tabla nueva.

**¿Qué pasa si no hay sesión, o entra un cliente de la tienda?**
> El Router responde **401** (no autenticado) a cualquiera que no tenga sesión de **staff**,
> y eso incluye a los clientes de la tienda, porque su sesión es otra. Además, el controller
> tiene una segunda barrera (`esStaff()` → **403**). El panel es solo para vendedores y admin.

**¿Por qué estaban mal las horas y cómo lo arreglaron?**
> PHP estaba en UTC y MySQL en hora argentina. Fijamos la zona horaria de PHP en
> `America/Argentina/Buenos_Aires` al inicio de `index.php`.

---

## 10. Mini glosario

| Palabra | Qué significa (simple) |
|---|---|
| **Dashboard / panel** | Pantalla con números y gráficos para ver de un vistazo cómo va algo |
| **Endpoint / ruta de API** | Una "dirección" del backend que devuelve datos (acá, `/api/vendedor/dashboard`) |
| **JSON** | Formato de texto con el que el backend le pasa los datos al navegador |
| **fetch** | La función de JavaScript con la que el navegador pide datos al backend sin recargar la página |
| **Controller** | Recibe el pedido, chequea permisos y responde. No tiene SQL |
| **Repository** | La única parte que habla con la base de datos |
| **Sentencia preparada** | Consulta SQL con `?` donde los valores van aparte → evita inyección SQL |
| **Sesión** | La "memoria" del servidor que recuerda quién inició sesión |
| **Balde** | Cada casillero del gráfico (una hora, un día, una semana o un mes), arranca en 0 |
| **Ticket promedio** | Total vendido ÷ cantidad de ventas |
| **Consumidor final** | Cliente genérico del POS para ventas sin datos del comprador |
| **XSS** | Ataque que mete código en la página a través de un texto; se evita escapando con `esc()` |
| **Volcado (dump)** | Archivo `.sql` exportado con toda la base (estructura + datos) |
| **`.env`** | Archivo con la configuración local (qué base usar, contraseñas); no se sube a git |
| **Rama (branch)** | Una línea de trabajo separada en git, para no tocar `master` hasta revisar |

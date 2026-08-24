# Defensa del código — qué hace, cómo funciona y por qué (25/08/2026)

> Para cuando el profe pregunte "¿y esto cómo funciona? ¿por qué lo hicieron así?".
> Está armado siguiendo **un solo caso de punta a punta**: *guardar un producto*
> desde el ABM. Si dominan este recorrido, dominan todo el sistema (los demás ABM
> son iguales).
>
> **Reparto sugerido (somos 3):**
> - **Franco → Frontend** (la web: `admin.js`, `api.js`, cómo se dibuja la tabla y el modal).
> - **Tobi → Controllers + Router** (cómo entra el pedido HTTP y quién lo atiende).
> - **Eric → Services + Repositories + arquitectura general** (la lógica, las validaciones, el SQL, la BD).
>
> Igual **los 3 tienen que entender el recorrido completo**; el reparto es solo
> para quién lleva la voz en cada parte.

---

## 0. La idea en una frase (la dice cualquiera)

> "Es una arquitectura **en capas**: el navegador pide datos por **fetch/AJAX**,
> el pedido entra por **un único punto** (front controller), el **Router** decide
> qué **Controller** lo atiende, el Controller llama al **Service** (que valida y
> aplica las reglas), y el Service usa el **Repository** (el único que habla con
> la base con **consultas preparadas**). Cada capa tiene una sola responsabilidad."

Diagrama para tener en un slide:

```
Navegador (fetch/JSON)
      │
      ▼
public/index.php   ← Front Controller (única puerta de entrada)
      │
      ▼
Router             ← ¿qué ruta es? ¿requiere login?
      │
      ▼
Controller         ← recibe/responde HTTP (JSON). NO tiene lógica ni SQL
      │
      ▼
Service            ← reglas de negocio + validaciones + transacciones
      │
      ▼
Repository         ← el único que escribe SQL (consultas preparadas)
      │
      ▼
MySQL (PDO)
```

**Por qué en capas:** cada cosa en su lugar. Si mañana cambia el diseño de la web,
no tocamos la lógica. Si cambia una regla de negocio, está en un solo lugar (el
Service). Y todo el SQL está aislado en los Repositories, así es fácil de revisar
y no hay consultas sueltas por todos lados.

---

## 1. FRANCO — Frontend (la web que se ve)

### Archivos: `public/assets/js/api.js` y `public/assets/js/admin.js`

### 1.1 `api.js` — la capa que habla con el backend
Todas las llamadas al servidor pasan por acá (patrón "módulo": un solo lugar).

```js
async function pedir(url, opciones) {
    const resp = await fetch(url, opciones);
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) throw new Error(json.error || ('Error HTTP ' + resp.status));
    return json;
}
const api = { get, post, subir };
```
- **Qué hace:** `api.get()` trae datos, `api.post()` envía JSON, `api.subir()` sube archivos.
- **Cómo:** usa `fetch` (AJAX = pedir datos sin recargar la página).
- **Por qué centralizado:** si un pedido falla, el manejo del error está en **un
  solo lugar**. Y usamos el mensaje que mandó el backend (`json.error`) para
  mostrarle algo claro al usuario.
- Detalle a mencionar: en `subir` **no** ponemos `Content-Type` a mano; el
  navegador lo arma solo cuando el body es un `FormData` (si no, se rompe la subida).

### 1.2 `admin.js` — un framework de ABM reutilizable
La parte más lucida para mostrar. **Una sola lógica** (tabla + búsqueda + filtros
+ paginación + modal) que sirve para las 5 tablas, configurada en un objeto `ENTIDADES`:

```js
const ENTIDADES = {
  productos: {
    endpoint: '/api/admin/productos',
    columnas: [ ... ],   // qué mostrar en la tabla
    filtros:  [ ... ],   // qué filtros ofrecer
    campos:   [ ... ],   // qué campos tiene el formulario
  },
  clientes: { ... }, proveedores: { ... }, ...
};
```
- **Qué hace:** describe cada entidad como **datos** (columnas/filtros/campos), y
  el mismo código dibuja la tabla y el formulario para cualquiera.
- **Por qué así (LA respuesta clave):** *"En vez de copiar y pegar la pantalla de
  ABM 5 veces, escribimos la lógica **una sola vez** y la configuramos por
  entidad. Menos código, menos errores, y agregar una tabla nueva es agregar una
  entrada al objeto, no una pantalla entera."* (principio **DRY** — no repetirse).

El ciclo de vida (mostrarlo en el código):
```js
async function cargar() {                         // 1. pide la página actual
  const params = new URLSearchParams({ page, per_page: 10 });
  if (q) params.set('q', q);                       //    + búsqueda
  for (const k in filtros) params.set(k, filtros[k]); // + filtros
  const r = await api.get(`${cfg.endpoint}?${params}`);
  renderFilas(r.data);                             // 2. dibuja la tabla
  renderPaginacion(r);                             // 3. dibuja los botones de página
}
```
- **Búsqueda con debounce** (importante, lo van a preguntar):
  ```js
  $('q').addEventListener('input', (e) => {
    clearTimeout(debounce);
    debounce = setTimeout(() => { q = e.target.value.trim(); page = 1; cargar(); }, 300);
  });
  ```
  *"Esperamos 300 ms desde la última tecla antes de buscar. Así no mandamos un
  pedido por cada letra; solo cuando el usuario deja de escribir."* (rendimiento).

- **Anti-XSS en el frontend:** todo lo que viene de la base se escapa con `esc()`
  antes de meterlo en el HTML:
  ```js
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, ...);
  ```
  *"Si un producto se llamara `<script>`, no se ejecuta: lo mostramos como texto."*

- **Guardar** (crear/editar usan el mismo modal y el mismo botón):
  ```js
  async function guardar(e) {
    e.preventDefault();
    await api.post(`${cfg.endpoint}/guardar`, leerFormulario());
    cerrarModal(); await cargar();       // recarga la tabla ya actualizada
  }
  ```
  Si el backend devuelve error de validación, lo mostramos en el formulario
  (`err.textContent = ex.message`).

### Preguntas típicas para Franco
- **¿Qué es AJAX/fetch?** → Pedir o enviar datos al servidor **sin recargar** la
  página; el servidor responde JSON y con JavaScript actualizamos solo lo necesario.
- **¿Por qué separan HTML del JavaScript de datos?** → El HTML es la estructura;
  el JS pide los datos y los pinta. La misma página sirve para cualquier entidad.
- **¿Cómo evitan repetir código para cada tabla?** → El objeto `ENTIDADES`: se
  configura por datos, la lógica es una sola.
- **¿Y si el servidor tarda o falla?** → `api.js` centraliza el error y mostramos
  el mensaje; los botones se deshabilitan mientras se guarda (evita doble envío).

---

## 2. TOBI — Router + Controllers (la puerta y el "recepcionista")

### 2.1 Front Controller — `public/index.php`
**Una sola puerta de entrada** a todo el backend:
```php
Session::iniciar();                 // 1. arranca la sesión
$router = new Router();
require '.../routes/api.php';        // 2. carga todas las rutas
$router->despachar(Request::metodo(), Request::ruta());  // 3. despacha
```
- **Por qué un único punto:** un solo lugar donde arranca la sesión, se cargan las
  rutas y se decide todo. Nada entra "por la ventana".

### 2.2 Router — `app/Core/Router.php`
Guarda un mapa `método + ruta → [Controller, acción]` y también hace de
**middleware de autenticación**:
```php
public function get($ruta, $accion, $auth = false) { ... }   // el 3er parámetro
// ...
if ($r['auth'] && Session::usuarioId() === null) {
    Response::json(['error' => 'No autenticado'], 401);  // corta si no hay sesión
    return;
}
[$clase, $metodo] = $r['accion'];
(new $clase())->$metodo();
```
- **Qué hace:** decide **qué** controlador atiende cada ruta.
- **El truco del `true`:** en `routes/api.php`, el tercer parámetro marca la ruta
  como protegida. Si no hay sesión → **401** y nunca llega al controlador.
  ```php
  $router->get('/api/admin/productos', [ProductoController::class, 'admin'], true);
  //                                                                          ^^^^ requiere login
  ```
- **Por qué es simple (a propósito):** solo hace falta match exacto de ruta. No
  metimos un router con parámetros en la URL (`/productos/{id}`) porque no lo
  necesitábamos: usamos `?id=` o rutas con sufijo (`/guardar`, `/baja`). *Código
  simple para lo que el proyecto pide.*

### 2.3 Controller — `app/Controllers/ProductoController.php`
El "recepcionista": recibe el pedido, llama al Service, y devuelve JSON.
**No tiene lógica de negocio ni SQL.**
```php
public function guardar(): void {
    if (!Session::esAdmin()) { Response::json([...], 403); return; }   // permiso
    try {
        $id = (new ProductoService())->guardar(Request::json(), (int) Session::usuarioId());
        Response::json(['ok' => true, 'id' => $id]);
    } catch (ValidacionException $e) {
        Response::json(['ok' => false, 'error' => $e->getMessage()], 422);  // dato mal
    } catch (\Throwable $e) {
        Response::json(['ok' => false, 'error' => 'No se pudo guardar...'], 500); // error real
    }
}
```
- **Qué hace:** valida el **permiso** (solo admin → si no, **403**), delega en el
  Service y traduce el resultado a **códigos HTTP**:
  - **200/201** ok · **403** sin permiso · **422** dato inválido · **500** error inesperado.
- **Por qué dos `catch`:** distinguimos *"el usuario cargó algo mal"* (422, le
  mostramos el mensaje) de *"se rompió algo del servidor"* (500, mensaje genérico
  para **no filtrar** detalles internos).
- **El paginado en el controller:**
  ```php
  [$page, $perPage, $offset] = Paginacion::desde();
  $r = (new ProductoService())->listar(Request::query('q'), $filtros, $perPage, $offset);
  Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage));
  ```

### Preguntas típicas para Tobi
- **¿Qué es un Front Controller?** → La única puerta de entrada: todas las
  peticiones pasan por `index.php`, que arranca la sesión y llama al router.
- **¿Cómo protegen las rutas del admin?** → Doble candado: el Router corta con
  **401** si no hay sesión, y el Controller devuelve **403** si el usuario no es
  admin (`Session::esAdmin()`).
- **¿Por qué el Controller no tiene la lógica?** → Para que sea fino y reusable:
  solo traduce HTTP ↔ Service. La lógica vive en el Service (se puede testear y
  reutilizar sin depender del HTTP).
- **¿Qué son los códigos 401/403/422/500?** → 401 no logueado, 403 logueado pero
  sin permiso, 422 datos inválidos, 500 error del servidor.
- **¿Qué pasa si piden una ruta que no existe?** → El Router responde **404**.

---

## 3. ERIC — Service + Repository + Base de datos (el motor)

### 3.1 Service — `app/Services/ProductoService.php`
Acá viven **las reglas y las validaciones**. Lo más importante para defender.

**Validación siempre en el backend:**
```php
if (mb_strlen($nombre) < 2) throw new ValidacionException('El nombre es obligatorio...');
if ($precioMin === '' || !is_numeric($precioMin) || (float)$precioMin < 0)
    throw new ValidacionException('El precio minorista es obligatorio y ≥ 0.');
```
- **Por qué en el backend y no solo en el HTML:** *"La validación del navegador es
  comodidad para el usuario, pero se puede saltear (mandando el pedido a mano). La
  que **manda** es la del servidor, porque nunca confiamos en lo que llega."*

**Transacción (la joya de la demo):** guardar un producto toca **3 tablas**:
```php
$pdo->beginTransaction();
  $id = $this->repo->crear($d);              // tabla producto
  $this->repo->fijarPrecio($id, 1, ...);     // tabla precio (minorista)
  $this->repo->reemplazarImagenes($id, ...); // tabla producto_imagen
  $this->inv->establecer($id, $stock);       // tabla inventario
  $this->inv->registrarMovimiento(...);      // libro mayor (movimiento_inventario)
$pdo->commit();                              // se confirma TODO junto
// si algo falla → $pdo->rollBack(): no queda nada a medias
```
- **Qué es una transacción:** varias operaciones que se confirman **todas juntas o
  ninguna** (todo o nada). *"Si se guarda el producto pero falla el stock, sin
  transacción quedaría un producto sin stock ni precio. Con transacción, o entra
  todo o no entra nada."*
- **Libro mayor de inventario:** el stock no solo se pisa; cada cambio deja un
  registro en `movimiento_inventario` (ingreso/egreso, motivo, quién lo hizo). Así
  hay **trazabilidad** de por qué el stock es el que es.

**Baja lógica:**
```php
public function baja(int $id): void {
    // no borramos: hay ventas que referencian el producto
    $this->repo->actualizar($id, $this->soloEstado($id, 0));  // activo = 0
}
```
- **Por qué no borrar (DELETE):** si borráramos el producto, se romperían las
  ventas viejas que lo apuntan y perderíamos historial. Lo marcamos `activo = 0`:
  desaparece del catálogo pero los datos quedan.

### 3.2 Repository — `app/Repositories/ProductoRepository.php`
El **único** lugar con SQL de productos. Todo con **consultas preparadas**.
```php
$sql = "SELECT ... FROM producto p
        WHERE (p.nombre LIKE ? OR p.sku LIKE ? OR p.codigo_barras LIKE ?)
        LIMIT ? OFFSET ?";
$st = $pdo->prepare($sql);
$st->bindValue($i+1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
$st->execute();
```
- **Anti SQL injection (respuesta estrella):** *"Nunca pegamos datos del usuario
  dentro del texto del SQL. Usamos `?` (marcadores) y mandamos los valores aparte
  con `prepare`/`execute`. Para la base, esos valores son **datos**, nunca código,
  así que es imposible inyectar SQL."*
- **Paginación real en la base:**
  - `LIMIT ? OFFSET ?` trae solo la página pedida (ej. 10 filas), no toda la tabla.
  - Un `SELECT COUNT(*)` con el **mismo WHERE** da el total, para saber cuántas
    páginas hay.
  - *"Si la tabla tuviera 10.000 productos, seguimos trayendo de a 10: rápido y
    escalable."*
- **Filtros dinámicos:** se arma el `WHERE` según qué filtros vinieron, empujando
  cada condición y su valor a un array de parámetros (siempre como marcador `?`).

### 3.3 Base de datos — `app/Core/Database.php`
```php
self::$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // errores = excepción
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // filas como array
    PDO::ATTR_EMULATE_PREPARES   => false,                   // preparadas REALES
]);
```
- **Patrón Singleton:** una sola conexión para toda la petición (la primera vez se
  crea, después se reusa). *"No abrimos una conexión nueva por cada consulta."*
- **`EMULATE_PREPARES => false`:** las prepara el motor de MySQL de verdad (más
  seguro), no las "simula" PHP.
- **Config desde `.env`:** los datos de conexión (y la clave del correo) están en
  `.env`, **fuera del código** y **fuera de git**. Principio **12-factor**: la
  configuración va en el entorno, no hardcodeada. *(Por eso `.env` está en `.gitignore`.)*

### Preguntas típicas para Eric
- **¿Cómo evitan inyección SQL?** → Consultas preparadas con `?` + `execute`; los
  datos van separados del SQL. Además `EMULATE_PREPARES => false`.
- **¿Qué es una transacción y dónde la usan?** → Al guardar un producto (producto +
  precio + inventario + movimiento): `beginTransaction/commit`, y `rollBack` si
  algo falla. Todo o nada.
- **¿Por qué baja lógica y no `DELETE`?** → Para no perder historial ni romper las
  ventas que referencian el producto.
- **¿Por qué el SQL está solo en los Repositories?** → Aísla el acceso a datos: si
  cambia una consulta, está en un solo lugar; el resto del código no sabe de SQL.
- **¿Qué es el patrón Repository / Service?** → Repository = habla con la base.
  Service = reglas de negocio. Separar los dos deja la lógica testeable y el SQL ordenado.
- **¿Dónde guardan la contraseña de la base y del mail?** → En `.env`, fuera del
  código y de git (12-factor).
- **¿Las contraseñas de usuarios cómo se guardan?** → Con `password_hash()` (hash
  bcrypt, no se guarda la clave en texto).

---

## 4. Guion de "seguime el dato" (recorrido para narrar en vivo)

Si el profe dice "mostrame cómo se guarda un producto", narrar así mientras Franco
hace clic:

1. **(Franco)** "Toco *Guardar* en el formulario. `admin.js` junta los campos y
   hace `api.post('/api/admin/productos/guardar', datos)` → un fetch con el JSON."
2. **(Tobi)** "El pedido entra por `index.php`, el Router ve que la ruta necesita
   login (`true`) y que hay sesión, y llama a `ProductoController::guardar`. El
   controller chequea que sea admin y delega en el Service."
3. **(Eric)** "El `ProductoService` **valida** (nombre, precio, stock…). Si está
   ok, abre una **transacción** y guarda en producto, precio, inventario y el
   movimiento de stock, todo con el `Repository` usando **consultas preparadas**.
   `commit` confirma todo junto."
4. **(Franco)** "El backend responde `{ok:true}`, cerramos el modal y recargamos
   la tabla, que ya muestra el producto nuevo."

Y para mostrar una **validación fallando**: cargar un producto sin precio → el
Service tira `ValidacionException` → el controller responde **422** con el mensaje
→ `admin.js` lo muestra en rojo en el formulario. *Punta a punta.*

---

## 5. Glosario rápido (por si preguntan un término)
- **MVC / capas:** separar en Controller (HTTP), Service (lógica), Repository (datos).
- **PDO:** la librería de PHP para hablar con la base de forma segura.
- **Consulta preparada:** SQL con `?` donde los datos van aparte (anti inyección).
- **Transacción:** varias operaciones que se confirman/deshacen juntas.
- **AJAX / fetch:** pedir datos al servidor sin recargar la página.
- **JSON:** el formato de texto en que el backend y el frontend se pasan los datos.
- **Middleware:** un control que corre antes del controlador (acá: chequear login).
- **Singleton:** una sola instancia compartida (acá: la conexión a la base).
- **DRY:** "no te repitas" (por eso el framework de ABM único).
- **Baja lógica:** marcar `activo = 0` en vez de borrar la fila.
- **Hash de contraseña:** guardar la clave cifrada de una sola vía (`password_hash`).
- **12-factor:** buenas prácticas; acá, la config en `.env` fuera del código.

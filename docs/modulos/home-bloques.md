# Módulo: Home modular (page builder de la tienda)

> La home de la tienda es una **lista ordenada de bloques** guardada en la BD.
> Cada bloque tiene un `tipo` y un `config` (JSON). Agregar un tipo nuevo NO
> requiere tablas nuevas: se agrega el `tipo` + una función de render.

## Datos
- **`bloque`** (`id, tipo, titulo, config JSON, activo, orden`).
- **`bloque_slide`** (slides del hero: imagen desktop/mobile, título, subtítulo,
  botón, url, activo, orden, desde, hasta) — FK a `bloque`, ON DELETE CASCADE.
- **`categoria`** += `imagen`, `orden` (carrusel de categorías).
- **`producto`** += `precio_anterior` (para mostrar ofertas / % off).

## Tipos de bloque
`hero` · `banner` · `video` · `carrusel_categorias` · `carrusel_productos` ·
`grid_productos`. El `config` guarda lo propio de cada uno (ej. carrusel_productos:
`categoria_id`, `limite`, `banner:{imagen,url,posicion}`; grid: `solo_ofertas`).

## Backend (MVC)
- `BloqueRepository` — SQL de bloques y slides (público + ABM).
- `HomeService::home($listaId)` — resuelve cada bloque a sus datos (hero→slides,
  carrusel_productos→`ProductoRepository::porCategoria`, categorías, etc.).
- `HomeController::home` → **GET `/api/tienda/home`** (pública; precio según el
  modo del cliente).
- `BloqueController` → ABM admin en `/api/admin/bloques*` (listar, guardar,
  estado, mover ↑/↓, borrar, slides).

## Frontend (componentes reutilizables)
- `tienda-home.js` — helpers compartidos (`$`, `money`, `esc`, `inicial`),
  **`cardProducto`** (tarjeta única para home/carruseles/grid/catálogo),
  `renderHome` + un render por tipo, componente **carrusel** (flechas + scroll +
  swipe nativo) y **hero** (autoplay + flechas + dots).
- `tienda.js` — orquesta: vista **home** (bloques) por defecto y vista
  **catálogo** (búsqueda / "Ver todos") con el grid paginado.
- CSS en `tienda.css`. **El overflow de los carruseles está contenido**: nunca
  generan scroll horizontal de toda la página (verificado).

## Administración (panel → "Inicio tienda")
`admin-bloques.js` + sección `#vista-bloques`. Permite:
- **Listar** los bloques en orden, con su tipo/título/estado.
- **Reordenar** arrastrando (**drag & drop**, `/api/admin/bloques/reordenar` con la
  lista de ids) o con ↑/↓ (`/api/admin/bloques/mover`) — cambia el orden de la home
  sin tocar código. El drag usa HTML5 Drag&Drop (handle ⠿), reordena el DOM en vivo
  y guarda solo al soltar. Probado end-to-end (persiste en `bloque.orden`).
- **Activar/Desactivar** (`/estado`), **Editar**, **Borrar**.
- **Crear/editar** con formulario dinámico según el tipo (categoría, límite,
  banner, imagen con botón *Subir*, etc.).
- **Slides del hero**: sub-editor (lista + alta/edición/baja, subida de imagen,
  fechas desde/hasta).
Verificado: los cambios (orden, activo) se reflejan en `/api/tienda/home`.

## Cambios 2026-08-23 (imagen de categoría + logo)
- **Imagen de categoría desde el ABM (bugfix):** el carrusel de categorías usa
  `categoria.imagen`, pero el ABM de Categorías no tenía forma de cargarla. Ahora
  el ABM (que es genérico, `maestraSimple`) muestra un **campo de imagen** solo
  para categorías (`opts.imagen`), reutilizando el **widget de imágenes de
  productos** → **subir archivo o pegar con Ctrl+V** funcionan igual. Backend:
  `MaestraSimpleRepository`/`MaestraSimpleController` guardan `imagen` solo para las
  tablas con esa columna (`CON_IMAGEN = ['categoria']`); `marca` no se ve afectada.
- **Cards de categoría verticales:** `.cat-img` pasó a `aspect-ratio: 3/4` con
  `background-size: contain` + fondo neutro → la imagen **se adapta sin recortarse**
  (pedido puntual de Smartphones).
- **Logo de la marca:** marca "play" (botón de reproducción en caja) como SVG
  inline reutilizable (`.brand-mark`/`.brand-lockup` en `tokens.css`, todo en
  `currentColor` para contrastar en claro/oscuro) en tienda, admin, POS, login e
  inicio; favicon `public/assets/img/logo.svg`. Se quitaron los "puntos ámbar"
  viejos de marca. *(El SVG es una recreación vectorial del logo; si se quiere el
  PNG exacto, se reemplaza el archivo y las referencias.)*

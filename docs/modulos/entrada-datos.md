# Módulo: Entrada de Datos (panel admin) + Tienda online

> Estado: **CONSTRUIDO Y PROBADO** (2026-08-21). Entrega del 25/08/2026.
> Cubre la consigna "Módulo de Entrada de Datos": ABM de tablas maestras con
> búsqueda, filtros y paginación, dentro del panel del admin; más la tienda
> online (carrito + compra) y la navegación por rol.

## 1. Alcance entregado
- **ABM de tablas maestras** (panel admin, solo admin):
  Clientes · Productos · Proveedores · Categorías · Marcas.
  Cada uno: listar **paginado** + **búsqueda** + **filtros** + crear/editar (modal)
  + baja lógica, con **validaciones en el backend**.
- **Productos**: descripción, precio (minorista/mayorista), categoría, marca,
  proveedor y **stock inicial**. Guardar toca 3 tablas (producto, precio,
  inventario) en una **transacción**; el stock inicial y los ajustes quedan en
  `movimiento_inventario` (libro mayor).
- **Dashboard** para toma de decisiones (endpoint `/api/admin/dashboard`,
  `DashboardRepository`): KPIs (ventas de hoy, ventas del mes, ticket promedio,
  clientes + nuevos del mes), **gráfico de ventas de los últimos 7 días**,
  panel **"Requiere acción"** (pedidos y solicitudes pendientes, productos sin
  stock — clickeable a su sección) y listas de **stock bajo** y **top productos
  (30 días)**.
- **Seguridad (hardening):** cookie de sesión con `HttpOnly` + `SameSite=Lax`
  (mitiga CSRF y robo por JS) y `use_strict_mode`; **rate limiting** por IP en
  login staff/tienda y registro (tabla `intento_acceso`, `App\Core\RateLimit`) →
  429 tras varios intentos. `secure` en la cookie queda para activar con HTTPS.
- **Ticket en PDF (POS):** botón "PDF" en el ticket → `GET /api/ventas/ticket?id=`
  genera el comprobante 80 mm con **Dompdf** (`VentaController::ticket`).
- **Recuperar contraseña / reenviar activación (tienda):** el cliente puede pedir
  "olvidé mi contraseña" (`/tienda-reset.html`) y reenviar el correo de activación,
  con tokens `reset`/`verificacion` en `cliente_token`.
- **Verificación de correo del cliente (tienda):** al registrarse (nombre + email,
  **sin** contraseña) se crea la cuenta sin verificar y se manda un correo
  (PHPMailer). Desde el link, el cliente **elige su contraseña** y la cuenta queda
  **verificada y activa** (login automático). Sin activar no puede iniciar sesión.
  Tokens en `cliente_token` (hash, un solo uso, vencen a las 48 h); columna
  `cliente.email_verificado`. Se puede reenviar el correo. Páginas: registro en el
  modal de la tienda, activación en `tienda-activar.html`.
- **Navegación conectada por rol:** el login del staff ofrece crear cuenta en la
  tienda (`?registro=1` autoabre el registro); la tienda muestra un acceso directo
  al **POS/Panel** si además hay un usuario del equipo logueado (un vendedor puede
  comprar en la tienda y saltar al POS); el modal de la tienda linkea al login staff.
- **Pedidos**: el admin ve los pedidos de la tienda, cambia el estado
  (pendiente → preparando → entregado → cancelado) y ve el detalle.
- **Envíos**: en el checkout el cliente elige **medio de envío** (con su costo) e
  ingresa la **dirección**; el total suma productos + envío. Se crea un `envio`
  por pedido con su **estado** (pendiente → despachado → en camino → entregado →
  cancelado) y **seguimiento**, que el admin gestiona desde el detalle del pedido.
  Las **empresas de envío** son una tabla maestra con ABM propio (sección
  "Envíos"). Tablas `empresa_envio` y `envio`
  ([schema_envios.sql](../../database/schema_envios.sql)). El cliente ve el estado
  del envío en "Mis pedidos".
- **Tienda online**: catálogo con precio por lista, carrito (localStorage),
  **registro/login de cliente**, y **checkout que crea un Pedido**.
- **Ficha de producto** (tienda): al tocar una card se abre una **página completa**
  (vista `#vista-producto`, estilo e-commerce tipo atacadoconnect): breadcrumb,
  **galería** (imagen grande + miniaturas), panel de compra (precio, **oferta**
  con precio tachado y %off, **3 cuotas sin interés**, stock, cantidad, agregar,
  favorito), fila de **beneficios**, **descripción**, tabla de **especificaciones**
  y carrusel de **productos relacionados** (misma categoría). Responsive (2 columnas
  → apiladas) y sin scroll horizontal. Endpoint `GET /api/tienda/producto?id=`.
  Las imágenes se cargan desde el ABM **subiendo archivos locales** o **pegando con Ctrl+V**
  (widget con miniaturas y borrar); tabla `producto_imagen`
  ([schema_imagenes.sql](../../database/schema_imagenes.sql)). La subida
  (`POST /api/admin/productos/imagen`, solo admin) valida que sea una imagen real
  (por contenido, no por extensión), limita a 5 MB, guarda con nombre aleatorio en
  `public/uploads/productos/` y devuelve la URL. Ficha pública:
  `GET /api/tienda/producto?id=`.
- **Favoritos / wishlist**: el cliente marca productos con ❤️ (tabla `favorito`,
  PK cliente+producto). Corazón en cada card del catálogo y sección "Mis favoritos"
  (agregar al carrito / quitar). Requiere sesión de cliente.
- **Cantidad mínima mayorista**: cada producto puede exigir una cantidad mínima
  de compra **en modo mayorista** (`producto.min_mayorista`, campo en el ABM). Se
  **valida al confirmar el pedido** (backend, solo si el modo es mayorista); la
  ficha muestra el mínimo y arranca la cantidad ahí, y en el catálogo "Agregar"
  suma el mínimo. En minorista no aplica.
- **Acceso mayorista B2B** (como atacadoconnect): el cliente nace **minorista**,
  puede **solicitar acceso mayorista**, el **admin aprueba/rechaza** (sección
  Solicitudes del panel), y una vez aprobado el cliente **elige cómo navegar**
  con un **toggle Minorista/Mayorista** que cambia los precios. Ver
  [database/schema_mayorista.sql](../../database/schema_mayorista.sql)
  (`cliente.mayorista_aprobado`, tabla `solicitud_mayorista`). El modo de
  navegación vive en la sesión; el precio del catálogo sale de la lista según el modo.
- **Navegación por rol**: landing `/` (hub) → admin al panel, vendedor al POS,
  cliente a la tienda. El POS tiene link al panel para el admin.

## 2. Modelo de datos (nuevo)
Ver [database/schema_datos_maestros.sql](../../database/schema_datos_maestros.sql)
y [database/schema_tienda.sql](../../database/schema_tienda.sql).
- Tablas nuevas: **`categoria`**, **`marca`**, **`proveedor`**, **`pedido`**, **`pedido_detalle`**.
- `producto` += `descripcion`, `categoria_id`, `marca_id`, `proveedor_id` (FK).
- `cliente` += `email`, `telefono`, `direccion`, `localidad`, `password_hash`.
  (El cliente de la tienda es un `cliente` con contraseña; su `lista_precio_id`
  define qué precios ve.)

## 3. Backend (patrón por capas)
- **Paginación**: `App\Core\Paginacion` (lee `page`/`per_page`, arma la respuesta
  `{data, page, per_page, total, total_pages}`).
- **Repositorios**: SQL con `prepared statements`; búsqueda por `LIKE`, filtros
  dinámicos, `LIMIT/OFFSET` como enteros. `ClienteRepository`, `ProductoRepository`,
  `ProveedorRepository`, `MaestraSimpleRepository` (categoría/marca — tabla por
  lista blanca, nunca del request), `PedidoRepository`.
- **Servicios** (validaciones + transacciones): `ClienteService`, `ProductoService`,
  `ProveedorService`, `PedidoService`, `TiendaAuthService`. Categoría/Marca validan
  en `MaestraSimpleController`.
- **Controladores**: `admin`/`guardar`/`baja` por entidad; solo admin
  (`Session::esAdmin()`). Tienda: `TiendaAuthController`, `CatalogoController`,
  `PedidoController`.
- **Rutas**: `/api/admin/*` (ABM + pedidos, protegidas) y `/api/tienda/*`
  (catálogo público, auth y pedidos de cliente). Ver [routes/api.php](../../routes/api.php).

## 4. Frontend
- **Panel admin** ([public/admin.html](../../public/admin.html) + `admin.js` + `admin.css`):
  sidebar + dashboard + un **framework de ABM reutilizable** guiado por config por
  entidad (columnas, filtros, campos del formulario). Modal crear/editar, paginación,
  búsqueda con debounce, escape de HTML (anti-XSS).
- **Tienda** ([public/tienda.html](../../public/tienda.html) + `tienda.js` + `tienda.css`):
  catálogo (grid de cards), carrito, modal de auth (login/registro), checkout,
  "mis pedidos", menú de cuenta.
- **Landing** ([public/inicio.html](../../public/inicio.html)): hub con las 3 áreas.

## 5. Seguridad
- Validación siempre en el backend; precio del pedido calculado en el backend
  (nunca se confía en el navegador).
- ABM solo admin (403 si no). Catálogo público; crear pedido exige sesión de cliente.
- Contraseñas de cliente con `password_hash()`; login con mensaje genérico.
- Sesión de cliente (`cliente_id`) separada de la de staff (`usuario_id`).

## 6. Probado (curl + navegador)
- ABM: crear/editar/baja, búsqueda, filtros, paginación y validaciones (email,
  duplicados, obligatorios) en las 5 tablas maestras.
- Producto: alta con precio L1/L2 + stock inicial (movimiento `ingreso`); editar
  stock genera ajuste (`egreso`/`ingreso`).
- Tienda: registro de cliente → catálogo → carrito → checkout → **Pedido P-000002**.
  El admin lo ve, cambia estado y abre el detalle.
- Navegación: landing `/`, login staff redirige por rol, POS con link al panel.

## 7. Pendiente / futuro (no requerido para el 25)
- Convertir Pedido → Venta desde el panel. · Direcciones de envío del pedido. ·
  Recuperar contraseña del cliente. · Imágenes reales de producto. · Docker.

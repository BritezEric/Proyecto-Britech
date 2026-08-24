<?php

/**
 * Rutas de la API. El tercer parámetro `true` = requiere estar logueado.
 * $router viene desde public/index.php.
 */

use App\Controllers\ProductoController;
use App\Controllers\ClienteController;
use App\Controllers\TipoPagoController;
use App\Controllers\VentaController;
use App\Controllers\AuthController;
use App\Controllers\UsuarioController;
use App\Controllers\ProveedorController;
use App\Controllers\CategoriaController;
use App\Controllers\MarcaController;
use App\Controllers\GastoController;
use App\Controllers\EmpleadoController;
use App\Controllers\TiendaAuthController;
use App\Controllers\CatalogoController;
use App\Controllers\PedidoController;
use App\Controllers\MayoristaController;
use App\Controllers\FavoritoController;
use App\Controllers\HomeController;
use App\Controllers\BloqueController;
use App\Controllers\DashboardController;
use App\Controllers\EmpresaEnvioController;

/** @var App\Core\Router $router */

// --- Autenticación (públicas) ---
$router->post('/api/login',      [AuthController::class, 'login']);
$router->post('/api/logout',     [AuthController::class, 'logout']);
$router->get('/api/yo',          [AuthController::class, 'yo']);
$router->post('/api/verificar',    [UsuarioController::class, 'verificar']);
$router->post('/api/olvide',       [AuthController::class, 'olvide']);
$router->post('/api/restablecer',  [AuthController::class, 'restablecer']);

// --- Usuarios (crear: solo admin, verificado dentro del controlador) ---
$router->post('/api/usuarios',   [UsuarioController::class, 'crear'], true);

// --- POS (requieren login) ---
$router->get('/api/productos',         [ProductoController::class, 'index'],  true);
$router->get('/api/productos/buscar',  [ProductoController::class, 'buscar'], true);
$router->get('/api/productos/precios', [ProductoController::class, 'precios'], true);
$router->get('/api/clientes',          [ClienteController::class, 'index'],   true);
$router->get('/api/tipos-pago',        [TipoPagoController::class, 'index'],   true);
$router->post('/api/ventas',           [VentaController::class, 'crear'],      true);
$router->get('/api/ventas',            [VentaController::class, 'listar'],     true);
$router->get('/api/ventas/ticket',     [VentaController::class, 'ticket'],     true);
$router->post('/api/ventas/anular',    [VentaController::class, 'anular'],     true);

// --- Panel admin: dashboard + ABM de tablas maestras (solo admin) ---
$router->get('/api/admin/dashboard', [DashboardController::class, 'resumen'], true);
$router->get('/api/admin/dashboard/serie', [DashboardController::class, 'serie'], true);
$router->get('/api/admin/clientes',            [ClienteController::class, 'admin'],   true);
$router->post('/api/admin/clientes/guardar',   [ClienteController::class, 'guardar'], true);
$router->post('/api/admin/clientes/baja',      [ClienteController::class, 'baja'],    true);

$router->get('/api/admin/productos',           [ProductoController::class, 'admin'],   true);
$router->get('/api/admin/productos/detalle',   [ProductoController::class, 'detalle'],  true);
$router->post('/api/admin/productos/imagen',    [ProductoController::class, 'subirImagen'], true);
$router->post('/api/admin/productos/guardar',  [ProductoController::class, 'guardar'], true);
$router->post('/api/admin/productos/baja',     [ProductoController::class, 'baja'],    true);

$router->get('/api/admin/proveedores',           [ProveedorController::class, 'admin'],   true);
$router->post('/api/admin/proveedores/guardar',  [ProveedorController::class, 'guardar'], true);
$router->post('/api/admin/proveedores/baja',     [ProveedorController::class, 'baja'],    true);

$router->get('/api/admin/categorias',           [CategoriaController::class, 'admin'],   true);
$router->post('/api/admin/categorias/guardar',  [CategoriaController::class, 'guardar'], true);
$router->post('/api/admin/categorias/baja',     [CategoriaController::class, 'baja'],    true);

$router->get('/api/admin/marcas',           [MarcaController::class, 'admin'],   true);
$router->post('/api/admin/marcas/guardar',  [MarcaController::class, 'guardar'], true);
$router->post('/api/admin/marcas/baja',     [MarcaController::class, 'baja'],    true);

$router->get('/api/admin/gastos',           [GastoController::class, 'admin'],   true);
$router->post('/api/admin/gastos/guardar',  [GastoController::class, 'guardar'], true);
$router->post('/api/admin/gastos/baja',     [GastoController::class, 'baja'],    true);

// Empleados (rendimiento + pagos de sueldo + PDF) — solo admin
$router->get('/api/admin/empleados',          [EmpleadoController::class, 'listar'],  true);
$router->get('/api/admin/empleados/detalle',  [EmpleadoController::class, 'detalle'], true);
$router->get('/api/admin/empleados/pdf',      [EmpleadoController::class, 'pdf'],     true);

// Pedidos (gestión admin)
$router->get('/api/admin/pedidos',          [PedidoController::class, 'adminListar'],  true);
$router->get('/api/admin/pedidos/detalle',  [PedidoController::class, 'adminDetalle'], true);
$router->post('/api/admin/pedidos/estado',  [PedidoController::class, 'adminEstado'],  true);
$router->post('/api/admin/pedidos/envio',   [PedidoController::class, 'adminEnvio'],   true);

// Empresas de envío (ABM admin)
$router->get('/api/admin/empresas-envio',          [EmpresaEnvioController::class, 'admin'],   true);
$router->post('/api/admin/empresas-envio/guardar', [EmpresaEnvioController::class, 'guardar'], true);
$router->post('/api/admin/empresas-envio/baja',    [EmpresaEnvioController::class, 'baja'],    true);

// --- Tienda online ---
// Auth de cliente (la protección de cliente se chequea dentro del controlador)
$router->post('/api/tienda/registro', [TiendaAuthController::class, 'registro']);
$router->post('/api/tienda/activar',  [TiendaAuthController::class, 'activar']);
$router->post('/api/tienda/reenviar', [TiendaAuthController::class, 'reenviar']);
$router->post('/api/tienda/olvide',       [TiendaAuthController::class, 'olvide']);
$router->post('/api/tienda/restablecer',  [TiendaAuthController::class, 'restablecer']);
$router->post('/api/tienda/login',    [TiendaAuthController::class, 'login']);
$router->post('/api/tienda/logout',   [TiendaAuthController::class, 'logout']);
$router->get('/api/tienda/yo',        [TiendaAuthController::class, 'yo']);
// Catálogo público
$router->get('/api/tienda/catalogo',   [CatalogoController::class, 'index']);
$router->get('/api/tienda/producto',   [CatalogoController::class, 'producto']);
$router->get('/api/tienda/categorias', [CatalogoController::class, 'categorias']);
$router->get('/api/tienda/envios',     [EmpresaEnvioController::class, 'opciones']);
// Pedidos del cliente
$router->post('/api/tienda/pedidos',     [PedidoController::class, 'crear']);
$router->get('/api/tienda/mis-pedidos',  [PedidoController::class, 'mis']);
// Modo de navegación (minorista/mayorista) y solicitud de acceso mayorista
$router->post('/api/tienda/modo',                [TiendaAuthController::class, 'modo']);
$router->post('/api/tienda/solicitud-mayorista', [MayoristaController::class, 'solicitar']);
$router->get('/api/tienda/favoritos',         [FavoritoController::class, 'listar']);
$router->post('/api/tienda/favoritos/toggle', [FavoritoController::class, 'toggle']);
// Home modular (pública)
$router->get('/api/tienda/home', [HomeController::class, 'home']);

// --- Panel admin: bloques de la home (page builder) ---
$router->get('/api/admin/bloques',              [BloqueController::class, 'listar'],      true);
$router->post('/api/admin/bloques/guardar',     [BloqueController::class, 'guardar'],     true);
$router->post('/api/admin/bloques/estado',      [BloqueController::class, 'estado'],      true);
$router->post('/api/admin/bloques/mover',       [BloqueController::class, 'mover'],       true);
$router->post('/api/admin/bloques/reordenar',   [BloqueController::class, 'reordenar'],   true);
$router->post('/api/admin/bloques/borrar',      [BloqueController::class, 'borrar'],      true);
$router->get('/api/admin/bloques/slides',       [BloqueController::class, 'slides'],      true);
$router->post('/api/admin/bloques/slide/guardar',[BloqueController::class, 'guardarSlide'],true);
$router->post('/api/admin/bloques/slide/borrar', [BloqueController::class, 'borrarSlide'], true);
// Solicitudes mayoristas (gestión admin)
$router->get('/api/admin/solicitudes',          [MayoristaController::class, 'adminListar'],  true);
$router->post('/api/admin/solicitudes/resolver', [MayoristaController::class, 'adminResolver'], true);

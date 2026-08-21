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

/** @var App\Core\Router $router */

// --- Autenticación (públicas) ---
$router->post('/api/login',  [AuthController::class, 'login']);
$router->post('/api/logout', [AuthController::class, 'logout']);
$router->get('/api/yo',      [AuthController::class, 'yo']);

// --- POS (requieren login) ---
$router->get('/api/productos',         [ProductoController::class, 'index'],  true);
$router->get('/api/productos/buscar',  [ProductoController::class, 'buscar'], true);
$router->get('/api/productos/precios', [ProductoController::class, 'precios'], true);
$router->get('/api/clientes',          [ClienteController::class, 'index'],   true);
$router->get('/api/tipos-pago',        [TipoPagoController::class, 'index'],   true);
$router->post('/api/ventas',           [VentaController::class, 'crear'],      true);

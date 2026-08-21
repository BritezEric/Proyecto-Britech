<?php

/**
 * Definicion de rutas de la API.
 * Cada linea dice: "cuando llegue ESTE metodo + ESTA ruta, ejecuta ESTE controlador".
 * $router viene desde public/index.php.
 */

use App\Controllers\ProductoController;
use App\Controllers\ClienteController;
use App\Controllers\TipoPagoController;
use App\Controllers\VentaController;

/** @var App\Core\Router $router */

$router->get('/api/productos', [ProductoController::class, 'index']);
$router->get('/api/productos/buscar', [ProductoController::class, 'buscar']);
$router->get('/api/productos/precios', [ProductoController::class, 'precios']);
$router->get('/api/clientes', [ClienteController::class, 'index']);
$router->get('/api/tipos-pago', [TipoPagoController::class, 'index']);
$router->post('/api/ventas', [VentaController::class, 'crear']);

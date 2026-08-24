<?php

/**
 * FRONT CONTROLLER: la unica puerta de entrada al backend.
 * TODAS las peticiones a la API pasan por aca.
 */

use App\Core\Router;
use App\Core\Request;
use App\Core\Session;

// En el servidor de desarrollo (php -S), servir archivos estaticos (html/css/js)
// tal cual, sin pasar por el router. En produccion esto lo hace el .htaccess.
if (php_sapi_name() === 'cli-server') {
    $ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    // La raíz "/" muestra la landing con toda la navegación.
    if ($ruta === '/' || $ruta === '') {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/inicio.html');
        return true;
    }
    if (is_file(__DIR__ . $ruta)) {
        return false;   // archivo estático (html/css/js): servirlo tal cual
    }
}

// 1. Carga autoload de Composer + variables de entorno (.env) y la config.
$config = require dirname(__DIR__) . '/config/config.php';

// Red de seguridad: cualquier error no capturado sale como JSON limpio (no un
// stack trace en pantalla). En modo debug incluimos el mensaje; en prod, genérico.
set_exception_handler(function (\Throwable $e) use ($config) {
    error_log('[Britech] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $debug = $config['app']['debug'] ?? false;
    App\Core\Response::json([
        'error' => 'Ocurrió un error en el servidor.',
        'detalle' => $debug ? $e->getMessage() : null,
    ], 500);
});

// 2. Arranca la sesión (para saber si el usuario está logueado).
Session::iniciar();

// 3. Crea el router y carga las rutas de la API.
$router = new Router();
require dirname(__DIR__) . '/routes/api.php';

// 4. Despacha: busca la ruta pedida y ejecuta el controlador correcto.
$router->despachar(Request::metodo(), Request::ruta());

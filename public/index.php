<?php

/**
 * FRONT CONTROLLER: la unica puerta de entrada al backend.
 * TODAS las peticiones a la API pasan por aca.
 */

use App\Core\Router;
use App\Core\Request;

// En el servidor de desarrollo (php -S), servir archivos estaticos (html/css/js)
// tal cual, sin pasar por el router. En produccion esto lo hace el .htaccess.
if (php_sapi_name() === 'cli-server') {
    $archivo = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($archivo)) {
        return false;
    }
}

// 1. Carga autoload de Composer + variables de entorno (.env) y la config.
require dirname(__DIR__) . '/config/config.php';

// 2. Crea el router y carga las rutas de la API.
$router = new Router();
require dirname(__DIR__) . '/routes/api.php';

// 3. Despacha: busca la ruta pedida y ejecuta el controlador correcto.
$router->despachar(Request::metodo(), Request::ruta());

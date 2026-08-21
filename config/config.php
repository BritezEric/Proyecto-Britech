<?php
/**
 * Configuracion central de la aplicacion.
 * Lee las credenciales del entorno:
 *  - En Laragon: desde el archivo .env
 *  - En Docker:  desde las variables que define docker-compose.yml
 * Devuelve un array con toda la config para que el resto del sistema la use.
 */

// 1. Carga el autoload de Composer (una sola vez).
require_once dirname(__DIR__) . '/vendor/autoload.php';

// 2. Carga el .env SOLO si el entorno no definio ya las variables.
//    (En Docker las define docker-compose, y no queremos pisarlas.)
if (getenv('DB_HOST') === false && !isset($_ENV['DB_HOST'])) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

// Lee una variable: primero del entorno real (Docker), luego del .env (Laragon).
$leer = fn(string $clave, ?string $def = null): ?string
    => getenv($clave) !== false ? getenv($clave) : ($_ENV[$clave] ?? $def);

// 3. Devuelve la config lista para usar.
return [
    'app' => [
        'nombre'  => $leer('APP_NAME', 'Britech'),
        'entorno' => $leer('APP_ENV', 'local'),
        'debug'   => $leer('APP_DEBUG', 'false') === 'true',
        'url'     => $leer('APP_URL', 'http://127.0.0.1:8123'),
    ],
    'db' => [
        'host'    => $leer('DB_HOST', '127.0.0.1'),
        'port'    => $leer('DB_PORT', '3306'),
        'name'    => $leer('DB_NAME', ''),
        'user'    => $leer('DB_USER', 'root'),
        'pass'    => $leer('DB_PASS', ''),
        'charset' => $leer('DB_CHARSET', 'utf8mb4'),
    ],
    'mail' => [
        'host'        => $leer('MAIL_HOST', 'smtp.gmail.com'),
        'port'        => $leer('MAIL_PORT', '587'),
        'secure'      => $leer('MAIL_SECURE', 'tls'),
        'user'        => $leer('MAIL_USER', ''),
        'password'    => $leer('MAIL_PASSWORD', ''),
        'from_email'  => $leer('MAIL_FROM_EMAIL', 'no-reply@britech.local'),
        'from_nombre' => $leer('MAIL_FROM_NOMBRE', 'Britech'),
    ],
];

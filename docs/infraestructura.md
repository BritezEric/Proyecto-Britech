# Infraestructura / Cimientos

> Estado: **funcionando y probado** (2026-08-19). PHP 8.4, Composer 2.10, MySQL 8.4.

## Estructura de carpetas
```
public/          web accesible (se agrega index.php = Front Controller)
app/
  Core/          Router, Database, etc. (namespace App\Core)
  Controllers/   Services/  Repositories/  Models/  Middleware/  Views/  Helpers/
config/          config.php (lee el .env)
routes/          definicion de rutas (a futuro)
database/        schema_ventas.sql
storage/logs/    logs
docs/            documentacion
vendor/          dependencias de Composer (NO se sube al repo)
```

## Composer
- `composer.json` = lista de dependencias + regla de autoload PSR-4 (`App\ -> app/`).
- `composer.lock` = versiones exactas instaladas.
- Dependencia instalada: **vlucas/phpdotenv** (lee el `.env`).
- Reinstalar en otra PC: `composer install`.

## Variables de entorno (.env)
- `.env` guarda credenciales (DB_HOST, DB_NAME, etc.). **No se sube al repo** (`.gitignore`).
- `.env.example` es la plantilla (sí se sube, sin secretos).
- Las lee `config/config.php` y las deja en un array.

## Conexion a la base (PDO)
- Clase `App\Core\Database` (`app/Core/Database.php`).
- `Database::conexion()` devuelve una unica conexion PDO (patron singleton).
- Configurada con: errores como excepciones, fetch asociativo, prepared statements reales.
- Uso: `$pdo = \App\Core\Database::conexion();`

## Como probar la conexion
```php
require 'vendor/autoload.php';
$pdo = App\Core\Database::conexion();
var_dump($pdo->query('SELECT COUNT(*) FROM producto')->fetch());
```

## API (backend) y frontend separados
- **Backend:** `public/index.php` (Front Controller) → `App\Core\Router` reparte
  las rutas → Controller → Repository → PDO. Los controladores devuelven **JSON**.
  - `App\Core\Request` / `App\Core\Response`: leer la peticion / devolver JSON.
  - Rutas definidas en `routes/api.php`. Primera ruta: `GET /api/productos`.
- **Frontend:** HTML/CSS/JS en `public/`. Pide datos a la API con `fetch`.
  - Ejemplo probado: `public/productos.html` + `assets/js/productos.js`.

## Como correr el proyecto en desarrollo
```
php -S 127.0.0.1:8123 -t public public/index.php
```
Luego abrir: http://127.0.0.1:8123/productos.html
(En Laragon con Apache, el `.htaccess` cumple el mismo rol que el router de `php -S`.)

## Cimientos: COMPLETOS ✓
Estructura, Composer, .env, PDO, Router y separacion back/front: funcionando y probado.

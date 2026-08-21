<?php

namespace App\Core;

use PDO;

/**
 * Conexion a la base de datos usando PDO.
 *
 * Usa el patron "una sola conexion" (singleton): la primera vez crea la conexion,
 * y las siguientes veces devuelve la misma. Asi no abrimos una conexion nueva
 * en cada consulta.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function conexion(): PDO
    {
        // Si todavia no existe la conexion, la creamos.
        if (self::$pdo === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
            $db = $config['db'];

            // DSN = la "direccion" de la base para PDO.
            $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";

            self::$pdo = new PDO($dsn, $db['user'], $db['pass'], [
                // Si hay un error de SQL, lanza una excepcion (no lo ignora en silencio).
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                // Los resultados vienen como array asociativo: $fila['nombre'].
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Prepared statements reales (mas seguros contra SQL injection).
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$pdo;
    }
}

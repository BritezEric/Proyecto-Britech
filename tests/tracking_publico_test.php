<?php

/**
 * Pruebas del seguimiento PÚBLICO por token (link sin login).
 *   1) Token válido → devuelve el envío (estado + destino).
 *   2) Token inválido → null (el controlador responde 404).
 *   3) El token del envío A NO devuelve datos del envío B (aislamiento).
 *   4) La respuesta NO expone datos personales del cliente.
 *
 * Inserta dentro de una transacción y hace ROLLBACK al final: no deja basura,
 * corre sobre cualquier base. Requiere schema_tracking.sql + schema_tracking_token.sql.
 *   DB_HOST=127.0.0.1 DB_NAME=britech_v2 php tests/tracking_publico_test.php
 */

require dirname(__DIR__) . '/config/config.php';

use App\Core\Database;
use App\Repositories\TrackingRepository;

$fallos = 0; $total = 0;
function check(string $nombre, callable $fn): void {
    global $fallos, $total; $total++;
    try { $fn(); echo "  OK    $nombre\n"; }
    catch (\Throwable $e) { $fallos++; echo "  FALLA $nombre → {$e->getMessage()}\n"; }
}
function verdad($cond, string $que): void { if (!$cond) throw new \Exception($que); }

$pdo = Database::conexion();
$repo = new TrackingRepository();

$tokenA = 'test_' . bin2hex(random_bytes(8));
$tokenB = 'test_' . bin2hex(random_bytes(8));

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare("INSERT INTO envio (estado, costo, direccion, token, dest_lat, dest_lng) VALUES ('en_camino', 0, 'Calle Falsa 123', ?, ?, ?)");
    $ins->execute([$tokenA, -26.1800, -58.1700]);
    $ins->execute([$tokenB, -26.2000, -58.2000]);

    check('token válido devuelve el envío', function () use ($repo, $tokenA) {
        $e = $repo->seguimientoPorToken($tokenA);
        verdad($e !== null, 'devolvió null para un token válido');
        verdad($e['estado'] === 'en_camino', 'estado incorrecto');
    });

    check('token inválido devuelve null (→ 404)', function () use ($repo) {
        $e = $repo->seguimientoPorToken('no_existe_' . bin2hex(random_bytes(6)));
        verdad($e === null, 'un token inexistente no debería devolver datos');
    });

    check('el token de A no devuelve el destino de B (aislamiento)', function () use ($repo, $tokenA, $tokenB) {
        $a = $repo->seguimientoPorToken($tokenA);
        $b = $repo->seguimientoPorToken($tokenB);
        verdad(abs((float) $a['dest_lat'] - (-26.1800)) < 1e-6, 'A trajo otro destino');
        verdad(abs((float) $b['dest_lat'] - (-26.2000)) < 1e-6, 'B trajo otro destino');
        verdad($a['envio_id'] !== $b['envio_id'], 'A y B comparten envío');
    });

    check('no expone datos personales del cliente', function () use ($repo, $tokenA) {
        $e = $repo->seguimientoPorToken($tokenA);
        foreach (['destinatario', 'telefono', 'direccion', 'numero', 'cp', 'cliente', 'cliente_id', 'email'] as $campo) {
            verdad(!array_key_exists($campo, $e), "filtró el campo personal: $campo");
        }
    });

} finally {
    $pdo->rollBack();   // no persistimos nada
}

echo "\n$total pruebas, $fallos fallas\n";
exit($fallos === 0 ? 0 : 1);

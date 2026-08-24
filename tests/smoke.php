<?php

/**
 * Smoke test de Britech — sin frameworks. Corre con:  php tests/smoke.php
 *
 * Verifica los invariantes que suelen romperse el día de la demo:
 *   1. El proyecto arranca (autoload + config).
 *   2. La base está importada (tablas núcleo presentes).
 *   3. El admin existe y su contraseña verifica.
 *   4. Las reglas de validación del dinero rechazan datos inválidos.
 *
 * Sale con código 0 si todo pasa, 1 si algo falla (útil para CI).
 */

require dirname(__DIR__) . '/config/config.php';

use App\Core\Database;
use App\Core\ValidacionException;
use App\Services\GastoService;

$fallos = 0;
function check(string $nombre, callable $fn): void
{
    global $fallos;
    try {
        $fn();
        echo "  OK   $nombre\n";
    } catch (\Throwable $e) {
        $fallos++;
        echo "  FALLA $nombre → {$e->getMessage()}\n";
    }
}
function assertTrue(bool $c, string $msg): void
{
    if (!$c) throw new \Exception($msg);
}

echo "== Britech smoke test ==\n";

// 1. Arranque
check('config carga', fn() => assertTrue(
    class_exists(Database::class),
    'no se cargó el autoload / la clase Database'
));

// 2. Base importada: tablas núcleo
$pdo = Database::conexion();
foreach (['usuario', 'cliente', 'producto', 'inventario', 'venta', 'gasto'] as $t) {
    check("tabla '$t' existe", function () use ($pdo, $t) {
        $r = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($t))->fetch();
        assertTrue($r !== false, "falta la tabla '$t' (¿importaste los .sql?)");
    });
}

// 3. Admin existe y su contraseña verifica
check("admin@britech.local / admin1234", function () use ($pdo) {
    $u = $pdo->query("SELECT password_hash FROM usuario WHERE email = 'admin@britech.local'")->fetch();
    assertTrue($u !== false, 'no existe el usuario admin');
    assertTrue(password_verify('admin1234', $u['password_hash']), 'la contraseña admin1234 no verifica');
});

// 4. Reglas de validación de Gastos (money path). validar() corre antes de tocar
//    la base, así que rechazar datos inválidos no escribe nada.
$svc = new GastoService();
$rechaza = function (array $in, string $porque) use ($svc) {
    try {
        $svc->guardar($in, 1);
        throw new \Exception("debió rechazar: $porque");
    } catch (ValidacionException $e) {
        // esperado
    }
};
check('gasto sin concepto → rechazado', fn() => $rechaza(['concepto' => '', 'monto' => 100], 'concepto vacío'));
check('gasto con monto 0 → rechazado', fn() => $rechaza(['concepto' => 'Cable', 'monto' => 0], 'monto <= 0'));
check('producto sin cantidad → rechazado', fn() => $rechaza(
    ['concepto' => 'Compra', 'monto' => 500, 'producto_id' => 1, 'cantidad' => ''],
    'producto sin cantidad'
));

echo $fallos === 0 ? "\nTODO OK\n" : "\n$fallos falla(s)\n";
exit($fallos === 0 ? 0 : 1);

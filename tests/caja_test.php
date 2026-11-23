<?php

/**
 * Pruebas del Cierre de caja — sin frameworks (mismo estilo que los otros tests).
 *
 * Recorre un turno REAL por HTTP, como lo haría un vendedor en el POS y un admin en el
 * panel, y comprueba la plata en cada paso:
 *   apertura → ventas (efectivo y mixta) → ingreso / retiro / gasto → anulación →
 *   arqueo por forma de pago → cierre → reporte PDF → cierre del día →
 *   cierre administrativo de una caja olvidada → permisos.
 *
 * SOLO sobre una base de prueba (su nombre debe terminar en "_test"): abre y cierra cajas,
 * vende, anula y le pone una contraseña conocida a un vendedor. Requiere
 * database/schema_caja_cierre.sql aplicado sobre esa base y el servidor levantado con ella:
 *   DB_NAME=britech_caja_test php -S 127.0.0.1:8125 -t public server.php
 *   DB_NAME=britech_caja_test BRITECH_TEST_URL=http://127.0.0.1:8125 php tests/caja_test.php
 * Admin por defecto: admin@britech.local / admin1234 (BRITECH_ADMIN_EMAIL / BRITECH_ADMIN_PASS).
 *
 * Sale con código 0 si todo pasa, 1 si algo falla.
 */

require dirname(__DIR__) . '/config/config.php';

use App\Core\Database;
use App\Repositories\CajaRepository;

$fallos = 0;
$total = 0;
function check(string $nombre, callable $fn): void
{
    global $fallos, $total;
    $total++;
    try {
        $fn();
        echo "  OK    $nombre\n";
    } catch (\Throwable $e) {
        $fallos++;
        echo "  FALLA $nombre → {$e->getMessage()}\n";
    }
}
function igual($esperado, $real, string $que): void
{
    if (is_numeric($esperado) && is_numeric($real)) {
        if (abs((float) $esperado - (float) $real) > 0.001) throw new \Exception("$que: esperado $esperado, obtenido $real");
        return;
    }
    if ($esperado != $real) throw new \Exception("$que: esperado " . json_encode($esperado) . ', obtenido ' . json_encode($real));
}
function verdad(bool $c, string $que): void
{
    if (!$c) throw new \Exception($que);
}

$pdo = Database::conexion();
$base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with($base, '_test')) {
    fwrite(STDERR, "Base '$base': este test modifica datos y solo corre sobre una base *_test.\n");
    exit(1);
}
$url = rtrim((string) (getenv('BRITECH_TEST_URL') ?: ''), '/');
if ($url === '') {
    fwrite(STDERR, "Falta BRITECH_TEST_URL (el servidor levantado con DB_NAME=$base).\n");
    exit(1);
}
if (!CajaRepository::esquemaListo()) {
    fwrite(STDERR, "La base '$base' no tiene database/schema_caja_cierre.sql aplicado.\n");
    exit(1);
}
$adminEmail = getenv('BRITECH_ADMIN_EMAIL') ?: 'admin@britech.local';
$adminPass  = getenv('BRITECH_ADMIN_PASS') ?: 'admin1234';

/** Cliente HTTP con su propia sesión (cookie). Devuelve [código, cuerpo, content-type]. */
function http(string $sesion, string $metodo, string $ruta, ?array $json = null): array
{
    global $url;
    $jar = sys_get_temp_dir() . "/britech_caja_$sesion.txt";
    $ch = curl_init($url . $ruta);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 60,
    ]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $cuerpo = (string) curl_exec($ch);
    $r = [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $cuerpo, (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
    curl_close($ch);
    return $r;
}
function jget(string $sesion, string $ruta): array
{
    [$c, $b] = http($sesion, 'GET', $ruta);
    $j = json_decode($b, true);
    if (!is_array($j)) throw new \Exception("GET $ruta ($c) no devolvió JSON");
    return $j + ['_code' => $c];
}
function jpost(string $sesion, string $ruta, array $datos): array
{
    [$c, $b] = http($sesion, 'POST', $ruta, $datos);
    $j = json_decode($b, true);
    if (!is_array($j)) throw new \Exception("POST $ruta ($c) no devolvió JSON");
    return $j + ['_code' => $c];
}
/** Total de una forma de pago en el resumen. */
function forma(array $resumen, string $nombre): array
{
    foreach ($resumen['formas'] as $f) if (mb_strtolower($f['nombre']) === mb_strtolower($nombre)) return $f;
    throw new \Exception("no aparece la forma de pago $nombre en el resumen");
}
/** Arma un conteo de billetes que sume exactamente $monto (los centavos van a "sueltos"). */
function billetesPara(float $monto): array
{
    $resto = (int) floor($monto);
    $b = [];
    foreach ([20000, 10000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1] as $d) {
        if ($resto >= $d) { $b[(string) $d] = intdiv($resto, $d); $resto %= $d; }
    }
    return [$b, round($monto - floor($monto), 2)];
}

foreach (glob(sys_get_temp_dir() . '/britech_caja_*.txt') ?: [] as $f) @unlink($f);
echo "Cierre de caja · base $base · $url\n";

// --- Preparación (solo en la base *_test) ---
$vend = $pdo->query("SELECT id, email, nombre FROM usuario WHERE rol_id = 2 AND activo = 1 ORDER BY id LIMIT 1")->fetch();
if (!$vend) { fwrite(STDERR, "La base no tiene ningún vendedor activo.\n"); exit(1); }
$vendId = (int) $vend['id'];
$pdo->prepare("UPDATE usuario SET password_hash = ?, email_verificado = 1 WHERE id = ?")
    ->execute([password_hash('vendedor1234', PASSWORD_DEFAULT), $vendId]);
$adminId = (int) $pdo->query("SELECT id FROM usuario WHERE email = " . $pdo->quote($adminEmail))->fetchColumn();
// Arrancamos sin cajas abiertas del vendedor ni del admin (cerradas "a mano" en la base de prueba).
$pdo->prepare("UPDATE caja SET estado = 'cerrada', cerrada_en = NOW() WHERE estado = 'abierta' AND usuario_id IN (?, ?)")
    ->execute([$vendId, $adminId]);
$prod = $pdo->query(
    "SELECT p.id, pr.precio FROM producto p JOIN precio pr ON pr.producto_id = p.id AND pr.lista_precio_id = 1
     JOIN inventario i ON i.producto_id = p.id
     WHERE p.activo = 1 AND p.es_sobre_pedido = 0 AND i.cantidad >= 5 ORDER BY p.id LIMIT 1"
)->fetch();
if (!$prod) { fwrite(STDERR, "No hay un producto con stock (>=5) y precio para vender.\n"); exit(1); }
$P = (float) $prod['precio'];
$pid = (int) $prod['id'];
$venta = fn(int $cant, array $pagos) => jpost('vend', '/api/ventas', [
    'cliente_id' => 1, 'items' => [['producto_id' => $pid, 'cantidad' => $cant]], 'pagos' => $pagos,
]);

$r = jpost('vend', '/api/login', ['email' => $vend['email'], 'password' => 'vendedor1234']);
if (empty($r['ok'])) { fwrite(STDERR, 'No pudo entrar el vendedor: ' . ($r['error'] ?? '') . "\n"); exit(1); }
$r = jpost('admin', '/api/login', ['email' => $adminEmail, 'password' => $adminPass]);
if (empty($r['ok'])) { fwrite(STDERR, 'No pudo entrar el admin: ' . ($r['error'] ?? '') . "\n"); exit(1); }

$cajaId = 0;
$v1 = 0; $v2 = 0;
$APERTURA = 10000.0;

// ---------- 1. Apertura ----------
echo "\n1) Apertura\n";

check('sin caja abierta no se puede vender', function () use ($venta, $P) {
    $r = $venta(1, [['tipo_pago_id' => 1, 'monto' => $P]]);
    verdad(empty($r['ok']), 'dejó vender sin caja');
    verdad(str_contains($r['error'] ?? '', 'abrir la caja'), 'el error no pide abrir la caja: ' . ($r['error'] ?? ''));
});

check('apertura con monto negativo → rechazada', function () {
    $r = jpost('vend', '/api/caja/abrir', ['monto' => -1]);
    igual(422, $r['_code'], 'código');
});

check('abrir caja con $ 10.000 (y no se puede abrir otra)', function () use (&$cajaId, $APERTURA) {
    $r = jpost('vend', '/api/caja/abrir', ['monto' => $APERTURA]);
    verdad(!empty($r['ok']), 'no abrió: ' . ($r['error'] ?? ''));
    $cajaId = (int) $r['caja']['caja_id'];
    $e = jget('vend', '/api/caja/estado');
    verdad(!empty($e['abierta']), 'el estado no la muestra abierta');
    igual($APERTURA, $e['esperado'], 'efectivo esperado al abrir');
    $r2 = jpost('vend', '/api/caja/abrir', ['monto' => 5]);
    igual(422, $r2['_code'], 'segunda apertura');
});

// ---------- 2. Movimientos del día ----------
echo "\n2) Ventas y movimientos del día\n";

check('venta en efectivo + venta mixta (efectivo y transferencia) → totales por forma de pago', function () use ($venta, $P, &$v1, &$v2, $APERTURA) {
    $a = $venta(1, [['tipo_pago_id' => 1, 'monto' => $P]]);
    verdad(!empty($a['ok']), 'falló la venta en efectivo: ' . ($a['error'] ?? ''));
    $b = $venta(2, [['tipo_pago_id' => 1, 'monto' => $P], ['tipo_pago_id' => 2, 'monto' => $P]]);
    verdad(!empty($b['ok']), 'falló la venta mixta: ' . ($b['error'] ?? ''));
    $v1 = (int) $a['venta']['venta_id']; $v2 = (int) $b['venta']['venta_id'];
    $e = jget('vend', '/api/caja/estado');
    $ef = forma($e['resumen'], 'Efectivo'); $tr = forma($e['resumen'], 'Transferencia');
    igual(2 * $P, $ef['total'], 'total en efectivo');
    igual(2, $ef['operaciones'], 'cobros en efectivo');
    igual($P, $tr['total'], 'total por transferencia');
    igual(3 * $P, $e['resumen']['total_ventas'], 'total vendido');
    igual(2, $e['resumen']['ventas'], 'cantidad de ventas');
    igual($APERTURA + 2 * $P, $e['esperado'], 'efectivo esperado (la transferencia no suma al cajón)');
});

check('retiro sin motivo → rechazado', function () {
    $r = jpost('vend', '/api/caja/movimiento', ['tipo' => 'retiro', 'monto' => 100]);
    igual(422, $r['_code'], 'código');
});

check('retiro mayor al efectivo disponible → rechazado', function () use ($APERTURA, $P) {
    $r = jpost('vend', '/api/caja/movimiento', ['tipo' => 'retiro', 'monto' => $APERTURA + 2 * $P + 1, 'motivo' => 'Prueba']);
    igual(422, $r['_code'], 'código');
    verdad(str_contains($r['error'] ?? '', 'No hay tanto efectivo'), 'mensaje: ' . ($r['error'] ?? ''));
});

check('ingreso, retiro y gasto → el esperado se mueve y el gasto queda en Gastos', function () use (&$cajaId, $APERTURA, $P) {
    foreach ([['ingreso', 2000, ''], ['retiro', 3000, 'A tesorería'], ['gasto', 1500, 'Artículos de limpieza']] as [$t, $m, $mot]) {
        $r = jpost('vend', '/api/caja/movimiento', ['tipo' => $t, 'monto' => $m, 'motivo' => $mot]);
        verdad(!empty($r['ok']), "no registró el $t: " . ($r['error'] ?? ''));
    }
    $e = jget('vend', '/api/caja/estado');
    igual(2000, $e['resumen']['ingresos'], 'ingresos');
    igual(3000, $e['resumen']['retiros'], 'retiros');
    igual(1500, $e['resumen']['gastos'], 'gastos');
    igual($APERTURA + 2 * $P + 2000 - 3000 - 1500, $e['esperado'], 'efectivo esperado');
    $g = Database::conexion()->prepare("SELECT monto, concepto FROM gasto WHERE observacion = ? ORDER BY id DESC LIMIT 1");
    $g->execute(["Pagado con efectivo de la caja #$cajaId"]);
    $gasto = $g->fetch();
    verdad((bool) $gasto, 'el gasto no se registró en la tabla gasto');
    igual(1500, $gasto['monto'], 'monto del gasto');
});

check('movimientos del día: aparecen todos y el saldo final = efectivo esperado', function () {
    $e = jget('vend', '/api/caja/estado');
    $tipos = array_count_values(array_column($e['timeline'], 'tipo'));
    igual(1, $tipos['apertura'] ?? 0, 'aperturas');
    igual(2, $tipos['venta'] ?? 0, 'ventas');
    foreach (['ingreso', 'retiro', 'gasto'] as $t) igual(1, $tipos[$t] ?? 0, $t);
    $ultimo = end($e['timeline']);
    igual($e['esperado'], $ultimo['saldo'], 'saldo de efectivo del último movimiento');
});

check('anular una venta en efectivo descuenta del esperado', function () use (&$v1, $APERTURA, $P) {
    $r = jpost('admin', '/api/ventas/anular', ['venta_id' => $v1, 'motivo' => 'Prueba de caja']);
    verdad(!empty($r['ok']), 'no se pudo anular: ' . ($r['error'] ?? ''));
    $e = jget('vend', '/api/caja/estado');
    igual(1, $e['resumen']['anuladas'], 'anuladas');
    igual($P, $e['resumen']['total_anulado'], 'total anulado');
    igual($APERTURA + $P + 2000 - 3000 - 1500, $e['esperado'], 'efectivo esperado después de anular');
    verdad(in_array('anulacion', array_column($e['timeline'], 'tipo'), true), 'la anulación no aparece en los movimientos');
});

// ---------- 3. Arqueo y cierre ----------
echo "\n3) Arqueo por forma de pago y cierre\n";

check('billete inexistente ($ 300) → rechazado', function () {
    $r = jpost('vend', '/api/caja/cerrar', ['billetes' => ['300' => 1]]);
    igual(422, $r['_code'], 'código');
});

check('si no cuadra, la observación es obligatoria', function () {
    $r = jpost('vend', '/api/caja/cerrar', ['contado' => 1, 'declarados' => []]);
    igual(422, $r['_code'], 'código');
    verdad(str_contains($r['error'] ?? '', 'observación'), 'mensaje: ' . ($r['error'] ?? ''));
});

check('cierre con billetes: el servidor suma el conteo (ignora un "contado" falso) y calcula la diferencia', function () use (&$cajaId, $P, $vendId) {
    $esperado = (float) jget('vend', '/api/caja/estado')['esperado'];
    [$billetes, $sueltos] = billetesPara($esperado - 500);
    $r = jpost('vend', '/api/caja/cerrar', [
        'billetes' => $billetes, 'monedas_sueltas' => $sueltos, 'contado' => 999999,
        'declarados' => ['2' => $P], 'observacion' => 'Faltan 500 de vuelto (test)',
    ]);
    verdad(!empty($r['ok']), 'no cerró: ' . ($r['error'] ?? ''));
    $c = $r['cierre'];
    igual($esperado - 500, $c['contado'], 'efectivo contado');
    igual(-500, $c['diferencia'], 'diferencia de efectivo');
    $tr = array_values(array_filter($c['formas'], fn($f) => $f['nombre'] === 'Transferencia'))[0] ?? null;
    verdad($tr !== null, 'falta la transferencia en el arqueo');
    igual(0, $tr['diferencia'], 'diferencia de transferencia');

    $db = Database::conexion()->prepare("SELECT * FROM caja WHERE id = ?");
    $db->execute([$cajaId]);
    $caja = $db->fetch();
    igual('cerrada', $caja['estado'], 'estado en la base');
    igual($vendId, $caja['cerrada_por'], 'cerrada_por');
    $conteo = json_decode((string) $caja['conteo_efectivo'], true);
    $suma = ($conteo['sueltos'] ?? 0);
    foreach ($conteo as $d => $n) if ($d !== 'sueltos') $suma += (int) $d * $n;
    igual($esperado - 500, $suma, 'el conteo guardado suma lo contado');
    $n = Database::conexion()->prepare("SELECT COUNT(*) FROM caja_cierre_forma WHERE caja_id = ?");
    $n->execute([$cajaId]);
    igual(2, $n->fetchColumn(), 'filas del arqueo por forma (efectivo + transferencia)');
});

check('con la caja cerrada no se puede vender ni mover plata', function () use ($venta, $P) {
    $r = $venta(1, [['tipo_pago_id' => 1, 'monto' => $P]]);
    verdad(empty($r['ok']), 'dejó vender con la caja cerrada');
    $m = jpost('vend', '/api/caja/movimiento', ['tipo' => 'ingreso', 'monto' => 10]);
    igual(422, $m['_code'], 'movimiento con caja cerrada');
});

check('el cierre no cambia si después se anula una venta de esa caja (foto del cierre)', function () use (&$cajaId, &$v2, $P) {
    $antes = jget('vend', '/api/caja/detalle?id=' . $cajaId)['cierre'];
    $r = jpost('admin', '/api/ventas/anular', ['venta_id' => $v2, 'motivo' => 'Prueba post-cierre']);
    verdad(!empty($r['ok']), 'no se pudo anular: ' . ($r['error'] ?? ''));
    $despues = jget('vend', '/api/caja/detalle?id=' . $cajaId)['cierre'];
    igual(json_encode($antes), json_encode($despues), 'arqueo guardado');
});

// ---------- 4. Salidas: reporte, historial y cierre del día ----------
echo "\n4) Reporte PDF, historial y cierre del día\n";

check('reporte de cierre en PDF (el vendedor, de su caja)', function () use (&$cajaId) {
    [$c, $b, $t] = http('vend', 'GET', '/api/caja/reporte?id=' . $cajaId);
    igual(200, $c, 'código');
    verdad(str_contains($t, 'pdf') && str_starts_with($b, '%PDF'), 'no devolvió un PDF');
});

check('historial del admin: filtro "no cuadraron" y resumen del período', function () use (&$cajaId) {
    $r = jget('admin', '/api/admin/cajas?con_diferencia=1&per_page=100');
    verdad(!empty($r['ok']), 'no cargó el historial');
    verdad(in_array($cajaId, array_map('intval', array_column($r['data'], 'id')), true), 'la caja con diferencia no aparece');
    verdad($r['resumen']['con_diferencia'] >= 1, 'el resumen no cuenta cajas con diferencia');
    $todas = array_map(fn($x) => (int) $x['con_diferencia'], $r['data']);
    verdad(!in_array(0, $todas, true), 'el filtro trajo cajas que cuadraron');
});

check('cierre del día: incluye la caja, PDF, y fecha inválida → 422', function () use (&$cajaId) {
    $hoy = date('Y-m-d');
    $z = jget('admin', '/api/admin/cajas/dia?fecha=' . $hoy);
    verdad(!empty($z['ok']), 'no cargó el cierre del día');
    verdad(in_array($cajaId, array_column($z['cajas'], 'id'), true), 'la caja no aparece en el día');
    [$c, $b] = http('admin', 'GET', '/api/admin/cajas/dia?pdf=1&fecha=' . $hoy);
    verdad($c === 200 && str_starts_with($b, '%PDF'), 'el PDF del día no salió');
    igual(422, jget('admin', '/api/admin/cajas/dia?fecha=2026-99-99')['_code'], 'fecha inválida');
});

// ---------- 5. Caja olvidada: cierre administrativo ----------
echo "\n5) Caja olvidada → cierre administrativo\n";

$olvidada = 0;
check('una caja abierta hace 20 h aparece como olvidada', function () use (&$olvidada, $vendId) {
    $pdo = Database::conexion();
    $pdo->prepare("INSERT INTO caja (usuario_id, monto_apertura, abierta_en) VALUES (?, 5000, NOW() - INTERVAL 20 HOUR)")->execute([$vendId]);
    $olvidada = (int) $pdo->lastInsertId();
    $r = jget('admin', '/api/admin/cajas');
    verdad(in_array($olvidada, array_map('intval', array_column($r['olvidadas'], 'id')), true), 'no aparece en el aviso de cajas olvidadas');
});

check('el vendedor no puede usar el cierre administrativo', function () use (&$olvidada) {
    $r = jpost('vend', '/api/admin/cajas/cerrar', ['caja_id' => $olvidada, 'contado' => 5000, 'observacion' => 'x']);
    igual(403, $r['_code'], 'código');
});

check('el admin la cierra: motivo obligatorio, queda quién la cerró, auditoría y aviso al vendedor', function () use (&$olvidada, $adminId, $vendId) {
    $sin = jpost('admin', '/api/admin/cajas/cerrar', ['caja_id' => $olvidada, 'contado' => 4800]);
    igual(422, $sin['_code'], 'sin motivo');
    $desdeAud = (int) Database::conexion()->query('SELECT COALESCE(MAX(id), 0) FROM auditoria')->fetchColumn();
    $desdeNot = (int) Database::conexion()->query('SELECT COALESCE(MAX(id), 0) FROM notificacion')->fetchColumn();
    $r = jpost('admin', '/api/admin/cajas/cerrar', ['caja_id' => $olvidada, 'contado' => 4800, 'observacion' => 'Se olvidó de cerrarla']);
    verdad(!empty($r['ok']), 'no cerró: ' . ($r['error'] ?? ''));
    igual(-200, $r['cierre']['diferencia'], 'diferencia');
    $c = Database::conexion()->query("SELECT estado, cerrada_por FROM caja WHERE id = $olvidada")->fetch();
    igual('cerrada', $c['estado'], 'estado');
    igual($adminId, $c['cerrada_por'], 'cerrada_por = admin');
    $a = Database::conexion()->query("SELECT COUNT(*) FROM auditoria WHERE id > $desdeAud AND entidad = 'caja' AND entidad_id = $olvidada AND accion = 'cerrar_admin'")->fetchColumn();
    verdad((int) $a >= 1, 'no quedó en la auditoría como cerrar_admin');
    $n = Database::conexion()->prepare("SELECT titulo FROM notificacion WHERE id > ? AND usuario_id = ? AND tipo = 'caja_cierre_detalle'");
    $n->execute([$desdeNot, $vendId]);
    $t = (string) $n->fetchColumn();
    verdad(str_contains($t, 'Se olvidó de cerrarla'), 'el vendedor no recibió el aviso con el motivo');
});

check('no se puede cerrar dos veces (ni el admin ni el vendedor)', function () use (&$olvidada) {
    $r = jpost('admin', '/api/admin/cajas/cerrar', ['caja_id' => $olvidada, 'contado' => 1, 'observacion' => 'x']);
    igual(422, $r['_code'], 'admin');
    $v = jpost('vend', '/api/caja/cerrar', ['contado' => 1, 'observacion' => 'x']);
    igual(422, $v['_code'], 'vendedor');
});

// ---------- 6. Permisos ----------
echo "\n6) Permisos\n";

check('sin sesión → 401', function () {
    igual(401, jget('anon', '/api/caja/estado')['_code'], 'estado');
});

check('el vendedor no ve el historial ni el cierre del día', function () {
    igual(403, jget('vend', '/api/admin/cajas')['_code'], 'historial');
    igual(403, jget('vend', '/api/admin/cajas/dia?fecha=' . date('Y-m-d'))['_code'], 'cierre del día');
});

check('el vendedor no puede ver la caja de otro (detalle ni PDF)', function () {
    $r = jpost('admin', '/api/caja/abrir', ['monto' => 0]);
    verdad(!empty($r['ok']), 'el admin no pudo abrir su caja');
    $ajena = (int) $r['caja']['caja_id'];
    $d = jget('vend', '/api/caja/detalle?id=' . $ajena);
    verdad(empty($d['ok']), 'vio el detalle de una caja ajena');
    [$c] = http('vend', 'GET', '/api/caja/reporte?id=' . $ajena);
    verdad($c !== 200, 'bajó el PDF de una caja ajena');
    $cerrar = jpost('admin', '/api/caja/cerrar', ['contado' => 0]);
    verdad(!empty($cerrar['ok']), 'el admin no pudo cerrar su caja: ' . ($cerrar['error'] ?? ''));
});

echo "\n" . ($total - $fallos) . "/$total pruebas OK\n";
exit($fallos ? 1 : 0);

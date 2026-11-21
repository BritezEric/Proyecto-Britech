<?php

/**
 * Pruebas del centro de control de Auditoría (alertas, ventas, cajas, stock, usuarios,
 * exportaciones e integridad) — sin frameworks, mismo estilo que tests/auditoria_test.php.
 *
 * Hace operaciones REALES por HTTP y verifica en la base y en la API que quedaron
 * registradas. SOLO sobre una base de prueba (nombre terminado en "_test") con
 * database/schema_auditoria.sql aplicado y el servidor levantado sobre esa base:
 *   DB_NAME=britech_auditoria_test php -S 127.0.0.1:8125 -t public public/index.php
 *   DB_NAME=britech_auditoria_test BRITECH_TEST_URL=http://127.0.0.1:8125 php tests/auditoria_centro_test.php
 * Usuarios: BRITECH_ADMIN_EMAIL/BRITECH_ADMIN_PASS (admin) y BRITECH_VEND_EMAIL/BRITECH_VEND_PASS (vendedor).
 *
 * Sale con código 0 si todo pasa, 1 si algo falla.
 */

require dirname(__DIR__) . '/config/config.php';

use App\Core\Database;
use App\Repositories\AuditoriaRepository;

$fallos = 0; $total = 0;
function check(string $nombre, callable $fn): void
{
    global $fallos, $total;
    $total++;
    try { $fn(); echo "  OK    $nombre\n"; }
    catch (\Throwable $e) { $fallos++; echo "  FALLA $nombre → {$e->getMessage()}\n"; }
}
function verdad(bool $c, string $que): void { if (!$c) throw new \Exception($que); }
function igual($esp, $real, string $que): void
{
    if ($esp != $real) throw new \Exception("$que: esperado " . json_encode($esp) . ', obtenido ' . json_encode($real));
}

$pdo = Database::conexion();
$base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with($base, '_test')) { fwrite(STDERR, "Base '$base': solo corre sobre una base *_test.\n"); exit(1); }
$url = rtrim((string) (getenv('BRITECH_TEST_URL') ?: ''), '/');
if ($url === '') { fwrite(STDERR, "Falta BRITECH_TEST_URL.\n"); exit(1); }
$adminEmail = getenv('BRITECH_ADMIN_EMAIL') ?: 'prueba@britech.test';
$adminPass  = getenv('BRITECH_ADMIN_PASS') ?: 'prueba1234';
$vendEmail  = getenv('BRITECH_VEND_EMAIL') ?: 'vende@britech.test';
$vendPass   = getenv('BRITECH_VEND_PASS') ?: 'prueba1234';

function http(string $sesion, string $metodo, string $ruta, ?array $json = null): array
{
    global $url;
    $jar = sys_get_temp_dir() . "/britech_cc_$sesion.txt";
    $ch = curl_init($url . $ruta);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => "BritechTest/$sesion"]);
    if ($json !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $b = (string) curl_exec($ch);
    $r = [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $b, (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE)];
    curl_close($ch);
    return $r;
}
function jget(string $s, string $ruta): array
{
    [$c, $b] = http($s, 'GET', $ruta);
    $j = json_decode($b, true);
    if (!is_array($j)) throw new \Exception("GET $ruta ($c) no devolvió JSON");
    return $j + ['_code' => $c];
}
function jpost(string $s, string $ruta, array $d): array
{
    [$c, $b] = http($s, 'POST', $ruta, $d);
    $j = json_decode($b, true);
    if (!is_array($j)) throw new \Exception("POST $ruta ($c) no devolvió JSON");
    return $j + ['_code' => $c];
}
function filas(string $sql, array $p = []): array
{
    $st = Database::conexion()->prepare($sql);
    $st->execute($p);
    return $st->fetchAll();
}
function alertas(int $desde, string $tipo): array
{
    return filas('SELECT * FROM auditoria_alerta WHERE id > ? AND tipo = ? ORDER BY id', [$desde, $tipo]);
}
$maxAud = fn() => (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM auditoria')->fetchColumn();
$maxAl  = fn() => (int) $pdo->query('SELECT COALESCE(MAX(id),0) FROM auditoria_alerta')->fetchColumn();

foreach (glob(sys_get_temp_dir() . '/britech_cc_*.txt') ?: [] as $f) @unlink($f);
$pdo->exec("DELETE FROM intento_acceso");   // el bloqueo por IP de pruebas anteriores no debe interferir
echo "Centro de control de Auditoría · base $base · $url\n";

$adminId = (int) filas('SELECT id FROM usuario WHERE email = ?', [$adminEmail])[0]['id'];
$vendId  = (int) filas('SELECT id FROM usuario WHERE email = ?', [$vendEmail])[0]['id'];
$al0 = $maxAl(); $au0 = $maxAud();
$venta = []; $caja = 0;

check('login de admin y vendedor', function () use ($adminEmail, $adminPass, $vendEmail, $vendPass) {
    verdad(!empty(jpost('admin', '/api/login', ['email' => $adminEmail, 'password' => $adminPass])['ok']), 'admin no entró');
    verdad(!empty(jpost('vend', '/api/login', ['email' => $vendEmail, 'password' => $vendPass])['ok']), 'vendedor no entró');
});

echo "\n1) Venta del vendedor\n";
check('el vendedor abre caja y vende: queda en Auditoría con tipo, cliente, total y método', function () use (&$venta, &$caja, $vendId) {
    $e = jget('vend', '/api/caja/estado');
    if (!empty($e['abierta'])) jpost('vend', '/api/caja/cerrar', ['contado' => $e['esperado']]);
    verdad(!empty(jpost('vend', '/api/caja/abrir', ['monto' => 1000])['ok']), 'no abrió la caja');
    $caja = (int) jget('vend', '/api/caja/estado')['caja']['id'];
    $p = filas("SELECT p.id, pr.precio FROM producto p JOIN precio pr ON pr.producto_id = p.id AND pr.lista_precio_id = 1
                JOIN inventario i ON i.producto_id = p.id WHERE p.activo = 1 AND p.es_sobre_pedido = 0 AND i.cantidad > 5 ORDER BY p.id LIMIT 1")[0];
    $r = jpost('vend', '/api/ventas', ['cliente_id' => 1, 'items' => [['producto_id' => (int) $p['id'], 'cantidad' => 1]],
        'pagos' => [['tipo_pago_id' => 1, 'monto' => (float) $p['precio']]]]);
    verdad(!empty($r['ok']), 'la venta falló: ' . ($r['error'] ?? json_encode($r)));
    $v = filas('SELECT * FROM venta WHERE usuario_id = ? ORDER BY id DESC LIMIT 1', [$vendId])[0];
    $venta = ['id' => (int) $v['id'], 'numero' => $v['numero'], 'total' => (float) $v['total'], 'producto' => (int) $p['id']];
    $a = filas("SELECT * FROM auditoria WHERE entidad = 'venta' AND accion = 'crear' AND entidad_id = ?", [$v['id']]);
    verdad(count($a) === 1, 'no se registró la venta');
    verdad(str_contains($a[0]['detalle'], 'presencial') && str_contains($a[0]['detalle'], 'Efectivo'), 'faltan tipo o método: ' . $a[0]['detalle']);
    igual($vendId, $a[0]['usuario_id'], 'usuario que vendió');
    verdad($a[0]['hash'] !== null && $a[0]['agente'] === 'BritechTest/vend', 'falta hash o dispositivo');
    $s = jget('admin', '/api/admin/auditoria/seccion?s=ventas&numero=' . $v['numero']);
    igual(1, $s['total'], 'la sección Ventas no la encuentra');
    igual('Efectivo', $s['data'][0]['metodo'], 'método de pago');
    igual('Vendedor Prueba', $s['data'][0]['usuario'], 'vendedor');
});

echo "\n2) Anulación con motivo\n";
check('el admin anula: motivo, quién autorizó, estado anterior → nuevo y reintegro de stock', function () use (&$venta, $adminId) {
    $r = jpost('admin', '/api/ventas/anular', ['venta_id' => $venta['id'], 'motivo' => 'Cliente desistió (prueba)']);
    verdad(!empty($r['ok']), 'no se pudo anular: ' . ($r['error'] ?? ''));
    $a = filas("SELECT * FROM auditoria WHERE entidad = 'venta' AND accion = 'anular' AND entidad_id = ?", [$venta['id']]);
    verdad(count($a) === 1 && str_contains($a[0]['detalle'], 'Cliente desistió'), 'sin motivo en auditoría');
    igual(['registrada', 'anulada'], [$a[0]['valor_anterior'], $a[0]['valor_nuevo']], 'estado');
    igual($adminId, $a[0]['usuario_id'], 'quién anuló');
    $d = jget('admin', "/api/admin/auditoria/detalle?tipo=venta&id={$venta['id']}")['datos'];
    igual('Cliente desistió (prueba)', $d['motivo'], 'motivo en el detalle');
    igual('Admin Prueba', $d['anulo'], 'autorizó');
    verdad(count($d['pagos']) === 1 && count($d['stock']) === 2, 'faltan pagos o movimientos de stock (salida + reintegro)');
    verdad(count($d['historial']) >= 2, 'falta el historial de la venta');
    $s = jget('admin', '/api/admin/auditoria/seccion?s=ventas&tipo=presencial&estado=anulada&numero=' . $venta['numero']);
    igual(1, $s['total'], 'filtros combinados tipo + estado + número');
});

echo "\n3) Devolución (cancelación de un pedido online)\n";
check('cancelar un pedido reintegra stock y queda con estado anterior → nuevo y quién lo hizo', function () use ($adminId) {
    $p = filas("SELECT id, numero, estado FROM pedido WHERE estado IN ('pendiente','preparando') ORDER BY id LIMIT 1")[0] ?? null;
    verdad((bool) $p, 'no hay pedidos para cancelar en la base de prueba');
    $r = jpost('admin', '/api/admin/pedidos/estado', ['id' => (int) $p['id'], 'estado' => 'cancelado']);
    verdad(!empty($r['ok']), 'no se pudo cancelar');
    $a = filas("SELECT * FROM auditoria WHERE entidad = 'pedido' AND entidad_id = ? AND campo = 'estado' ORDER BY id DESC LIMIT 1", [$p['id']]);
    igual([$p['estado'], 'cancelado', $adminId], [$a[0]['valor_anterior'], $a[0]['valor_nuevo'], (int) $a[0]['usuario_id']], 'registro');
    $d = jget('admin', "/api/admin/auditoria/detalle?tipo=pedido&id={$p['id']}")['datos'];
    igual('Admin Prueba', $d['anulo'], 'quién canceló');
});

echo "\n4) y 5) Caja: apertura, movimientos, cierre con diferencia\n";
check('la caja se reconstruye en orden con saldo anterior/posterior y el esperado coincide', function () use (&$caja) {
    verdad(!empty(jpost('vend', '/api/caja/movimiento', ['tipo' => 'ingreso', 'monto' => 200, 'motivo' => 'Cambio'])['ok']), 'ingreso');
    verdad(!empty(jpost('vend', '/api/caja/movimiento', ['tipo' => 'retiro', 'monto' => 100, 'motivo' => 'Pago a proveedor'])['ok']), 'retiro');
    $esp = (float) jget('vend', '/api/caja/estado')['esperado'];
    verdad(!empty(jpost('vend', '/api/caja/cerrar', ['contado' => $esp - 50])['ok']), 'no cerró');
    $d = jget('admin', "/api/admin/auditoria/detalle?tipo=caja&id=$caja")['datos'];
    igual('faltante', $d['resultado'], 'resultado');
    $tipos = array_column($d['movimientos'], 'tipo');
    foreach (['apertura', 'venta', 'anulacion', 'ingreso', 'retiro', 'cierre'] as $t) verdad(in_array($t, $tipos, true), "falta el movimiento $t");
    $cierre = end($d['movimientos']);
    igual(round($esp, 2), $cierre['saldo_anterior'], 'saldo reconstruido = esperado guardado');
    igual(round($esp, 2), (float) $d['monto_esperado'], 'esperado');
});
check('la diferencia genera una alerta automática (esperado − declarado)', function () use ($al0, &$caja) {
    $a = alertas($al0, 'caja_diferencia');
    verdad(count($a) === 1, 'no hay alerta de caja');
    igual($caja, (int) $a[0]['entidad_id'], 'caja');
    verdad(str_contains($a[0]['titulo'], 'faltante de $ 50,00'), 'título: ' . $a[0]['titulo']);
    $s = jget('admin', "/api/admin/auditoria/seccion?s=cajas&resultado=faltante&caja_id=$caja");
    igual(1, $s['total'], 'filtro de cajas');
});

echo "\n6) Stock manual\n";
check('ajuste manual: queda como "Ajuste manual" sin documento y con alerta de ajuste inusual', function () use ($al0, &$venta, $adminId) {
    $d = jget('admin', '/api/admin/productos/detalle?id=' . $venta['producto']);
    $p = $d['producto'];
    $p['stock'] = (int) $p['stock'] + 25;
    verdad(!empty(jpost('admin', '/api/admin/productos/guardar', $p)['ok']), 'no guardó el producto');
    $s = jget('admin', "/api/admin/auditoria/seccion?s=stock&producto_id={$venta['producto']}&revisar=manual&usuario_id=$adminId");
    verdad($s['total'] >= 1, 'no aparece el ajuste');
    $x = $s['data'][0];
    igual(['ajuste', 25, null], [$x['tipo'], (int) $x['valor_nuevo'] - (int) $x['valor_anterior'], $x['documento']], 'ajuste');
    verdad(count(alertas($al0, 'ajuste_inusual')) === 1, 'falta la alerta de ajuste inusual');
    $v = jget('admin', "/api/admin/auditoria/seccion?s=stock&producto_id={$venta['producto']}&tipo=venta");
    igual('venta', $v['data'][0]['documento']['tipo'] ?? null, 'la salida por venta tiene su documento');
    $h = jget('admin', "/api/admin/auditoria/detalle?tipo=producto&id={$venta['producto']}")['datos'];
    verdad(count($h['movimientos']) >= 3 && isset($h['stock_actual']), 'historial del producto');
});

echo "\n7) Cambio de rol\n";
check('admin cambia el rol: quién lo hizo, a quién, antes → después y alerta crítica', function () use ($al0, $vendId, $adminId) {
    foreach ([1, 2] as $rol) {
        $r = jpost('admin', '/api/admin/usuarios/guardar', ['id' => $vendId, 'nombre' => 'Vendedor Prueba', 'rol_id' => $rol, 'activo' => 1]);
        verdad(!empty($r['ok']), 'no cambió el rol');
    }
    $a = alertas($al0, 'cambio_rol');
    igual(['critica', 'advertencia'], array_column($a, 'nivel'), 'niveles');
    $u = jget('admin', "/api/admin/auditoria/seccion?s=usuarios&accion=rol&usuario_id=$vendId");
    verdad($u['total'] >= 2, 'sección usuarios');
    igual(['Admin Prueba', 'Vendedor Prueba', 'admin', 'vendedor'],
        [$u['data'][0]['actor'], $u['data'][0]['afectado'], $u['data'][0]['valor_anterior'], $u['data'][0]['valor_nuevo']], 'registro');
});

echo "\n8) Intentos fallidos y accesos sin permiso\n";
check('5 logins fallidos: alerta de advertencia y crítica, sin guardar la contraseña', function () use ($al0, $vendEmail) {
    for ($i = 0; $i < 5; $i++) jpost('anon', '/api/login', ['email' => $vendEmail, 'password' => "mala-clave-$i"]);
    igual(['advertencia', 'critica'], array_column(alertas($al0, 'login_fallido'), 'nivel'), 'niveles');
    verdad(!filas("SELECT id FROM auditoria WHERE detalle LIKE '%mala-clave%'"), 'se guardó la contraseña');
});
check('el vendedor intenta entrar a funciones de admin: 403 registrado y alerta', function () use ($al0) {
    for ($i = 0; $i < 3; $i++) igual(403, jget('vend', '/api/admin/auditoria/seccion?s=alertas')['_code'], 'código');
    verdad(count(filas("SELECT id FROM auditoria WHERE accion = 'acceso_denegado' AND id > 0")) >= 3, 'no se registraron');
    verdad(count(alertas($al0, 'acceso_denegado')) === 1, 'falta la alerta');
});

echo "\n9) Revisión de alertas\n";
check('marcar como revisada guarda quién y cuándo; no se puede repetir ni borrar', function () use ($al0, $adminId) {
    $a = alertas($al0, 'caja_diferencia')[0];
    $r = jpost('admin', '/api/admin/auditoria/alertas/revisar', ['id' => (int) $a['id'], 'nota' => 'Se habló con el cajero']);
    verdad(!empty($r['ok']), 'no se revisó');
    $x = filas('SELECT * FROM auditoria_alerta WHERE id = ?', [$a['id']])[0];
    igual([1, $adminId, 'Se habló con el cajero'], [(int) $x['revisada'], (int) $x['revisada_por'], $x['nota_revision']], 'revisión');
    verdad($x['revisada_en'] !== null, 'sin fecha de revisión');
    igual(409, jpost('admin', '/api/admin/auditoria/alertas/revisar', ['id' => (int) $a['id']])['_code'], 'segunda revisión');
    $h = jget('admin', '/api/admin/auditoria/seccion?s=alertas&estado=revisada&tipo=caja_diferencia');
    verdad($h['total'] >= 1, 'historial de alertas atendidas');
    verdad(count(filas("SELECT id FROM auditoria WHERE entidad = 'alerta' AND accion = 'revisar' AND entidad_id = ?", [$a['id']])) === 1, 'la revisión no quedó en auditoría');
});

echo "\n10) Filtros y exportaciones\n";
check('cada sección exporta Excel y PDF con sus filtros y la exportación queda registrada', function () use ($maxAud) {
    $desde = $maxAud();
    foreach (['alertas&nivel=critica', 'ventas&estado=anulada', 'cajas&resultado=faltante', 'stock&revisar=manual', 'usuarios&accion=login_fallido'] as $q) {
        [$c, $b, $t] = http('admin', 'GET', "/api/admin/auditoria/seccion/excel?s=$q");
        verdad($c === 200 && str_contains($t, 'spreadsheetml') && str_starts_with($b, 'PK'), "Excel $q ($c)");
        [$c, $b, $t] = http('admin', 'GET', "/api/admin/auditoria/seccion/pdf?s=$q");
        verdad($c === 200 && str_contains($t, 'pdf') && str_starts_with($b, '%PDF'), "PDF $q ($c)");
    }
    [$c, , $t] = http('admin', 'GET', '/api/admin/auditoria/excel?accion=venta');
    verdad($c === 200 && str_contains($t, 'spreadsheetml'), 'Excel historial general');
    igual(11, count(filas("SELECT id FROM auditoria WHERE accion = 'exportar' AND id > ?", [$desde])), 'exportaciones registradas');
});
check('las tarjetas salen de la base', function () {
    $k = jget('admin', '/api/admin/auditoria/kpis');
    igual((int) Database::conexion()->query("SELECT COUNT(*) FROM auditoria_alerta WHERE revisada = 0")->fetchColumn(), $k['alertas_pendientes'], 'pendientes');
    igual((int) Database::conexion()->query("SELECT COUNT(*) FROM auditoria WHERE creado_en >= CURDATE()")->fetchColumn(), $k['operaciones_hoy'], 'operaciones de hoy');
    verdad($k['anulaciones_hoy'] >= 2 && $k['cajas_diferencia_hoy'] >= 1 && $k['stock_manual_hoy'] >= 1, 'valores del día');
});

echo "\nIntegridad y solo lectura\n";
check('la base rechaza modificar o borrar auditoría y alertas', function () {
    $pdo = Database::conexion();
    foreach (['UPDATE auditoria SET detalle = "x" ORDER BY id DESC LIMIT 1', 'DELETE FROM auditoria ORDER BY id DESC LIMIT 1',
              'UPDATE auditoria_alerta SET titulo = "x" ORDER BY id DESC LIMIT 1', 'DELETE FROM auditoria_alerta ORDER BY id DESC LIMIT 1',
              'UPDATE auditoria_alerta SET revisada = 0 WHERE revisada = 1 LIMIT 1'] as $sql) {
        $rechazado = false;
        try { $pdo->exec($sql); } catch (\PDOException $e) { $rechazado = true; }
        verdad($rechazado, "la base aceptó: $sql");
    }
});
check('no hay rutas para editar o borrar y el vendedor no puede leer', function () {
    foreach (['/api/admin/auditoria/borrar', '/api/admin/auditoria/alertas/borrar', '/api/admin/auditoria/alertas/editar'] as $r) {
        igual(404, http('admin', 'POST', $r, ['id' => 1])[0], $r);
    }
    igual(403, jget('vend', '/api/admin/auditoria/detalle?tipo=registro&id=1')['_code'], 'detalle');
    igual(403, jpost('vend', '/api/admin/auditoria/alertas/revisar', ['id' => 1])['_code'], 'revisar');
});
check('cadena de hashes: verifica OK y detecta un cambio en el contenido', function () {
    $r = jget('admin', '/api/admin/auditoria/integridad');
    verdad($r['disponible'] && $r['ok'] && $r['verificadas'] > 10 && $r['con_clave'], 'verificación: ' . json_encode($r));
    $f = filas('SELECT * FROM auditoria WHERE hash IS NOT NULL ORDER BY id DESC LIMIT 1')[0];
    igual($f['hash'], AuditoriaRepository::hashFila($f), 'hash recalculado');
    $f['detalle'] .= ' (alterado)';
    verdad(AuditoriaRepository::hashFila($f) !== filas('SELECT hash FROM auditoria WHERE id = ?', [$f['id']])[0]['hash'], 'no detecta el cambio');
});
check('una misma situación no duplica alertas', function () {
    igual(0, (int) Database::conexion()->query("SELECT COUNT(*) - COUNT(DISTINCT clave) FROM auditoria_alerta")->fetchColumn(), 'claves repetidas');
});

echo "\n$total pruebas · " . ($total - $fallos) . " OK · $fallos con fallas\n";
exit($fallos ? 1 : 0);

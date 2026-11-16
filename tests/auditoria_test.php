<?php

/**
 * Pruebas del módulo de Auditoría — sin frameworks (mismo estilo que los otros tests).
 *
 * Hace operaciones REALES por HTTP (login, venta, anulación, precio, stock, rol de usuario)
 * y comprueba que cada una quedó en la auditoría con usuario, valores anterior/nuevo e IP.
 * También prueba filtros, exportación PDF/Excel, permisos y que el historial no se pueda
 * modificar ni borrar.
 *
 * SOLO sobre una base de prueba (su nombre debe terminar en "_test"): crea ventas, cambia
 * precios y le pone una contraseña conocida a un vendedor para poder probar sus permisos.
 * Requiere database/schema_auditoria.sql aplicado sobre esa base y el servidor levantado
 * con esa misma base:
 *   DB_NAME=britech_auditoria_test php -S 127.0.0.1:8125 -t public server.php
 *   DB_NAME=britech_auditoria_test BRITECH_TEST_URL=http://127.0.0.1:8125 php tests/auditoria_test.php
 * Admin por defecto: admin@britech.local / admin1234 (BRITECH_ADMIN_EMAIL / BRITECH_ADMIN_PASS).
 *
 * Sale con código 0 si todo pasa, 1 si algo falla.
 */

require dirname(__DIR__) . '/config/config.php';

use App\Core\Database;
use App\Services\Auditoria;

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
$adminEmail = getenv('BRITECH_ADMIN_EMAIL') ?: 'admin@britech.local';
$adminPass  = getenv('BRITECH_ADMIN_PASS') ?: 'admin1234';

/** Cliente HTTP con su propia sesión (cookie). Devuelve [código, cuerpo, content-type]. */
function http(string $sesion, string $metodo, string $ruta, ?array $json = null): array
{
    global $url;
    $jar = sys_get_temp_dir() . "/britech_au_$sesion.txt";
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
/** Filas de auditoría creadas después de $desdeId que cumplen las condiciones. */
function auditoria(int $desdeId, array $cond): array
{
    $w = ['id > ?'];
    $p = [$desdeId];
    foreach ($cond as $k => $v) { $w[] = "$k = ?"; $p[] = $v; }
    $st = Database::conexion()->prepare('SELECT * FROM auditoria WHERE ' . implode(' AND ', $w) . ' ORDER BY id');
    $st->execute($p);
    return $st->fetchAll();
}
$ultimo = fn() => (int) Database::conexion()->query('SELECT COALESCE(MAX(id), 0) FROM auditoria')->fetchColumn();

foreach (glob(sys_get_temp_dir() . '/britech_au_*.txt') ?: [] as $f) @unlink($f);
echo "Auditoría · base $base · $url\n";

// Vendedor de prueba con contraseña conocida (solo en la base *_test).
$vend = $pdo->query("SELECT id, email FROM usuario WHERE rol_id = 2 AND activo = 1 ORDER BY id LIMIT 1")->fetch();
if (!$vend) { fwrite(STDERR, "La base no tiene ningún vendedor activo.\n"); exit(1); }
$pdo->prepare("UPDATE usuario SET password_hash = ?, email_verificado = 1 WHERE id = ?")
    ->execute([password_hash('vendedor1234', PASSWORD_DEFAULT), (int) $vend['id']]);
$adminId = (int) $pdo->query("SELECT id FROM usuario WHERE email = " . $pdo->quote($adminEmail))->fetchColumn();
$inicio = $ultimo();

// ---------- 1. Registro automático de operaciones ----------
echo "\n1) Operaciones reales → auditoría\n";

check('login fallido: guarda el email y nunca la contraseña', function () use ($adminEmail, $adminId, $inicio) {
    $r = jpost('anon', '/api/login', ['email' => $adminEmail, 'password' => 'clave-equivocada-XYZ']);
    igual(401, $r['_code'], 'código');
    $f = auditoria($inicio, ['accion' => 'login_fallido']);
    verdad(count($f) === 1, 'no se registró el intento');
    igual($adminId, $f[0]['entidad_id'], 'usuario afectado');
    verdad(str_contains($f[0]['detalle'], $adminEmail), 'falta el email en el detalle');
    verdad(!str_contains(json_encode($f[0]), 'clave-equivocada'), 'la contraseña quedó guardada');
});

check('login correcto: usuario, módulo sesión e IP', function () use ($adminEmail, $adminPass, $adminId, $inicio) {
    $r = jpost('admin', '/api/login', ['email' => $adminEmail, 'password' => $adminPass]);
    verdad(!empty($r['ok']), 'no pudo entrar el admin: ' . ($r['error'] ?? ''));
    $f = auditoria($inicio, ['accion' => 'login', 'usuario_id' => $adminId]);
    verdad(count($f) === 1, 'no se registró el login');
    igual('sesion', $f[0]['entidad'], 'módulo');
    verdad(!empty($f[0]['ip']), 'falta la IP');
});

$venta = null;
$prod = null;
check('venta en el POS: alta de venta + salida de stock con anterior → nuevo', function () use (&$venta, &$prod, $adminId, $pdo) {
    $est = jget('admin', '/api/caja/estado');
    if (empty($est['abierta'])) {
        $a = jpost('admin', '/api/caja/abrir', ['monto' => 1000]);
        verdad(!empty($a['ok']), 'no se pudo abrir la caja');
    }
    $prod = $pdo->query(
        "SELECT p.id, pr.precio, i.cantidad FROM producto p JOIN precio pr ON pr.producto_id = p.id AND pr.lista_precio_id = 1
         JOIN inventario i ON i.producto_id = p.id WHERE p.activo = 1 AND p.es_sobre_pedido = 0 AND i.cantidad >= 3 ORDER BY p.id LIMIT 1"
    )->fetch();
    verdad((bool) $prod, 'no hay producto con stock para vender');
    $desde = (int) Database::conexion()->query('SELECT MAX(id) FROM auditoria')->fetchColumn();
    $r = jpost('admin', '/api/ventas', [
        'cliente_id' => 1, 'items' => [['producto_id' => (int) $prod['id'], 'cantidad' => 1]],
        'pagos' => [['tipo_pago_id' => 1, 'monto' => (float) $prod['precio']]],
    ]);
    verdad(!empty($r['ok']), 'la venta falló: ' . ($r['error'] ?? ''));
    $venta = $r['venta'];
    $ventaId = (int) ($venta['venta_id'] ?? $venta['id'] ?? 0);
    $v = auditoria($desde, ['entidad' => 'venta', 'accion' => 'crear']);
    verdad(count($v) === 1 && (int) $v[0]['usuario_id'] === $adminId, 'no se registró la venta con su vendedor');
    $s = auditoria($desde, ['entidad' => 'stock', 'accion' => 'salida', 'entidad_id' => (int) $prod['id']]);
    verdad(count($s) === 1, 'no se registró la salida de stock');
    igual((int) $prod['cantidad'], (int) $s[0]['valor_anterior'], 'stock anterior');
    igual((int) $prod['cantidad'] - 1, (int) $s[0]['valor_nuevo'], 'stock nuevo');
    verdad(str_contains($s[0]['detalle'], 'Venta V-'), 'el detalle no dice qué venta fue');
    $venta['_id'] = $ventaId ?: (int) $v[0]['entidad_id'];
});

check('anulación de venta: evento + reintegro de stock', function () use (&$venta, &$prod) {
    verdad($venta !== null, 'no hay venta para anular');
    $desde = (int) Database::conexion()->query('SELECT MAX(id) FROM auditoria')->fetchColumn();
    $r = jpost('admin', '/api/ventas/anular', ['venta_id' => $venta['_id'], 'motivo' => 'Prueba de auditoría']);
    verdad(!empty($r['ok']), 'no se pudo anular: ' . ($r['error'] ?? ''));
    $a = auditoria($desde, ['entidad' => 'venta', 'accion' => 'anular', 'entidad_id' => $venta['_id']]);
    verdad(count($a) === 1 && str_contains($a[0]['detalle'], 'Prueba de auditoría'), 'no se registró la anulación con su motivo');
    $s = auditoria($desde, ['entidad' => 'stock', 'accion' => 'entrada', 'entidad_id' => (int) $prod['id']]);
    verdad(count($s) === 1, 'no se registró el reintegro');
    igual((int) $prod['cantidad'], (int) $s[0]['valor_nuevo'], 'stock después de anular');
});

check('cambio de precio y ajuste de stock desde Productos', function () use (&$prod) {
    $d = jget('admin', '/api/admin/productos/detalle?id=' . $prod['id']);
    verdad(!empty($d['ok']), 'no se pudo leer el producto');
    $p = $d['producto'];
    $precioAntes = (float) $p['precio_minorista'];
    $stockAntes = (int) $p['stock'];
    $p['precio_minorista'] = $precioAntes + 100;
    $p['stock'] = $stockAntes + 5;
    $desde = (int) Database::conexion()->query('SELECT MAX(id) FROM auditoria')->fetchColumn();
    $r = jpost('admin', '/api/admin/productos/guardar', $p);
    verdad(!empty($r['ok']), 'no se pudo guardar: ' . ($r['error'] ?? ''));
    $c = auditoria($desde, ['entidad' => 'producto', 'campo' => 'precio_minorista', 'entidad_id' => (int) $prod['id']]);
    verdad(count($c) === 1, 'no se registró el cambio de precio');
    igual($precioAntes, (float) $c[0]['valor_anterior'], 'precio anterior');
    igual($precioAntes + 100, (float) $c[0]['valor_nuevo'], 'precio nuevo');
    $s = auditoria($desde, ['entidad' => 'stock', 'accion' => 'ajuste', 'entidad_id' => (int) $prod['id']]);
    verdad(count($s) === 1, 'no se registró el ajuste de stock');
    igual([$stockAntes, $stockAntes + 5], [(int) $s[0]['valor_anterior'], (int) $s[0]['valor_nuevo']], 'stock anterior/nuevo');
});

check('cambio de rol de un usuario (y vuelta atrás)', function () use ($vend) {
    $desde = (int) Database::conexion()->query('SELECT MAX(id) FROM auditoria')->fetchColumn();
    $u = Database::conexion()->query('SELECT nombre FROM usuario WHERE id = ' . (int) $vend['id'])->fetchColumn();
    $r = jpost('admin', '/api/admin/usuarios/guardar', ['id' => (int) $vend['id'], 'nombre' => $u, 'rol_id' => 1, 'activo' => 1]);
    verdad(!empty($r['ok']), 'no se pudo cambiar el rol: ' . ($r['error'] ?? ''));
    $r = jpost('admin', '/api/admin/usuarios/guardar', ['id' => (int) $vend['id'], 'nombre' => $u, 'rol_id' => 2, 'activo' => 1]);
    verdad(!empty($r['ok']), 'no se pudo volver el rol');
    $f = auditoria($desde, ['entidad' => 'usuario', 'campo' => 'rol', 'entidad_id' => (int) $vend['id']]);
    igual(2, count($f), 'cambios de rol registrados');
    igual(['vendedor', 'admin'], [$f[0]['valor_anterior'], $f[0]['valor_nuevo']], 'primer cambio');
    igual(['admin', 'vendedor'], [$f[1]['valor_anterior'], $f[1]['valor_nuevo']], 'segundo cambio');
});

check('el admin no puede quitarse su propio rol', function () use ($adminId) {
    $n = Database::conexion()->query("SELECT nombre FROM usuario WHERE id = $adminId")->fetchColumn();
    $r = jpost('admin', '/api/admin/usuarios/guardar', ['id' => $adminId, 'nombre' => $n, 'rol_id' => 2, 'activo' => 1]);
    igual(422, $r['_code'], 'código');
});

check('campos sensibles se guardan enmascarados', function () {
    $desde = (int) Database::conexion()->query('SELECT MAX(id) FROM auditoria')->fetchColumn();
    Auditoria::campo('usuario', 1, 'password', 'secreto-viejo', 'secreto-nuevo');
    Auditoria::campo('repartidor', 1, 'pin', '1234', '9876');
    $f = auditoria($desde, []);
    igual(2, count($f), 'filas');
    foreach ($f as $x) igual(['[oculto]', '[oculto]'], [$x['valor_anterior'], $x['valor_nuevo']], $x['campo']);
});

// ---------- 2. Consulta y filtros ----------
echo "\n2) Pantalla de consulta: filtros, búsqueda y resumen\n";
$hoy = date('Y-m-d');
check('filtro por módulo', function () {
    $r = jget('admin', '/api/admin/auditoria?entidad=stock&per_page=100');
    verdad($r['total'] > 0, 'sin resultados');
    foreach ($r['data'] as $f) igual('stock', $f['entidad'], 'módulo de una fila');
});
check('filtro por acción', function () {
    $r = jget('admin', '/api/admin/auditoria?accion=login_fallido');
    verdad($r['total'] >= 1, 'sin resultados');
    foreach ($r['data'] as $f) igual('login_fallido', $f['accion'], 'acción de una fila');
});
check('filtro por usuario (y trae su nombre)', function () use ($adminId) {
    $r = jget('admin', "/api/admin/auditoria?usuario_id=$adminId&per_page=100");
    verdad($r['total'] > 0, 'sin resultados');
    foreach ($r['data'] as $f) igual($adminId, (int) $f['usuario_id'], 'usuario de una fila');
    verdad(!empty($r['data'][0]['usuario']), 'falta el nombre del usuario');
});
check('filtro por fechas', function () use ($hoy) {
    $r = jget('admin', "/api/admin/auditoria?desde=$hoy&hasta=$hoy");
    verdad($r['total'] > 0, 'hoy debería tener registros');
    $manana = date('Y-m-d', strtotime('+1 day'));
    igual(0, jget('admin', "/api/admin/auditoria?desde=$manana")['total'], 'registros en el futuro');
});
check('búsqueda por texto', function () {
    $r = jget('admin', '/api/admin/auditoria?q=' . rawurlencode('Prueba de auditoría'));
    verdad($r['total'] >= 1, 'no encontró la anulación por su motivo');
});
check('resumen por tipo de operación', function () {
    $r = jget('admin', '/api/admin/auditoria');
    foreach (['total', 'alta', 'cambio', 'baja', 'stock', 'login_fallido', 'usuarios'] as $k) {
        verdad(isset($r['resumen'][$k]), "falta $k en el resumen");
    }
    verdad($r['resumen']['stock'] >= 3 && $r['resumen']['baja'] >= 1, 'el resumen no cuenta las operaciones');
});
check('filtros inválidos → 422', function () {
    igual(422, jget('admin', '/api/admin/auditoria?desde=2026-13-45')['_code'], 'fecha inválida');
    igual(422, jget('admin', '/api/admin/auditoria?desde=2026-05-10&hasta=2026-05-01')['_code'], 'rango invertido');
    igual(422, jget('admin', '/api/admin/auditoria?entidad=' . rawurlencode("x' OR 1=1"))['_code'], 'módulo con inyección');
});

// ---------- 3. Exportación ----------
echo "\n3) Exportación\n";
check('PDF con filtros', function () use ($hoy) {
    [$c, $b, $t] = http('admin', 'GET', "/api/admin/auditoria/pdf?entidad=stock&desde=$hoy");
    igual(200, $c, 'código');
    verdad(str_starts_with($b, '%PDF'), 'no es un PDF');
    verdad(strlen($b) > 2000, 'PDF vacío');
});
check('Excel (.xlsx) con filtros', function () use ($hoy) {
    [$c, $b, $t] = http('admin', 'GET', "/api/admin/auditoria/excel?entidad=stock&desde=$hoy");
    igual(200, $c, 'código');
    verdad(str_contains($t, 'spreadsheetml'), "tipo $t");
    verdad(str_starts_with($b, "PK\x03\x04"), 'no es un .xlsx (zip)');
    verdad(str_contains($b, 'Auditoría del sistema') && str_contains($b, 'Módulo: Stock'), 'falta título o filtros');
    $esperadas = jget('admin', "/api/admin/auditoria?entidad=stock&desde=$hoy")['total'];
    verdad(str_contains($b, "$esperadas registro(s)"), "no exporta las $esperadas filas filtradas");
    verdad(!str_contains($b, '>Ventas<'), 'se coló un registro de otro módulo');
});

// ---------- 4. Seguridad ----------
echo "\n4) Seguridad\n";
check('sin sesión → 401', function () {
    foreach (['/api/admin/auditoria', '/api/admin/auditoria/pdf', '/api/admin/auditoria/excel'] as $r) {
        igual(401, http('anon2', 'GET', $r)[0], $r);
    }
});
check('vendedor → 403 en auditoría y en usuarios', function () use ($vend) {
    $r = jpost('vend', '/api/login', ['email' => $vend['email'], 'password' => 'vendedor1234']);
    verdad(!empty($r['ok']), 'no pudo entrar el vendedor');
    foreach (['/api/admin/auditoria', '/api/admin/auditoria/pdf', '/api/admin/auditoria/excel', '/api/admin/usuarios'] as $ruta) {
        igual(403, http('vend', 'GET', $ruta)[0], $ruta);
    }
    igual(403, jpost('vend', '/api/admin/usuarios/guardar', ['id' => (int) $vend['id'], 'nombre' => 'x', 'rol_id' => 1, 'activo' => 1])['_code'], 'auto-ascenso');
});
check('no hay rutas para editar ni borrar la auditoría', function () {
    foreach (['/api/admin/auditoria/borrar', '/api/admin/auditoria/guardar', '/api/admin/auditoria/editar'] as $r) {
        igual(404, http('admin', 'POST', $r, ['id' => 1])[0], $r);
    }
});
check('la base rechaza UPDATE y DELETE sobre la auditoría', function () {
    $pdo = Database::conexion();
    foreach (['UPDATE auditoria SET detalle = \'x\' WHERE id = (SELECT m FROM (SELECT MAX(id) m FROM auditoria) t)', 'DELETE FROM auditoria'] as $sql) {
        try { $pdo->exec($sql); throw new \LogicException("se ejecutó: $sql"); }
        catch (\PDOException $e) { verdad(str_contains($e->getMessage(), 'solo lectura'), $e->getMessage()); }
    }
});
check('logout queda registrado', function () use ($adminId) {
    $desde = (int) Database::conexion()->query('SELECT MAX(id) FROM auditoria')->fetchColumn();
    jpost('admin', '/api/logout', []);
    verdad(count(auditoria($desde, ['accion' => 'logout', 'usuario_id' => $adminId])) === 1, 'no se registró el logout');
});

foreach (glob(sys_get_temp_dir() . '/britech_au_*.txt') ?: [] as $f) @unlink($f);
echo "\n" . ($total - $fallos) . "/$total pruebas OK\n";
exit($fallos === 0 ? 0 : 1);

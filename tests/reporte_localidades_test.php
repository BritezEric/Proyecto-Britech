<?php

/**
 * Pruebas del Reporte de Localidades — sin frameworks (mismo estilo que smoke.php).
 *
 *   1) Cálculos: carga tests/fixtures/reporte_localidades_seed.sql y compara los
 *      totales del Service contra valores calculados a mano.
 *   2) HTTP (opcional): si se define BRITECH_TEST_URL, prueba permisos, filtros y
 *      exportaciones contra el servidor en marcha (admin / vendedor / cliente / sin sesión).
 *
 * SOLO sobre una base de prueba (su nombre debe terminar en "_test"), porque inserta datos:
 *   DB_HOST=127.0.0.1 DB_NAME=britech_reportes_test php tests/reporte_localidades_test.php
 *   (con el servidor levantado sobre la misma base, agregar BRITECH_TEST_URL=http://127.0.0.1:8124)
 *
 * Sale con código 0 si todo pasa, 1 si algo falla.
 */

require dirname(__DIR__) . '/config/config.php';

use App\Core\Database;
use App\Core\ValidacionException;
use App\Services\ReporteLocalidadService;

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
    if ($esperado != $real) {
        throw new \Exception("$que: esperado " . json_encode($esperado) . ', obtenido ' . json_encode($real));
    }
}

$pdo = Database::conexion();
$base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with($base, '_test')) {
    fwrite(STDERR, "Base '$base': este test inserta datos y solo corre sobre una base *_test.\n");
    exit(1);
}

echo "== Reporte de Localidades — base $base ==\n";

// ---------- Datos de prueba ----------
$pdo->exec((string) file_get_contents(__DIR__ . '/fixtures/reporte_localidades_seed.sql'));
$pdo->prepare('UPDATE usuario SET password_hash = ? WHERE id IN (9010, 9011)')
    ->execute([password_hash('vendedor-test-1234', PASSWORD_DEFAULT)]);
// La cuenta de tienda necesita contraseña + email verificado (columna que usa el login de la tienda).
$tieneVerif = (bool) $pdo->query("SHOW COLUMNS FROM cliente LIKE 'email_verificado'")->fetch();
if ($tieneVerif) {
    $pdo->prepare('UPDATE cliente SET password_hash = ?, email_verificado = 1 WHERE id = 9030')
        ->execute([password_hash('cliente-test-1234', PASSWORD_DEFAULT)]);
}

$oct = ['desde' => '2026-10-01', 'hasta' => '2026-10-27', 'dias_demora' => '3'];
$admin = new ReporteLocalidadService(true, 1);
$vendA = new ReporteLocalidadService(false, 9010);
$vendB = new ReporteLocalidadService(false, 9011);

$kpi = function (ReporteLocalidadService $s, array $q) use ($oct) {
    $f = $s->filtros(array_merge($oct, $q));
    return $s->resumen($f, $s->periodoComparacion($f, $q + ['comparar' => 'anterior']));
};
$zonas = function (ReporteLocalidadService $s, array $q = []) use ($oct) {
    $f = $s->filtros(array_merge($oct, $q));
    $rows = $s->zonas($f, $s->periodoComparacion($f, ['comparar' => 'anterior']), $q, 1, 100)['rows'];
    $out = [];
    foreach ($rows as $r) { $out[$r['zona_key']] = $r; }
    return $out;
};

echo "-- Cálculos (admin, 1 al 27 de octubre de 2026) --\n";
$k = $kpi($admin, []);
$a = $k['actual'];
check('operaciones válidas = 7 (3 POS + 4 online)', function () use ($a) {
    igual(7, $a['operaciones'], 'operaciones'); igual(3, $a['ventas_pos'], 'POS'); igual(4, $a['pedidos_online'], 'online');
});
check('anuladas y canceladas se cuentan aparte (1 + 1)', function () use ($a) {
    igual(1, $a['ventas_anuladas'], 'anuladas'); igual(1, $a['pedidos_cancelados'], 'cancelados');
});
check('importe = 97.000 (productos, sin envío ni anuladas)', function () use ($a) {
    igual(97000, $a['importe'], 'importe'); igual(44000, $a['importe_pos'], 'POS'); igual(53000, $a['importe_online'], 'online');
});
check('cobrado 78.000 y pendiente de cobro 19.000 (no se mezclan)', function () use ($a) {
    igual(78000, $a['cobrado'], 'cobrado'); igual(19000, $a['pendiente_cobro'], 'pendiente');
});
check('zonas con actividad = 3 y clientes = 4', function () use ($a) {
    igual(3, $a['zonas_activas'], 'zonas'); igual(4, $a['clientes'], 'clientes');
});
check('envíos 5 (sin retiros ni cancelados), 2 entregados, 3 activos, 3 demorados', function () use ($a) {
    igual(5, $a['envios'], 'envíos'); igual(2, $a['envios_entregados'], 'entregados');
    igual(3, $a['envios_activos'], 'activos'); igual(3, $a['envios_demorados'], 'demorados');
});
check('envío cobrado al cliente = 19.300', fn() => igual(19300, $a['envio_cobrado'], 'envío'));
check('variación vs septiembre: 16.000 → 97.000 = +506,3 %', function () use ($k) {
    igual(16000, $k['anterior']['importe'], 'anterior'); igual(506.3, $k['variacion']['importe']['pct'], 'pct');
});

$z = $zonas($admin);
check('"Formosa" y " formosa " se agrupan: 2 operaciones, 45.000', function () use ($z) {
    $f = $z['l:formosa'] ?? null;
    if ($f === null) throw new \Exception('no aparece la zona l:formosa (' . implode(', ', array_keys($z)) . ')');
    igual(2, $f['operaciones'], 'operaciones'); igual(45000, $f['importe'], 'importe'); igual(1, $f['demorados'], 'demorados');
    igual('Con demoras', $f['estado_entregas'], 'estado');
});
check('pedido con 2 productos cuenta 1 sola operación', function () use ($z) {
    igual(1, $z['l:formosa']['pedidos_online'], 'pedidos de Formosa');
});
check('barrios de Moto Express separados de las localidades', function () use ($z) {
    igual('barrio', $z['b:3']['zona_tipo'], 'tipo b:3'); igual(20000, $z['b:3']['importe'], 'importe b:3');
    igual('barrio', $z['b:1']['zona_tipo'], 'tipo b:1');
});
check('envío sin localidad → "Sin localidad identificada" (7.000)', function () use ($z) {
    igual(7000, $z['sin']['importe'], 'importe'); igual('Sin localidad identificada', $z['sin']['zona_nombre'], 'nombre');
});
check('ventas sin envío y retiros → "Retiro / venta en el local" (13.000)', function () use ($z) {
    igual(13000, $z['local']['importe'], 'importe'); igual(2, $z['local']['operaciones'], 'operaciones');
});
check('pedido cancelado: aparece con 0 operaciones y 1 cancelado (Clorinda)', function () use ($z) {
    igual(0, $z['l:clorinda']['operaciones'], 'operaciones'); igual(1, $z['l:clorinda']['anuladas'], 'anuladas');
});
check('zona sin ventas en el período anterior → sin % (no divide por cero)', function () use ($z) {
    igual(null, $z['b:1']['variacion_importe']['pct'], 'pct'); igual(true, $z['b:1']['variacion_importe']['sin_base'], 'sin_base');
    igual(350.0, $z['l:formosa']['variacion_importe']['pct'], 'pct Formosa');
});

check('productos por precio histórico (Auricular: 8 u, 44.000, 4 operaciones)', function () use ($admin, $oct) {
    $p = $admin->productos($admin->filtros($oct));
    igual(1, $p[0]['id'], 'primero'); igual(8, $p[0]['unidades'], 'unidades');
    igual(44000, $p[0]['importe'], 'importe'); igual(4, $p[0]['operaciones'], 'operaciones');
});
check('productos de la zona Formosa (Auricular 3 u)', function () use ($admin, $oct) {
    $p = $admin->productos($admin->filtros($oct + ['zona' => 'l:formosa']));
    igual(3, count($p), 'cantidad'); igual(1, $p[0]['id'], 'primero'); igual(3, $p[0]['unidades'], 'unidades');
});

echo "-- Filtros --\n";
check('canal POS: 3 operaciones / 44.000', function () use ($kpi, $admin) {
    $a = $kpi($admin, ['canal' => 'pos'])['actual']; igual(3, $a['operaciones'], 'ops'); igual(44000, $a['importe'], 'importe');
});
check('canal online: 4 operaciones / 53.000', function () use ($kpi, $admin) {
    $a = $kpi($admin, ['canal' => 'online'])['actual']; igual(4, $a['operaciones'], 'ops'); igual(53000, $a['importe'], 'importe');
});
check('estado de pedido "entregado": 2 pedidos / 34.000 (excluye POS)', function () use ($kpi, $admin) {
    $a = $kpi($admin, ['estado_pedido' => 'entregado'])['actual']; igual(2, $a['operaciones'], 'ops'); igual(34000, $a['importe'], 'importe');
});
check('zona Circuito 5 (b:1): 1 operación', fn() => igual(1, $kpi($admin, ['zona' => 'b:1'])['actual']['operaciones'], 'ops'));
check('estado de envío "cancelado": solo el pedido cancelado', function () use ($kpi, $admin) {
    $a = $kpi($admin, ['estado_envio' => 'cancelado'])['actual']; igual(0, $a['operaciones'], 'ops'); igual(1, $a['pedidos_cancelados'], 'cancelados');
});
check('medio de envío Correo Argentino: 2 operaciones', fn() => igual(2, $kpi($admin, ['empresa_envio_id' => '2'])['actual']['operaciones'], 'ops'));
check('producto Notebook: 2 operaciones', fn() => igual(2, $kpi($admin, ['producto_id' => '3'])['actual']['operaciones'], 'ops'));
check('base "cliente": Formosa 50.000 / CABA 27.000 / sin localidad 20.000', function () use ($zonas, $admin) {
    $z = $zonas($admin, ['base' => 'cliente']);
    igual(50000, $z['l:formosa']['importe'], 'Formosa'); igual(27000, $z['l:caba']['importe'], 'CABA'); igual(20000, $z['sin']['importe'], 'sin');
});
check('rango sin datos: todo en cero, sin errores', function () use ($admin) {
    $f = $admin->filtros(['desde' => '2020-01-01', 'hasta' => '2020-01-31']);
    igual(0, $admin->resumen($f, null)['actual']['operaciones'], 'ops');
    igual(0, $admin->zonas($f, null, [], 1, 10)['total'], 'zonas');
    igual([], $admin->logistica($f)['rows'], 'logística');
});
check('búsqueda de zona "formo" encuentra Formosa', function () use ($admin, $oct) {
    $r = $admin->zonas($admin->filtros($oct), null, ['q' => 'formo'], 1, 10);
    igual(1, $r['total'], 'total'); igual('l:formosa', $r['rows'][0]['zona_key'], 'zona');
});
check('orden por operaciones asc + paginación (2 por página)', function () use ($admin, $oct) {
    $r = $admin->zonas($admin->filtros($oct), null, ['orden' => 'operaciones', 'dir' => 'asc'], 1, 2);
    igual(6, $r['total'], 'total'); igual(2, count($r['rows']), 'filas'); igual(0, $r['rows'][0]['operaciones'], 'primera');
});
foreach ([
    'fecha inválida'          => ['desde' => '2026-02-30'],
    'desde > hasta'           => ['desde' => '2026-10-10', 'hasta' => '2026-10-01'],
    'rango > 3 años'          => ['desde' => '2020-01-01', 'hasta' => '2026-10-01'],
    'canal inexistente'       => ['canal' => 'whatsapp'],
    'zona con formato raro'   => ['zona' => "x' OR 1=1 --"],
    'id no numérico'          => ['producto_id' => '1 OR 1=1'],
    'días de demora fuera de rango' => ['dias_demora' => '999'],
] as $caso => $q) {
    check("rechaza $caso", function () use ($admin, $q) {
        try { $admin->filtros($q); } catch (ValidacionException $e) { return; }
        throw new \Exception('no lanzó ValidacionException');
    });
}
check('comparación "anterior" con mes parcial = mismos días del mes anterior', function () use ($admin, $oct) {
    $c = $admin->periodoComparacion($admin->filtros($oct), ['comparar' => 'anterior']);
    igual('2026-09-01', $c['desde_d']->format('Y-m-d'), 'desde'); igual('2026-09-27', $c['hasta_d']->format('Y-m-d'), 'hasta');
});
check('comparación "anterior" con rango libre = bloque previo de igual duración', function () use ($admin) {
    $c = $admin->periodoComparacion($admin->filtros(['desde' => '2026-10-10', 'hasta' => '2026-10-16']), ['comparar' => 'anterior']);
    igual('2026-10-03', $c['desde_d']->format('Y-m-d'), 'desde'); igual('2026-10-09', $c['hasta_d']->format('Y-m-d'), 'hasta');
});
check('comparar dos zonas (Formosa vs Centro)', function () use ($admin, $oct) {
    $r = $admin->compararZonas($admin->filtros($oct), 'l:formosa', 'b:3');
    igual(45000, $r['a']['importe'], 'A'); igual(20000, $r['b']['importe'], 'B');
});

echo "-- Logística --\n";
$log = $admin->logistica($admin->filtros($oct));
check('6 combinaciones zona + medio, sin retiros', fn() => igual(6, count($log['rows']), 'filas'));
check('Moto Circuito 5: pendiente, demorado y sin repartidor', function () use ($log) {
    foreach ($log['rows'] as $r) {
        if ($r['zona_key'] === 'b:1') {
            igual(1, $r['pendientes'], 'pendientes'); igual(1, $r['demorados'], 'demorados'); igual(1, $r['sin_repartidor'], 'sin repartidor');
            igual(5000, $r['costo_cobrado'], 'costo'); igual(0, $r['paga_moto'], 'paga (no entregado)');
            return;
        }
    }
    throw new \Exception('no está la fila de b:1');
});
check('Moto Centro entregado: paga al repartidor 2.500', function () use ($log) {
    foreach ($log['rows'] as $r) { if ($r['zona_key'] === 'b:3') { igual(2500, $r['paga_moto'], 'paga'); return; } }
    throw new \Exception('no está la fila de b:3');
});
check('repartidores: 1 asignado y entregado', function () use ($log) {
    igual(1, count($log['repartidores']), 'repartidores'); igual(1, $log['repartidores'][0]['entregados'], 'entregados');
});

echo "-- Alcance del vendedor --\n";
check('vendedor A: sus ventas POS (2) + pedidos online (4) = 82.000', function () use ($kpi, $vendA) {
    $a = $kpi($vendA, [])['actual']; igual(6, $a['operaciones'], 'ops'); igual(82000, $a['importe'], 'importe');
});
check('vendedor B no ve las ventas de A (68.000)', fn() => igual(68000, $kpi($vendB, [])['actual']['importe'], 'importe'));
check('vendedor no recibe cobros ni costos de envío', function () use ($kpi, $vendA, $zonas) {
    $a = $kpi($vendA, [])['actual'];
    foreach (['cobrado', 'pendiente_cobro', 'envio_cobrado'] as $c) { if (array_key_exists($c, $a)) throw new \Exception("recibió $c"); }
    foreach ($zonas($vendA) as $z) { if (array_key_exists('cobrado', $z) || array_key_exists('envio_cobrado', $z)) throw new \Exception('zona con cobros'); }
});
check('vendedor no recibe repartidores ni paga', function () use ($vendA, $oct) {
    $l = $vendA->logistica($vendA->filtros($oct));
    igual(null, $l['repartidores'], 'repartidores');
    foreach ($l['rows'] as $r) { if (array_key_exists('paga_moto', $r) || array_key_exists('costo_cobrado', $r)) throw new \Exception('fila con paga/costo'); }
});

// ---------- HTTP (opcional) ----------
$url = getenv('BRITECH_TEST_URL') ?: null;
if ($url !== null) {
    echo "-- HTTP contra $url --\n";

    /** Mini cliente HTTP con cookie de sesión. */
    $http = function (string $metodo, string $ruta, ?array $json = null, ?string &$cookie = null) use ($url): array {
        $h = "Accept: */*\r\n";
        if ($cookie) $h .= "Cookie: $cookie\r\n";
        if ($json !== null) $h .= "Content-Type: application/json\r\n";
        $ctx = stream_context_create(['http' => ['method' => $metodo, 'header' => $h, 'ignore_errors' => true,
            'content' => $json !== null ? json_encode($json) : null, 'timeout' => 60]]);
        $body = file_get_contents($url . $ruta, false, $ctx);
        $code = 0; $tipo = '';
        foreach ($http_response_header ?? [] as $l) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $l, $m)) $code = (int) $m[1];
            if (stripos($l, 'Content-Type:') === 0) $tipo = trim(substr($l, 13));
            if (stripos($l, 'Set-Cookie:') === 0 && preg_match('/(PHPSESSID=[^;]+)/', $l, $m)) $cookie = $m[1];
        }
        return [$code, $body, $tipo];
    };
    $login = function (string $ruta, string $email, string $pass) use ($http): ?string {
        $c = null;
        [$code] = $http('POST', $ruta, ['email' => $email, 'password' => $pass], $c);
        return $code === 200 ? $c : null;
    };
    $qs = '?desde=2026-10-01&hasta=2026-10-27&comparar=anterior';
    $rutas = ['opciones', 'resumen' . $qs, 'zonas' . $qs, 'productos' . $qs, 'csv' . $qs, 'pdf' . $qs];

    check('sin sesión → 401 en todas las rutas', function () use ($http, $rutas) {
        foreach ($rutas as $r) { $c = null; [$code] = $http('GET', "/api/admin/reportes/localidades/$r", null, $c); igual(401, $code, $r); }
    });

    $cAdmin = $login('/api/login', 'admin@britech.local', 'admin1234');
    $cVend  = $login('/api/login', 'vendedor.a@britech.test', 'vendedor-test-1234');
    $cCli   = $tieneVerif ? $login('/api/tienda/login', 'cliente.test@britech.test', 'cliente-test-1234') : null;

    check('login admin y vendedor', function () use ($cAdmin, $cVend) {
        if (!$cAdmin) throw new \Exception('no pudo loguear admin');
        if (!$cVend) throw new \Exception('no pudo loguear vendedor');
    });
    check('cliente de la tienda → rechazado en todas las rutas', function () use ($http, $rutas, $cCli, $tieneVerif) {
        if (!$tieneVerif) throw new \Exception('la base no tiene cliente.email_verificado: no se pudo loguear al cliente');
        if (!$cCli) throw new \Exception('no pudo loguear al cliente');
        foreach ($rutas as $r) {
            $c = $cCli; [$code] = $http('GET', "/api/admin/reportes/localidades/$r", null, $c);
            if (!in_array($code, [401, 403], true)) throw new \Exception("$r devolvió $code");
        }
    });
    check('admin: resumen 200 con los mismos totales', function () use ($http, $cAdmin, $qs) {
        $c = $cAdmin; [$code, $body] = $http('GET', "/api/admin/reportes/localidades/resumen$qs", null, $c);
        igual(200, $code, 'código'); $j = json_decode($body, true);
        igual(97000, $j['indicadores']['actual']['importe'], 'importe'); igual(78000, $j['indicadores']['actual']['cobrado'], 'cobrado');
    });
    check('vendedor: resumen sin datos financieros del admin', function () use ($http, $cVend, $qs) {
        $c = $cVend; [$code, $body] = $http('GET', "/api/admin/reportes/localidades/resumen$qs", null, $c);
        igual(200, $code, 'código'); $j = json_decode($body, true);
        igual(82000, $j['indicadores']['actual']['importe'], 'importe');
        if (array_key_exists('cobrado', $j['indicadores']['actual'])) throw new \Exception('recibió cobrado');
        igual(null, $j['logistica']['repartidores'], 'repartidores');
    });
    check('vendedor no puede ampliar su alcance por parámetro (vendedor_id)', function () use ($http, $cVend, $qs) {
        $c = $cVend; [, $body] = $http('GET', "/api/admin/reportes/localidades/resumen$qs&vendedor_id=9011", null, $c);
        igual(82000, json_decode($body, true)['indicadores']['actual']['importe'], 'importe');
    });
    check('filtro inválido → 422', function () use ($http, $cAdmin) {
        $c = $cAdmin; [$code] = $http('GET', '/api/admin/reportes/localidades/resumen?desde=2026-13-45', null, $c); igual(422, $code, 'código');
    });
    check('zonas paginadas (per_page=2)', function () use ($http, $cAdmin, $qs) {
        $c = $cAdmin; [, $body] = $http('GET', "/api/admin/reportes/localidades/zonas$qs&per_page=2&page=2", null, $c);
        $j = json_decode($body, true); igual(2, count($j['data']), 'filas'); igual(3, $j['total_pages'], 'páginas');
    });
    check('CSV: BOM, separador ; y respeta el filtro de canal', function () use ($http, $cAdmin, $qs) {
        $c = $cAdmin; [$code, $body, $tipo] = $http('GET', "/api/admin/reportes/localidades/csv$qs&canal=pos", null, $c);
        igual(200, $code, 'código');
        if (!str_starts_with($tipo, 'text/csv')) throw new \Exception("tipo $tipo");
        if (!str_starts_with($body, "\xEF\xBB\xBF")) throw new \Exception('sin BOM');
        if (!str_contains($body, 'Zona;Tipo;Provincia')) throw new \Exception('encabezado');
        if (str_contains($body, 'Clorinda')) throw new \Exception('incluyó una zona solo online con canal=pos');
        if (!str_contains($body, '15000,00')) throw new \Exception('no tiene el importe de Formosa POS');
    });
    check('CSV del vendedor sin columnas de cobro', function () use ($http, $cVend, $qs) {
        $c = $cVend; [, $body] = $http('GET', "/api/admin/reportes/localidades/csv$qs", null, $c);
        if (str_contains($body, 'Cobrado')) throw new \Exception('tiene la columna Cobrado');
    });
    check('PDF: se genera (application/pdf, %PDF)', function () use ($http, $cAdmin, $qs) {
        $c = $cAdmin; [$code, $body, $tipo] = $http('GET', "/api/admin/reportes/localidades/pdf$qs&zona=l:formosa", null, $c);
        igual(200, $code, 'código');
        if (!str_starts_with($tipo, 'application/pdf') || !str_starts_with($body, '%PDF')) throw new \Exception("tipo $tipo");
    });
    check('módulos existentes siguen andando (dashboard, pedidos, barrios)', function () use ($http, $cAdmin) {
        foreach (['/api/admin/dashboard', '/api/admin/pedidos', '/api/admin/barrios'] as $r) {
            $c = $cAdmin; [$code] = $http('GET', $r, null, $c); igual(200, $code, $r);
        }
    });
}

echo "\n" . ($total - $fallos) . " de $total pruebas OK" . ($fallos ? " · $fallos FALLARON" : '') . "\n";
exit($fallos ? 1 : 0);

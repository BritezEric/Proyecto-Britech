<?php

/**
 * Pruebas de los Reportes Operativos — sin frameworks (mismo estilo que reporte_localidades_test.php).
 *
 *   1) Cálculos: carga tests/fixtures/reportes_operativos_seed.sql (todo el 20/10/2026) y
 *      compara cada reporte contra valores calculados a mano.
 *   2) HTTP (opcional): con BRITECH_TEST_URL prueba permisos (admin / vendedor / sin sesión),
 *      validación de filtros y exportaciones CSV/PDF contra el servidor en marcha.
 *
 * SOLO sobre una base de prueba (su nombre debe terminar en "_test"), porque inserta datos.
 * Requiere database/schema_reportes_operativos.sql aplicado sobre esa base.
 *   DB_HOST=127.0.0.1 DB_NAME=britech_reportes_test php tests/reportes_operativos_test.php
 *
 * Sale con código 0 si todo pasa, 1 si algo falla.
 */

require dirname(__DIR__) . '/config/config.php';

use App\Core\Database;
use App\Core\ValidacionException;
use App\Repositories\EnvioRepository;
use App\Services\ReporteOperativoService;

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
/** Valor de un indicador por su clave. */
function kpi(array $d, string $k)
{
    foreach ($d['kpis'] as $x) if ($x['k'] === $k) return $x['valor'];
    throw new \Exception("no existe el indicador $k");
}
function fila(array $tabla, string $campo, $valor): array
{
    foreach ($tabla['filas'] as $f) if (($f[$campo] ?? null) == $valor) return $f;
    throw new \Exception("no hay fila con $campo = $valor");
}

$pdo = Database::conexion();
$base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if (!str_ends_with($base, '_test')) {
    fwrite(STDERR, "Base '$base': este test inserta datos y solo corre sobre una base *_test.\n");
    exit(1);
}
if (!$pdo->query("SHOW COLUMNS FROM envio LIKE 'asignado_en'")->fetch()) {
    fwrite(STDERR, "Falta aplicar database/schema_reportes_operativos.sql sobre '$base'.\n");
    exit(1);
}

echo "== Reportes operativos — base $base ==\n";
$cargarSeed = function () use ($pdo) {
    $pdo->exec((string) file_get_contents(__DIR__ . '/fixtures/reportes_operativos_seed.sql'));
    $pdo->prepare('UPDATE usuario SET password_hash = ? WHERE id IN (9110, 9111)')
        ->execute([password_hash('cajero-test-1234', PASSWORD_DEFAULT)]);
};
$cargarSeed();

$s = new ReporteOperativoService();
$dia = ['desde' => '2026-10-20', 'hasta' => '2026-10-20'];
$soloDia = $dia + ['incluir_activos' => '0'];

// ---------- 1) Medios de pago ----------
check('pagos: totales por medio solo con lo acreditado', function () use ($s, $dia) {
    $d = $s->generar('pagos', $dia);
    igual(53800, kpi($d, 'total'), 'total ingresado');
    igual(10, kpi($d, 'operaciones'), 'operaciones (venta dividida = 1)');
    igual(19300, kpi($d, 'medio_efectivo'), 'efectivo');
    igual(16500, kpi($d, 'medio_transferencia'), 'transferencia');
    igual(18000, kpi($d, 'medio_mercadopago'), 'mercado pago');
    igual(15500, kpi($d, 'no_contabilizado'), 'no contabilizado (anulada, pendiente, cancelado, en revisión)');
    $ef = fila($d['tablas'][1], 'medio_k', 'efectivo');
    igual(5, $ef['operaciones'], 'operaciones en efectivo'); igual(35.9, $ef['pct'], '% efectivo');
    igual(2, fila($d['tablas'][1], 'medio_k', 'mercadopago')['operaciones'], 'operaciones MP');
});
check('pagos: filtros de canal, medio y estado', function () use ($s, $dia) {
    igual(18800, kpi($s->generar('pagos', $dia + ['canal' => 'local']), 'total'), 'solo local');
    igual(18000, kpi($s->generar('pagos', $dia + ['medio' => 'mercadopago']), 'total'), 'solo MP');
    $d = $s->generar('pagos', $dia + ['estado' => 'pendiente']);
    igual(0, kpi($d, 'total'), 'pendientes no suman'); igual(1, count($d['tablas'][0]['filas']), 'filas pendientes');
    igual(11800, kpi($s->generar('pagos', $dia + ['vendedor_id' => '9110']), 'total'), 'vendedor 9110');
});

// ---------- 2) Cajas y anulaciones ----------
check('cajas: arqueo (faltante, sobrante, abierta) y anulaciones', function () use ($s, $dia) {
    $d = $s->generar('cajas', $dia);
    igual(1, kpi($d, 'anuladas'), 'anuladas'); igual(2000, kpi($d, 'importe_anulado'), 'importe anulado');
    igual(1, kpi($d, 'abiertas'), 'abiertas'); igual(2, kpi($d, 'cerradas'), 'cerradas');
    igual(13500, kpi($d, 'esperado'), 'esperado'); igual(13200, kpi($d, 'declarado'), 'declarado');
    igual(-300, kpi($d, 'diferencia'), 'diferencia neta');
    igual(1, kpi($d, 'faltantes'), 'faltantes'); igual(1, kpi($d, 'sobrantes'), 'sobrantes'); igual(0, kpi($d, 'coinciden'), 'coinciden');
    $c1 = fila($d['tablas'][0], 'id', 9101);
    igual(9500, $c1['ventas_efectivo'], 'efectivo caja 9101 (sin la anulada)'); igual(-500, $c1['diferencia'], 'dif 9101');
    igual('faltante', $c1['estado_k'], 'estado 9101');
    igual('sobrante', fila($d['tablas'][0], 'id', 9102)['estado_k'], 'estado 9102');
    $c3 = fila($d['tablas'][0], 'id', 9103);
    igual(1000, $c3['esperado'], 'esperado en vivo de la caja abierta'); igual(null, $c3['diferencia'], 'abierta sin diferencia');
    $a = fila($d['tablas'][1], 'numero', 'T-V9103');
    igual('Error de carga', $a['motivo'], 'motivo'); igual('Cajera Dos', $a['anulado_por'], 'anulada por');
});
check('cajas: filtro por cajero y por caja', function () use ($s, $dia) {
    $d = $s->generar('cajas', $dia + ['usuario_id' => '9111']);
    igual(1, count($d['tablas'][0]['filas']), 'cajas de 9111'); igual(0, kpi($d, 'anuladas'), 'anuladas de 9111');
    igual(1, count($s->generar('cajas', $dia + ['caja_id' => '9103'])['tablas'][0]['filas']), 'caja 9103');
});

// ---------- 3) Vendedores ----------
check('vendedores: ventas, ticket sin anuladas y % de anulaciones', function () use ($s, $dia) {
    $d = $s->generar('vendedores', $dia);
    igual(5, kpi($d, 'ventas'), 'ventas válidas'); igual(18800, kpi($d, 'total'), 'total vendido');
    igual(3760, kpi($d, 'ticket'), 'ticket global'); igual(1, kpi($d, 'anuladas'), 'anuladas');
    igual(16.7, kpi($d, 'pct_anul'), '% anulaciones global');
    $v = $d['tablas'][0]['filas'];
    igual(9110, $v[0]['id'], 'primero en el ranking');
    igual(3, $v[0]['ventas'], 'ventas 9110'); igual(11800, $v[0]['total'], 'total 9110');
    igual(3933.33, $v[0]['ticket'], 'ticket 9110'); igual(25.0, $v[0]['pct_anul'], '% 9110');
    igual(3500, $v[1]['ticket'], 'ticket 9111');
});
check('vendedores: detalle de un vendedor', function () use ($s, $dia) {
    $d = $s->generar('vendedores', $dia + ['vendedor_id' => '9111']);
    igual(1, count($d['tablas'][0]['filas']), 'ranking filtrado'); igual(2, count($d['tablas'][1]['filas']), 'operaciones');
    igual('Efectivo', fila($d['tablas'][1], 'numero', 'T-V9104')['medios'], 'medios');
});

// ---------- 4) Embudo de la tienda ----------
check('pedidos: una etapa por pedido con su importe (productos + envío)', function () use ($s, $soloDia) {
    $d = $s->generar('pedidos', $soloDia);
    $esp = ['pendiente_pago' => 2, 'por_preparar' => 1, 'en_preparacion' => 1, 'listo_retirar' => 1,
            'enviado' => 1, 'entregado' => 1, 'cancelado' => 1];
    foreach ($esp as $k => $n) igual($n, kpi($d, $k), "etapa $k");
    igual(8, count($d['tablas'][0]['filas']), 'pedidos');
    igual(12000, fila($d['tablas'][0], 'numero', 'T-P9101')['importe'], 'importe con envío');
    igual(6, kpi($d, 'sin_actualizar'), 'activos sin actualizar hace +24 h');
    igual(1, kpi($d, 'cancelados_periodo'), 'cancelados');
    igual('pendiente_pago', $d['tablas'][0]['filas'][0]['etapa_k'], 'lo que requiere acción primero');
});
check('pedidos: filtros de etapa, pago y cliente', function () use ($s, $soloDia) {
    igual(2, count($s->generar('pedidos', $soloDia + ['etapa' => 'pendiente_pago'])['tablas'][0]['filas']), 'etapa');
    igual(1, count($s->generar('pedidos', $soloDia + ['estado_pago' => 'en_revision'])['tablas'][0]['filas']), 'pago');
    igual(4, count($s->generar('pedidos', $soloDia + ['cliente' => 'Diego'])['tablas'][0]['filas']), 'cliente');
});
check('pedidos: incluye activos de días anteriores si se pide', function () use ($s, $dia) {
    $nums = array_column($s->generar('pedidos', $dia)['tablas'][0]['filas'], 'numero');
    if (!in_array('T-P9002', $nums, true)) throw new \Exception('falta el pedido pendiente del 12/10 (seed de localidades)');
});

// ---------- 5) Reclamos ----------
check('reclamos: estados, sin responder y vencidos', function () use ($s, $soloDia) {
    $d = $s->generar('reclamos', $soloDia);
    igual(5, kpi($d, 'total'), 'total'); igual(1, kpi($d, 'abiertos'), 'abiertos');
    igual(2, kpi($d, 'sin_responder'), 'sin responder'); igual(2, kpi($d, 'en_revision'), 'en revisión');
    igual(1, kpi($d, 'resueltos'), 'resueltos'); igual(1, kpi($d, 'rechazados'), 'rechazados');
    igual(5, kpi($d, 'recibidos'), 'recibidos'); igual(3, kpi($d, 'vencidos'), 'abiertos hace +48 h');
    igual('Sin responder', fila($d['tablas'][0], 'numero', 'R-9103')['respuesta'], 'R-9103 (último mensaje del cliente)');
    igual('Respondido', fila($d['tablas'][0], 'numero', 'R-9102')['respuesta'], 'R-9102');
});
check('reclamos: filtros de estado, motivo y cliente', function () use ($s, $soloDia) {
    igual(2, count($s->generar('reclamos', $soloDia + ['estado' => 'sin_responder'])['tablas'][0]['filas']), 'sin responder');
    igual(2, count($s->generar('reclamos', $soloDia + ['motivo' => 'demora'])['tablas'][0]['filas']), 'motivo');
    igual(2, count($s->generar('reclamos', $soloDia + ['cliente' => 'Diego'])['tablas'][0]['filas']), 'cliente');
});

// ---------- 6) Envíos sin asignar ----------
check('envíos: sin asignar, esperas y estados', function () use ($s, $soloDia) {
    $d = $s->generar('envios', $soloDia);
    igual(6, count($d['tablas'][0]['filas']), 'envíos de moto (sin retiros)');
    igual(2, kpi($d, 'sin_asignar'), 'sin asignar'); igual(2, kpi($d, 'sin_asignar_periodo'), 'del período');
    igual(38, kpi($d, 'asignacion_prom'), 'promedio de asignación (45 y 30 min)');
    igual(3, kpi($d, 'estado_pendiente'), 'pendientes'); igual(1, kpi($d, 'estado_cancelado'), 'cancelado por venta anulada');
    igual(45, fila($d['tablas'][0], 'id', 9106)['espera'], 'espera hasta asignarse');
    igual(null, fila($d['tablas'][0], 'id', 9107)['espera'], 'asignado sin hora registrada');
    igual(9101, $d['tablas'][0]['filas'][0]['id'], 'el que más espera, primero');
});
check('envíos: filtros de barrio, repartidor y espera mínima', function () use ($s, $soloDia) {
    igual(3, count($s->generar('envios', $soloDia + ['barrio_id' => '9130'])['tablas'][0]['filas']), 'barrio');
    igual(3, count($s->generar('envios', $soloDia + ['repartidor_id' => 'sin'])['tablas'][0]['filas']), 'sin repartidor');
    igual(3, count($s->generar('envios', $soloDia + ['espera_min' => '40'])['tablas'][0]['filas']), 'espera ≥ 40 min (2 esperando + 1 de 45)');
});

// ---------- 7) Carga por barrio ----------
check('carga: grupos por barrio sin duplicar', function () use ($s, $dia) {
    $d = $s->generar('carga', $dia);
    igual(6, kpi($d, 'total'), 'total');
    foreach (['pendiente' => 2, 'asignado' => 1, 'en_reparto' => 1, 'entregado' => 1, 'cancelado' => 1] as $k => $n) igual($n, kpi($d, $k), $k);
    $n = fila($d['tablas'][0], 'id', 9130);
    igual([3, 1, 1, 1, 0, 0], [$n['total'], $n['pendiente'], $n['asignado'], $n['en_reparto'], $n['entregado'], $n['cancelado']], 'Barrio Norte');
    igual(6, count($d['tablas'][1]['filas']), 'detalle');
    igual(3, kpi($s->generar('carga', $dia + ['repartidor_id' => '9140']), 'total'), 'filtro repartidor');
});

// ---------- Validación, vacíos y asignación ----------
check('filtros inválidos → ValidacionException', function () use ($s) {
    foreach ([['pagos', ['desde' => '2026-13-45']], ['pagos', ['desde' => '2026-10-21', 'hasta' => '2026-10-20']],
              ['pagos', ['desde' => '2024-01-01', 'hasta' => '2026-01-01']], ['pagos', ['canal' => "x' OR 1=1"]],
              ['envios', ['barrio_id' => '-1']], ['otro', []]] as [$r, $q]) {
        try { $s->generar($r, $q); throw new \Exception("aceptó $r " . json_encode($q)); }
        catch (ValidacionException) {}
    }
});
check('día sin registros: todo en cero y tablas vacías', function () use ($s) {
    $vacio = ['desde' => '2020-01-01', 'hasta' => '2020-01-01', 'incluir_activos' => '0'];
    foreach (array_keys(ReporteOperativoService::REPORTES) as $r) {
        $d = $s->generar($r, $vacio);
        foreach ($d['tablas'] as $t) {
            // El resumen por medio siempre lista Efectivo, Transferencia y Mercado Pago (en cero).
            if ($t['id'] === 'resumen') { igual(0, array_sum(array_column($t['filas'], 'total')), 'resumen en cero'); continue; }
            igual(0, $t['total'], "$r / {$t['id']}");
        }
    }
    igual(0, kpi($s->generar('pagos', $vacio), 'total'), 'pagos');
    igual(null, kpi($s->generar('vendedores', $vacio), 'ticket'), 'ticket sin ventas');
});
check('derivar registra la hora de la PRIMERA asignación', function () use ($pdo) {
    $repo = new EnvioRepository();
    $leer = fn() => $pdo->query('SELECT asignado_en FROM envio WHERE id = 9101')->fetchColumn();
    $repo->derivar(9101, 9140);
    $t1 = $leer(); if (!$t1) throw new \Exception('no guardó asignado_en');
    $pdo->exec("UPDATE envio SET asignado_en = '2026-10-20 09:20:00' WHERE id = 9101");
    $repo->derivar(9101, 9140);   // reasignar conserva la primera hora
    igual('2026-10-20 09:20:00', $leer(), 'reasignación');
    $repo->derivar(9101, null);
    igual(null, $leer() ?: null, 'desasignado');
});
$cargarSeed();   // deja los datos como los describe el seed

// ---------- HTTP (opcional) ----------
$url = getenv('BRITECH_TEST_URL') ?: null;
if ($url !== null) {
    echo "-- HTTP contra $url --\n";
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
    $login = function (string $email, string $pass) use ($http): ?string {
        $c = null;
        [$code] = $http('POST', '/api/login', ['email' => $email, 'password' => $pass], $c);
        return $code === 200 ? $c : null;
    };
    $qs = '&desde=2026-10-20&hasta=2026-10-20';
    $rutas = ['opciones', '?reporte=pagos' . $qs, 'csv?reporte=cajas' . $qs, 'pdf?reporte=carga' . $qs];
    $ruta = fn($r) => '/api/admin/reportes/operativo' . (str_starts_with($r, '?') ? $r : '/' . $r);

    check('sin sesión → 401', function () use ($http, $rutas, $ruta) {
        foreach ($rutas as $r) { $c = null; [$code] = $http('GET', $ruta($r), null, $c); igual(401, $code, $r); }
    });
    $cAdmin = $login('admin@britech.local', 'admin1234');
    $cVend  = $login('cajero.uno@britech.test', 'cajero-test-1234');
    check('vendedor → 403 en todas las rutas', function () use ($http, $rutas, $ruta, $cVend) {
        if (!$cVend) throw new \Exception('no pudo loguear al vendedor');
        foreach ($rutas as $r) { $c = $cVend; [$code] = $http('GET', $ruta($r), null, $c); igual(403, $code, $r); }
    });
    check('admin: los 7 reportes responden 200 con los mismos totales', function () use ($http, $cAdmin, $qs) {
        if (!$cAdmin) throw new \Exception('no pudo loguear al admin');
        foreach (array_keys(ReporteOperativoService::REPORTES) as $r) {
            $c = $cAdmin; [$code, $body] = $http('GET', "/api/admin/reportes/operativo?reporte=$r$qs", null, $c);
            igual(200, $code, $r);
            $j = json_decode($body, true);
            if ($r === 'pagos') igual(53800, kpi($j, 'total'), 'total pagos');
        }
    });
    check('admin: filtro inválido → 422', function () use ($http, $cAdmin) {
        $c = $cAdmin; [$code] = $http('GET', '/api/admin/reportes/operativo?reporte=pagos&desde=2026-02-31', null, $c); igual(422, $code, 'código');
    });
    check('CSV: BOM, separador ; y todas las filas', function () use ($http, $cAdmin, $qs) {
        $c = $cAdmin; [$code, $body, $tipo] = $http('GET', "/api/admin/reportes/operativo/csv?reporte=cajas&tabla=0$qs", null, $c);
        igual(200, $code, 'código');
        if (!str_starts_with($tipo, 'text/csv')) throw new \Exception("tipo $tipo");
        if (!str_starts_with($body, "\xEF\xBB\xBF")) throw new \Exception('sin BOM');
        if (!str_contains($body, 'Caja;Cajero;Apertura')) throw new \Exception('encabezado');
        if (!str_contains($body, '-500,00')) throw new \Exception('falta la diferencia de la caja 9101');
    });
    check('PDF: se genera para los 7 reportes', function () use ($http, $cAdmin, $qs) {
        foreach (array_keys(ReporteOperativoService::REPORTES) as $r) {
            $c = $cAdmin; [$code, $body, $tipo] = $http('GET', "/api/admin/reportes/operativo/pdf?reporte=$r$qs", null, $c);
            igual(200, $code, $r);
            if (!str_starts_with($tipo, 'application/pdf') || !str_starts_with($body, '%PDF')) throw new \Exception("$r no es un PDF");
        }
    });
}

echo "\n$total pruebas, " . ($total - $fallos) . " OK, $fallos con fallas.\n";
exit($fallos ? 1 : 0);

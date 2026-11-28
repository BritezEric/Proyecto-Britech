<?php

/**
 * Servidor WebSocket de Britech (Workerman, PHP puro). Proceso aparte del servidor web.
 *
 *   php bin/websocket.php start        (o doble clic en INICIAR-WEBSOCKET.bat)
 *   Ctrl+C para detenerlo. En Linux además: start -d (en segundo plano), stop, status.
 *
 * Dos puertos en el mismo proceso:
 *  - Público  ws://WS_HOST:WS_PORT (127.0.0.1:8090): navegadores. Cada conexión presenta un ticket
 *    firmado por el backend (?ticket=…); sin ticket válido se rechaza con 401.
 *  - Interno  tcp://127.0.0.1:WS_PORT_INTERNO (8091): solo la propia máquina. El backend PHP
 *    publica eventos acá (JSON por línea, con WS_SECRET). Nadie de afuera puede publicar.
 *    (En Windows Workerman admite un solo Worker por archivo; el puerto interno se abre
 *    dentro del mismo proceso con listen(), como indica su documentación.)
 *
 * Grupos: cada conexión entra a su grupo personal (u:5 / c:12 / r:3) y, según el rol,
 * a "staff" y/o "admin". El backend decide a qué grupos va cada evento; el navegador
 * NO puede elegir grupos, así nadie recibe eventos que no le corresponden.
 *
 * Del navegador solo se acepta {"tipo":"ping"} (latido); todo lo que modifica datos
 * sigue pasando por la API HTTP, que valida permisos.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/config/config.php';
date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'America/Argentina/Buenos_Aires');

use App\Services\TiempoReal;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Timer;
use Workerman\Worker;

$cfg = TiempoReal::config();
if (!TiempoReal::activo()) {
    fwrite(STDERR, "Falta WS_SECRET en el .env: el servidor WebSocket no arranca (ver .env.example).\n");
    exit(1);
}
$host = (string) ($cfg['host'] ?? '127.0.0.1');
$puerto = (int) ($cfg['puerto'] ?? 8090);
$puertoInterno = (int) ($cfg['puerto_interno'] ?? 8091);
$origenes = array_filter(array_map('trim', explode(',', (string) ($cfg['origenes'] ?? ''))));

const LATIDO_MAX = 70;          // s sin noticias del navegador → se cierra la conexión
const MENSAJES_POR_MINUTO = 60; // tope de mensajes del navegador (anti-abuso)
const MAX_CONEXIONES_POR_SUJETO = 10;

/** Estado en memoria: conexiones y grupos. */
$conexiones = [];   // id → ['con' => TcpConnection, 'sub', 'rol', 'grupos', 'desde', 'ultimo', 'msgs', 'ventana', 'ip']
$grupos = [];       // grupo → [id => true]

function registrar(string $txt): void
{
    echo '[' . date('H:i:s') . "] $txt\n";
}

function quitar(int $id): void
{
    global $conexiones, $grupos;
    if (!isset($conexiones[$id])) return;
    foreach ($conexiones[$id]['grupos'] as $g) {
        unset($grupos[$g][$id]);
        if (empty($grupos[$g])) unset($grupos[$g]);
    }
    unset($conexiones[$id]);
}

$ws = new Worker("websocket://$host:$puerto");
$ws->name = 'britech-websocket';
$ws->count = 1;   // un proceso: las conexiones y los grupos viven en memoria

// Handshake: validar origen y ticket ANTES de aceptar la conexión.
$ws->onWebSocketConnect = function (TcpConnection $con, Request $req) use ($origenes) {
    global $conexiones, $grupos;
    try {
        $origen = (string) $req->header('origin', '');
        if ($origenes && !in_array($origen, $origenes, true)) {
            registrar("rechazada (origen no permitido: $origen)");
            $con->close("HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n", true);
            return;
        }
        $t = TiempoReal::validarTicket((string) $req->get('ticket', ''));
        if ($t === null) {
            registrar('rechazada (ticket inválido o vencido) desde ' . $con->getRemoteIp());
            $con->close("HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\n\r\n", true);
            return;
        }
        // Tope de conexiones por persona (muchas pestañas o un script que abre sin parar).
        $suyas = count($grupos[$t['sub']] ?? []);
        if ($suyas >= MAX_CONEXIONES_POR_SUJETO) {
            $con->close("HTTP/1.1 429 Too Many Requests\r\nContent-Length: 0\r\n\r\n", true);
            return;
        }
        $mios = [$t['sub']];
        if ($t['sub'][0] === 'u') $mios[] = 'staff';
        if ($t['sub'][0] === 'u' && $t['rol'] === 'admin') $mios[] = 'admin';
        $conexiones[$con->id] = [
            'con' => $con, 'sub' => $t['sub'], 'rol' => $t['rol'], 'grupos' => $mios,
            'desde' => time(), 'ultimo' => time(), 'msgs' => 0, 'ventana' => time(), 'ip' => $con->getRemoteIp(),
        ];
        foreach ($mios as $g) $grupos[$g][$con->id] = true;
        registrar("conectado {$t['sub']} ({$t['rol']}) · " . count($conexiones) . ' conexión(es)');
    } catch (\Throwable $e) {
        // Un error acá no puede tumbar el servidor (Workerman lo detendría).
        registrar('error en el handshake: ' . $e->getMessage());
        $con->close("HTTP/1.1 500 Internal Server Error\r\nContent-Length: 0\r\n\r\n", true);
    }
};

$ws->onWebSocketConnected = function (TcpConnection $con) {
    global $conexiones;
    if (isset($conexiones[$con->id])) {
        $c = $conexiones[$con->id];
        $con->send(json_encode(['evento' => 'bienvenida', 'datos' => ['sujeto' => $c['sub'], 'rol' => $c['rol']]]));
    }
};

// Mensajes del navegador: solo el latido. Cualquier otra cosa se rechaza.
$ws->onMessage = function (TcpConnection $con, $data) {
    global $conexiones;
    try {
        if (!isset($conexiones[$con->id])) { $con->close(); return; }
        $c = &$conexiones[$con->id];
        $c['ultimo'] = time();
        if (time() - $c['ventana'] >= 60) { $c['ventana'] = time(); $c['msgs'] = 0; }
        if (++$c['msgs'] > MENSAJES_POR_MINUTO) {
            registrar("cerrada por exceso de mensajes: {$c['sub']}");
            $con->close(json_encode(['evento' => 'error', 'datos' => ['mensaje' => 'Demasiados mensajes.']]));
            return;
        }
        $m = is_string($data) && strlen($data) <= 2048 ? json_decode($data, true) : null;
        if (($m['tipo'] ?? '') === 'ping') {
            $con->send(json_encode(['evento' => 'pong', 'datos' => ['t' => time()]]));
            return;
        }
        $con->send(json_encode(['evento' => 'error', 'datos' => ['mensaje' => 'Mensaje no permitido.']]));
    } catch (\Throwable $e) {
        registrar('error en un mensaje: ' . $e->getMessage());
    }
};

$ws->onClose = function (TcpConnection $con) {
    global $conexiones;
    if (isset($conexiones[$con->id])) {
        $sub = $conexiones[$con->id]['sub'];
        quitar($con->id);
        registrar("desconectado $sub · " . count($conexiones) . ' conexión(es)');
    }
};

$ws->onWorkerStart = function () use ($puertoInterno, $puerto, $host) {
    // Latido: se cierran las conexiones que dejaron de responder (pestaña colgada, red caída).
    Timer::add(30, function () {
        global $conexiones;
        foreach ($conexiones as $id => $c) {
            if (time() - $c['ultimo'] > LATIDO_MAX) {
                registrar("cerrada por inactividad: {$c['sub']}");
                $c['con']->close();
                quitar($id);
            }
        }
    });

    // Puerto interno: el backend publica eventos. Escucha SOLO en 127.0.0.1.
    $interno = new Worker("text://127.0.0.1:$puertoInterno");
    $interno->onMessage = function (TcpConnection $con, $linea) {
        global $conexiones, $grupos;
        try {
            $m = json_decode((string) $linea, true);
            if (!is_array($m) || !hash_equals((string) TiempoReal::config()['secret'], (string) ($m['secreto'] ?? ''))) {
                $con->close();
                return;
            }
            if (($m['cmd'] ?? '') === 'estado') {
                $lista = [];
                foreach ($conexiones as $c) {
                    $lista[] = ['sujeto' => $c['sub'], 'rol' => $c['rol'], 'desde' => date('Y-m-d H:i:s', $c['desde']), 'ip' => $c['ip']];
                }
                $con->send(json_encode(['conexiones' => count($conexiones), 'lista' => $lista]));
                return;
            }
            $evento = (string) ($m['evento'] ?? '');
            if (!preg_match('/^[a-z_]{1,40}$/', $evento) || !is_array($m['para'] ?? null)) return;
            // Unión de los grupos destino, sin repetir conexiones (una persona = un aviso por pestaña).
            $destino = [];
            foreach ($m['para'] as $g) foreach (array_keys($grupos[(string) $g] ?? []) as $id) $destino[$id] = true;
            $json = json_encode(['evento' => $evento, 'datos' => $m['datos'] ?? []], JSON_UNESCAPED_UNICODE);
            foreach (array_keys($destino) as $id) $conexiones[$id]['con']->send($json);
            registrar("evento $evento → " . implode(',', $m['para']) . ' (' . count($destino) . ' conexión/es)');
        } catch (\Throwable $e) {
            registrar('error en un evento interno: ' . $e->getMessage());
        }
    };
    $interno->listen();
    registrar("Britech WebSocket escuchando en ws://$host:$puerto (interno 127.0.0.1:$puertoInterno)");
};

Worker::runAll();

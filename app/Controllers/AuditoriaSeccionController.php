<?php

namespace App\Controllers;

use App\Core\Paginacion;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidacionException;
use App\Core\Xlsx;
use App\Repositories\AlertaRepository;
use App\Repositories\AuditoriaRepository;
use App\Repositories\AuditoriaSeccionRepository as Sec;
use App\Services\AlertaService;
use App\Services\Auditoria;
use Dompdf\Dompdf;

/**
 * Centro de control de Auditoría (solo admin): tarjetas del día, secciones
 * (alertas, ventas, cajas, stock, usuarios), detalle de cada registro,
 * revisión de alertas, verificación de integridad y exportaciones.
 *
 * Todo es lectura, salvo marcar una alerta como revisada (que la base además
 * limita: no se puede deshacer ni tocar otro dato de la alerta).
 * Los permisos se validan acá, en el servidor, en cada pedido.
 */
class AuditoriaSeccionController
{
    private const SECCIONES = [
        'alertas' => 'Alertas y actividades sospechosas', 'ventas' => 'Ventas, anulaciones y pedidos',
        'cajas' => 'Cajas y movimientos de dinero', 'stock' => 'Movimientos de stock', 'usuarios' => 'Usuarios y accesos',
    ];
    private const RESULTADOS = ['sin_diferencia' => 'Sin diferencia', 'faltante' => 'Faltante', 'sobrante' => 'Sobrante', 'abierta' => 'Abierta'];
    private const MAX_PDF = 1000;

    public function kpis(): void
    {
        if (!$this->soloAdmin()) return;
        Response::json(['ok' => true] + (new Sec())->kpis());
    }

    /** Listado paginado de una sección con sus filtros combinados. */
    public function seccion(): void
    {
        if (!$this->soloAdmin()) return;
        $f = $this->filtros();
        if ($f === null) return;
        [$page, $perPage, $offset] = Paginacion::desde(20);
        $r = $this->datos($f, $perPage, $offset);
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage) + ['opciones' => $this->opciones($f['s'])]);
    }

    /** Detalle completo de un registro (se abre en el panel lateral). */
    public function detalle(): void
    {
        if (!$this->soloAdmin()) return;
        $tipo = (string) Request::query('tipo', '');
        $id = (int) Request::query('id', 0);
        $repo = new Sec();
        $d = match ($tipo) {
            'venta'    => $repo->detalleVenta($id),
            'pedido'   => $repo->detallePedido($id),
            'caja'     => $repo->detalleCaja($id),
            'producto' => $repo->detalleProducto($id),
            'registro' => $repo->registro($id),
            'alerta'   => (new AlertaRepository())->buscar($id),
            default    => false,
        };
        if ($d === false) { Response::json(['ok' => false, 'error' => 'Tipo de detalle inválido.'], 422); return; }
        if ($d === null)  { Response::json(['ok' => false, 'error' => 'No se encontró el registro.'], 404); return; }
        if ($tipo === 'alerta' && $d['auditoria_id']) $d['origen'] = $repo->registro((int) $d['auditoria_id']);
        Response::json(['ok' => true, 'tipo' => $tipo, 'datos' => $d]);
    }

    /** Marca una alerta como revisada (queda quién y cuándo; no se puede deshacer ni borrar). */
    public function revisar(): void
    {
        if (!$this->soloAdmin()) return;
        $d = Request::json();
        $id = (int) ($d['id'] ?? 0);
        $nota = trim((string) ($d['nota'] ?? ''));
        if (mb_strlen($nota) > 255) { Response::json(['ok' => false, 'error' => 'La nota admite hasta 255 caracteres.'], 422); return; }
        $repo = new AlertaRepository();
        $alerta = $repo->buscar($id);
        if ($alerta === null) { Response::json(['ok' => false, 'error' => 'La alerta no existe.'], 404); return; }
        if (!$repo->revisar($id, (int) Session::usuarioId(), $nota !== '' ? $nota : null)) {
            Response::json(['ok' => false, 'error' => 'La alerta ya estaba revisada.'], 409); return;
        }
        Auditoria::evento('alerta', $id, 'revisar', mb_substr("{$alerta['titulo']}" . ($nota !== '' ? " · Nota: $nota" : ''), 0, 255));
        Response::json(['ok' => true]);
    }

    /** Verifica la cadena de hashes del historial. */
    public function integridad(): void
    {
        if (!$this->soloAdmin()) return;
        $r = (new AuditoriaRepository())->verificarIntegridad();
        Auditoria::evento('auditoria', null, 'verificar', $r['disponible']
            ? ($r['ok'] ? "Integridad OK · {$r['verificadas']} registros" : 'Integridad con problemas · alterados: '
                . implode(',', $r['alteradas']) . ' · enlaces rotos: ' . implode(',', $r['enlaces_rotos']))
            : 'Integridad no disponible (falta aplicar schema_auditoria.sql)');
        Response::json(['ok' => true] + $r);
    }

    public function excel(): void { $this->exportar('excel'); }
    public function pdf(): void   { $this->exportar('pdf'); }

    // ---------- internos ----------

    private function datos(array $f, int $limit, int $offset): array
    {
        $repo = new Sec();
        return match ($f['s']) {
            'alertas'  => (new AlertaRepository())->listar($f, $limit, $offset),
            'ventas'   => $repo->ventas($f, $limit, $offset),
            'cajas'    => $repo->cajas($f, $limit, $offset),
            'stock'    => $repo->stock($f, $limit, $offset),
            'usuarios' => $repo->usuarios($f, $limit, $offset),
        };
    }

    private function opciones(string $s): array
    {
        $o = ['usuarios' => (new Sec())->staff()];
        if ($s === 'alertas') {
            $o['niveles'] = AlertaRepository::NIVELES;
            $o['tipos'] = array_intersect_key(AlertaService::TIPOS, array_flip((new AlertaRepository())->tipos())) ?: AlertaService::TIPOS;
        }
        if ($s === 'stock') $o['tipos'] = Sec::TIPOS_STOCK;
        if ($s === 'usuarios') $o['acciones'] = Sec::ACCIONES_USUARIOS;
        if ($s === 'cajas') $o['resultados'] = self::RESULTADOS;
        return $o;
    }

    /** Filtros de todas las secciones, validados en el servidor (los que no aplican quedan vacíos). */
    private function filtros(): ?array
    {
        try {
            $t = fn(string $k, int $max = 80) => mb_substr(trim((string) Request::query($k, '')), 0, $max);
            $f = [
                's' => $t('s', 20), 'q' => $t('q', 100), 'usuario_id' => $t('usuario_id', 10), 'tipo' => $t('tipo', 30),
                'estado' => $t('estado', 20), 'nivel' => $t('nivel', 20), 'numero' => $t('numero', 30), 'cliente' => $t('cliente'),
                'producto' => $t('producto'), 'producto_id' => $t('producto_id', 10), 'caja_id' => $t('caja_id', 10),
                'resultado' => $t('resultado', 20), 'revisar' => $t('revisar', 10), 'accion' => $t('accion', 30),
                'desde' => $t('desde', 10), 'hasta' => $t('hasta', 10),
            ];
            if (!isset(self::SECCIONES[$f['s']])) throw new ValidacionException('Sección inválida.');
            foreach (['usuario_id', 'producto_id', 'caja_id'] as $k) {
                if ($f[$k] !== '' && !ctype_digit($f[$k])) throw new ValidacionException('Filtro numérico inválido.');
            }
            foreach (['tipo', 'estado', 'accion'] as $k) {
                if ($f[$k] !== '' && !preg_match('/^[a-z_]{1,30}$/', $f[$k])) throw new ValidacionException('Filtro inválido.');
            }
            if ($f['nivel'] !== '' && !isset(AlertaRepository::NIVELES[$f['nivel']])) throw new ValidacionException('Nivel inválido.');
            if ($f['resultado'] !== '' && !isset(self::RESULTADOS[$f['resultado']])) throw new ValidacionException('Resultado inválido.');
            if (!in_array($f['revisar'], ['', 'manual', 'inusual'], true)) throw new ValidacionException('Filtro inválido.');
            if ($f['numero'] !== '' && !preg_match('/^[A-Za-z0-9\-]+$/', $f['numero'])) throw new ValidacionException('Número inválido.');
            foreach (['desde', 'hasta'] as $k) {
                if ($f[$k] === '') continue;
                $d = \DateTime::createFromFormat('!Y-m-d', $f[$k]);
                if (!$d || $d->format('Y-m-d') !== $f[$k]) throw new ValidacionException('La fecha tiene que ser AAAA-MM-DD.');
            }
            if ($f['desde'] !== '' && $f['hasta'] !== '' && $f['desde'] > $f['hasta']) {
                throw new ValidacionException('La fecha "desde" no puede ser posterior a "hasta".');
            }
            return $f;
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            return null;
        }
    }

    private function soloAdmin(): bool
    {
        if (Session::esAdmin()) return true;
        Response::json(['ok' => false, 'error' => 'Solo el administrador puede ver la auditoría.'], 403);
        return false;
    }

    // ---------- exportación (mismos filtros que la pantalla) ----------

    private function exportar(string $formato): void
    {
        if (!$this->soloAdmin()) return;
        $f = $this->filtros();
        if ($f === null) return;
        try {
            [$cols, $anchos, $filas] = $this->tabla($f);
            $titulo = self::SECCIONES[$f['s']];
            $filtrosTxt = $this->filtrosTexto($f);
            $nombre = 'auditoria-' . $f['s'] . '-' . date('Ymd-His');
            Auditoria::evento('auditoria', null, 'exportar',
                ($formato === 'pdf' ? 'PDF' : 'Excel') . " · $titulo · " . count($filas) . " fila(s) · $filtrosTxt");
            if ($formato === 'excel') {
                $xlsx = Xlsx::generar(mb_substr($titulo, 0, 31), [
                    "Britech · Auditoría · $titulo",
                    'Generado: ' . date('d/m/Y H:i') . ' por ' . (Session::usuario()['nombre'] ?? 'admin'),
                    "Filtros: $filtrosTxt",
                    count($filas) . ' fila(s)' . (count($filas) >= Sec::MAX_EXPORT ? ' (tope de exportación alcanzado: acotá las fechas)' : ''),
                ], $cols, $filas, $anchos);
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header("Content-Disposition: attachment; filename=\"$nombre.xlsx\"");
                header('Content-Length: ' . strlen($xlsx));
                header('Cache-Control: no-store');
                echo $xlsx;
                return;
            }
            $dompdf = new Dompdf();
            $dompdf->loadHtml($this->htmlPdf($titulo, $filtrosTxt, $cols, $filas), 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
        } catch (\Throwable $e) {
            error_log('[Auditoria] exportar: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'No se pudo generar el archivo.'], 500);
            return;
        }
        header('Cache-Control: no-store');
        $dompdf->stream("$nombre.pdf", ['Attachment' => true]);
    }

    /** Columnas, anchos y filas (texto) de cada sección para exportar. */
    private function tabla(array $f): array
    {
        $m = fn($n) => $n === null ? '' : '$ ' . number_format((float) $n, 2, ',', '.');
        $fe = fn($s) => $s ? date('d/m/Y H:i', strtotime($s)) : '';
        $rows = $this->datos($f, Sec::MAX_EXPORT, 0)['rows'];
        switch ($f['s']) {
            case 'alertas':
                return [['Fecha', 'Nivel', 'Tipo', 'Título', 'Descripción', 'Usuario relacionado', 'Estado', 'Revisó', 'Revisada', 'Nota'],
                    [16, 12, 24, 34, 60, 20, 11, 18, 16, 30],
                    array_map(fn($a) => [$fe($a['creado_en']), AlertaRepository::NIVELES[$a['nivel']], AlertaService::TIPOS[$a['tipo']] ?? $a['tipo'],
                        $a['titulo'], $a['descripcion'], $a['usuario'] ?? '—', $a['revisada'] ? 'Revisada' : 'Pendiente',
                        $a['revisor'] ?? '', $fe($a['revisada_en']), $a['nota_revision'] ?? ''], $rows)];
            case 'ventas':
                return [['Fecha', 'N°', 'Tipo', 'Vendedor', 'Cliente', 'Total', 'Método de pago', 'Estado', 'Pago', 'Motivo anulación', 'Anuló / canceló', 'Fecha anulación'],
                    [16, 11, 11, 18, 22, 13, 24, 11, 12, 30, 18, 16],
                    array_map(fn($v) => [$fe($v['fecha']), $v['numero'], $v['tipo'] === 'online' ? 'Online' : 'Presencial',
                        $v['usuario'] ?? 'Tienda online', $v['cliente'], $m($v['total']), $v['metodo'] ?? '', $v['estado'],
                        $v['estado_pago'] ?? '', $v['motivo'] ?? '', $v['anulo'] ?? '', $fe($v['anulada_en'])], $rows)];
            case 'cajas':
                // "Movimientos de caja": cada movimiento de las cajas filtradas, en orden cronológico.
                $out = [];
                $repo = new Sec();
                foreach ($rows as $c) {
                    foreach ($repo->movimientosCaja($c) as $e) {
                        $out[] = ['#' . $c['id'], $c['usuario'], $fe($e['fecha']), $e['tipo_txt'], $m($e['importe']), $e['metodo'],
                            $e['descripcion'], $e['usuario'] ?? '', $m($e['saldo_anterior']), $m($e['saldo_posterior']),
                            self::RESULTADOS[$c['resultado']] . ($e['posterior_al_cierre'] ? ' · posterior al cierre' : '')];
                        if (count($out) >= Sec::MAX_EXPORT) break 2;
                    }
                }
                return [['Caja', 'Cajero', 'Fecha', 'Movimiento', 'Importe', 'Método de pago', 'Descripción', 'Usuario', 'Saldo anterior (efectivo)', 'Saldo posterior (efectivo)', 'Resultado caja'],
                    [8, 18, 16, 16, 14, 26, 40, 18, 16, 16, 18], $out];
            case 'stock':
                return [['Fecha', 'Producto', 'SKU', 'Tipo de movimiento', 'Anterior', 'Cambio', 'Resultante', 'Motivo', 'Documento', 'Usuario', 'Revisión'],
                    [16, 30, 14, 22, 10, 10, 10, 34, 12, 18, 22],
                    array_map(fn($x) => [$fe($x['creado_en']), $x['producto'] ?? '#' . $x['producto_id'], $x['sku'] ?? '',
                        Sec::TIPOS_STOCK[$x['tipo']] ?? $x['tipo'], $x['valor_anterior'], (int) $x['valor_nuevo'] - (int) $x['valor_anterior'],
                        $x['valor_nuevo'], $x['detalle'], $x['documento']['numero'] ?? '', $x['usuario'] ?? 'Sistema',
                        trim(($x['tipo'] === 'ajuste' ? 'Manual sin operación' : '') . ($x['alerta'] ? ' · con alerta' : '')
                            . ($x['ajustes_mismo_dia'] > 1 ? ' · repetido' : ''), ' ·')], $rows)];
            case 'usuarios':
                return [['Fecha', 'Acción', 'Usuario afectado', 'Realizado por', 'Antes', 'Después', 'Detalle', 'IP', 'Dispositivo'],
                    [16, 22, 26, 22, 14, 14, 40, 15, 18],
                    array_map(fn($x) => [$fe($x['creado_en']), Sec::ACCIONES_USUARIOS[$x['clase']] ?? $x['clase'],
                        $x['afectado'] ? "{$x['afectado']} ({$x['afectado_email']})" : '—', $x['actor'] ?? 'Sin sesión',
                        $x['valor_anterior'] ?? '', $x['valor_nuevo'] ?? '', $x['detalle'] ?? '', $x['ip'] ?? '', $x['dispositivo']], $rows)];
        }
        return [[], [], []];
    }

    private function filtrosTexto(array $f): string
    {
        $et = ['q' => 'Búsqueda', 'usuario_id' => 'Usuario', 'tipo' => 'Tipo', 'estado' => 'Estado', 'nivel' => 'Nivel', 'numero' => 'N°',
               'cliente' => 'Cliente', 'producto' => 'Producto', 'producto_id' => 'Producto #', 'caja_id' => 'Caja', 'resultado' => 'Resultado',
               'revisar' => 'Revisión', 'accion' => 'Acción', 'desde' => 'Desde', 'hasta' => 'Hasta'];
        $nombres = $f['usuario_id'] !== '' ? array_column((new Sec())->staff(), 'nombre', 'id') : [];
        $t = [];
        foreach ($et as $k => $l) {
            if ($f[$k] === '') continue;
            $t[] = "$l: " . ($k === 'usuario_id' ? ($nombres[(int) $f[$k]] ?? '#' . $f[$k]) : $f[$k]);
        }
        return $t ? implode(' · ', $t) : 'sin filtros';
    }

    private function htmlPdf(string $titulo, string $filtros, array $cols, array $filas): string
    {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $logo = '';
        $ruta = dirname(__DIR__, 2) . '/public/assets/img/britech-logo.png';
        if (is_file($ruta)) $logo = "<img class='logo' src='data:image/png;base64," . base64_encode((string) file_get_contents($ruta)) . "'>";
        $th = implode('', array_map(fn($c) => '<th>' . $e($c) . '</th>', $cols));
        $tr = '';
        foreach (array_slice($filas, 0, self::MAX_PDF) as $f) $tr .= '<tr>' . implode('', array_map(fn($v) => '<td>' . $e($v) . '</td>', $f)) . '</tr>';
        $n = count($cols);
        if (!$filas) $tr = "<tr><td colspan='$n' class='mute'>No hay registros para los filtros elegidos.</td></tr>";
        if (count($filas) > self::MAX_PDF) {
            $tr .= "<tr><td colspan='$n' class='mute'>Se muestran " . self::MAX_PDF . ' de ' . count($filas) . ' filas. El detalle completo está en el Excel.</td></tr>';
        }
        return "<!doctype html><html><head><meta charset='utf-8'><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #1c1c1c; }
            .cab { width: 100%; border-bottom: 2px solid #5b3fb0; margin-bottom: 8px; }
            .logo { height: 26px; } h1 { font-size: 15px; margin: 0; } .sub { color: #666; font-size: 9px; }
            .filtros { color: #444; margin: 4px 0 8px; }
            table.d { width: 100%; border-collapse: collapse; }
            table.d th { background: #f0f0f0; text-align: left; padding: 4px; border-bottom: 1px solid #ccc; font-size: 7px; text-transform: uppercase; }
            table.d td { padding: 3px 4px; border-bottom: 1px solid #e6e6e6; vertical-align: top; }
            .mute { color: #888; text-align: center; padding: 10px; } .pie { margin-top: 8px; color: #888; font-size: 8px; }
        </style></head><body>
            <table class='cab'><tr><td>$logo</td><td style='text-align:right'><h1>Auditoría · " . $e($titulo) . "</h1>
                <div class='sub'>Generado el " . date('d/m/Y H:i') . ' por ' . $e(Session::usuario()['nombre'] ?? 'admin') . "</div></td></tr></table>
            <div class='filtros'><b>Filtros:</b> " . $e($filtros) . "</div>
            <table class='d'><tr>$th</tr>$tr</table>
            <div class='pie'>Datos tomados de las operaciones registradas en el sistema. El historial de auditoría es de solo lectura.</div>
        </body></html>";
    }
}

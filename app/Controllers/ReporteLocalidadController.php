<?php

namespace App\Controllers;

use App\Core\Paginacion;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidacionException;
use App\Services\ReporteLocalidadService;
use Dompdf\Dompdf;

/**
 * Reporte de Localidades (Módulo 5 · Salidas): ventas, pedidos y envíos por zona.
 *
 * Permisos (se chequean ACÁ, en el backend; ocultar botones no alcanza):
 *  - admin    → reporte completo + exportaciones.
 *  - vendedor → vista acotada (el Service recorta datos y alcance según la sesión).
 *  - cliente de la tienda / sin sesión → 401 por el Router, y 403 acá por las dudas.
 * Todas las rutas son GET de solo lectura: no modifican nada.
 */
class ReporteLocalidadController
{
    /** GET /api/admin/reportes/localidades/opciones — listas para los filtros. */
    public function opciones(): void
    {
        $this->responder(fn(ReporteLocalidadService $s) => [
            'es_admin' => $s->esAdmin(),
            'hoy'      => $s->filtros([])['hasta_d']->format('Y-m-d'),
        ] + $s->opciones((string) ($_GET['base'] ?? 'entrega')));
    }

    /** GET /api/admin/reportes/localidades/resumen — indicadores, gráficos, logística y top productos. */
    public function resumen(): void
    {
        $this->responder(function (ReporteLocalidadService $s) {
            $f = $s->filtros($_GET);
            $comp = $s->periodoComparacion($f, $_GET);
            return [
                'periodo'     => $this->periodoTxt($f),
                'comparacion' => $comp ? ['texto' => $comp['texto']] + $this->periodoTxt($comp) : null,
                'indicadores' => $s->resumen($f, $comp),
                'graficos'    => $s->graficos($f),
                'logistica'   => $s->logistica($f),
                'productos'   => $s->productos($f),
                'es_admin'    => $s->esAdmin(),
            ];
        });
    }

    /** GET /api/admin/reportes/localidades/zonas — tabla por zona (paginada, ordenable, buscable). */
    public function zonas(): void
    {
        $this->responder(function (ReporteLocalidadService $s) {
            $f = $s->filtros($_GET);
            $comp = $s->periodoComparacion($f, $_GET);
            [$page, $perPage] = Paginacion::desde(10, 100);
            $r = $s->zonas($f, $comp, $_GET, $page, $perPage);
            return Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage);
        });
    }

    /** GET /api/admin/reportes/localidades/productos — más vendidos con los filtros (ej: una zona). */
    public function productos(): void
    {
        $this->responder(function (ReporteLocalidadService $s) {
            $f = $s->filtros($_GET);
            $orden = ($_GET['orden'] ?? '') === 'importe' ? 'importe' : 'unidades';
            return ['productos' => $s->productos($f, $orden)];
        });
    }

    /** GET /api/admin/reportes/localidades/comparar-zonas?zona_a=&zona_b= */
    public function compararZonas(): void
    {
        $this->responder(function (ReporteLocalidadService $s) {
            $f = $s->filtros(array_merge($_GET, ['zona' => '']));
            return ['zonas' => $s->compararZonas($f, (string) ($_GET['zona_a'] ?? ''), (string) ($_GET['zona_b'] ?? ''))];
        });
    }

    // ===================== Exportaciones =====================

    /** GET /api/admin/reportes/localidades/csv?seccion=zonas|productos|logistica (+ filtros). */
    public function csv(): void
    {
        $s = $this->servicio();
        if ($s === null) return;
        try {
            $f = $s->filtros($_GET);
            $comp = $s->periodoComparacion($f, $_GET);
            $seccion = $_GET['seccion'] ?? 'zonas';
            [$encabezado, $filas] = match ($seccion) {
                'productos' => $this->csvProductos($s, $f),
                'logistica' => $this->csvLogistica($s, $f),
                'zonas'     => $this->csvZonas($s, $f, $comp),
                default     => throw new ValidacionException('Sección de exportación no válida.'),
            };
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        } catch (\Throwable $e) {
            error_log('[ReporteLocalidad] csv: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'No se pudo generar el CSV.'], 500);
            return;
        }

        $nombre = "reporte-localidades-{$seccion}-" . $f['desde_d']->format('Ymd') . '-' . $f['hasta_d']->format('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // BOM: Excel abre bien los acentos y la ñ
        fputcsv($out, $encabezado, ';', '"', '');
        foreach ($filas as $fila) {
            fputcsv($out, array_map([$this, 'celdaCsv'], $fila), ';', '"', '');
        }
        fclose($out);
    }

    /** GET /api/admin/reportes/localidades/pdf (+ filtros). */
    public function pdf(): void
    {
        $s = $this->servicio();
        if ($s === null) return;
        try {
            $f = $s->filtros($_GET);
            $comp = $s->periodoComparacion($f, $_GET);
            $datos = [
                'filtros'     => $s->describirFiltros($f, $s->opciones($f['base'])),
                'comparacion' => $comp ? $comp['texto'] . ' (' . $comp['desde_d']->format('d/m/Y') . ' al ' . $comp['hasta_d']->format('d/m/Y') . ')' : null,
                'indicadores' => $s->resumen($f, $comp),
                'zonas'       => $s->zonas($f, $comp, ['orden' => 'importe', 'dir' => 'desc'], 1, 40),
                'logistica'   => $s->logistica($f),
                'productos'   => array_slice($s->productos($f), 0, 10),
                'generado'    => $s->ahora(),
            ];
            $html = $this->htmlPdf($datos, $s->esAdmin());
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        } catch (\Throwable $e) {
            error_log('[ReporteLocalidad] pdf: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'No se pudo generar el PDF.'], 500);
            return;
        }

        // Se genera en memoria y se envía directo: no quedan archivos temporales.
        $dompdf = new Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        header('Cache-Control: no-store');
        $dompdf->stream('reporte-localidades-' . $f['desde_d']->format('Ymd') . '-' . $f['hasta_d']->format('Ymd') . '.pdf',
            ['Attachment' => true]);
    }

    // ===================== Internos =====================

    /** Servicio con el rol de la sesión, o null (y 403) si no es staff. */
    private function servicio(): ?ReporteLocalidadService
    {
        $rol = Session::usuario()['rol'] ?? '';
        if (!Session::esStaff() || !in_array($rol, ['admin', 'vendedor'], true)) {
            Response::json(['ok' => false, 'error' => 'No tenés permiso para ver reportes.'], 403);
            return null;
        }
        return new ReporteLocalidadService($rol === 'admin', (int) Session::usuarioId());
    }

    /** Ejecuta la acción con manejo de errores estándar (422 validación / 500 interno). */
    private function responder(callable $accion): void
    {
        $s = $this->servicio();
        if ($s === null) return;
        try {
            Response::json(['ok' => true] + $accion($s));
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            error_log('[ReporteLocalidad] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            Response::json(['ok' => false, 'error' => 'No se pudo generar el reporte.'], 500);
        }
    }

    private function periodoTxt(array $f): array
    {
        return ['desde' => $f['desde_d']->format('Y-m-d'), 'hasta' => $f['hasta_d']->format('Y-m-d')];
    }

    /**
     * Celda segura para Excel: un texto que empieza con = + - @ (o tab/CR) se
     * ejecutaría como fórmula ("CSV injection") → se le antepone un apóstrofo.
     * Los números se escriben con coma decimal (Excel en español).
     */
    private function celdaCsv($v): string
    {
        if ($v === null) return '';
        if (is_int($v)) return (string) $v;
        if (is_float($v)) return number_format($v, 2, ',', '');
        $v = (string) $v;
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }

    private function tipoZona(string $t): string
    {
        return ['localidad' => 'Localidad', 'barrio' => 'Barrio (Moto Express)', 'local' => 'Retiro / mostrador',
                'sin' => 'Sin localidad'][$t] ?? $t;
    }

    private function csvZonas(ReporteLocalidadService $s, array $f, ?array $comp): array
    {
        $admin = $s->esAdmin();
        $rows = $s->zonas($f, $comp, $_GET, 1, 5000)['rows'];
        $enc = ['Zona', 'Tipo', 'Provincia', 'Ventas POS', 'Pedidos online', 'Operaciones', 'Anuladas/canceladas',
                'Importe', 'Importe POS', 'Importe online', 'Promedio por operación', 'Clientes',
                'Envíos', 'Entregados', 'Activos', 'Demorados', 'Estado de entregas'];
        if ($admin) array_push($enc, 'Cobrado', 'Envío cobrado al cliente');
        if ($comp) array_push($enc, 'Importe período anterior', 'Variación importe %');
        $filas = [];
        foreach ($rows as $z) {
            $fila = [$z['zona_nombre'], $this->tipoZona($z['zona_tipo']), $z['provincias'], $z['ventas_pos'], $z['pedidos_online'],
                     $z['operaciones'], $z['anuladas'], (float) $z['importe'], (float) $z['importe_pos'], (float) $z['importe_online'],
                     $z['ticket'] === null ? null : (float) $z['ticket'], $z['clientes'],
                     $z['envios'], $z['entregados'], $z['activos'], $z['demorados'], $z['estado_entregas']];
            if ($admin) array_push($fila, (float) $z['cobrado'], (float) $z['envio_cobrado']);
            if ($comp) {
                $v = $z['variacion_importe'];
                array_push($fila, (float) $v['anterior'], $v['pct'] === null ? 'Sin datos en el período anterior' : (float) $v['pct']);
            }
            $filas[] = $fila;
        }
        return [$enc, $filas];
    }

    private function csvProductos(ReporteLocalidadService $s, array $f): array
    {
        $filas = array_map(fn($p) => [$p['nombre'], $p['unidades'], (float) $p['importe'], $p['operaciones']], $s->productos($f));
        return [['Producto', 'Unidades', 'Importe (precio histórico)', 'Operaciones'], $filas];
    }

    private function csvLogistica(ReporteLocalidadService $s, array $f): array
    {
        $admin = $s->esAdmin();
        $enc = ['Zona', 'Tipo', 'Medio de envío', 'Envíos', 'Pendientes', 'Despachados', 'En camino', 'Entregados',
                'Cancelados', 'Demorados', 'Moto sin repartidor'];
        if ($admin) array_push($enc, 'Costo cobrado al cliente', 'Paga a repartidores (moto, tarifa vigente)');
        $filas = [];
        foreach ($s->logistica($f)['rows'] as $r) {
            $fila = [$r['zona_nombre'], $this->tipoZona($r['zona_tipo']), $r['empresa'] ?? 'Sin medio', $r['envios'], $r['pendientes'],
                     $r['despachados'], $r['en_camino'], $r['entregados'], $r['cancelados'], $r['demorados'], $r['sin_repartidor']];
            if ($admin) array_push($fila, (float) $r['costo_cobrado'], (float) $r['paga_moto']);
            $filas[] = $fila;
        }
        return [$enc, $filas];
    }

    /** HTML del PDF (Dompdf). Gráfico de barras con CSS: no depende de JavaScript. */
    private function htmlPdf(array $d, bool $admin): string
    {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $m = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.');
        $n = fn($n) => number_format((float) $n, 0, ',', '.');
        $pct = function (?array $v) {
            if ($v === null) return '';
            if ($v['pct'] === null) return "<span class='mute'>Sin datos en el período anterior</span>";
            $cls = $v['pct'] >= 0 ? 'up' : 'down';
            return "<span class='$cls'>" . ($v['pct'] >= 0 ? '▲ +' : '▼ ') . number_format($v['pct'], 1, ',', '.') . '%</span>';
        };

        $logo = '';
        $ruta = dirname(__DIR__, 2) . '/public/assets/img/britech-logo.png';
        if (is_file($ruta)) {
            $logo = "<img class='logo' src='data:image/png;base64," . base64_encode((string) file_get_contents($ruta)) . "'>";
        }

        $filtros = '';
        foreach ($d['filtros'] as $k => $v) { $filtros .= "<tr><td class='k'>" . $e($k) . "</td><td>" . $e($v) . "</td></tr>"; }
        if ($d['comparacion']) { $filtros .= "<tr><td class='k'>Comparado con</td><td>" . $e($d['comparacion']) . "</td></tr>"; }

        $a = $d['indicadores']['actual'];
        $var = $d['indicadores']['variacion'] ?? [];
        $kpi = fn($t, $v, $nota, $vk = null) => "<td class='kpi'><div class='kt'>" . $e($t) . "</div><div class='kv'>$v</div>"
            . "<div class='kn'>" . $e($nota) . "</div>" . ($vk ? '<div>' . $pct($var[$vk] ?? null) . '</div>' : '') . "</td>";
        $kpis = "<table class='kpis'><tr>"
            . $kpi('Operaciones de venta', $n($a['operaciones']), "POS {$a['ventas_pos']} · Online {$a['pedidos_online']} (sin anuladas/canceladas)", 'operaciones')
            . $kpi('Importe vendido', $m($a['importe']), 'Productos, sin costo de envío', 'importe')
            . $kpi('Pedidos online', $n($a['pedidos_online']), "Cancelados aparte: {$a['pedidos_cancelados']}", 'pedidos_online')
            . $kpi('Zonas con actividad', $n($a['zonas_activas']), 'Localidades + barrios con ventas', 'zonas_activas')
            . $kpi('Envíos', $n($a['envios']), "Entregados {$a['envios_entregados']} · sin retiros en local", 'envios')
            . $kpi('Entregas pendientes', $n($a['envios_activos']), "Demorados: {$a['envios_demorados']}")
            . "</tr></table>";
        if ($admin) {
            $kpis .= "<p class='fin'>Cobrado: <b>" . $m($a['cobrado']) . "</b> · Pendiente de cobro (online): <b>" . $m($a['pendiente_cobro'])
                . "</b> · Envíos cobrados al cliente: <b>" . $m($a['envio_cobrado']) . "</b></p>";
        }

        // Gráfico de barras (top 10 por importe).
        $top = array_slice($d['zonas']['rows'], 0, 10);
        $max = max(1, ...array_map(fn($z) => (float) $z['importe'], $top ?: [['importe' => 1]]));
        $barras = '';
        foreach ($top as $z) {
            $w = max(1, (int) round((float) $z['importe'] / $max * 100));
            $barras .= "<tr><td class='bl'>" . $e($z['zona_nombre']) . "</td><td class='bt'><div class='bf' style='width:{$w}%'></div></td>"
                . "<td class='r'>" . $m($z['importe']) . "</td></tr>";
        }

        $filasZ = '';
        foreach ($d['zonas']['rows'] as $z) {
            $filasZ .= "<tr><td>" . $e($z['zona_nombre']) . "<br><span class='mute'>" . $e($this->tipoZona($z['zona_tipo'])) . "</span></td>"
                . "<td class='r'>{$z['ventas_pos']}</td><td class='r'>{$z['pedidos_online']}</td>"
                . "<td class='r'>" . $m($z['importe']) . "</td><td class='r'>" . ($z['ticket'] === null ? '—' : $m($z['ticket'])) . "</td>"
                . "<td class='r'>{$z['envios']}</td><td>" . $e($z['estado_entregas']) . "</td>"
                . (isset($z['variacion_importe']) ? "<td class='r'>" . $pct($z['variacion_importe']) . "</td>" : '') . "</tr>";
        }
        $thVar = $d['comparacion'] ? '<th>Var. importe</th>' : '';
        if ($d['zonas']['total'] > count($d['zonas']['rows'])) {
            $filasZ .= "<tr><td colspan='8' class='mute'>Se muestran las " . count($d['zonas']['rows']) . " zonas con más importe de "
                . $d['zonas']['total'] . ". El detalle completo está en la exportación CSV.</td></tr>";
        }

        $filasL = '';
        foreach ($d['logistica']['rows'] as $r) {
            $filasL .= "<tr><td>" . $e($r['zona_nombre']) . "</td><td>" . $e($r['empresa'] ?? 'Sin medio') . "</td>"
                . "<td class='r'>{$r['envios']}</td><td class='r'>" . ($r['pendientes'] + $r['despachados'] + $r['en_camino']) . "</td>"
                . "<td class='r'>{$r['entregados']}</td><td class='r'>{$r['cancelados']}</td><td class='r'>{$r['demorados']}</td>"
                . ($admin ? "<td class='r'>" . $m($r['costo_cobrado']) . "</td>" : '') . "</tr>";
        }
        $thCosto = $admin ? '<th>Costo cobrado</th>' : '';

        $filasP = '';
        foreach ($d['productos'] as $p) {
            $filasP .= "<tr><td>" . $e($p['nombre']) . "</td><td class='r'>{$p['unidades']}</td><td class='r'>" . $m($p['importe'])
                . "</td><td class='r'>{$p['operaciones']}</td></tr>";
        }
        $vacio = fn($cols) => "<tr><td colspan='$cols' class='mute'>Sin datos para los filtros elegidos.</td></tr>";

        $generado = $e(date('d/m/Y H:i', strtotime($d['generado'])));
        return "<!DOCTYPE html><html><head><meta charset='utf-8'><style>
            * { font-family: DejaVu Sans, sans-serif; }
            body { color: #1a1a2e; font-size: 9.5px; }
            .top { border-bottom: 3px solid #4f46e5; padding-bottom: 8px; margin-bottom: 12px; }
            .logo { height: 34px; float: right; }
            h1 { color: #4f46e5; margin: 0 0 2px; font-size: 19px; }
            h2 { font-size: 12px; margin: 14px 0 6px; color: #312e81; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
            .mute { color: #777; }
            table { width: 100%; border-collapse: collapse; }
            .filtros td { padding: 2px 6px; } .filtros .k { width: 140px; color: #555; font-weight: bold; }
            .kpis td.kpi { border: 1px solid #ddd; padding: 6px; vertical-align: top; width: 16.6%; }
            .kt { font-size: 8px; text-transform: uppercase; color: #666; font-weight: bold; }
            .kv { font-size: 14px; font-weight: bold; margin: 2px 0; }
            .kn { font-size: 7.5px; color: #777; }
            .fin { margin: 6px 0 0; }
            .up { color: #15803d; } .down { color: #b91c1c; }
            .datos th { background: #eef; text-align: left; padding: 4px; font-size: 8.5px; }
            .datos td { border-bottom: 1px solid #eee; padding: 3px 4px; vertical-align: top; }
            .r { text-align: right; white-space: nowrap; }
            .bl { width: 160px; } .bt { padding: 2px 6px; } .bf { background: #6d28d9; height: 9px; }
            .notas { margin-top: 12px; font-size: 8px; color: #555; }
          </style></head><body>
            <div class='top'>$logo<h1>Reporte de Localidades</h1>
              <div class='mute'>Britech · Ventas, pedidos y entregas por zona · Generado el $generado</div></div>
            <table class='filtros'>$filtros</table>
            <h2>Indicadores principales</h2>$kpis
            <h2>Importe por zona (top 10)</h2><table>" . ($barras ?: $vacio(3)) . "</table>
            <h2>Ventas por localidad / zona</h2>
            <table class='datos'><tr><th>Zona</th><th>Ventas POS</th><th>Pedidos online</th><th>Importe</th><th>Promedio</th>
              <th>Envíos</th><th>Entregas</th>$thVar</tr>" . ($filasZ ?: $vacio(8)) . "</table>
            <h2>Entregas por zona y medio de envío</h2>
            <table class='datos'><tr><th>Zona</th><th>Medio</th><th>Envíos</th><th>Activos</th><th>Entregados</th><th>Cancelados</th>
              <th>Demorados</th>$thCosto</tr>" . ($filasL ?: $vacio(8)) . "</table>
            <h2>Productos más vendidos</h2>
            <table class='datos'><tr><th>Producto</th><th>Unidades</th><th>Importe</th><th>Operaciones</th></tr>" . ($filasP ?: $vacio(4)) . "</table>
            <div class='notas'><b>Aclaraciones:</b> El importe es de productos (sin envío) e incluye ventas del POS registradas y pedidos
              online no cancelados; las ventas anuladas y los pedidos cancelados se cuentan aparte. La zona es el destino del envío:
              las ventas sin envío y los retiros figuran como \"Retiro / venta en el local\"; los barrios de Moto Express se muestran
              separados de las localidades; los envíos sin localidad cargada se agrupan como \"Sin localidad identificada\".
              \"Demorado\" = envío activo con más días que el umbral elegido (el sistema no registra una fecha prometida de entrega).
              El importe por producto usa el precio guardado en cada venta (no incluye el descuento general de la venta).</div>
          </body></html>";
    }
}

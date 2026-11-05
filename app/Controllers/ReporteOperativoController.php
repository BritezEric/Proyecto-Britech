<?php

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Core\ValidacionException;
use App\Services\ReporteOperativoService;
use Dompdf\Dompdf;

/**
 * Reportes y Control Operativo (Módulo 5 · Salidas) — SOLO administrador.
 * El permiso se chequea acá en el backend (el Router ya exige sesión de staff;
 * un vendedor recibe 403 aunque escriba la URL a mano).
 * Todas las rutas son GET de solo lectura: ningún reporte modifica datos.
 *
 * Como todos los reportes tienen la misma estructura (kpis / grafico / tablas /
 * notas), el CSV y el PDF son genéricos: sirven para los siete.
 */
class ReporteOperativoController
{
    /** GET /api/admin/reportes/operativo/opciones — listas para los filtros. */
    public function opciones(): void
    {
        $this->responder(fn(ReporteOperativoService $s) => $s->opciones());
    }

    /** GET /api/admin/reportes/operativo?reporte=pagos|cajas|vendedores|pedidos|reclamos|envios|carga (+ filtros). */
    public function datos(): void
    {
        $this->responder(fn(ReporteOperativoService $s) => $s->generar((string) ($_GET['reporte'] ?? ''), $_GET));
    }

    /** GET /api/admin/reportes/operativo/csv?reporte=…&tabla=0 (+ filtros). */
    public function csv(): void
    {
        $s = $this->servicio();
        if ($s === null) return;
        try {
            $d = $s->generar((string) ($_GET['reporte'] ?? ''), $_GET, false);   // el CSV lleva todas las filas
            $i = (int) ($_GET['tabla'] ?? 0);
            if (!isset($d['tablas'][$i])) throw new ValidacionException('Tabla de exportación no válida.');
            $t = $d['tablas'][$i];
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        } catch (\Throwable $e) {
            error_log('[ReporteOperativo] csv: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'No se pudo generar el CSV.'], 500);
            return;
        }

        $nombre = 'reporte-' . $d['reporte'] . '-' . $t['id'] . '-' . str_replace('-', '', $d['periodo']['desde'])
            . '-' . str_replace('-', '', $d['periodo']['hasta']) . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");   // BOM: Excel abre bien los acentos y la ñ
        fputcsv($out, array_column($t['columnas'], 't'), ';', '"', '');
        foreach ($t['filas'] as $fila) {
            fputcsv($out, array_map(fn($c) => $this->celdaCsv($fila[$c['k']] ?? null, $c['tipo']), $t['columnas']), ';', '"', '');
        }
        fclose($out);
    }

    /** GET /api/admin/reportes/operativo/pdf?reporte=… (+ filtros). */
    public function pdf(): void
    {
        $s = $this->servicio();
        if ($s === null) return;
        try {
            $d = $s->generar((string) ($_GET['reporte'] ?? ''), $_GET);
            $html = $this->htmlPdf($d);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            return;
        } catch (\Throwable $e) {
            error_log('[ReporteOperativo] pdf: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'No se pudo generar el PDF.'], 500);
            return;
        }

        // En memoria y directo al navegador: no quedan archivos temporales.
        $dompdf = new Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        header('Cache-Control: no-store');
        $dompdf->stream('reporte-' . $d['reporte'] . '-' . str_replace('-', '', $d['periodo']['desde'])
            . '-' . str_replace('-', '', $d['periodo']['hasta']) . '.pdf', ['Attachment' => true]);
    }

    // ===================== Internos =====================

    /** Servicio si la sesión es de un administrador; si no, 403. */
    private function servicio(): ?ReporteOperativoService
    {
        if (!Session::esStaff() || !Session::esAdmin()) {
            Response::json(['ok' => false, 'error' => 'Solo el administrador puede ver los reportes operativos.'], 403);
            return null;
        }
        return new ReporteOperativoService();
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
            error_log('[ReporteOperativo] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            Response::json(['ok' => false, 'error' => 'No se pudo generar el reporte.'], 500);
        }
    }

    /** Minutos → "2 h 15 min" / "3 d 4 h". */
    private function duracion(?int $min): string
    {
        if ($min === null) return '—';
        if ($min < 60) return $min . ' min';
        if ($min < 1440) return intdiv($min, 60) . ' h ' . ($min % 60) . ' min';
        return intdiv($min, 1440) . ' d ' . intdiv($min % 1440, 60) . ' h';
    }

    /** Valor de una celda formateado para leer (PDF). */
    private function celdaTexto($v, string $tipo): string
    {
        if ($v === null || $v === '') return '—';
        return match ($tipo) {
            'money' => '$ ' . number_format((float) $v, 2, ',', '.'),
            'int'   => number_format((float) $v, 0, ',', '.'),
            'pct'   => number_format((float) $v, 1, ',', '.') . '%',
            'fecha' => date('d/m/Y H:i', strtotime((string) $v)),
            'min'   => $this->duracion((int) $v),
            default => (string) $v,
        };
    }

    /**
     * Celda segura para Excel: un texto que empieza con = + - @ (o tab/CR) se
     * ejecutaría como fórmula ("CSV injection") → se le antepone un apóstrofo.
     * Los montos van con coma decimal (Excel en español).
     */
    private function celdaCsv($v, string $tipo): string
    {
        if ($v === null) return '';
        if ($tipo === 'money' || $tipo === 'pct') return number_format((float) $v, 2, ',', '');
        if ($tipo === 'int') return (string) (int) $v;
        if ($tipo === 'min') return $this->duracion((int) $v);
        if ($tipo === 'fecha') return date('d/m/Y H:i', strtotime((string) $v));
        $v = (string) $v;
        return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    }

    private function htmlPdf(array $d): string
    {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

        $logo = '';
        $ruta = dirname(__DIR__, 2) . '/public/assets/img/britech-logo.png';
        if (is_file($ruta)) {
            $logo = "<img class='logo' src='data:image/png;base64," . base64_encode((string) file_get_contents($ruta)) . "'>";
        }

        $filtros = '';
        foreach ($d['filtros_txt'] as $k => $v) { $filtros .= "<tr><td class='k'>" . $e($k) . "</td><td>" . $e($v) . "</td></tr>"; }

        // Indicadores en filas de 5.
        $celdas = array_map(fn($k) => "<td class='kpi " . $e($k['tono'] ?? '') . "'><div class='kt'>" . $e($k['label']) . "</div><div class='kv'>"
            . $e($k['tipo'] === 'text' ? ($k['valor'] ?? '—') : $this->celdaTexto($k['valor'], $k['tipo'])) . "</div><div class='kn'>"
            . $e($k['nota'] ?? '') . "</div></td>", $d['kpis']);
        $kpis = '';
        foreach (array_chunk($celdas, 5) as $fila) { $kpis .= '<tr>' . implode('', $fila) . str_repeat('<td></td>', 5 - count($fila)) . '</tr>'; }

        // Gráfico como barras CSS (primera serie, o la suma si es apilado).
        $g = $d['grafico'];
        $valores = [];
        foreach ($g['labels'] as $i => $lab) {
            $valores[$lab] = array_sum(array_map(fn($s) => (float) ($s['data'][$i] ?? 0), !empty($g['apilado']) ? $g['series'] : [$g['series'][0]]));
        }
        arsort($valores);
        $valores = array_slice($valores, 0, 12, true);
        $max = max(1, ...array_values($valores ?: [1]));
        $barras = '';
        foreach ($valores as $lab => $v) {
            $w = max(1, (int) round($v / $max * 100));
            $barras .= "<tr><td class='bl'>" . $e($lab) . "</td><td class='bt'><div class='bf' style='width:{$w}%'></div></td><td class='r'>"
                . $e($this->celdaTexto($v, $g['formato'] === 'money' ? 'money' : 'int')) . "</td></tr>";
        }
        if (!array_filter($valores)) $barras = "<tr><td class='mute'>Sin datos para graficar.</td></tr>";

        // Tablas (hasta 150 filas cada una en el PDF; el resto va en el CSV).
        $tablas = '';
        foreach ($d['tablas'] as $t) {
            $th = implode('', array_map(fn($c) => '<th>' . $e($c['t']) . '</th>', $t['columnas']));
            $filas = '';
            foreach (array_slice($t['filas'], 0, 150) as $f) {
                $filas .= '<tr>' . implode('', array_map(function ($c) use ($f, $e) {
                    $num = in_array($c['tipo'], ['money', 'int', 'pct', 'min'], true);
                    return "<td" . ($num ? " class='r'" : '') . '>' . $e($this->celdaTexto($f[$c['k']] ?? null, $c['tipo'])) . '</td>';
                }, $t['columnas'])) . '</tr>';
            }
            if (!$t['filas']) $filas = "<tr><td colspan='" . count($t['columnas']) . "' class='mute'>Sin registros para los filtros elegidos.</td></tr>";
            if ($t['total'] > 150) {
                $filas .= "<tr><td colspan='" . count($t['columnas']) . "' class='mute'>Se muestran 150 de {$t['total']} filas. El detalle completo está en la exportación CSV.</td></tr>";
            }
            $tablas .= "<h2>" . $e($t['titulo']) . "</h2><table class='datos'><tr>$th</tr>$filas</table>";
        }

        $notas = implode('', array_map(fn($n) => '<li>' . $e($n) . '</li>', $d['notas']));
        $generado = $e(date('d/m/Y H:i', strtotime($d['generado'])));
        $titulo = $e($d['titulo']);
        return "<!DOCTYPE html><html><head><meta charset='utf-8'><style>
            * { font-family: DejaVu Sans, sans-serif; }
            body { color: #1a1a2e; font-size: 9px; }
            .top { border-bottom: 3px solid #4f46e5; padding-bottom: 8px; margin-bottom: 12px; }
            .logo { height: 34px; float: right; }
            h1 { color: #4f46e5; margin: 0 0 2px; font-size: 19px; }
            h2 { font-size: 12px; margin: 14px 0 6px; color: #312e81; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
            .mute { color: #777; }
            table { width: 100%; border-collapse: collapse; }
            .filtros td { padding: 2px 6px; } .filtros .k { width: 180px; color: #555; font-weight: bold; }
            .kpis td { padding: 3px; width: 20%; vertical-align: top; }
            .kpis td.kpi { border: 1px solid #ddd; padding: 6px; }
            .kpis td.ok { border-left: 3px solid #15803d; } .kpis td.warn { border-left: 3px solid #d97706; }
            .kpis td.danger { border-left: 3px solid #b91c1c; }
            .kt { font-size: 7.5px; text-transform: uppercase; color: #666; font-weight: bold; }
            .kv { font-size: 13px; font-weight: bold; margin: 2px 0; }
            .kn { font-size: 7.5px; color: #777; }
            .datos th { background: #eef; text-align: left; padding: 4px; font-size: 8px; }
            .datos td { border-bottom: 1px solid #eee; padding: 3px 4px; vertical-align: top; }
            .r { text-align: right; white-space: nowrap; }
            .bl { width: 200px; } .bt { padding: 2px 6px; } .bf { background: #6d28d9; height: 9px; }
            .notas { margin-top: 12px; font-size: 8px; color: #555; }
          </style></head><body>
            <div class='top'>$logo<h1>$titulo</h1>
              <div class='mute'>Britech · Reportes y control operativo · Generado el $generado</div></div>
            <table class='filtros'>$filtros</table>
            <h2>Indicadores</h2><table class='kpis'>$kpis</table>
            <h2>" . $e($g['titulo']) . "</h2><table>$barras</table>
            $tablas
            <div class='notas'><b>Aclaraciones:</b><ul>$notas</ul></div>
          </body></html>";
    }
}

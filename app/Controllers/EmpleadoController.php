<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\EmpleadoRepository;
use Dompdf\Dompdf;

/**
 * Gestor de empleados (solo admin): rendimiento en ventas + pagos de sueldo +
 * salida en PDF del desempeño mensual.
 */
class EmpleadoController
{
    private function guardAdmin(): bool
    {
        if (!Session::esAdmin()) {
            Response::json(['ok' => false, 'error' => 'Solo el administrador.'], 403);
            return false;
        }
        return true;
    }

    /** Rango [desde, hasta) del mes 'YYYY-MM'. Si no es válido, usa el mes actual. */
    private function rango(string $periodo): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) { $periodo = date('Y-m'); }
        $desde = $periodo . '-01';
        $hasta = date('Y-m-01', strtotime($desde . ' +1 month'));
        return [$periodo, $desde, $hasta];
    }

    public function listar(): void
    {
        if (!$this->guardAdmin()) return;
        Response::json(['ok' => true, 'empleados' => (new EmpleadoRepository())->listar()]);
    }

    public function detalle(): void
    {
        if (!$this->guardAdmin()) return;
        $id = (int) Request::query('id', '0');
        $repo = new EmpleadoRepository();
        $emp = $repo->info($id);
        if ($emp === null) { Response::json(['ok' => false, 'error' => 'No existe.'], 404); return; }

        [$periodo, $desde, $hasta] = $this->rango(Request::query('periodo', date('Y-m')));
        Response::json([
            'ok'           => true,
            'empleado'     => $emp,
            'periodo'      => $periodo,
            'rendimiento'  => $repo->rendimiento($id, $desde, $hasta),
            'serie'        => $repo->serieMensual($id),
            'pagos'        => $repo->pagos($id),
            'pago_periodo' => $repo->pagoDePeriodo($id, $periodo),
        ]);
    }

    /** Salida de datos: PDF del desempeño del empleado en un mes. */
    public function pdf(): void
    {
        if (!$this->guardAdmin()) return;
        $id = (int) Request::query('id', '0');
        $repo = new EmpleadoRepository();
        $emp = $repo->info($id);
        if ($emp === null) { Response::json(['ok' => false, 'error' => 'No existe.'], 404); return; }

        [$periodo, $desde, $hasta] = $this->rango(Request::query('periodo', date('Y-m')));
        $rend = $repo->rendimiento($id, $desde, $hasta);
        $pago = $repo->pagoDePeriodo($id, $periodo);

        $dompdf = new Dompdf();
        $dompdf->loadHtml($this->html($emp, $periodo, $rend, $pago), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        $nombre = 'desempeno-' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($emp['nombre'])) . "-{$periodo}.pdf";
        $dompdf->stream($nombre, ['Attachment' => true]);
    }

    /** HTML del reporte (dompdf lo convierte a PDF). */
    private function html(array $emp, string $periodo, array $rend, ?array $pago): string
    {
        $money = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.');
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        [$anio, $mes] = explode('-', $periodo);
        $meses = ['01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril',
            '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto',
            '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'];
        $mesTxt = ($meses[$mes] ?? $mes) . ' ' . $anio;

        $pagoBloque = $pago
            ? "<p class='ok'>✔ Sueldo pagado el " . $e($pago['fecha']) . " — <strong>" . $e($money($pago['monto'])) . "</strong></p>"
            : "<p class='pend'>Sueldo del período: <strong>pendiente de pago</strong></p>";

        return "<!DOCTYPE html><html><head><meta charset='utf-8'><style>
            * { font-family: DejaVu Sans, sans-serif; }
            body { color: #1a1a2e; font-size: 12px; }
            .top { border-bottom: 3px solid #4f46e5; padding-bottom: 12px; margin-bottom: 20px; }
            .top h1 { color: #4f46e5; margin: 0 0 4px; font-size: 22px; }
            .top .sub { color: #666; }
            h2 { font-size: 14px; margin: 18px 0 8px; color: #4f46e5; }
            .row { display: block; margin: 4px 0; }
            table { width: 100%; border-collapse: collapse; margin-top: 6px; }
            td, th { padding: 8px 10px; border: 1px solid #ddd; text-align: left; }
            th { background: #f3f2ff; color: #4f46e5; }
            .kpi td:last-child { text-align: right; font-weight: bold; font-size: 14px; }
            .ok { color: #16794c; } .pend { color: #b4530a; }
            .foot { margin-top: 30px; color: #999; font-size: 10px; border-top: 1px solid #eee; padding-top: 8px; }
        </style></head><body>
            <div class='top'>
                <h1>Britech — Desempeño de empleado</h1>
                <div class='sub'>" . $e($emp['nombre']) . " · " . $e(ucfirst($emp['rol'])) . " · " . $e($emp['email']) . "</div>
            </div>
            <h2>Período: {$mesTxt}</h2>
            <table class='kpi'>
                <tr><td>Ventas realizadas</td><td>" . (int) $rend['ventas'] . "</td></tr>
                <tr><td>Unidades vendidas</td><td>" . (int) $rend['unidades'] . "</td></tr>
                <tr><td>Total facturado</td><td>" . $e($money($rend['monto'])) . "</td></tr>
                <tr><td>Ticket promedio</td><td>" . $e($money($rend['ticket'])) . "</td></tr>
            </table>
            <h2>Sueldo</h2>
            {$pagoBloque}
            <div class='foot'>Generado por Britech el " . date('d/m/Y H:i') . ". Documento interno.</div>
        </body></html>";
    }
}

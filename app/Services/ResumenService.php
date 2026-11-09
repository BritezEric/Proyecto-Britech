<?php

namespace App\Services;

use App\Repositories\ReporteOperativoRepository;
use App\Repositories\NotificacionRepository;

/**
 * Resumen de caja (diario o semanal) para los admin: total vendido, nº de ventas
 * y desglose por medio de pago. Se manda como notificación de rol admin, así sale
 * por la campana y por Telegram con la etiqueta "Resumen de caja".
 * Lo dispara una tarea programada (tools/resumen.php dia|semana).
 */
class ResumenService
{
    /** Arma y manda el resumen del período. $periodo = 'dia' | 'semana'. */
    public function enviar(string $periodo): array
    {
        [$desde, $hasta, $label] = $this->rango($periodo);
        $pagos = (new ReporteOperativoRepository())->pagos($desde, $hasta);

        $totLocal = 0.0; $ventas = []; $porMedio = [];
        $totOnline = 0.0; $online = [];
        foreach ($pagos as $r) {
            $imp = (float) $r['importe'];
            if (($r['canal'] ?? '') === 'local') {
                if (($r['estado_op'] ?? '') === 'anulada') continue;   // no contamos anuladas
                $totLocal += $imp; $ventas[$r['op_id']] = 1;
                $med = $r['medio_nombre'] ?: 'Otro';
                $porMedio[$med] = ($porMedio[$med] ?? 0) + $imp;
            } else {
                if (($r['estado_pago'] ?? '') !== 'pagado') continue;   // online solo si está pago
                $totOnline += $imp; $online[$r['op_id']] = 1;
            }
        }

        $m = fn($n) => number_format((float) $n, 0, ',', '.');
        $lineas = [$label, 'Ventas: ' . count($ventas) . ' · Total $ ' . $m($totLocal)];
        $medios = [];
        foreach ($porMedio as $k => $v) { $medios[] = $k . ' $ ' . $m($v); }
        if ($medios) $lineas[] = implode(' · ', $medios);
        if ($totOnline > 0) $lineas[] = 'Online pagado: ' . count($online) . ' · $ ' . $m($totOnline);
        $texto = implode("\n", $lineas);

        (new NotificacionRepository())->crearRol(1, 'caja_resumen', $texto, 'reportes', null, 'info');

        return [
            'periodo' => $periodo, 'desde' => $desde, 'hasta' => $hasta,
            'ventas' => count($ventas), 'total_local' => round($totLocal, 2),
            'online' => count($online), 'total_online' => round($totOnline, 2),
            'texto' => $texto,
        ];
    }

    /** [desde, hasta) + etiqueta legible. 'semana' = últimos 7 días; 'dia' = hoy. */
    private function rango(string $periodo): array
    {
        $hoy   = new \DateTimeImmutable('today');
        $hasta = $hoy->modify('+1 day')->format('Y-m-d 00:00:00');
        if ($periodo === 'semana') {
            $ini = $hoy->modify('-6 days');
            return [$ini->format('Y-m-d 00:00:00'), $hasta,
                'Semana · ' . $ini->format('d/m') . ' al ' . $hoy->format('d/m')];
        }
        return [$hoy->format('Y-m-d 00:00:00'), $hasta, 'Hoy · ' . $hoy->format('d/m')];
    }
}

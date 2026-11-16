<?php

namespace App\Services;

use App\Core\ValidacionException;
use App\Repositories\CajaRepository;

/**
 * CajaService — lógica de la caja del vendedor (apertura, movimientos, cierre).
 * El esperado en efectivo del arqueo = apertura + ventas en efectivo − retiros + ingresos.
 */
class CajaService
{
    private CajaRepository $repo;

    public function __construct()
    {
        $this->repo = new CajaRepository();
    }

    /** Caja abierta del usuario + resumen del turno + efectivo esperado. */
    public function estado(int $usuarioId): array
    {
        $caja = $this->repo->abiertaDe($usuarioId);
        if ($caja === null) return ['abierta' => false];

        $resumen = $this->repo->resumen((int) $caja['id']);
        return [
            'abierta'   => true,
            'caja'      => $caja,
            'resumen'   => $resumen,
            'esperado'  => $this->esperado((float) $caja['monto_apertura'], $resumen),
        ];
    }

    public function abrir(int $usuarioId, float $monto): array
    {
        if ($this->repo->abiertaDe($usuarioId) !== null) {
            throw new ValidacionException('Ya tenés una caja abierta.');
        }
        if ($monto < 0) throw new ValidacionException('El monto de apertura no puede ser negativo.');

        $id = $this->repo->abrir($usuarioId, round($monto, 2));
        Auditoria::campo('caja', $id, 'monto_apertura', null, round($monto, 2), 'abrir', $usuarioId, 'Apertura de caja');
        return ['caja_id' => $id];
    }

    public function movimiento(int $usuarioId, string $tipo, float $monto, ?string $motivo): array
    {
        $caja = $this->repo->abiertaDe($usuarioId);
        if ($caja === null) throw new ValidacionException('No tenés una caja abierta.');
        if (!in_array($tipo, ['retiro', 'ingreso'], true)) throw new ValidacionException('Tipo de movimiento inválido.');
        if ($monto <= 0) throw new ValidacionException('El monto tiene que ser mayor a 0.');

        $this->repo->agregarMovimiento((int) $caja['id'], $tipo, round($monto, 2), trim((string) $motivo) ?: null, $usuarioId);
        Auditoria::campo('caja', (int) $caja['id'], $tipo, null, round($monto, 2), $tipo, $usuarioId,
            ($tipo === 'retiro' ? 'Retiro de efectivo' : 'Ingreso de efectivo') . (trim((string) $motivo) !== '' ? ': ' . trim((string) $motivo) : ''));
        return ['ok' => true];
    }

    public function cerrar(int $usuarioId, float $contado, ?string $observacion): array
    {
        $caja = $this->repo->abiertaDe($usuarioId);
        if ($caja === null) throw new ValidacionException('No tenés una caja abierta.');
        if ($contado < 0) throw new ValidacionException('El efectivo contado no puede ser negativo.');

        $resumen  = $this->repo->resumen((int) $caja['id']);
        $esperado = $this->esperado((float) $caja['monto_apertura'], $resumen);
        $contado  = round($contado, 2);
        $diferencia = round($contado - $esperado, 2);

        $this->repo->cerrar((int) $caja['id'], $contado, $esperado, $diferencia, trim((string) $observacion) ?: null);
        // Esperado → contado: la diferencia del arqueo queda a la vista en la auditoría.
        Auditoria::campo('caja', (int) $caja['id'], 'efectivo', $esperado, $contado, 'cerrar', $usuarioId,
            'Cierre de caja · diferencia $ ' . number_format($diferencia, 2, ',', '.'));

        // Aviso a los admin: se cerró una caja (marcamos alerta si no cuadra).
        $notif = new \App\Repositories\NotificacionRepository();
        $dif = number_format($diferencia, 2, ',', '.');
        $notif->crearRol(
            1, 'caja_cierre',
            $diferencia == 0.0 ? 'Caja cerrada y cuadrada ✓' : "Caja cerrada · diferencia \$ {$dif}",
            'cajas', (int) $caja['id'], $diferencia == 0.0 ? 'exito' : 'alerta'
        );

        // Aviso al vendedor que cerró, con el detalle del cierre.
        $m = fn($n) => number_format((float) $n, 2, ',', '.');
        $detalle = "Cierre de tu caja · {$resumen['ventas']} ventas · "
            . "Efectivo \$ {$m($resumen['efectivo'])} · Transf \$ {$m($resumen['transferencia'])} · "
            . "Esperado \$ {$m($esperado)} · Contado \$ {$m($contado)} · Diferencia \$ {$dif}";
        $notif->crearUsuario(
            $usuarioId, 'caja_cierre_detalle', $detalle,
            'cajas', (int) $caja['id'], $diferencia == 0.0 ? 'exito' : 'alerta'
        );

        return [
            'caja_id'    => (int) $caja['id'],
            'esperado'   => $esperado,
            'contado'    => $contado,
            'diferencia' => $diferencia,
            'resumen'    => $resumen,
        ];
    }

    /** Efectivo esperado en la caja física. */
    private function esperado(float $apertura, array $resumen): float
    {
        return round($apertura + $resumen['efectivo'] - $resumen['retiros'] + $resumen['ingresos'], 2);
    }
}

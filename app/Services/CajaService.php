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
        return ['caja_id' => $id];
    }

    public function movimiento(int $usuarioId, string $tipo, float $monto, ?string $motivo): array
    {
        $caja = $this->repo->abiertaDe($usuarioId);
        if ($caja === null) throw new ValidacionException('No tenés una caja abierta.');
        if (!in_array($tipo, ['retiro', 'ingreso'], true)) throw new ValidacionException('Tipo de movimiento inválido.');
        if ($monto <= 0) throw new ValidacionException('El monto tiene que ser mayor a 0.');

        $this->repo->agregarMovimiento((int) $caja['id'], $tipo, round($monto, 2), trim((string) $motivo) ?: null, $usuarioId);
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

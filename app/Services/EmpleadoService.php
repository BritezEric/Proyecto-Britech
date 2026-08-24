<?php

namespace App\Services;

use App\Core\ValidacionException;
use App\Repositories\EmpleadoRepository;

/**
 * Gestión de empleados. El rendimiento se lee del EmpleadoRepository; el pago de
 * sueldo se registra como un GASTO etiquetado al empleado (reusa GastoService),
 * así queda en finanzas y suma al total invertido.
 */
class EmpleadoService
{
    private EmpleadoRepository $repo;

    public function __construct()
    {
        $this->repo = new EmpleadoRepository();
    }

    /** Registra el pago del sueldo de un empleado. Devuelve el id del gasto creado. */
    public function pagarSueldo(array $in, int $adminId): int
    {
        $usuarioId = (int) ($in['usuario_id'] ?? 0);
        $emp = $this->repo->info($usuarioId);
        if ($emp === null) {
            throw new ValidacionException('El empleado no existe.');
        }

        $periodo = trim($in['periodo'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) {
            throw new ValidacionException('Elegí el mes que cubre el sueldo (periodo).');
        }

        // Concepto legible en la lista de gastos.
        $concepto = 'Sueldo ' . $emp['nombre'] . ' — ' . $periodo;

        // Delegamos monto/fecha/validación al GastoService (mismo money-path que gastos).
        return (new GastoService())->guardar([
            'usuario_id'  => $usuarioId,
            'periodo'     => $periodo,
            'concepto'    => $concepto,
            'monto'       => $in['monto'] ?? '',
            'fecha'       => $in['fecha'] ?? date('Y-m-d'),
            'observacion' => $in['observacion'] ?? null,
        ], $adminId);
    }
}

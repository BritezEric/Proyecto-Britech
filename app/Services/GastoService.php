<?php

namespace App\Services;

use App\Core\Database;
use App\Core\ValidacionException;
use App\Repositories\GastoRepository;
use App\Repositories\InventarioRepository;

/**
 * Lógica + validaciones del ABM de Gastos (finanzas del negocio).
 * Regla: validar SIEMPRE en el backend.
 * Si el gasto es una compra de stock (producto + cantidad), al CREARLO suma ese
 * stock al inventario y registra el movimiento (todo en una transacción).
 */
class GastoService
{
    private GastoRepository $repo;
    private InventarioRepository $inv;

    public function __construct()
    {
        $this->repo = new GastoRepository();
        $this->inv  = new InventarioRepository();
    }

    public function listar(?string $q, array $filtros, int $limit, int $offset): array
    {
        return $this->repo->listarPaginado($q, $filtros, $limit, $offset);
    }

    /** Crea (si no viene id) o actualiza. Devuelve el id del gasto. */
    public function guardar(array $in, int $usuarioId): int
    {
        $d = $this->validar($in);
        $id = (int) ($in['id'] ?? 0);
        $editando = $id > 0;

        if ($editando && $this->repo->buscarPorId($id) === null) {
            throw new ValidacionException('El gasto no existe.');
        }

        $pdo = Database::conexion();
        try {
            $pdo->beginTransaction();
            if ($editando) {
                // ponytail: al editar NO re-tocamos stock (evita duplicar). El stock
                // se suma una sola vez, al crear la compra.
                $this->repo->actualizar($id, $d);
            } else {
                $id = $this->repo->crear($d);
                // Compra de stock: sumar al inventario + registrar el ingreso.
                if ($d['producto_id'] !== null && $d['cantidad'] !== null && $d['cantidad'] > 0) {
                    $actual = $this->inv->cantidadDe($d['producto_id']);
                    $this->inv->establecer($d['producto_id'], $actual + $d['cantidad']);
                    $this->inv->registrarMovimiento(
                        $d['producto_id'], 'ingreso', $d['cantidad'],
                        'Compra a proveedor (gasto)', null, $usuarioId
                    );
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    public function baja(int $id): void
    {
        if ($this->repo->buscarPorId($id) === null) {
            throw new ValidacionException('El gasto no existe.');
        }
        $this->repo->cambiarEstado($id, 0);
    }

    private function validar(array $in): array
    {
        $concepto = trim($in['concepto'] ?? '');
        if (mb_strlen($concepto) < 2) {
            throw new ValidacionException('El concepto es obligatorio (qué se compró/pagó).');
        }

        $monto = $in['monto'] ?? '';
        if ($monto === '' || !is_numeric($monto) || (float) $monto <= 0) {
            throw new ValidacionException('El monto es obligatorio y debe ser mayor a 0.');
        }

        $fecha = trim($in['fecha'] ?? '');
        if ($fecha === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            $fecha = date('Y-m-d');   // por defecto, hoy
        }

        // Compra de stock (opcional): producto + cantidad. Si hay producto, exige cantidad.
        $productoId = (int) ($in['producto_id'] ?? 0) ?: null;
        $cantRaw    = $in['cantidad'] ?? '';
        $cantidad   = ($cantRaw === '' || $cantRaw === null) ? null : (int) $cantRaw;
        if ($productoId !== null && ($cantidad === null || $cantidad < 1)) {
            throw new ValidacionException('Si elegís un producto, indicá cuántas unidades compraste (cantidad ≥ 1).');
        }
        if ($productoId === null) { $cantidad = null; }   // sin producto no guardamos cantidad

        return [
            'fecha'        => $fecha,
            'proveedor_id' => (int) ($in['proveedor_id'] ?? 0) ?: null,
            'producto_id'  => $productoId,
            'cantidad'     => $cantidad,
            'concepto'     => $concepto,
            'monto'        => round((float) $monto, 2),
            'observacion'  => trim($in['observacion'] ?? '') ?: null,
            'activo'       => isset($in['activo']) ? (int) (bool) $in['activo'] : 1,
        ];
    }
}

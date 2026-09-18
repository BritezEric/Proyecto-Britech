<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Caja del vendedor: apertura, movimientos (retiro/ingreso) y cierre/arqueo.
 * Efectivo = tipo_pago 'Efectivo' (para el esperado del arqueo).
 */
class CajaRepository
{
    /** Caja abierta de un usuario (o null). */
    public function abiertaDe(int $usuarioId): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT * FROM caja WHERE usuario_id = ? AND estado = 'abierta' ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$usuarioId]);
        return $st->fetch() ?: null;
    }

    public function buscarPorId(int $id): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT c.*, u.nombre AS vendedor FROM caja c JOIN usuario u ON u.id = c.usuario_id WHERE c.id = ?"
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function abrir(int $usuarioId, float $montoApertura): int
    {
        $pdo = Database::conexion();
        $pdo->prepare("INSERT INTO caja (usuario_id, monto_apertura) VALUES (?, ?)")
            ->execute([$usuarioId, $montoApertura]);
        return (int) $pdo->lastInsertId();
    }

    public function agregarMovimiento(int $cajaId, string $tipo, float $monto, ?string $motivo, int $usuarioId): void
    {
        Database::conexion()
            ->prepare("INSERT INTO caja_movimiento (caja_id, tipo, monto, motivo, usuario_id) VALUES (?, ?, ?, ?, ?)")
            ->execute([$cajaId, $tipo, $monto, $motivo, $usuarioId]);
    }

    public function movimientosDe(int $cajaId): array
    {
        $st = Database::conexion()->prepare(
            "SELECT id, tipo, monto, motivo, creado_en FROM caja_movimiento WHERE caja_id = ? ORDER BY id"
        );
        $st->execute([$cajaId]);
        return $st->fetchAll();
    }

    /**
     * Resumen de una caja: totales por forma de pago (ventas no anuladas ligadas
     * a la caja) + retiros/ingresos. 'efectivo' es lo que entra al arqueo físico.
     * @return array{efectivo: float, transferencia: float, otros: float, retiros: float, ingresos: float, ventas: int}
     */
    public function resumen(int $cajaId): array
    {
        $pdo = Database::conexion();

        // Totales por forma de pago (solo ventas NO anuladas de esta caja).
        $st = $pdo->prepare(
            "SELECT tp.nombre AS forma, COALESCE(SUM(pg.monto), 0) AS total
             FROM pago pg
             JOIN venta v     ON v.id = pg.venta_id
             JOIN tipo_pago tp ON tp.id = pg.tipo_pago_id
             WHERE v.caja_id = ? AND v.estado <> 'anulada'
             GROUP BY tp.nombre"
        );
        $st->execute([$cajaId]);
        $efectivo = 0.0; $transferencia = 0.0; $otros = 0.0;
        foreach ($st->fetchAll() as $r) {
            $forma = mb_strtolower((string) $r['forma']);
            if ($forma === 'efectivo')           $efectivo = (float) $r['total'];
            elseif ($forma === 'transferencia')  $transferencia = (float) $r['total'];
            else                                 $otros += (float) $r['total'];
        }

        // Cantidad de ventas no anuladas.
        $stV = $pdo->prepare("SELECT COUNT(*) FROM venta WHERE caja_id = ? AND estado <> 'anulada'");
        $stV->execute([$cajaId]);
        $ventas = (int) $stV->fetchColumn();

        // Retiros / ingresos manuales.
        $stM = $pdo->prepare(
            "SELECT tipo, COALESCE(SUM(monto), 0) AS total FROM caja_movimiento WHERE caja_id = ? GROUP BY tipo"
        );
        $stM->execute([$cajaId]);
        $retiros = 0.0; $ingresos = 0.0;
        foreach ($stM->fetchAll() as $r) {
            if ($r['tipo'] === 'retiro')  $retiros  = (float) $r['total'];
            if ($r['tipo'] === 'ingreso') $ingresos = (float) $r['total'];
        }

        return [
            'efectivo'      => round($efectivo, 2),
            'transferencia' => round($transferencia, 2),
            'otros'         => round($otros, 2),
            'retiros'       => round($retiros, 2),
            'ingresos'      => round($ingresos, 2),
            'ventas'        => $ventas,
        ];
    }

    public function cerrar(int $cajaId, float $contado, float $esperado, float $diferencia, ?string $observacion): void
    {
        Database::conexion()->prepare(
            "UPDATE caja SET estado = 'cerrada', monto_contado = ?, monto_esperado = ?,
                    diferencia = ?, observacion = ?, cerrada_en = NOW() WHERE id = ?"
        )->execute([$contado, $esperado, $diferencia, $observacion, $cajaId]);
    }

    /** Historial de cajas (admin), con nombre del vendedor. Paginado. */
    public function listarPaginado(int $limit, int $offset): array
    {
        $pdo = Database::conexion();
        $total = (int) $pdo->query("SELECT COUNT(*) FROM caja")->fetchColumn();
        $st = $pdo->prepare(
            "SELECT c.id, c.estado, c.monto_apertura, c.monto_contado, c.monto_esperado, c.diferencia,
                    c.abierta_en, c.cerrada_en, u.nombre AS vendedor
             FROM caja c JOIN usuario u ON u.id = c.usuario_id
             ORDER BY c.id DESC LIMIT ? OFFSET ?"
        );
        $st->bindValue(1, $limit, \PDO::PARAM_INT);
        $st->bindValue(2, $offset, \PDO::PARAM_INT);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }
}

<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Datos de empleados (usuarios del sistema) para el gestor de personal:
 * rendimiento en ventas + pagos de sueldo (que son gastos con usuario_id).
 */
class EmpleadoRepository
{
    /** Lista de empleados con su rendimiento del mes actual y lo pagado este mes. */
    public function listar(): array
    {
        $sql = "SELECT u.id, u.nombre, u.email, u.activo, r.nombre AS rol,
                    (SELECT COUNT(*) FROM venta v
                      WHERE v.usuario_id = u.id AND v.estado = 'registrada'
                        AND v.creado_en >= DATE_FORMAT(CURDATE(),'%Y-%m-01')) AS ventas_mes,
                    (SELECT COALESCE(SUM(v.total),0) FROM venta v
                      WHERE v.usuario_id = u.id AND v.estado = 'registrada'
                        AND v.creado_en >= DATE_FORMAT(CURDATE(),'%Y-%m-01')) AS monto_mes,
                    (SELECT COALESCE(SUM(g.monto),0) FROM gasto g
                      WHERE g.usuario_id = u.id AND g.activo = 1
                        AND g.periodo = DATE_FORMAT(CURDATE(),'%Y-%m')) AS pagado_mes
                FROM usuario u JOIN rol r ON r.id = u.rol_id
                ORDER BY u.activo DESC, monto_mes DESC, u.nombre";
        return Database::conexion()->query($sql)->fetchAll();
    }

    /** Datos básicos de un empleado (para encabezar el detalle / PDF). */
    public function info(int $id): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT u.id, u.nombre, u.email, u.activo, u.creado_en, r.nombre AS rol
             FROM usuario u JOIN rol r ON r.id = u.rol_id WHERE u.id = ?"
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Rendimiento de ventas de un empleado en un periodo [desde, hasta).
     * $desde/$hasta son fechas 'YYYY-MM-DD'. Solo ventas registradas.
     */
    public function rendimiento(int $id, string $desde, string $hasta): array
    {
        $pdo = Database::conexion();
        $st = $pdo->prepare(
            "SELECT COUNT(*) AS ventas, COALESCE(SUM(total),0) AS monto
             FROM venta WHERE usuario_id = ? AND estado = 'registrada'
               AND creado_en >= ? AND creado_en < ?"
        );
        $st->execute([$id, $desde, $hasta]);
        $r = $st->fetch();

        $stU = $pdo->prepare(
            "SELECT COALESCE(SUM(vd.cantidad),0) AS unidades
             FROM venta_detalle vd JOIN venta v ON v.id = vd.venta_id
             WHERE v.usuario_id = ? AND v.estado = 'registrada'
               AND v.creado_en >= ? AND v.creado_en < ?"
        );
        $stU->execute([$id, $desde, $hasta]);

        $ventas = (int) $r['ventas'];
        $monto  = (float) $r['monto'];
        return [
            'ventas'   => $ventas,
            'monto'    => $monto,
            'unidades' => (int) $stU->fetchColumn(),
            'ticket'   => $ventas > 0 ? round($monto / $ventas, 2) : 0.0,
        ];
    }

    /** Monto facturado por mes (últimos N meses) para el mini-gráfico. */
    public function serieMensual(int $id, int $meses = 6): array
    {
        $st = Database::conexion()->prepare(
            "SELECT DATE_FORMAT(creado_en,'%Y-%m') AS mes, COALESCE(SUM(total),0) AS monto
             FROM venta WHERE usuario_id = ? AND estado = 'registrada'
               AND creado_en >= DATE_FORMAT(CURDATE() - INTERVAL ? MONTH, '%Y-%m-01')
             GROUP BY mes ORDER BY mes"
        );
        $st->execute([$id, $meses - 1]);
        return $st->fetchAll();
    }

    /** Pagos de sueldo del empleado (gastos con su usuario_id). */
    public function pagos(int $id): array
    {
        $st = Database::conexion()->prepare(
            "SELECT id, fecha, periodo, monto, observacion
             FROM gasto WHERE usuario_id = ? AND activo = 1
             ORDER BY fecha DESC, id DESC"
        );
        $st->execute([$id]);
        return $st->fetchAll();
    }

    /** ¿Ya hay un pago cargado para ese empleado y periodo 'YYYY-MM'? */
    public function pagoDePeriodo(int $id, string $periodo): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT id, monto, fecha FROM gasto
             WHERE usuario_id = ? AND periodo = ? AND activo = 1 LIMIT 1"
        );
        $st->execute([$id, $periodo]);
        return $st->fetch() ?: null;
    }
}

<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Métricas para el dashboard del admin. Solo lecturas agregadas (COUNT/SUM/AVG).
 * Pensadas para decidir: qué se vendió, qué falta reponer, qué requiere acción.
 */
class DashboardRepository
{
    private const UMBRAL_STOCK_BAJO = 5;
    private const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    private const DIAS  = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];

    private function unaFila(string $sql): array
    {
        return Database::conexion()->query($sql)->fetch() ?: [];
    }

    private function unValor(string $sql)
    {
        return Database::conexion()->query($sql)->fetchColumn();
    }

    /** Ventas de hoy (cantidad y monto), solo registradas (no anuladas). */
    public function ventasHoy(): array
    {
        $r = $this->unaFila(
            "SELECT COUNT(*) AS cantidad, COALESCE(SUM(total),0) AS monto
             FROM venta WHERE estado = 'registrada' AND DATE(creado_en) = CURDATE()"
        );
        return ['cantidad' => (int) ($r['cantidad'] ?? 0), 'monto' => (float) ($r['monto'] ?? 0)];
    }

    public function ventasMes(): float
    {
        return (float) $this->unValor(
            "SELECT COALESCE(SUM(total),0) FROM venta
             WHERE estado = 'registrada'
               AND YEAR(creado_en) = YEAR(CURDATE()) AND MONTH(creado_en) = MONTH(CURDATE())"
        );
    }

    public function ticketPromedioMes(): float
    {
        return (float) $this->unValor(
            "SELECT COALESCE(AVG(total),0) FROM venta
             WHERE estado = 'registrada'
               AND YEAR(creado_en) = YEAR(CURDATE()) AND MONTH(creado_en) = MONTH(CURDATE())"
        );
    }

    public function pedidosPendientes(): int
    {
        return (int) $this->unValor("SELECT COUNT(*) FROM pedido WHERE estado = 'pendiente'");
    }

    public function solicitudesPendientes(): int
    {
        return (int) $this->unValor("SELECT COUNT(*) FROM solicitud_mayorista WHERE estado = 'pendiente'");
    }

    /** Productos activos con stock <= umbral (no sobre pedido). Para reponer. */
    public function stockBajo(): array
    {
        $sql = "SELECT p.id, p.nombre, COALESCE(i.cantidad,0) AS stock
                FROM producto p
                LEFT JOIN inventario i ON i.producto_id = p.id
                WHERE p.activo = 1 AND p.es_sobre_pedido = 0 AND COALESCE(i.cantidad,0) <= " . self::UMBRAL_STOCK_BAJO . "
                ORDER BY stock ASC, p.nombre
                LIMIT 8";
        return Database::conexion()->query($sql)->fetchAll();
    }

    public function sinStock(): int
    {
        return (int) $this->unValor(
            "SELECT COUNT(*) FROM producto p
             LEFT JOIN inventario i ON i.producto_id = p.id
             WHERE p.activo = 1 AND p.es_sobre_pedido = 0 AND COALESCE(i.cantidad,0) = 0"
        );
    }

    /** Top productos por unidades vendidas en los últimos 30 días. */
    public function topProductos(): array
    {
        $sql = "SELECT pr.nombre, SUM(d.cantidad) AS unidades, SUM(d.subtotal) AS monto
                FROM venta_detalle d
                JOIN venta v   ON v.id = d.venta_id AND v.estado = 'registrada'
                JOIN producto pr ON pr.id = d.producto_id
                WHERE v.creado_en >= (CURDATE() - INTERVAL 30 DAY)
                GROUP BY d.producto_id, pr.nombre
                ORDER BY unidades DESC
                LIMIT 5";
        return Database::conexion()->query($sql)->fetchAll();
    }

    /** Monto vendido por día en los últimos 7 días (para el mini gráfico). */
    public function ventas7Dias(): array
    {
        $sql = "SELECT DATE(creado_en) AS dia, COALESCE(SUM(total),0) AS monto
                FROM venta
                WHERE estado = 'registrada' AND creado_en >= (CURDATE() - INTERVAL 6 DAY)
                GROUP BY DATE(creado_en)";
        $porDia = [];
        foreach (Database::conexion()->query($sql)->fetchAll() as $r) {
            $porDia[$r['dia']] = (float) $r['monto'];
        }
        // Completar los 7 días (incluidos los que no tuvieron ventas).
        $serie = [];
        for ($i = 6; $i >= 0; $i--) {
            $fecha = date('Y-m-d', strtotime("-$i day"));
            $serie[] = ['dia' => $fecha, 'monto' => $porDia[$fecha] ?? 0.0];
        }
        return $serie;
    }

    /** Monto de pedidos online del mes (no cancelados). */
    public function ventasOnlineMes(): float
    {
        return (float) $this->unValor(
            "SELECT COALESCE(SUM(total),0) FROM pedido
             WHERE estado <> 'cancelado'
               AND YEAR(creado_en) = YEAR(CURDATE()) AND MONTH(creado_en) = MONTH(CURDATE())"
        );
    }

    /** Envíos de moto ACTIVOS sin repartidor asignado (para el aviso de Repartos). */
    public function enviosSinAsignar(): int
    {
        return (int) $this->unValor(
            "SELECT COUNT(*) FROM envio e JOIN barrio b ON b.id = e.barrio_id
             WHERE e.repartidor_id IS NULL AND e.estado IN ('pendiente','despachado','en_camino')"
        );
    }

    /** Ventas de la página (tienda online) de HOY: cantidad de pedidos + monto. */
    public function ventasOnlineHoy(): array
    {
        $r = $this->unaFila(
            "SELECT COUNT(*) AS cantidad, COALESCE(SUM(total),0) AS monto
             FROM pedido WHERE estado <> 'cancelado' AND DATE(creado_en) = CURDATE()"
        );
        return ['cantidad' => (int) ($r['cantidad'] ?? 0), 'monto' => (float) ($r['monto'] ?? 0)];
    }

    /**
     * Serie de ventas para el gráfico, con el eje según el período elegido, y
     * separando ventas FÍSICAS (POS, tabla venta) de ONLINE (tienda, tabla pedido).
     * @param string $periodo  'semana' (7 días) | 'mes' (4 semanas) | 'ano' (12 meses)
     * @return array<int,array{label:string,fisica:float,online:float}>
     */
    public function serieVentas(string $periodo = 'semana'): array
    {
        // Armamos los "baldes" en PHP (con su etiqueta) y una expresión de
        // agrupación en SQL que produzca la MISMA clave para poder mapearlos.
        $buckets = [];
        if ($periodo === 'ano') {
            for ($i = 11; $i >= 0; $i--) {
                $t = strtotime("first day of -$i month");
                $buckets[date('Y-m', $t)] = ['label' => self::MESES[(int) date('n', $t) - 1], 'fisica' => 0.0, 'online' => 0.0];
            }
            $grp = "DATE_FORMAT(creado_en,'%Y-%m')";
            $desde = date('Y-m-01', strtotime('first day of -11 month'));
        } elseif ($periodo === 'mes') {
            for ($i = 3; $i >= 0; $i--) {
                $t = strtotime("monday -$i week");
                $buckets[date('oW', $t)] = ['label' => 'Sem ' . date('d/m', $t), 'fisica' => 0.0, 'online' => 0.0];
            }
            $grp = "DATE_FORMAT(creado_en,'%x%v')";   // %x%v = año-semana ISO (igual que date('oW'))
            $desde = date('Y-m-d', strtotime('monday -3 week'));
        } else { // semana
            for ($i = 6; $i >= 0; $i--) {
                $t = strtotime("-$i day");
                $buckets[date('Y-m-d', $t)] = ['label' => self::DIAS[(int) date('w', $t)] . ' ' . date('d', $t), 'fisica' => 0.0, 'online' => 0.0];
            }
            $grp = "DATE(creado_en)";
            $desde = date('Y-m-d', strtotime('-6 day'));
        }

        $this->sumarEn($buckets, 'fisica',
            "SELECT $grp AS k, COALESCE(SUM(total),0) AS m FROM venta
             WHERE estado = 'registrada' AND creado_en >= ? GROUP BY k", $desde);
        $this->sumarEn($buckets, 'online',
            "SELECT $grp AS k, COALESCE(SUM(total),0) AS m FROM pedido
             WHERE estado <> 'cancelado' AND creado_en >= ? GROUP BY k", $desde);

        return array_values($buckets);
    }

    /** Corre una consulta agrupada y suma sus montos en el balde correspondiente. */
    private function sumarEn(array &$buckets, string $campo, string $sql, string $desde): void
    {
        $st = Database::conexion()->prepare($sql);
        $st->execute([$desde]);
        foreach ($st->fetchAll() as $r) {
            $k = (string) $r['k'];
            if (isset($buckets[$k])) { $buckets[$k][$campo] = (float) $r['m']; }
        }
    }

    /** Como sumarEn, pero ACUMULA (+=) — para juntar varias fuentes en un mismo campo. */
    private function acumularEn(array &$buckets, string $campo, string $sql, string $desde): void
    {
        $st = Database::conexion()->prepare($sql);
        $st->execute([$desde]);
        foreach ($st->fetchAll() as $r) {
            $k = (string) $r['k'];
            if (isset($buckets[$k])) { $buckets[$k][$campo] += (float) $r['m']; }
        }
    }

    /** Ingresos (ventas físicas + online) vs Gastos, por mes, últimos 6 meses. */
    public function serieFinanzas(): array
    {
        $buckets = [];
        for ($i = 5; $i >= 0; $i--) {
            $t = strtotime("first day of -$i month");
            $buckets[date('Y-m', $t)] = ['label' => self::MESES[(int) date('n', $t) - 1], 'ingresos' => 0.0, 'gastos' => 0.0];
        }
        $desde = date('Y-m-01', strtotime('first day of -5 month'));

        $this->acumularEn($buckets, 'ingresos',
            "SELECT DATE_FORMAT(creado_en,'%Y-%m') AS k, COALESCE(SUM(total),0) AS m FROM venta
             WHERE estado = 'registrada' AND creado_en >= ? GROUP BY k", $desde);
        $this->acumularEn($buckets, 'ingresos',
            "SELECT DATE_FORMAT(creado_en,'%Y-%m') AS k, COALESCE(SUM(total),0) AS m FROM pedido
             WHERE estado <> 'cancelado' AND creado_en >= ? GROUP BY k", $desde);
        $this->acumularEn($buckets, 'gastos',
            "SELECT DATE_FORMAT(fecha,'%Y-%m') AS k, COALESCE(SUM(monto),0) AS m FROM gasto
             WHERE activo = 1 AND fecha >= ? GROUP BY k", $desde);

        return array_values($buckets);
    }

    /** Ventas físicas por categoría (monto), últimos 90 días. Top 6. */
    public function ventasPorCategoria(): array
    {
        $sql = "SELECT COALESCE(c.nombre,'(sin categoría)') AS categoria, COALESCE(SUM(d.subtotal),0) AS monto
                FROM venta_detalle d
                JOIN venta v    ON v.id = d.venta_id AND v.estado = 'registrada'
                JOIN producto p ON p.id = d.producto_id
                LEFT JOIN categoria c ON c.id = p.categoria_id
                WHERE v.creado_en >= (CURDATE() - INTERVAL 90 DAY)
                GROUP BY c.id, categoria
                ORDER BY monto DESC
                LIMIT 6";
        return Database::conexion()->query($sql)->fetchAll();
    }

    /** Últimos gastos cargados (para el panel de finanzas del dashboard). */
    public function gastosRecientes(): array
    {
        $sql = "SELECT g.fecha, g.concepto, g.monto, g.cantidad,
                       pv.nombre AS proveedor, pr.nombre AS producto
                FROM gasto g
                LEFT JOIN proveedor pv ON pv.id = g.proveedor_id
                LEFT JOIN producto  pr ON pr.id = g.producto_id
                WHERE g.activo = 1
                ORDER BY g.fecha DESC, g.id DESC
                LIMIT 6";
        return Database::conexion()->query($sql)->fetchAll();
    }

    /** Ventas físicas por vendedor este mes (top 5). */
    public function ventasPorVendedor(): array
    {
        $sql = "SELECT u.nombre AS vendedor, COUNT(*) AS ventas, COALESCE(SUM(v.total),0) AS monto
                FROM venta v JOIN usuario u ON u.id = v.usuario_id
                WHERE v.estado = 'registrada'
                  AND YEAR(v.creado_en) = YEAR(CURDATE()) AND MONTH(v.creado_en) = MONTH(CURDATE())
                GROUP BY v.usuario_id, u.nombre
                ORDER BY monto DESC LIMIT 5";
        return Database::conexion()->query($sql)->fetchAll();
    }

    /** Compara lo facturado (físicas) este mes vs. el mes anterior. */
    public function comparativaMes(): array
    {
        $r = $this->unaFila(
            "SELECT
                COALESCE(SUM(CASE WHEN creado_en >= DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN total END),0) AS actual,
                COALESCE(SUM(CASE WHEN creado_en >= DATE_FORMAT(CURDATE() - INTERVAL 1 MONTH,'%Y-%m-01')
                                   AND creado_en <  DATE_FORMAT(CURDATE(),'%Y-%m-01') THEN total END),0) AS anterior
             FROM venta WHERE estado = 'registrada'"
        );
        $act = (float) ($r['actual'] ?? 0);
        $ant = (float) ($r['anterior'] ?? 0);
        $var = $ant > 0 ? round(($act - $ant) / $ant * 100, 1) : null;   // % variación (null si no hay base)
        return ['actual' => $act, 'anterior' => $ant, 'variacion' => $var];
    }

    /** Productos activos SIN ventas en los últimos 30 días (para revisar rotación). */
    public function sinMovimiento(): array
    {
        $sql = "SELECT p.id, p.nombre, COALESCE(i.cantidad,0) AS stock
                FROM producto p
                LEFT JOIN inventario i ON i.producto_id = p.id
                WHERE p.activo = 1
                  AND p.id NOT IN (
                    SELECT DISTINCT d.producto_id FROM venta_detalle d
                    JOIN venta v ON v.id = d.venta_id
                    WHERE v.estado = 'registrada' AND v.creado_en >= (CURDATE() - INTERVAL 30 DAY)
                  )
                ORDER BY p.nombre LIMIT 8";
        $filas = Database::conexion()->query($sql)->fetchAll();
        $total = (int) $this->unValor(
            "SELECT COUNT(*) FROM producto p WHERE p.activo = 1 AND p.id NOT IN (
                SELECT DISTINCT d.producto_id FROM venta_detalle d JOIN venta v ON v.id = d.venta_id
                WHERE v.estado = 'registrada' AND v.creado_en >= (CURDATE() - INTERVAL 30 DAY))"
        );
        return ['total' => $total, 'lista' => $filas];
    }

    /**
     * Totales históricos del negocio (finanzas): clientes, ventas físicas + online,
     * total generado (ingresos), total invertido (gastos) y balance.
     */
    public function totales(): array
    {
        $ventas  = $this->unaFila("SELECT COUNT(*) AS c, COALESCE(SUM(total),0) AS m FROM venta WHERE estado = 'registrada'");
        $pedidos = $this->unaFila("SELECT COUNT(*) AS c, COALESCE(SUM(total),0) AS m FROM pedido WHERE estado <> 'cancelado'");
        $clientes = (int) $this->unValor("SELECT COUNT(*) FROM cliente");
        $invertido = (float) $this->unValor("SELECT COALESCE(SUM(monto),0) FROM gasto WHERE activo = 1");

        $generado = (float) $ventas['m'] + (float) $pedidos['m'];
        return [
            'clientes'      => $clientes,
            'ventas_cant'   => (int) $ventas['c'],
            'ventas_monto'  => (float) $ventas['m'],
            'pedidos_cant'  => (int) $pedidos['c'],
            'pedidos_monto' => (float) $pedidos['m'],
            'generado'      => $generado,
            'invertido'     => $invertido,
            'balance'       => $generado - $invertido,
        ];
    }

    public function clientes(): array
    {
        $total = (int) $this->unValor("SELECT COUNT(*) FROM cliente WHERE activo = 1");
        $nuevos = (int) $this->unValor(
            "SELECT COUNT(*) FROM cliente
             WHERE activo = 1 AND YEAR(creado_en) = YEAR(CURDATE()) AND MONTH(creado_en) = MONTH(CURDATE())"
        );
        return ['total' => $total, 'nuevos_mes' => $nuevos];
    }
}

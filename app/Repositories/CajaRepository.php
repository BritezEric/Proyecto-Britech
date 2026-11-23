<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Caja del vendedor: apertura, movimientos (retiro/ingreso/gasto) y cierre/arqueo.
 * Efectivo = tipo_pago 'Efectivo' (es lo que entra al arqueo físico).
 */
class CajaRepository
{
    private static ?bool $esquemaOk = null;

    /**
     * ¿La base tiene el script del cierre de caja v2 (schema_caja_cierre.sql)?
     * Si alguien actualiza el código pero no la base, la caja avisa en vez de romperse.
     */
    public static function esquemaListo(): bool
    {
        if (self::$esquemaOk === null) {
            $st = Database::conexion()->query(
                "SELECT (SELECT COUNT(*) FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja_cierre_forma')
                      + (SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'caja' AND COLUMN_NAME IN ('conteo_efectivo', 'cerrada_por'))"
            );
            self::$esquemaOk = (int) $st->fetchColumn() === 3;
        }
        return self::$esquemaOk;
    }

    /** Caja abierta de un usuario (o null). */
    public function abiertaDe(int $usuarioId): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT c.*, u.nombre AS vendedor FROM caja c JOIN usuario u ON u.id = c.usuario_id
             WHERE c.usuario_id = ? AND c.estado = 'abierta' ORDER BY c.id DESC LIMIT 1"
        );
        $st->execute([$usuarioId]);
        return $st->fetch() ?: null;
    }

    public function buscarPorId(int $id): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT c.*, u.nombre AS vendedor, uc.nombre AS cerrada_por_nombre
             FROM caja c JOIN usuario u ON u.id = c.usuario_id
             LEFT JOIN usuario uc ON uc.id = c.cerrada_por WHERE c.id = ?"
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

    public function agregarMovimiento(int $cajaId, string $tipo, float $monto, ?string $motivo, int $usuarioId, ?int $gastoId = null): int
    {
        $pdo = Database::conexion();
        $pdo->prepare("INSERT INTO caja_movimiento (caja_id, tipo, monto, motivo, gasto_id, usuario_id) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$cajaId, $tipo, $monto, $motivo, $gastoId, $usuarioId]);
        return (int) $pdo->lastInsertId();
    }

    public function movimientosDe(int $cajaId): array
    {
        $st = Database::conexion()->prepare(
            "SELECT m.id, m.tipo, m.monto, m.motivo, m.gasto_id, m.creado_en, u.nombre AS usuario
             FROM caja_movimiento m JOIN usuario u ON u.id = m.usuario_id
             WHERE m.caja_id = ? ORDER BY m.creado_en, m.id"
        );
        $st->execute([$cajaId]);
        return $st->fetchAll();
    }

    /** Formas de pago activas (para mostrar todas en el resumen, aunque estén en 0). */
    public function formasDePago(): array
    {
        return Database::conexion()
            ->query("SELECT id, nombre FROM tipo_pago WHERE activo = 1 ORDER BY id")
            ->fetchAll();
    }

    /**
     * Totales por forma de pago de las ventas NO anuladas de un conjunto de cajas.
     * @param int[] $cajaIds
     * @return array<int, array{tipo_pago_id:int, nombre:string, es_efectivo:bool, operaciones:int, total:float}>
     */
    public function totalesPorForma(array $cajaIds): array
    {
        $formas = [];
        foreach ($this->formasDePago() as $f) {
            $formas[(int) $f['id']] = $this->filaForma((int) $f['id'], (string) $f['nombre']);
        }
        if ($cajaIds) {
            $in = implode(',', array_fill(0, count($cajaIds), '?'));
            $st = Database::conexion()->prepare(
                "SELECT tp.id, tp.nombre, COUNT(*) AS operaciones, COALESCE(SUM(pg.monto), 0) AS total
                 FROM pago pg
                 JOIN venta v      ON v.id = pg.venta_id
                 JOIN tipo_pago tp ON tp.id = pg.tipo_pago_id
                 WHERE v.caja_id IN ($in) AND v.estado <> 'anulada'
                 GROUP BY tp.id, tp.nombre"
            );
            $st->execute(array_values($cajaIds));
            foreach ($st->fetchAll() as $r) {
                $id = (int) $r['id'];
                $formas[$id] ??= $this->filaForma($id, (string) $r['nombre']);   // forma ya desactivada pero usada
                $formas[$id]['operaciones'] = (int) $r['operaciones'];
                $formas[$id]['total'] = round((float) $r['total'], 2);
            }
        }
        // Efectivo primero, después el resto por id.
        $formas = array_values($formas);
        usort($formas, fn($a, $b) => [(int) !$a['es_efectivo'], $a['tipo_pago_id']] <=> [(int) !$b['es_efectivo'], $b['tipo_pago_id']]);
        return $formas;
    }

    private function filaForma(int $id, string $nombre): array
    {
        return [
            'tipo_pago_id' => $id,
            'nombre'       => $nombre,
            'es_efectivo'  => mb_strtolower(trim($nombre)) === 'efectivo',
            'operaciones'  => 0,
            'total'        => 0.0,
        ];
    }

    /**
     * Resumen de una caja: totales por forma de pago + ventas + movimientos manuales.
     * Mantiene las claves viejas (efectivo/transferencia/otros) por compatibilidad.
     */
    public function resumen(int $cajaId): array
    {
        return $this->resumenDe([$cajaId]);
    }

    /** Igual que resumen() pero para varias cajas (cierre del día). */
    public function resumenDe(array $cajaIds): array
    {
        $pdo = Database::conexion();
        $formas = $this->totalesPorForma($cajaIds);

        $efectivo = 0.0; $transferencia = 0.0; $otros = 0.0;
        foreach ($formas as $f) {
            $n = mb_strtolower($f['nombre']);
            if ($f['es_efectivo'])          $efectivo += $f['total'];
            elseif ($n === 'transferencia') $transferencia += $f['total'];
            else                            $otros += $f['total'];
        }

        $base = ['ventas' => 0, 'total_ventas' => 0.0, 'subtotal' => 0.0, 'descuentos' => 0.0,
                 'anuladas' => 0, 'total_anulado' => 0.0, 'retiros' => 0.0, 'ingresos' => 0.0, 'gastos' => 0.0,
                 'mov_retiros' => 0, 'mov_ingresos' => 0, 'mov_gastos' => 0];

        if ($cajaIds) {
            $in = implode(',', array_fill(0, count($cajaIds), '?'));
            $ids = array_values($cajaIds);

            $st = $pdo->prepare(
                "SELECT
                    SUM(estado <> 'anulada')                                   AS ventas,
                    COALESCE(SUM(CASE WHEN estado <> 'anulada' THEN total END), 0)     AS total_ventas,
                    COALESCE(SUM(CASE WHEN estado <> 'anulada' THEN subtotal END), 0)  AS subtotal,
                    COALESCE(SUM(CASE WHEN estado <> 'anulada' THEN descuento END), 0) AS descuentos,
                    SUM(estado = 'anulada')                                    AS anuladas,
                    COALESCE(SUM(CASE WHEN estado = 'anulada' THEN total END), 0)      AS total_anulado
                 FROM venta WHERE caja_id IN ($in)"
            );
            $st->execute($ids);
            $v = $st->fetch() ?: [];
            $base['ventas']        = (int) ($v['ventas'] ?? 0);
            $base['total_ventas']  = round((float) ($v['total_ventas'] ?? 0), 2);
            $base['subtotal']      = round((float) ($v['subtotal'] ?? 0), 2);
            $base['descuentos']    = round((float) ($v['descuentos'] ?? 0), 2);
            $base['anuladas']      = (int) ($v['anuladas'] ?? 0);
            $base['total_anulado'] = round((float) ($v['total_anulado'] ?? 0), 2);

            $stM = $pdo->prepare(
                "SELECT tipo, COUNT(*) AS n, COALESCE(SUM(monto), 0) AS total
                 FROM caja_movimiento WHERE caja_id IN ($in) GROUP BY tipo"
            );
            $stM->execute($ids);
            foreach ($stM->fetchAll() as $r) {
                $k = ['retiro' => 'retiros', 'ingreso' => 'ingresos', 'gasto' => 'gastos'][$r['tipo']] ?? null;
                if ($k === null) continue;
                $base[$k] = round((float) $r['total'], 2);
                $base['mov_' . $k] = (int) $r['n'];
            }
        }

        return $base + [
            'formas'          => $formas,
            'efectivo'        => round($efectivo, 2),
            'transferencia'   => round($transferencia, 2),
            'otros'           => round($otros, 2),
            'ticket_promedio' => $base['ventas'] > 0 ? round($base['total_ventas'] / $base['ventas'], 2) : 0.0,
        ];
    }

    /** Ventas de la caja (todas, incluidas anuladas) con sus formas de pago. */
    public function ventasDe(int $cajaId): array
    {
        $pdo = Database::conexion();
        $st = $pdo->prepare(
            "SELECT v.id, v.numero, v.total, v.descuento, v.estado, v.creado_en,
                    COALESCE(c.nombre, '') AS cliente,
                    va.creado_en AS anulada_en, va.motivo AS motivo_anulacion
             FROM venta v
             LEFT JOIN cliente c ON c.id = v.cliente_id
             LEFT JOIN venta_anulacion va ON va.venta_id = v.id
             WHERE v.caja_id = ? ORDER BY v.creado_en, v.id"
        );
        $st->execute([$cajaId]);
        $ventas = $st->fetchAll();
        if (!$ventas) return [];

        $ids = array_column($ventas, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $stP = $pdo->prepare(
            "SELECT pg.venta_id, tp.nombre, pg.monto FROM pago pg
             JOIN tipo_pago tp ON tp.id = pg.tipo_pago_id WHERE pg.venta_id IN ($in) ORDER BY pg.id"
        );
        $stP->execute($ids);
        $pagos = [];
        foreach ($stP->fetchAll() as $p) $pagos[(int) $p['venta_id']][] = $p;

        foreach ($ventas as &$v) $v['pagos'] = $pagos[(int) $v['id']] ?? [];
        return $ventas;
    }

    /** Snapshot por forma de pago guardado al cerrar (vacío si la caja sigue abierta). */
    public function cierrePorForma(int $cajaId): array
    {
        $st = Database::conexion()->prepare(
            "SELECT tipo_pago_id, nombre, operaciones, esperado, declarado, diferencia
             FROM caja_cierre_forma WHERE caja_id = ? ORDER BY id"
        );
        $st->execute([$cajaId]);
        return $st->fetchAll();
    }

    /**
     * Cierra la caja y guarda la foto por forma de pago. Se llama dentro de una
     * transacción (la abre el service).
     */
    /** @return bool false si la caja ya no estaba abierta (alguien la cerró antes). */
    public function cerrar(int $cajaId, float $contado, ?array $conteo, float $esperado, float $diferencia, ?string $observacion, array $formas, int $cerradaPor): bool
    {
        $pdo = Database::conexion();
        $up = $pdo->prepare(
            "UPDATE caja SET estado = 'cerrada', monto_contado = ?, conteo_efectivo = ?, monto_esperado = ?,
                    diferencia = ?, observacion = ?, cerrada_por = ?, cerrada_en = NOW() WHERE id = ? AND estado = 'abierta'"
        );
        $up->execute([$contado, $conteo ? json_encode($conteo) : null, $esperado, $diferencia, $observacion, $cerradaPor, $cajaId]);
        if ($up->rowCount() === 0) return false;

        $st = $pdo->prepare(
            "INSERT INTO caja_cierre_forma (caja_id, tipo_pago_id, nombre, operaciones, esperado, declarado, diferencia)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($formas as $f) {
            $st->execute([$cajaId, $f['tipo_pago_id'], $f['nombre'], $f['operaciones'], $f['esperado'], $f['declarado'], $f['diferencia']]);
        }
        return true;
    }

    /** Historial de cajas (admin) con filtros, nombre del vendedor y total vendido. Paginado. */
    public function listarPaginado(int $limit, int $offset, array $filtros = []): array
    {
        $pdo = Database::conexion();
        [$where, $params] = $this->filtrosSql($filtros);

        $stT = $pdo->prepare("SELECT COUNT(*) FROM caja c $where");
        $stT->execute($params);
        $total = (int) $stT->fetchColumn();

        $st = $pdo->prepare(
            "SELECT c.id, c.estado, c.monto_apertura, c.monto_contado, c.monto_esperado, c.diferencia,
                    c.abierta_en, c.cerrada_en, u.nombre AS vendedor,
                    " . self::CON_DIFERENCIA . " AS con_diferencia,
                    (SELECT COALESCE(SUM(f.diferencia), 0) FROM caja_cierre_forma f WHERE f.caja_id = c.id) AS diferencia_formas,
                    (SELECT COUNT(*) FROM venta v WHERE v.caja_id = c.id AND v.estado <> 'anulada') AS ventas,
                    (SELECT COALESCE(SUM(v.total), 0) FROM venta v WHERE v.caja_id = c.id AND v.estado <> 'anulada') AS total_ventas
             FROM caja c JOIN usuario u ON u.id = c.usuario_id
             $where
             ORDER BY c.id DESC LIMIT ? OFFSET ?"
        );
        $i = 1;
        foreach ($params as $p) $st->bindValue($i++, $p);
        $st->bindValue($i++, $limit, \PDO::PARAM_INT);
        $st->bindValue($i, $offset, \PDO::PARAM_INT);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** @return array{0:string,1:array} */
    private function filtrosSql(array $f): array
    {
        $w = []; $p = [];
        if (!empty($f['desde']))      { $w[] = 'DATE(c.abierta_en) >= ?'; $p[] = $f['desde']; }
        if (!empty($f['hasta']))      { $w[] = 'DATE(c.abierta_en) <= ?'; $p[] = $f['hasta']; }
        if (!empty($f['usuario_id'])) { $w[] = 'c.usuario_id = ?';        $p[] = (int) $f['usuario_id']; }
        if (!empty($f['estado']))     { $w[] = 'c.estado = ?';            $p[] = $f['estado']; }
        if (!empty($f['con_diferencia'])) { $w[] = self::CON_DIFERENCIA; }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }

    /** Una caja "no cuadró" si difiere el efectivo o cualquier otra forma de pago. */
    private const CON_DIFERENCIA = "(c.estado = 'cerrada' AND (ABS(COALESCE(c.diferencia, 0)) >= 0.01
        OR EXISTS (SELECT 1 FROM caja_cierre_forma f WHERE f.caja_id = c.id AND ABS(f.diferencia) >= 0.01)))";

    /** Totales del listado filtrado (franja de resumen del admin). */
    public function resumenListado(array $filtros): array
    {
        [$where, $params] = $this->filtrosSql($filtros);
        $st = Database::conexion()->prepare(
            "SELECT COUNT(*) AS cajas,
                    COALESCE(SUM(c.estado = 'abierta'), 0) AS abiertas,
                    COALESCE(SUM(" . self::CON_DIFERENCIA . "), 0) AS con_diferencia,
                    COALESCE(SUM(c.diferencia), 0) AS diferencia_efectivo,
                    COALESCE(SUM((SELECT COALESCE(SUM(v.total), 0) FROM venta v WHERE v.caja_id = c.id AND v.estado <> 'anulada')), 0) AS total_ventas
             FROM caja c $where"
        );
        $st->execute($params);
        $r = $st->fetch() ?: [];
        return [
            'cajas'               => (int) ($r['cajas'] ?? 0),
            'abiertas'            => (int) ($r['abiertas'] ?? 0),
            'con_diferencia'      => (int) ($r['con_diferencia'] ?? 0),
            'diferencia_efectivo' => round((float) ($r['diferencia_efectivo'] ?? 0), 2),
            'total_ventas'        => round((float) ($r['total_ventas'] ?? 0), 2),
        ];
    }

    /** Cajas que siguen abiertas hace más de $horas (probablemente olvidadas). */
    public function abiertasHaceMas(int $horas): array
    {
        $st = Database::conexion()->prepare(
            "SELECT c.id, c.abierta_en, u.nombre AS vendedor,
                    TIMESTAMPDIFF(HOUR, c.abierta_en, NOW()) AS horas
             FROM caja c JOIN usuario u ON u.id = c.usuario_id
             WHERE c.estado = 'abierta' AND c.abierta_en < NOW() - INTERVAL ? HOUR
             ORDER BY c.abierta_en"
        );
        $st->bindValue(1, $horas, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /** Último cierre del usuario (para sugerir el monto de apertura). */
    public function ultimoCierreDe(int $usuarioId): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT id, monto_contado, cerrada_en FROM caja
             WHERE usuario_id = ? AND estado = 'cerrada' ORDER BY cerrada_en DESC, id DESC LIMIT 1"
        );
        $st->execute([$usuarioId]);
        return $st->fetch() ?: null;
    }

    /** Vendedores que alguna vez abrieron caja (para el filtro del admin). */
    public function vendedores(): array
    {
        return Database::conexion()->query(
            "SELECT DISTINCT u.id, u.nombre FROM caja c JOIN usuario u ON u.id = c.usuario_id ORDER BY u.nombre"
        )->fetchAll();
    }

    /** Cajas abiertas en una fecha (para el cierre del día / Z). */
    public function cajasDelDia(string $fecha): array
    {
        $st = Database::conexion()->prepare(
            "SELECT c.*, u.nombre AS vendedor FROM caja c JOIN usuario u ON u.id = c.usuario_id
             WHERE DATE(c.abierta_en) = ? ORDER BY c.abierta_en, c.id"
        );
        $st->execute([$fecha]);
        return $st->fetchAll();
    }
}

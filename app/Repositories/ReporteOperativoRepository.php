<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Reportes y Control Operativo (Módulo 5 · Salidas): consultas de solo lectura.
 *
 * Cada método devuelve filas al nivel justo para no duplicar importes:
 *  - pagos: una fila por PAGO del POS (una venta pagada con dos medios = 2 filas,
 *    cada una con su monto) y una fila por PEDIDO online (pedido 1:1 envío).
 *  - ventas, cajas, pedidos, reclamos y envíos: una fila por registro; los
 *    "uno a muchos" (pagos, movimientos, mensajes) se resumen en subconsultas
 *    agrupadas antes de unirse, nunca con un JOIN directo que multiplique filas.
 * Las fechas llegan como [desde, hasta) ya validadas por el Service; todo va
 * parametrizado (PDO, sin concatenar valores del usuario).
 */
class ReporteOperativoRepository
{
    private const ENVIO_ACTIVO = "('pendiente','despachado','en_camino')";

    /** Hora del servidor de base de datos (misma referencia que creado_en). */
    public function ahora(): string
    {
        return (string) Database::conexion()->query("SELECT NOW()")->fetchColumn();
    }

    /** Columnas opcionales que agrega schema_reportes_operativos.sql. */
    public function tieneColumna(string $tabla, string $columna): bool
    {
        static $cache = [];
        $k = "$tabla.$columna";
        if (!isset($cache[$k])) {
            $st = Database::conexion()->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $st->execute([$tabla, $columna]);
            $cache[$k] = (bool) $st->fetchColumn();
        }
        return $cache[$k];
    }

    // ---------------------------------------------------------------
    // 1) Medios de pago
    // ---------------------------------------------------------------

    /** Pagos del POS (uno por fila) + pedidos online, creados en el período. */
    public function pagos(string $desde, string $hasta): array
    {
        $st = Database::conexion()->prepare(
            "SELECT 'local' AS canal, v.id AS op_id, v.numero, v.creado_en AS fecha,
                    v.usuario_id AS vendedor_id, u.nombre AS vendedor, c.nombre AS cliente,
                    tp.nombre AS medio_nombre, pg.monto AS importe,
                    v.estado AS estado_op, NULL AS estado_pago
             FROM pago pg
             JOIN venta v      ON v.id  = pg.venta_id
             JOIN tipo_pago tp ON tp.id = pg.tipo_pago_id
             JOIN usuario u    ON u.id  = v.usuario_id
             JOIN cliente c    ON c.id  = v.cliente_id
             WHERE v.creado_en >= ? AND v.creado_en < ?
             UNION ALL
             SELECT 'online', p.id, p.numero, p.creado_en,
                    NULL, NULL, c.nombre,
                    CASE WHEN p.mp_payment_id IS NOT NULL THEN 'Mercado Pago' ELSE p.metodo_pago END,
                    p.total + COALESCE(e.costo, 0),
                    p.estado, p.estado_pago
             FROM pedido p
             JOIN cliente c    ON c.id = p.cliente_id
             LEFT JOIN envio e ON e.pedido_id = p.id
             WHERE p.creado_en >= ? AND p.creado_en < ?
             ORDER BY fecha DESC, numero"
        );
        $st->execute([$desde, $hasta, $desde, $hasta]);
        return $st->fetchAll();
    }

    // ---------------------------------------------------------------
    // 2) Cajas y anulaciones
    // ---------------------------------------------------------------

    /**
     * Cajas abiertas en el período (y, si $incluirAbiertas, las que siguen abiertas
     * aunque sean de antes). Totales por caja en subconsultas agrupadas.
     */
    public function cajas(string $desde, string $hasta, bool $incluirAbiertas, ?int $cajaId, ?int $usuarioId): array
    {
        $where = ["(c.abierta_en >= ? AND c.abierta_en < ?" . ($incluirAbiertas ? " OR c.estado = 'abierta')" : ")")];
        $params = [$desde, $hasta];
        if ($cajaId !== null)    { $where[] = 'c.id = ?';         $params[] = $cajaId; }
        if ($usuarioId !== null) { $where[] = 'c.usuario_id = ?'; $params[] = $usuarioId; }

        $st = Database::conexion()->prepare(
            "SELECT c.id, c.usuario_id, u.nombre AS cajero, c.estado, c.abierta_en, c.cerrada_en,
                    c.monto_apertura, c.monto_contado, c.monto_esperado, c.diferencia, c.observacion,
                    COALESCE(ef.efectivo, 0)       AS ventas_efectivo,
                    COALESCE(mv.ingresos, 0)       AS ingresos,
                    COALESCE(mv.retiros, 0)        AS retiros,
                    COALESCE(vt.ventas, 0)         AS ventas,
                    COALESCE(vt.anuladas, 0)       AS anuladas,
                    COALESCE(vt.importe_anulado, 0) AS importe_anulado
             FROM caja c
             JOIN usuario u ON u.id = c.usuario_id
             LEFT JOIN (SELECT v.caja_id, SUM(pg.monto) AS efectivo
                        FROM pago pg
                        JOIN venta v      ON v.id  = pg.venta_id
                        JOIN tipo_pago tp ON tp.id = pg.tipo_pago_id
                        WHERE v.caja_id IS NOT NULL AND v.estado <> 'anulada' AND tp.nombre = 'Efectivo'
                        GROUP BY v.caja_id) ef ON ef.caja_id = c.id
             LEFT JOIN (SELECT caja_id,
                               SUM(CASE WHEN tipo = 'ingreso' THEN monto ELSE 0 END) AS ingresos,
                               SUM(CASE WHEN tipo = 'retiro'  THEN monto ELSE 0 END) AS retiros
                        FROM caja_movimiento GROUP BY caja_id) mv ON mv.caja_id = c.id
             LEFT JOIN (SELECT caja_id,
                               SUM(estado <> 'anulada') AS ventas,
                               SUM(estado = 'anulada')  AS anuladas,
                               SUM(CASE WHEN estado = 'anulada' THEN total ELSE 0 END) AS importe_anulado
                        FROM venta WHERE caja_id IS NOT NULL GROUP BY caja_id) vt ON vt.caja_id = c.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY c.abierta_en DESC, c.id DESC"
        );
        $st->execute($params);
        return $st->fetchAll();
    }

    /**
     * Ventas anuladas cuya anulación cae en el período (si no hay registro de
     * anulación, se usa la fecha de la venta). Las ventas NO se borran: siguen
     * en la tabla con estado 'anulada' y su motivo en venta_anulacion.
     */
    public function anulaciones(string $desde, string $hasta, ?int $cajaId, ?int $usuarioId): array
    {
        $where = ["v.estado = 'anulada'", "COALESCE(va.creado_en, v.creado_en) >= ?", "COALESCE(va.creado_en, v.creado_en) < ?"];
        $params = [$desde, $hasta];
        if ($cajaId !== null)    { $where[] = 'v.caja_id = ?';    $params[] = $cajaId; }
        if ($usuarioId !== null) { $where[] = 'v.usuario_id = ?'; $params[] = $usuarioId; }

        $st = Database::conexion()->prepare(
            "SELECT v.id, v.numero, v.caja_id, v.total, v.creado_en AS fecha_venta,
                    COALESCE(va.creado_en, v.creado_en) AS fecha_anulacion,
                    u.nombre AS vendedor, ua.nombre AS anulado_por, va.motivo, c.nombre AS cliente
             FROM venta v
             JOIN usuario u  ON u.id = v.usuario_id
             JOIN cliente c  ON c.id = v.cliente_id
             LEFT JOIN venta_anulacion va ON va.venta_id = v.id
             LEFT JOIN usuario ua ON ua.id = va.usuario_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY fecha_anulacion DESC"
        );
        $st->execute($params);
        return $st->fetchAll();
    }

    // ---------------------------------------------------------------
    // 3) Vendedores
    // ---------------------------------------------------------------

    /** Ventas del POS del período (registradas y anuladas), con sus medios de pago. */
    public function ventas(string $desde, string $hasta, ?int $vendedorId): array
    {
        $where = ['v.creado_en >= ?', 'v.creado_en < ?'];
        $params = [$desde, $hasta];
        if ($vendedorId !== null) { $where[] = 'v.usuario_id = ?'; $params[] = $vendedorId; }

        $st = Database::conexion()->prepare(
            "SELECT v.id, v.numero, v.creado_en, v.total, v.estado, v.caja_id,
                    v.usuario_id, u.nombre AS vendedor, c.nombre AS cliente,
                    (SELECT GROUP_CONCAT(DISTINCT tp.nombre ORDER BY tp.nombre SEPARATOR ', ')
                       FROM pago pg JOIN tipo_pago tp ON tp.id = pg.tipo_pago_id
                      WHERE pg.venta_id = v.id) AS medios
             FROM venta v
             JOIN usuario u ON u.id = v.usuario_id
             JOIN cliente c ON c.id = v.cliente_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY v.creado_en DESC"
        );
        $st->execute($params);
        return $st->fetchAll();
    }

    // ---------------------------------------------------------------
    // 4) Tienda online
    // ---------------------------------------------------------------

    /** Pedidos del período (y los que siguen activos, si se pide) con su envío 1:1. */
    public function pedidos(string $desde, string $hasta, bool $incluirActivos, ?string $cliente): array
    {
        $act = $this->tieneColumna('pedido', 'actualizado_en') ? 'p.actualizado_en' : 'NULL';
        $where = ["(p.creado_en >= ? AND p.creado_en < ?" . ($incluirActivos ? " OR p.estado IN ('pendiente','preparando'))" : ")")];
        $params = [$desde, $hasta];
        if ($cliente !== null) { $where[] = 'c.nombre LIKE ?'; $params[] = '%' . $cliente . '%'; }

        $st = Database::conexion()->prepare(
            "SELECT p.id, p.numero, p.creado_en, $act AS actualizado_en, p.estado, p.estado_pago,
                    p.metodo_pago, (p.mp_payment_id IS NOT NULL) AS es_mp, p.total,
                    COALESCE(e.costo, 0) AS envio_costo, e.estado AS envio_estado,
                    COALESCE(em.es_retiro, 0) AS es_retiro, COALESCE(em.es_moto, 0) AS es_moto,
                    em.nombre AS empresa, c.id AS cliente_id, c.nombre AS cliente
             FROM pedido p
             JOIN cliente c ON c.id = p.cliente_id
             LEFT JOIN envio e          ON e.pedido_id = p.id
             LEFT JOIN empresa_envio em ON em.id = e.empresa_envio_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY p.creado_en DESC"
        );
        $st->execute($params);
        return $st->fetchAll();
    }

    // ---------------------------------------------------------------
    // 5) Reclamos
    // ---------------------------------------------------------------

    /** Reclamos del período (y los abiertos de antes, si se pide) con su último mensaje. */
    public function reclamos(string $desde, string $hasta, bool $incluirAbiertos, ?string $cliente): array
    {
        $where = ["(r.creado_en >= ? AND r.creado_en < ?" . ($incluirAbiertos ? " OR r.estado IN ('abierto','en_revision'))" : ")")];
        $params = [$desde, $hasta];
        if ($cliente !== null) { $where[] = 'c.nombre LIKE ?'; $params[] = '%' . $cliente . '%'; }

        $st = Database::conexion()->prepare(
            "SELECT r.id, r.numero, r.asunto, r.estado, r.creado_en, r.actualizado_en,
                    r.pedido_id, p.numero AS pedido_numero, c.nombre AS cliente,
                    um.autor AS ultimo_autor, um.creado_en AS ultimo_en, LEFT(um.mensaje, 140) AS ultimo_mensaje,
                    COALESCE(ms.staff, 0) AS respuestas_staff, COALESCE(ms.total, 0) AS mensajes
             FROM reclamo r
             JOIN cliente c ON c.id = r.cliente_id
             JOIN pedido p  ON p.id = r.pedido_id
             LEFT JOIN (SELECT reclamo_id, MAX(id) AS ultimo_id, COUNT(*) AS total, SUM(autor = 'staff') AS staff
                        FROM reclamo_mensaje GROUP BY reclamo_id) ms ON ms.reclamo_id = r.id
             LEFT JOIN reclamo_mensaje um ON um.id = ms.ultimo_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY r.creado_en DESC"
        );
        $st->execute($params);
        return $st->fetchAll();
    }

    // ---------------------------------------------------------------
    // 6) y 7) Envíos de Moto Express (los únicos que llevan repartidor)
    // ---------------------------------------------------------------

    /**
     * Envíos con barrio (Moto Express) creados en el período; con
     * $incluirSinAsignar también los activos sin repartidor de días anteriores.
     */
    public function enviosMoto(string $desde, string $hasta, bool $incluirSinAsignar): array
    {
        $asig = $this->tieneColumna('envio', 'asignado_en') ? 'e.asignado_en' : 'NULL';
        $extra = $incluirSinAsignar ? " OR (e.repartidor_id IS NULL AND e.estado IN " . self::ENVIO_ACTIVO . ")" : '';

        $st = Database::conexion()->prepare(
            "SELECT e.id, e.pedido_id, e.venta_id,
                    COALESCE(p.numero, v.numero) AS numero_op,
                    CASE WHEN e.pedido_id IS NOT NULL THEN 'online' ELSE 'local' END AS canal,
                    COALESCE(cp.nombre, cv.nombre) AS cliente, e.destinatario,
                    e.direccion, e.numero AS altura, e.referencia,
                    e.barrio_id, b.nombre AS barrio, e.creado_en, $asig AS asignado_en,
                    e.estado, e.repartidor_id, r.nombre AS repartidor,
                    ((p.id IS NOT NULL AND p.estado = 'cancelado') OR (v.id IS NOT NULL AND v.estado = 'anulada')) AS op_cancelada
             FROM envio e
             JOIN barrio b         ON b.id  = e.barrio_id
             LEFT JOIN pedido p    ON p.id  = e.pedido_id
             LEFT JOIN venta v     ON v.id  = e.venta_id
             LEFT JOIN cliente cp  ON cp.id = p.cliente_id
             LEFT JOIN cliente cv  ON cv.id = v.cliente_id
             LEFT JOIN repartidor r ON r.id = e.repartidor_id
             WHERE (e.creado_en >= ? AND e.creado_en < ?)$extra
             ORDER BY e.creado_en DESC, e.id DESC"
        );
        $st->execute([$desde, $hasta]);
        return $st->fetchAll();
    }

    // ---------------------------------------------------------------
    // Listas para los filtros
    // ---------------------------------------------------------------

    /** Staff que vendió alguna vez o tiene rol de venta (para filtros de vendedor/cajero). */
    public function usuariosStaff(): array
    {
        return Database::conexion()->query(
            "SELECT u.id, u.nombre FROM usuario u JOIN rol r ON r.id = u.rol_id
             WHERE r.nombre IN ('admin','vendedor') ORDER BY u.nombre"
        )->fetchAll();
    }

    public function barrios(): array
    {
        return Database::conexion()->query("SELECT id, nombre, activo FROM barrio ORDER BY nombre")->fetchAll();
    }

    public function repartidores(): array
    {
        return Database::conexion()->query("SELECT id, nombre, activo FROM repartidor ORDER BY nombre")->fetchAll();
    }

    public function tiposPago(): array
    {
        return Database::conexion()->query("SELECT id, nombre FROM tipo_pago ORDER BY nombre")->fetchAll();
    }
}

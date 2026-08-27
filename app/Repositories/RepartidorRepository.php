<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Repartidores (motoqueros del Moto Express). No son usuarios del sistema.
 * La paga se calcula a partir de los envíos entregados: cada envío vale el
 * costo de su barrio (ver schema_moto_barrios.sql).
 */
class RepartidorRepository
{
    /** @return array{rows: array, total: int} */
    public function listarPaginado(?string $q, ?int $activo, int $limit, int $offset): array
    {
        $pdo = Database::conexion();
        $where = [];
        $params = [];
        if ($q !== null && $q !== '') { $where[] = "nombre LIKE ?"; $params[] = "%{$q}%"; }
        if ($activo !== null)         { $where[] = "activo = ?";    $params[] = $activo; }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stC = $pdo->prepare("SELECT COUNT(*) FROM repartidor $sqlWhere");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        // Métricas de la tarjeta: entregados HOY + a pagar hoy + envíos ACTIVOS (a repartir).
        $sql = "SELECT r.id, r.nombre, r.telefono, r.activo, r.creado_en,
                       COALESCE(hoy.envios, 0)  AS envios_hoy,
                       COALESCE(hoy.total, 0)   AS pago_hoy,
                       COALESCE(act.activos, 0) AS activos
                FROM repartidor r
                LEFT JOIN (
                    SELECT e.repartidor_id, COUNT(*) AS envios, SUM(b.costo) AS total
                    FROM envio e JOIN barrio b ON b.id = e.barrio_id
                    WHERE e.estado = 'entregado' AND DATE(e.creado_en) = CURDATE()
                    GROUP BY e.repartidor_id
                ) hoy ON hoy.repartidor_id = r.id
                LEFT JOIN (
                    SELECT e.repartidor_id, COUNT(*) AS activos
                    FROM envio e
                    WHERE e.estado IN ('pendiente','despachado','en_camino')
                    GROUP BY e.repartidor_id
                ) act ON act.repartidor_id = r.id
                $sqlWhere
                ORDER BY r.activo DESC, r.nombre
                LIMIT ? OFFSET ?";
        $st = $pdo->prepare($sql);
        $full = array_merge($params, [$limit, $offset]);
        foreach ($full as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** Repartidores activos para el desplegable de asignación (gestor de envíos). */
    public function activos(): array
    {
        return Database::conexion()
            ->query("SELECT id, nombre FROM repartidor WHERE activo = 1 ORDER BY nombre")
            ->fetchAll();
    }

    public function buscarPorId(int $id): ?array
    {
        $st = Database::conexion()->prepare("SELECT * FROM repartidor WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function nombreExiste(string $nombre, int $excluirId = 0): bool
    {
        $st = Database::conexion()->prepare("SELECT COUNT(*) FROM repartidor WHERE nombre = ? AND id <> ?");
        $st->execute([$nombre, $excluirId]);
        return (int) $st->fetchColumn() > 0;
    }

    public function crear(string $nombre, ?string $telefono, int $activo): int
    {
        $pdo = Database::conexion();
        $pdo->prepare("INSERT INTO repartidor (nombre, telefono, activo) VALUES (?, ?, ?)")
            ->execute([$nombre, $telefono, $activo]);
        return (int) $pdo->lastInsertId();
    }

    public function actualizar(int $id, string $nombre, ?string $telefono, int $activo): void
    {
        Database::conexion()
            ->prepare("UPDATE repartidor SET nombre = ?, telefono = ?, activo = ? WHERE id = ?")
            ->execute([$nombre, $telefono, $activo, $id]);
    }

    public function cambiarEstado(int $id, int $activo): void
    {
        Database::conexion()->prepare("UPDATE repartidor SET activo = ? WHERE id = ?")->execute([$activo, $id]);
    }

    /**
     * Paga de un repartidor en una fecha: sus envíos ENTREGADOS ese día,
     * agrupados por barrio (cantidad + costo unitario + subtotal).
     * ponytail: usa DATE(envio.creado_en) como día de la entrega (no hay
     * timestamp de "entregado"); para moto local es el mismo día. Si hiciera
     * falta separar día-de-carga vs día-de-entrega, agregar entregado_en.
     */
    public function pagaPorDia(int $repartidorId, string $fecha): array
    {
        $st = Database::conexion()->prepare(
            "SELECT b.nombre AS barrio, b.costo, COUNT(*) AS cantidad, SUM(b.costo) AS subtotal
             FROM envio e JOIN barrio b ON b.id = e.barrio_id
             WHERE e.repartidor_id = ? AND e.estado = 'entregado' AND DATE(e.creado_en) = ?
             GROUP BY b.id, b.nombre, b.costo
             ORDER BY b.nombre"
        );
        $st->execute([$repartidorId, $fecha]);
        return $st->fetchAll();
    }

    // SELECT + JOINs común de un envío de moto (sirve para pedidos de la tienda
    // Y ventas del POS): número, cliente, total, barrio, dirección y productos.
    private const ENVIO_SELECT = "
        SELECT e.id AS envio_id,
               COALESCE(p.numero, v.numero) AS numero,
               COALESCE(p.total, v.total)   AS total,
               COALESCE(cp.nombre, cv.nombre) AS cliente,
               CASE WHEN e.venta_id IS NULL THEN 'pedido' ELSE 'venta' END AS origen,
               b.nombre AS barrio, b.costo AS envio_costo,
               e.destinatario, e.direccion, e.numero AS altura, e.referencia, e.telefono, e.estado,
               COALESCE(
                 (SELECT GROUP_CONCAT(CONCAT(d.cantidad, '× ', pr.nombre) SEPARATOR ', ')
                    FROM pedido_detalle d JOIN producto pr ON pr.id = d.producto_id WHERE d.pedido_id = e.pedido_id),
                 (SELECT GROUP_CONCAT(CONCAT(dv.cantidad, '× ', pr2.nombre) SEPARATOR ', ')
                    FROM venta_detalle dv JOIN producto pr2 ON pr2.id = dv.producto_id WHERE dv.venta_id = e.venta_id)
               ) AS productos
        FROM envio e
        LEFT JOIN pedido p  ON p.id = e.pedido_id
        LEFT JOIN venta v   ON v.id = e.venta_id
        LEFT JOIN cliente cp ON cp.id = p.cliente_id
        LEFT JOIN cliente cv ON cv.id = v.cliente_id
        JOIN barrio b        ON b.id = e.barrio_id";

    // Orden por estado operativo (lo pendiente primero) y luego por número.
    private const ORDEN_ESTADO = "ORDER BY FIELD(e.estado, 'pendiente','despachado','en_camino','entregado','cancelado'), numero";

    /** Envíos DERIVADOS a un repartidor en una fecha (todos los estados). */
    public function enviosDerivados(int $repartidorId, string $fecha): array
    {
        $st = Database::conexion()->prepare(
            self::ENVIO_SELECT . " WHERE e.repartidor_id = ? AND DATE(e.creado_en) = ? " . self::ORDEN_ESTADO
        );
        $st->execute([$repartidorId, $fecha]);
        return $st->fetchAll();
    }

    /** Envíos ACTIVOS (a repartir) de un repartidor, sin importar la fecha. */
    public function enviosActivos(int $repartidorId): array
    {
        $st = Database::conexion()->prepare(
            self::ENVIO_SELECT . " WHERE e.repartidor_id = ?
                AND e.estado IN ('pendiente','despachado','en_camino') " . self::ORDEN_ESTADO
        );
        $st->execute([$repartidorId]);
        return $st->fetchAll();
    }

    /** Envíos de moto ACTIVOS sin repartidor asignado (para derivar). */
    public function sinAsignar(): array
    {
        return Database::conexion()->query(
            self::ENVIO_SELECT . " WHERE e.repartidor_id IS NULL
                AND e.estado IN ('pendiente','despachado','en_camino') " . self::ORDEN_ESTADO
        )->fetchAll();
    }

    /** Total por día (últimos $dias días) para el mini-gráfico del detalle. */
    public function serieDias(int $repartidorId, int $dias = 14): array
    {
        $st = Database::conexion()->prepare(
            "SELECT DATE(e.creado_en) AS dia, COUNT(*) AS envios, SUM(b.costo) AS total
             FROM envio e JOIN barrio b ON b.id = e.barrio_id
             WHERE e.repartidor_id = ? AND e.estado = 'entregado'
               AND e.creado_en >= (CURDATE() - INTERVAL ? DAY)
             GROUP BY DATE(e.creado_en)
             ORDER BY dia"
        );
        $st->bindValue(1, $repartidorId, \PDO::PARAM_INT);
        $st->bindValue(2, $dias, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
}

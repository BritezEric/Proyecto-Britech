<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Reporte de Localidades: SQL de ventas, pedidos y envíos agrupados por zona.
 *
 * Todo parte de UNA subconsulta de "operaciones" (ver base()): una fila por
 * venta del POS o por pedido de la tienda, con su envío pegado. Como envio.venta_id
 * y envio.pedido_id son UNIQUE, el LEFT JOIN es 1:1 y nunca multiplica filas.
 * Los pedidos de la tienda NO generan una venta (son tablas separadas), así que
 * una misma compra no puede contarse dos veces.
 *
 * Importes (siempre sin envío, que se informa aparte):
 *  - venta (POS): subtotal - descuento   (venta.total incluye el envío)
 *  - pedido (tienda): total               (pedido.total es solo productos)
 *
 * Zona de una operación (base "entrega", la de por defecto):
 *  - 'local'     → sin envío o con "Retiro en local" (no hay domicilio de entrega)
 *  - 'barrio'    → envío Moto Express (zona = barrio de la tabla `barrio`)
 *  - 'localidad' → otro medio de envío con localidad escrita en el envío
 *  - 'sin'       → envío sin localidad cargada (no se inventa ninguna)
 * Base "cliente": la localidad declarada en el perfil del cliente (domicilio).
 *
 * Filtros esperados en $f (ya validados por el Service):
 *   desde, hasta_excl (Y-m-d H:i:s), base, canal, vendedor_id, producto_id,
 *   zona, tipo_zona, estado_pedido, estado_envio, empresa_envio_id, dias_demora
 */
class ReporteLocalidadRepository
{
    private const ACTIVOS = "('pendiente','despachado','en_camino')";

    /** Columnas por las que se puede ordenar la tabla de zonas (lista blanca). */
    private const ORDEN = [
        'zona'        => 'zona_nombre',
        'operaciones' => 'operaciones',
        'ventas_pos'  => 'ventas_pos',
        'pedidos'     => 'pedidos_online',
        'importe'     => 'importe',
        'ticket'      => 'ticket',
        'envios'      => 'envios',
        'demorados'   => 'demorados',
        'clientes'    => 'clientes',
    ];

    /**
     * Nombre visible de una localidad escrita a mano: sin espacios y con la primera
     * letra en mayúscula ("formosa" y "Formosa" se ven igual). La clave de agrupado
     * es LOWER(TRIM(...)), y la collation de la base además ignora los acentos.
     */
    private static function nombreLocalidad(string $col): string
    {
        return "CONCAT(UPPER(LEFT(TRIM($col), 1)), SUBSTRING(TRIM($col), 2))";
    }

    /**
     * Subconsulta filtrada de operaciones (alias f). Devuelve [sql, params].
     * Ojo: los parámetros son posicionales y el orden importa.
     */
    private function base(array $f): array
    {
        $params = [];
        $ramas = [];

        // Rama POS (ventas del mostrador). El filtro de estado de pedido no aplica al POS.
        if ($f['canal'] !== 'online' && $f['estado_pedido'] === null) {
            $w = ['v.creado_en >= ?', 'v.creado_en < ?'];
            array_push($params, $f['desde'], $f['hasta_excl']);
            if ($f['vendedor_id'] !== null) { $w[] = 'v.usuario_id = ?'; $params[] = $f['vendedor_id']; }
            if ($f['producto_id'] !== null) {
                $w[] = 'EXISTS (SELECT 1 FROM venta_detalle vd WHERE vd.venta_id = v.id AND vd.producto_id = ?)';
                $params[] = $f['producto_id'];
            }
            $ramas[] = "SELECT 'pos' AS canal, v.id AS op_id, v.creado_en AS fecha, v.cliente_id,
                               v.subtotal - v.descuento AS importe,
                               IF(v.estado = 'registrada', 1, 0) AS valida,
                               'pagado' AS estado_pago, NULL AS estado_pedido,
                               e.id AS envio_id, e.estado AS envio_estado, e.costo AS envio_costo,
                               e.empresa_envio_id, e.barrio_id, e.repartidor_id, e.creado_en AS envio_creado,
                               e.localidad AS env_localidad, e.provincia AS env_provincia
                        FROM venta v
                        LEFT JOIN envio e ON e.venta_id = v.id
                        WHERE " . implode(' AND ', $w);
        }

        // Rama tienda online (pedidos). No tienen vendedor: el vendedor ve todos
        // (ya los gestiona en "Pedidos"), igual que el admin.
        if ($f['canal'] !== 'pos') {
            $w = ['p.creado_en >= ?', 'p.creado_en < ?'];
            array_push($params, $f['desde'], $f['hasta_excl']);
            if ($f['estado_pedido'] !== null) { $w[] = 'p.estado = ?'; $params[] = $f['estado_pedido']; }
            if ($f['producto_id'] !== null) {
                $w[] = 'EXISTS (SELECT 1 FROM pedido_detalle pd WHERE pd.pedido_id = p.id AND pd.producto_id = ?)';
                $params[] = $f['producto_id'];
            }
            // Con alias propios: puede ser la única rama (canal online / estado de pedido).
            $ramas[] = "SELECT 'online' AS canal, p.id AS op_id, p.creado_en AS fecha, p.cliente_id,
                               p.total AS importe,
                               IF(p.estado <> 'cancelado', 1, 0) AS valida,
                               p.estado_pago, p.estado AS estado_pedido,
                               e.id AS envio_id, e.estado AS envio_estado, e.costo AS envio_costo,
                               e.empresa_envio_id, e.barrio_id, e.repartidor_id, e.creado_en AS envio_creado,
                               e.localidad AS env_localidad, e.provincia AS env_provincia
                        FROM pedido p
                        LEFT JOIN envio e ON e.pedido_id = p.id
                        WHERE " . implode(' AND ', $w);
        }

        // Sin ramas (ej: canal POS + estado de pedido) → conjunto vacío con las mismas columnas.
        if (!$ramas) {
            $ramas[] = "SELECT 'pos' AS canal, 0 AS op_id, NULL AS fecha, 0 AS cliente_id, 0 AS importe,
                               0 AS valida, '' AS estado_pago, NULL AS estado_pedido, NULL AS envio_id,
                               NULL AS envio_estado, 0 AS envio_costo, NULL AS empresa_envio_id,
                               NULL AS barrio_id, NULL AS repartidor_id, NULL AS envio_creado,
                               NULL AS env_localidad, NULL AS env_provincia
                        FROM DUAL WHERE 1 = 0";
        }

        if ($f['base'] === 'cliente') {
            $joinCliente = 'LEFT JOIN cliente c ON c.id = o.cliente_id';
            $tipo = "CASE WHEN NULLIF(TRIM(c.localidad), '') IS NULL THEN 'sin' ELSE 'localidad' END";
            $key  = "CASE WHEN NULLIF(TRIM(c.localidad), '') IS NULL THEN 'sin' ELSE CONCAT('l:', LOWER(TRIM(c.localidad))) END";
            $nom  = "CASE WHEN NULLIF(TRIM(c.localidad), '') IS NULL THEN 'Sin localidad identificada' ELSE " . self::nombreLocalidad('c.localidad') . " END";
            $prov = "NULLIF(TRIM(c.provincia), '')";
        } else {
            $joinCliente = '';
            $esLocal = "(o.envio_id IS NULL OR ee.es_retiro = 1)";
            $sinLoc  = "NULLIF(TRIM(o.env_localidad), '') IS NULL";
            $tipo = "CASE WHEN $esLocal THEN 'local' WHEN o.barrio_id IS NOT NULL THEN 'barrio'
                          WHEN $sinLoc THEN 'sin' ELSE 'localidad' END";
            $key  = "CASE WHEN $esLocal THEN 'local' WHEN o.barrio_id IS NOT NULL THEN CONCAT('b:', o.barrio_id)
                          WHEN $sinLoc THEN 'sin' ELSE CONCAT('l:', LOWER(TRIM(o.env_localidad))) END";
            $nom  = "CASE WHEN $esLocal THEN 'Retiro / venta en el local' WHEN o.barrio_id IS NOT NULL THEN b.nombre
                          WHEN $sinLoc THEN 'Sin localidad identificada' ELSE " . self::nombreLocalidad('o.env_localidad') . " END";
            $prov = "CASE WHEN o.barrio_id IS NULL THEN NULLIF(TRIM(o.env_provincia), '') END";
        }

        $sql = "SELECT o.*,
                       ($tipo) AS zona_tipo, ($key) AS zona_key, ($nom) AS zona_nombre, ($prov) AS zona_provincia,
                       IF(o.envio_id IS NOT NULL AND COALESCE(ee.es_retiro, 0) = 0, 1, 0) AS es_entrega,
                       CASE WHEN o.envio_id IS NULL THEN NULL
                            WHEN o.valida = 0 THEN 'cancelado' ELSE o.envio_estado END AS estado_log,
                       ee.nombre AS empresa, b.costo AS barrio_costo
                FROM (" . implode("\nUNION ALL\n", $ramas) . ") o
                LEFT JOIN empresa_envio ee ON ee.id = o.empresa_envio_id
                LEFT JOIN barrio b ON b.id = o.barrio_id
                $joinCliente";

        // Filtros sobre columnas calculadas (zona, estado del envío, medio).
        $w = [];
        if ($f['zona'] !== null)             { $w[] = 'z.zona_key = ?';         $params[] = $f['zona']; }
        if ($f['tipo_zona'] !== null)        { $w[] = 'z.zona_tipo = ?';        $params[] = $f['tipo_zona']; }
        if ($f['estado_envio'] !== null)     { $w[] = 'z.estado_log = ?';       $params[] = $f['estado_envio']; }
        if ($f['empresa_envio_id'] !== null) { $w[] = 'z.empresa_envio_id = ?'; $params[] = $f['empresa_envio_id']; }
        $where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

        return ["(SELECT * FROM ($sql) z $where) f", $params];
    }

    /** Expresión "envío activo con más de N días" (N va como parámetro). */
    private function demorado(): string
    {
        return "f.es_entrega = 1 AND f.estado_log IN " . self::ACTIVOS . " AND f.envio_creado < (NOW() - INTERVAL ? DAY)";
    }

    /** Indicadores generales del período. */
    public function indicadores(array $f): array
    {
        [$from, $params] = $this->base($f);
        $sql = "SELECT
                  COALESCE(SUM(f.valida), 0)                                              AS operaciones,
                  COALESCE(SUM(f.valida = 1 AND f.canal = 'pos'), 0)                      AS ventas_pos,
                  COALESCE(SUM(f.valida = 1 AND f.canal = 'online'), 0)                   AS pedidos_online,
                  COALESCE(SUM(f.valida = 0 AND f.canal = 'pos'), 0)                      AS ventas_anuladas,
                  COALESCE(SUM(f.valida = 0 AND f.canal = 'online'), 0)                   AS pedidos_cancelados,
                  COALESCE(SUM(CASE WHEN f.valida = 1 THEN f.importe END), 0)              AS importe,
                  COALESCE(SUM(CASE WHEN f.valida = 1 AND f.canal = 'pos' THEN f.importe END), 0)    AS importe_pos,
                  COALESCE(SUM(CASE WHEN f.valida = 1 AND f.canal = 'online' THEN f.importe END), 0) AS importe_online,
                  COALESCE(SUM(CASE WHEN f.valida = 1 AND f.estado_pago = 'pagado' THEN f.importe END), 0) AS cobrado,
                  COALESCE(SUM(CASE WHEN f.valida = 1 AND f.canal = 'online'
                                     AND f.estado_pago IN ('pendiente','en_revision') THEN f.importe END), 0) AS pendiente_cobro,
                  COUNT(DISTINCT CASE WHEN f.valida = 1 AND f.zona_tipo IN ('localidad','barrio') THEN f.zona_key END) AS zonas_activas,
                  COUNT(DISTINCT CASE WHEN f.valida = 1 THEN f.cliente_id END)             AS clientes,
                  COALESCE(SUM(f.es_entrega = 1 AND f.estado_log <> 'cancelado'), 0)        AS envios,
                  COALESCE(SUM(f.es_entrega = 1 AND f.estado_log = 'entregado'), 0)         AS envios_entregados,
                  COALESCE(SUM(f.es_entrega = 1 AND f.estado_log IN " . self::ACTIVOS . "), 0) AS envios_activos,
                  COALESCE(SUM(" . $this->demorado() . "), 0)                               AS envios_demorados,
                  COALESCE(SUM(CASE WHEN f.es_entrega = 1 AND f.estado_log <> 'cancelado' THEN f.envio_costo END), 0) AS envio_cobrado
                FROM $from";
        // El "?" de días de demora está en el SELECT: va ANTES que los del FROM.
        array_unshift($params, $f['dias_demora']);
        $st = Database::conexion()->prepare($sql);
        $st->execute($params);
        return $st->fetch() ?: [];
    }

    /** SELECT de agregados por zona (lo comparten la tabla, los gráficos y los exports). */
    private function selectZonas(): string
    {
        return "SELECT f.zona_key, MIN(f.zona_tipo) AS zona_tipo, MIN(f.zona_nombre) AS zona_nombre,
                       GROUP_CONCAT(DISTINCT f.zona_provincia ORDER BY f.zona_provincia SEPARATOR ', ') AS provincias,
                       COALESCE(SUM(f.valida), 0)                                   AS operaciones,
                       COALESCE(SUM(f.valida = 1 AND f.canal = 'pos'), 0)           AS ventas_pos,
                       COALESCE(SUM(f.valida = 1 AND f.canal = 'online'), 0)        AS pedidos_online,
                       COALESCE(SUM(f.valida = 0), 0)                               AS anuladas,
                       COALESCE(SUM(CASE WHEN f.valida = 1 THEN f.importe END), 0)   AS importe,
                       COALESCE(SUM(CASE WHEN f.valida = 1 AND f.canal = 'pos' THEN f.importe END), 0)    AS importe_pos,
                       COALESCE(SUM(CASE WHEN f.valida = 1 AND f.canal = 'online' THEN f.importe END), 0) AS importe_online,
                       COALESCE(SUM(CASE WHEN f.valida = 1 THEN f.importe END), 0) / NULLIF(SUM(f.valida), 0) AS ticket,
                       COALESCE(SUM(CASE WHEN f.valida = 1 AND f.estado_pago = 'pagado' THEN f.importe END), 0) AS cobrado,
                       COUNT(DISTINCT CASE WHEN f.valida = 1 THEN f.cliente_id END)  AS clientes,
                       COALESCE(SUM(f.es_entrega = 1 AND f.estado_log <> 'cancelado'), 0)           AS envios,
                       COALESCE(SUM(f.es_entrega = 1 AND f.estado_log = 'entregado'), 0)            AS entregados,
                       COALESCE(SUM(f.es_entrega = 1 AND f.estado_log IN " . self::ACTIVOS . "), 0) AS activos,
                       COALESCE(SUM(f.es_entrega = 1 AND f.estado_log = 'cancelado'), 0)            AS envios_cancelados,
                       COALESCE(SUM(" . $this->demorado() . "), 0)                                  AS demorados,
                       COALESCE(SUM(CASE WHEN f.es_entrega = 1 AND f.estado_log <> 'cancelado' THEN f.envio_costo END), 0) AS envio_cobrado";
    }

    /**
     * Tabla de zonas paginada + ordenable + buscable.
     * @return array{rows: array, total: int}
     */
    public function zonas(array $f, ?string $q, string $orden, string $dir, int $limit, int $offset): array
    {
        [$from, $params] = $this->base($f);
        array_unshift($params, $f['dias_demora']);   // el "?" del SELECT va primero
        $having = '';
        if ($q !== null && $q !== '') { $having = 'HAVING zona_nombre LIKE ?'; $params[] = '%' . $q . '%'; }

        $grupo = $this->selectZonas() . " FROM $from GROUP BY f.zona_key $having";
        $pdo = Database::conexion();

        $stC = $pdo->prepare("SELECT COUNT(*) FROM ($grupo) t");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        $col = self::ORDEN[$orden] ?? 'importe';
        $dir = $dir === 'asc' ? 'ASC' : 'DESC';
        // Desempate estable por nombre (paginación consistente).
        $st = $pdo->prepare("SELECT * FROM ($grupo) t ORDER BY $col $dir, zona_nombre ASC LIMIT $limit OFFSET $offset");
        $st->execute($params);
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** Importe y operaciones por zona (para la variación contra el período anterior). */
    public function totalesPorZona(array $f): array
    {
        [$from, $params] = $this->base($f);
        $st = Database::conexion()->prepare(
            "SELECT f.zona_key,
                    COALESCE(SUM(f.valida), 0) AS operaciones,
                    COALESCE(SUM(CASE WHEN f.valida = 1 THEN f.importe END), 0) AS importe
             FROM $from GROUP BY f.zona_key"
        );
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll() as $r) { $out[$r['zona_key']] = $r; }
        return $out;
    }

    /**
     * Productos más vendidos (con los filtros, típicamente una zona). Usa el precio
     * HISTÓRICO guardado en cada línea (subtotal del detalle), no el precio actual.
     */
    public function productos(array $f, int $limit, string $orden = 'unidades'): array
    {
        [$from, $params] = $this->base($f);

        // Líneas de detalle, ya acotadas por fecha (para no recorrer todo el histórico).
        $det = "SELECT 'pos' AS canal, d.venta_id AS op_id, d.producto_id, d.cantidad, d.subtotal
                FROM venta_detalle d JOIN venta v ON v.id = d.venta_id
                WHERE v.creado_en >= ? AND v.creado_en < ?
                UNION ALL
                SELECT 'online', d.pedido_id, d.producto_id, d.cantidad, d.subtotal
                FROM pedido_detalle d JOIN pedido p ON p.id = d.pedido_id
                WHERE p.creado_en >= ? AND p.creado_en < ?";
        $pDet = [$f['desde'], $f['hasta_excl'], $f['desde'], $f['hasta_excl']];

        $wProd = '';
        $pProd = [];
        if ($f['producto_id'] !== null) { $wProd = 'AND d.producto_id = ?'; $pProd[] = $f['producto_id']; }

        $col = $orden === 'importe' ? 'importe' : 'unidades';
        $sql = "SELECT pr.id, pr.nombre,
                       SUM(d.cantidad) AS unidades,
                       SUM(d.subtotal) AS importe,
                       COUNT(DISTINCT d.canal, d.op_id) AS operaciones
                FROM ($det) d
                JOIN $from ON f.canal = d.canal AND f.op_id = d.op_id AND f.valida = 1
                JOIN producto pr ON pr.id = d.producto_id
                WHERE 1 = 1 $wProd
                GROUP BY pr.id, pr.nombre
                ORDER BY $col DESC, pr.nombre ASC
                LIMIT $limit";
        $st = Database::conexion()->prepare($sql);
        $st->execute(array_merge($pDet, $params, $pProd));
        return $st->fetchAll();
    }

    /**
     * Serie temporal de importe por zona. $grano: dia | semana | mes.
     * @return array filas {bucket, zona_key, zona_nombre, importe, operaciones}
     */
    public function serie(array $f, string $grano): array
    {
        [$from, $params] = $this->base($f);
        $bucket = match ($grano) {
            'mes'    => "DATE_FORMAT(f.fecha, '%Y-%m-01')",
            'semana' => "DATE_SUB(DATE(f.fecha), INTERVAL WEEKDAY(f.fecha) DAY)",
            default  => "DATE(f.fecha)",
        };
        $st = Database::conexion()->prepare(
            "SELECT $bucket AS bucket, f.zona_key, MIN(f.zona_nombre) AS zona_nombre,
                    SUM(f.importe) AS importe, COUNT(*) AS operaciones
             FROM $from
             WHERE f.valida = 1
             GROUP BY bucket, f.zona_key
             ORDER BY bucket"
        );
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Logística: envíos por zona y medio de envío (solo entregas, no retiros). */
    public function logistica(array $f): array
    {
        [$from, $params] = $this->base($f);
        array_unshift($params, $f['dias_demora']);   // el "?" del SELECT va primero
        $sql = "SELECT f.zona_key, MIN(f.zona_tipo) AS zona_tipo, MIN(f.zona_nombre) AS zona_nombre,
                       f.empresa_envio_id, MIN(f.empresa) AS empresa,
                       COUNT(*) AS envios,
                       SUM(f.estado_log = 'pendiente')  AS pendientes,
                       SUM(f.estado_log = 'despachado') AS despachados,
                       SUM(f.estado_log = 'en_camino')  AS en_camino,
                       SUM(f.estado_log = 'entregado')  AS entregados,
                       SUM(f.estado_log = 'cancelado')  AS cancelados,
                       SUM(" . $this->demorado() . ")   AS demorados,
                       COALESCE(SUM(CASE WHEN f.estado_log <> 'cancelado' THEN f.envio_costo END), 0) AS costo_cobrado,
                       COALESCE(SUM(CASE WHEN f.estado_log = 'entregado' AND f.barrio_id IS NOT NULL
                                         THEN f.barrio_costo END), 0) AS paga_moto,
                       SUM(f.barrio_id IS NOT NULL AND f.repartidor_id IS NULL AND f.estado_log IN " . self::ACTIVOS . ") AS sin_repartidor
                FROM $from
                WHERE f.es_entrega = 1
                GROUP BY f.zona_key, f.empresa_envio_id
                ORDER BY envios DESC, zona_nombre ASC";
        $st = Database::conexion()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Envíos de Moto Express por repartidor (solo admin). */
    public function repartidores(array $f): array
    {
        [$from, $params] = $this->base($f);
        $st = Database::conexion()->prepare(
            "SELECT r.id, r.nombre,
                    COUNT(*) AS asignados,
                    SUM(f.estado_log = 'entregado') AS entregados,
                    SUM(f.estado_log IN " . self::ACTIVOS . ") AS activos,
                    SUM(f.estado_log = 'cancelado') AS cancelados,
                    COUNT(DISTINCT f.zona_key) AS zonas
             FROM $from
             JOIN repartidor r ON r.id = f.repartidor_id
             WHERE f.es_entrega = 1
             GROUP BY r.id, r.nombre
             ORDER BY asignados DESC, r.nombre ASC"
        );
        $st->execute($params);
        return $st->fetchAll();
    }

    // ---- Opciones para los filtros ----

    /** Zonas posibles según la base (entrega: barrios + localidades de envíos; cliente: localidades de clientes). */
    public function zonasDisponibles(string $base): array
    {
        $pdo = Database::conexion();
        if ($base === 'cliente') {
            $loc = $pdo->query(
                "SELECT CONCAT('l:', LOWER(TRIM(localidad))) AS v, MIN(" . self::nombreLocalidad('localidad') . ") AS t, 'localidad' AS tipo
                 FROM cliente WHERE NULLIF(TRIM(localidad), '') IS NOT NULL
                 GROUP BY v ORDER BY t"
            )->fetchAll();
            return array_merge($loc, [['v' => 'sin', 't' => 'Sin localidad identificada', 'tipo' => 'sin']]);
        }
        $barrios = $pdo->query(
            "SELECT CONCAT('b:', id) AS v, nombre AS t, 'barrio' AS tipo FROM barrio ORDER BY nombre"
        )->fetchAll();
        $loc = $pdo->query(
            "SELECT CONCAT('l:', LOWER(TRIM(localidad))) AS v, MIN(" . self::nombreLocalidad('localidad') . ") AS t, 'localidad' AS tipo
             FROM envio WHERE barrio_id IS NULL AND NULLIF(TRIM(localidad), '') IS NOT NULL
             GROUP BY v ORDER BY t"
        )->fetchAll();
        return array_merge($loc, $barrios, [
            ['v' => 'local', 't' => 'Retiro / venta en el local', 'tipo' => 'local'],
            ['v' => 'sin',   't' => 'Sin localidad identificada', 'tipo' => 'sin'],
        ]);
    }

    public function empresasEnvio(): array
    {
        return Database::conexion()->query(
            "SELECT id AS v, nombre AS t, es_retiro, es_moto FROM empresa_envio ORDER BY nombre"
        )->fetchAll();
    }

    /** Productos que alguna vez se vendieron (para el filtro de producto). */
    public function productosVendidos(): array
    {
        return Database::conexion()->query(
            "SELECT pr.id AS v, pr.nombre AS t FROM producto pr
             WHERE EXISTS (SELECT 1 FROM venta_detalle d WHERE d.producto_id = pr.id)
                OR EXISTS (SELECT 1 FROM pedido_detalle d WHERE d.producto_id = pr.id)
             ORDER BY pr.nombre LIMIT 1000"
        )->fetchAll();
    }

    /** Fecha de hoy según la base (misma zona horaria que los creado_en). */
    public function hoy(): string
    {
        return (string) Database::conexion()->query("SELECT CURDATE()")->fetchColumn();
    }

    public function ahora(): string
    {
        return (string) Database::conexion()->query("SELECT NOW()")->fetchColumn();
    }
}

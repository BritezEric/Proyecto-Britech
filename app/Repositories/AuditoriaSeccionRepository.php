<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Secciones del centro de control de Auditoría (solo lectura).
 *
 * No duplica datos: cada sección se arma con las tablas reales del sistema
 * (venta, pago, venta_anulacion, pedido, caja, caja_movimiento, inventario,
 * movimiento_inventario) junto con el historial de auditoría. Todo con
 * consultas preparadas; los filtros ya vienen validados del controlador.
 */
class AuditoriaSeccionRepository
{
    public const MAX_EXPORT = 5000;

    /** Tipos de movimiento de stock (se deducen de la acción y del motivo que guarda el sistema). */
    public const TIPOS_STOCK = [
        'venta' => 'Salida por venta (POS)', 'pedido' => 'Salida por pedido online', 'anulacion' => 'Reintegro por anulación',
        'cancelacion' => 'Reintegro por cancelación', 'compra' => 'Entrada de mercadería', 'inicial' => 'Stock inicial',
        'ajuste' => 'Ajuste manual', 'salida_otra' => 'Otra salida', 'entrada_otra' => 'Otra entrada',
    ];
    private const SQL_TIPO_STOCK = "CASE
        WHEN a.accion = 'salida' AND a.detalle LIKE 'Venta %' THEN 'venta'
        WHEN a.accion = 'salida' AND a.detalle LIKE 'Pedido online%' THEN 'pedido'
        WHEN a.accion = 'salida' THEN 'salida_otra'
        WHEN a.accion = 'entrada' AND a.detalle LIKE 'Anulaci%n venta%' THEN 'anulacion'
        WHEN a.accion = 'entrada' AND a.detalle LIKE 'Cancelaci%n pedido%' THEN 'cancelacion'
        WHEN a.accion = 'entrada' AND (a.detalle LIKE 'Recepci%' OR a.detalle LIKE 'Compra%' OR a.detalle LIKE 'Ingreso de mercader%') THEN 'compra'
        WHEN a.accion = 'entrada' THEN 'entrada_otra'
        WHEN a.accion = 'ajuste' AND a.detalle LIKE 'Stock inicial%' THEN 'inicial'
        ELSE 'ajuste' END";

    public const ACCIONES_USUARIOS = [
        'login' => 'Inicio de sesión', 'logout' => 'Cierre de sesión', 'login_fallido' => 'Login fallido',
        'acceso_denegado' => 'Acceso denegado', 'bloqueo_ip' => 'Bloqueo de IP', 'crear' => 'Alta de usuario',
        'rol' => 'Cambio de rol', 'estado' => 'Activación / desactivación', 'nombre' => 'Cambio de nombre',
        'cambio_password' => 'Cambio de contraseña', 'activar' => 'Activación de cuenta (verificó el correo)',
    ];

    private \PDO $pdo;

    public function __construct() { $this->pdo = Database::conexion(); }

    // ================= Tarjetas (día de hoy, todo desde la base) =================

    public function kpis(): array
    {
        $uno = fn(string $sql) => (int) $this->pdo->query($sql)->fetchColumn();
        $pend = (new AlertaRepository())->pendientesPorNivel();
        return [
            'alertas_pendientes' => array_sum($pend),
            'alertas_criticas'   => $pend['critica'],
            'anulaciones_hoy'    => $uno("SELECT COUNT(*) FROM venta_anulacion WHERE creado_en >= CURDATE()")
                + $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'pedido' AND campo = 'estado' AND valor_nuevo = 'cancelado' AND creado_en >= CURDATE()"),
            'cajas_diferencia_hoy' => $uno("SELECT COUNT(*) FROM caja WHERE estado = 'cerrada' AND cerrada_en >= CURDATE() AND ABS(diferencia) >= 0.005"),
            'sospechosas_hoy'    => $uno("SELECT COUNT(*) FROM auditoria_alerta WHERE nivel IN ('advertencia','critica') AND creado_en >= CURDATE()"),
            'stock_manual_hoy'   => $uno("SELECT COUNT(*) FROM auditoria WHERE entidad = 'stock' AND accion = 'ajuste'
                                          AND (detalle IS NULL OR detalle NOT LIKE 'Stock inicial%') AND creado_en >= CURDATE()"),
            'operaciones_hoy'    => $uno("SELECT COUNT(*) FROM auditoria WHERE creado_en >= CURDATE()"),
        ];
    }

    // ================= Ventas (POS) y pedidos (tienda online) =================

    private function sqlVentas(): string
    {
        return "SELECT * FROM (
            SELECT 'presencial' AS tipo, v.id, v.numero, v.creado_en AS fecha, v.usuario_id, u.nombre AS usuario,
                   v.cliente_id, c.nombre AS cliente, v.total, v.estado, NULL AS estado_pago, v.caja_id,
                   (SELECT GROUP_CONCAT(DISTINCT tp.nombre ORDER BY tp.nombre SEPARATOR ' + ')
                      FROM pago pg JOIN tipo_pago tp ON tp.id = pg.tipo_pago_id WHERE pg.venta_id = v.id) AS metodo,
                   va.motivo, va.creado_en AS anulada_en, ua.nombre AS anulo
            FROM venta v
            JOIN usuario u ON u.id = v.usuario_id
            JOIN cliente c ON c.id = v.cliente_id
            LEFT JOIN venta_anulacion va ON va.venta_id = v.id
            LEFT JOIN usuario ua ON ua.id = va.usuario_id
            UNION ALL
            SELECT 'online', p.id, p.numero, p.creado_en, NULL, NULL, p.cliente_id, c.nombre, p.total, p.estado, p.estado_pago, NULL,
                   p.metodo_pago, NULL,
                   (SELECT a.creado_en FROM auditoria a WHERE a.entidad = 'pedido' AND a.entidad_id = p.id
                      AND a.campo = 'estado' AND a.valor_nuevo = 'cancelado' ORDER BY a.id DESC LIMIT 1),
                   (SELECT ux.nombre FROM auditoria a JOIN usuario ux ON ux.id = a.usuario_id WHERE a.entidad = 'pedido'
                      AND a.entidad_id = p.id AND a.campo = 'estado' AND a.valor_nuevo = 'cancelado' ORDER BY a.id DESC LIMIT 1)
            FROM pedido p JOIN cliente c ON c.id = p.cliente_id
        ) t";
    }

    public function ventas(array $f, int $limit, int $offset): array
    {
        $w = []; $p = [];
        if ($f['tipo'] !== '')       { $w[] = 't.tipo = ?'; $p[] = $f['tipo']; }
        if ($f['estado'] === 'anulada') $w[] = "t.estado IN ('anulada', 'cancelado')";
        if ($f['estado'] === 'vigente') $w[] = "t.estado NOT IN ('anulada', 'cancelado')";
        if ($f['numero'] !== '')     { $w[] = 't.numero LIKE ?'; $p[] = '%' . $f['numero'] . '%'; }
        if ($f['cliente'] !== '')    { $w[] = 't.cliente LIKE ?'; $p[] = '%' . $f['cliente'] . '%'; }
        if ($f['usuario_id'] !== '') { $w[] = 't.usuario_id = ?'; $p[] = (int) $f['usuario_id']; }
        if ($f['caja_id'] !== '')    { $w[] = 't.caja_id = ?'; $p[] = (int) $f['caja_id']; }
        if ($f['producto'] !== '') {
            $w[] = "((t.tipo = 'presencial' AND t.id IN (SELECT vd.venta_id FROM venta_detalle vd JOIN producto pr ON pr.id = vd.producto_id
                                                          WHERE pr.nombre LIKE ? OR pr.sku LIKE ?))
                  OR (t.tipo = 'online' AND t.id IN (SELECT pd.pedido_id FROM pedido_detalle pd JOIN producto pr ON pr.id = pd.producto_id
                                                       WHERE pr.nombre LIKE ? OR pr.sku LIKE ?)))";
            $l = '%' . $f['producto'] . '%'; array_push($p, $l, $l, $l, $l);
        }
        $this->fechas($w, $p, $f, 't.fecha');
        return $this->paginar($this->sqlVentas(), $w, $p, 't.fecha DESC, t.id DESC', $limit, $offset);
    }

    /** Venta del POS con todo lo relacionado: líneas, pagos, stock, caja, anulación y su historial. */
    public function detalleVenta(int $id): ?array
    {
        $st = $this->pdo->prepare($this->sqlVentas() . " WHERE t.tipo = 'presencial' AND t.id = ?");
        $st->execute([$id]);
        $v = $st->fetch();
        if (!$v) return null;
        $v['lineas'] = $this->filas("SELECT pr.nombre AS producto, pr.sku, vd.cantidad, vd.precio_unitario, vd.descuento_linea, vd.subtotal, vd.estado
                                     FROM venta_detalle vd JOIN producto pr ON pr.id = vd.producto_id WHERE vd.venta_id = ? ORDER BY vd.id", [$id]);
        $v['pagos'] = $this->filas("SELECT tp.nombre AS metodo, pg.monto FROM pago pg JOIN tipo_pago tp ON tp.id = pg.tipo_pago_id
                                    WHERE pg.venta_id = ? ORDER BY pg.id", [$id]);
        $v['stock'] = $this->filas("SELECT a.creado_en, pr.nombre AS producto, a.accion, a.valor_anterior, a.valor_nuevo, a.detalle, u.nombre AS usuario
                                    FROM auditoria a LEFT JOIN producto pr ON pr.id = a.entidad_id LEFT JOIN usuario u ON u.id = a.usuario_id
                                    WHERE a.entidad = 'stock' AND (a.detalle LIKE ? OR a.detalle LIKE ?) ORDER BY a.id",
                                   ["Venta {$v['numero']}%", "Anulaci_n venta {$v['numero']}%"]);
        $v['stock_inventario'] = $this->filas("SELECT mi.creado_en, pr.nombre AS producto, mi.tipo, mi.cantidad, mi.motivo, u.nombre AS usuario
                                               FROM movimiento_inventario mi JOIN producto pr ON pr.id = mi.producto_id
                                               LEFT JOIN usuario u ON u.id = mi.usuario_id WHERE mi.venta_id = ? ORDER BY mi.id", [$id]);
        $v['historial'] = $this->historialDe('venta', $id);
        return $v;
    }

    /** Pedido online con líneas, envío, cambios de estado/pago y movimientos de stock. */
    public function detallePedido(int $id): ?array
    {
        $st = $this->pdo->prepare($this->sqlVentas() . " WHERE t.tipo = 'online' AND t.id = ?");
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) return null;
        $p['lineas'] = $this->filas("SELECT pr.nombre AS producto, pr.sku, pd.cantidad, pd.precio_unitario, 0 AS descuento_linea, pd.subtotal, 'normal' AS estado
                                     FROM pedido_detalle pd JOIN producto pr ON pr.id = pd.producto_id WHERE pd.pedido_id = ? ORDER BY pd.id", [$id]);
        $p['pagos'] = [['metodo' => $p['metodo'], 'monto' => $p['total'], 'estado' => $p['estado_pago']]];
        $p['envio'] = $this->filas("SELECT e.estado, e.costo, e.direccion, e.localidad, r.nombre AS repartidor
                                    FROM envio e LEFT JOIN repartidor r ON r.id = e.repartidor_id WHERE e.pedido_id = ?", [$id])[0] ?? null;
        $p['stock'] = $this->filas("SELECT a.creado_en, pr.nombre AS producto, a.accion, a.valor_anterior, a.valor_nuevo, a.detalle, u.nombre AS usuario
                                    FROM auditoria a LEFT JOIN producto pr ON pr.id = a.entidad_id LEFT JOIN usuario u ON u.id = a.usuario_id
                                    WHERE a.entidad = 'stock' AND (a.detalle LIKE ? OR a.detalle LIKE ?) ORDER BY a.id",
                                   ["Pedido online {$p['numero']}%", "Cancelaci_n pedido {$p['numero']}%"]);
        $p['stock_inventario'] = [];
        $p['historial'] = $this->historialDe('pedido', $id);
        return $p;
    }

    // ================= Cajas =================

    private function sqlCajas(): string
    {
        return "SELECT * FROM (
            SELECT c.id, c.usuario_id, u.nombre AS usuario, c.abierta_en, c.cerrada_en, c.estado, c.monto_apertura,
                   c.monto_esperado, c.monto_contado, c.diferencia, c.observacion,
                   CASE WHEN c.estado = 'abierta' THEN 'abierta'
                        WHEN c.diferencia IS NULL OR ABS(c.diferencia) < 0.005 THEN 'sin_diferencia'
                        WHEN c.diferencia < 0 THEN 'faltante' ELSE 'sobrante' END AS resultado,
                   (SELECT COUNT(*) FROM venta v WHERE v.caja_id = c.id) AS ventas,
                   (SELECT COUNT(*) FROM venta v WHERE v.caja_id = c.id AND v.estado = 'anulada') AS anuladas
            FROM caja c JOIN usuario u ON u.id = c.usuario_id
        ) t";
    }

    public function cajas(array $f, int $limit, int $offset): array
    {
        $w = []; $p = [];
        if ($f['usuario_id'] !== '') { $w[] = 't.usuario_id = ?'; $p[] = (int) $f['usuario_id']; }
        if ($f['caja_id'] !== '')    { $w[] = 't.id = ?'; $p[] = (int) $f['caja_id']; }
        if ($f['resultado'] !== '')  { $w[] = 't.resultado = ?'; $p[] = $f['resultado']; }
        $this->fechas($w, $p, $f, 't.abierta_en');
        return $this->paginar($this->sqlCajas(), $w, $p, 't.id DESC', $limit, $offset);
    }

    /**
     * Reconstruye la caja en orden cronológico con el saldo de efectivo antes y después
     * de cada movimiento (misma regla que el arqueo: apertura + ventas en efectivo
     * − anuladas + ingresos − retiros). Todo sale de caja, venta, pago, venta_anulacion
     * y caja_movimiento: nada se calcula a mano ni se inventa.
     */
    public function detalleCaja(int $id): ?array
    {
        $st = $this->pdo->prepare($this->sqlCajas() . ' WHERE t.id = ?');
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) return null;
        $c['movimientos'] = $this->movimientosCaja($c);
        $c['historial'] = $this->historialDe('caja', $id);
        return $c;
    }

    public function movimientosCaja(array $c): array
    {
        $id = (int) $c['id'];
        $ev = [];
        $ev[] = ['fecha' => $c['abierta_en'], 'orden' => 0, 'tipo' => 'apertura', 'tipo_txt' => 'Apertura de caja', 'importe' => (float) $c['monto_apertura'],
                 'efectivo' => (float) $c['monto_apertura'], 'metodo' => 'Efectivo', 'descripcion' => 'Dinero inicial', 'usuario' => $c['usuario'], 'ref' => null];

        $ventas = $this->filas("SELECT v.id, v.numero, v.creado_en, v.total, v.estado, u.nombre AS usuario,
                                       va.creado_en AS anulada_en, va.motivo, ua.nombre AS anulo
                                FROM venta v JOIN usuario u ON u.id = v.usuario_id
                                LEFT JOIN venta_anulacion va ON va.venta_id = v.id LEFT JOIN usuario ua ON ua.id = va.usuario_id
                                WHERE v.caja_id = ? ORDER BY v.id", [$id]);
        $pagos = [];
        foreach ($this->filas("SELECT pg.venta_id, tp.nombre, pg.monto FROM pago pg JOIN tipo_pago tp ON tp.id = pg.tipo_pago_id
                               JOIN venta v ON v.id = pg.venta_id WHERE v.caja_id = ?", [$id]) as $pg) {
            $pagos[$pg['venta_id']][] = $pg;
        }
        foreach ($ventas as $v) {
            $lista = $pagos[$v['id']] ?? [];
            $efectivo = array_sum(array_map(fn($x) => mb_strtolower($x['nombre']) === 'efectivo' ? (float) $x['monto'] : 0, $lista));
            $metodo = implode(' + ', array_map(fn($x) => $x['nombre'] . ' $ ' . number_format((float) $x['monto'], 2, ',', '.'), $lista));
            $ev[] = ['fecha' => $v['creado_en'], 'orden' => 1, 'tipo' => 'venta', 'tipo_txt' => 'Venta', 'importe' => (float) $v['total'],
                     'efectivo' => $efectivo, 'metodo' => $metodo ?: '—', 'descripcion' => "Venta {$v['numero']}", 'usuario' => $v['usuario'],
                     'ref' => ['tipo' => 'venta', 'id' => (int) $v['id'], 'numero' => $v['numero']]];
            if ($v['anulada_en']) {
                $ev[] = ['fecha' => $v['anulada_en'], 'orden' => 1, 'tipo' => 'anulacion', 'tipo_txt' => 'Anulación', 'importe' => -(float) $v['total'],
                         'efectivo' => -$efectivo, 'metodo' => $metodo ?: '—', 'descripcion' => "Anulación {$v['numero']} · {$v['motivo']}",
                         'usuario' => $v['anulo'], 'ref' => ['tipo' => 'venta', 'id' => (int) $v['id'], 'numero' => $v['numero']]];
            }
        }
        foreach ($this->filas("SELECT cm.tipo, cm.monto, cm.motivo, cm.creado_en, u.nombre AS usuario
                               FROM caja_movimiento cm JOIN usuario u ON u.id = cm.usuario_id WHERE cm.caja_id = ? ORDER BY cm.id", [$id]) as $m) {
            $signo = $m['tipo'] === 'retiro' ? -1 : 1;
            $ev[] = ['fecha' => $m['creado_en'], 'orden' => 1, 'tipo' => $m['tipo'], 'tipo_txt' => $m['tipo'] === 'retiro' ? 'Retiro / egreso' : 'Ingreso manual',
                     'importe' => $signo * (float) $m['monto'], 'efectivo' => $signo * (float) $m['monto'], 'metodo' => 'Efectivo',
                     'descripcion' => $m['motivo'] ?: '—', 'usuario' => $m['usuario'], 'ref' => null];
        }
        if ($c['estado'] === 'cerrada') {
            $ev[] = ['fecha' => $c['cerrada_en'], 'orden' => 2, 'tipo' => 'cierre', 'tipo_txt' => 'Cierre de caja', 'importe' => (float) $c['monto_contado'],
                     'efectivo' => 0.0, 'metodo' => 'Efectivo', 'usuario' => $c['usuario'], 'ref' => null,
                     'descripcion' => 'Declarado $ ' . number_format((float) $c['monto_contado'], 2, ',', '.')
                         . ' · Esperado $ ' . number_format((float) $c['monto_esperado'], 2, ',', '.')
                         . ' · Esperado − Declarado $ ' . number_format(-(float) $c['diferencia'], 2, ',', '.')];
        }
        usort($ev, fn($a, $b) => [$a['fecha'], $a['orden']] <=> [$b['fecha'], $b['orden']]);

        $saldo = 0.0;
        foreach ($ev as &$e) {
            $e['saldo_anterior'] = round($saldo, 2);
            if ($e['tipo'] !== 'cierre') $saldo += $e['efectivo'];
            $e['saldo_posterior'] = round($saldo, 2);
            // Anulación registrada después del cierre: no entró en el arqueo de ese día.
            $e['posterior_al_cierre'] = $c['cerrada_en'] && $e['tipo'] !== 'cierre' && $e['fecha'] > $c['cerrada_en'];
        }
        return $ev;
    }

    // ================= Stock =================

    public function stock(array $f, int $limit, int $offset): array
    {
        $base = "SELECT * FROM (
            SELECT a.id, a.creado_en, a.entidad_id AS producto_id, pr.nombre AS producto, pr.sku, a.usuario_id, u.nombre AS usuario,
                   a.accion, a.valor_anterior, a.valor_nuevo, a.detalle, " . self::SQL_TIPO_STOCK . " AS tipo,
                   (SELECT al.nivel FROM auditoria_alerta al WHERE al.auditoria_id = a.id ORDER BY FIELD(al.nivel, 'critica', 'advertencia', 'informativa') LIMIT 1) AS alerta,
                   CASE WHEN a.accion = 'ajuste' THEN (SELECT COUNT(*) FROM auditoria b WHERE b.entidad = 'stock' AND b.accion = 'ajuste'
                        AND b.entidad_id = a.entidad_id AND DATE(b.creado_en) = DATE(a.creado_en)
                        AND (b.detalle IS NULL OR b.detalle NOT LIKE 'Stock inicial%')) ELSE 0 END AS ajustes_mismo_dia
            FROM auditoria a
            LEFT JOIN producto pr ON pr.id = a.entidad_id
            LEFT JOIN usuario u ON u.id = a.usuario_id
            WHERE a.entidad = 'stock'
        ) t";
        $w = []; $p = [];
        if ($f['producto_id'] !== '') { $w[] = 't.producto_id = ?'; $p[] = (int) $f['producto_id']; }
        if ($f['producto'] !== '')    { $w[] = '(t.producto LIKE ? OR t.sku LIKE ?)'; $l = '%' . $f['producto'] . '%'; array_push($p, $l, $l); }
        if ($f['tipo'] !== '')        { $w[] = 't.tipo = ?'; $p[] = $f['tipo']; }
        if ($f['usuario_id'] !== '')  { $w[] = 't.usuario_id = ?'; $p[] = (int) $f['usuario_id']; }
        if ($f['numero'] !== '')      { $w[] = 't.detalle LIKE ?'; $p[] = '%' . $f['numero'] . '%'; }
        if ($f['revisar'] === 'manual')   $w[] = "t.tipo = 'ajuste'";
        if ($f['revisar'] === 'inusual')  $w[] = "(t.alerta IS NOT NULL OR t.ajustes_mismo_dia > 1)";
        $this->fechas($w, $p, $f, 't.creado_en');
        $r = $this->paginar($base, $w, $p, 't.id DESC', $limit, $offset);
        foreach ($r['rows'] as &$x) $x['documento'] = self::documento($x['detalle']);
        return $r;
    }

    /** Historial individual de un producto: stock actual + auditoría + historial de inventario. */
    public function detalleProducto(int $id): ?array
    {
        $p = $this->filas("SELECT pr.id, pr.nombre, pr.sku, pr.codigo_barras, pr.stock_minimo, pr.activo, COALESCE(i.cantidad, 0) AS stock_actual
                           FROM producto pr LEFT JOIN inventario i ON i.producto_id = pr.id WHERE pr.id = ?", [$id])[0] ?? null;
        if (!$p) return null;
        $vacios = ['producto' => '', 'tipo' => '', 'usuario_id' => '', 'numero' => '', 'revisar' => '', 'desde' => '', 'hasta' => ''];
        $p['movimientos'] = $this->stock(['producto_id' => (string) $id] + $vacios, 500, 0)['rows'];
        // Tabla de inventario del sistema (incluye movimientos anteriores a la auditoría).
        $p['inventario'] = $this->filas("SELECT mi.creado_en, mi.tipo, mi.cantidad, mi.motivo, v.numero AS venta, u.nombre AS usuario
                                         FROM movimiento_inventario mi LEFT JOIN venta v ON v.id = mi.venta_id
                                         LEFT JOIN usuario u ON u.id = mi.usuario_id WHERE mi.producto_id = ? ORDER BY mi.id DESC LIMIT 500", [$id]);
        return $p;
    }

    /** Venta / pedido / orden de compra mencionada en el motivo del movimiento (los números los arma el sistema). */
    public static function documento(?string $detalle): ?array
    {
        if (!$detalle || !preg_match('/\b(V|P|OC)-(\d{6})\b/', $detalle, $m)) return null;
        $tipo = ['V' => 'venta', 'P' => 'pedido', 'OC' => 'compra'][$m[1]];
        return ['tipo' => $tipo, 'id' => (int) $m[2], 'numero' => $m[0]];
    }

    // ================= Usuarios y accesos =================

    public function usuarios(array $f, int $limit, int $offset): array
    {
        $base = "SELECT * FROM (
            SELECT a.id, a.creado_en, a.entidad, a.accion, a.campo, a.valor_anterior, a.valor_nuevo, a.detalle, a.ip, a.agente,
                   a.usuario_id AS actor_id, u.nombre AS actor, u.email AS actor_email,
                   a.entidad_id AS afectado_id, ua.nombre AS afectado, ua.email AS afectado_email,
                   CASE WHEN a.accion = 'editar' THEN a.campo ELSE a.accion END AS clase
            FROM auditoria a
            LEFT JOIN usuario u ON u.id = a.usuario_id
            LEFT JOIN usuario ua ON ua.id = a.entidad_id
            WHERE a.entidad IN ('sesion', 'usuario')
        ) t";
        $w = []; $p = [];
        if ($f['accion'] !== '')     { $w[] = 't.clase = ?'; $p[] = $f['accion']; }
        if ($f['usuario_id'] !== '') { $w[] = '(t.afectado_id = ? OR t.actor_id = ?)'; array_push($p, (int) $f['usuario_id'], (int) $f['usuario_id']); }
        if ($f['q'] !== '') {
            $w[] = '(t.ip LIKE ? OR t.detalle LIKE ? OR t.afectado_email LIKE ? OR t.actor_email LIKE ?)';
            $l = '%' . $f['q'] . '%'; array_push($p, $l, $l, $l, $l);
        }
        $this->fechas($w, $p, $f, 't.creado_en');
        $r = $this->paginar($base, $w, $p, 't.id DESC', $limit, $offset);
        foreach ($r['rows'] as &$x) $x['dispositivo'] = \App\Services\AlertaService::dispositivo($x['agente']);
        return $r;
    }

    // ================= Registro puntual del historial general =================

    public function registro(int $id): ?array
    {
        $r = $this->filas("SELECT a.*, u.nombre AS usuario, u.email AS usuario_email FROM auditoria a
                           LEFT JOIN usuario u ON u.id = a.usuario_id WHERE a.id = ?", [$id])[0] ?? null;
        if (!$r) return null;
        $r['dispositivo'] = \App\Services\AlertaService::dispositivo($r['agente'] ?? null);
        $r['alertas'] = $this->filas("SELECT id, nivel, titulo, revisada FROM auditoria_alerta WHERE auditoria_id = ?", [$id]);
        $r['documento'] = self::documento($r['detalle']);
        if (in_array($r['entidad'], ['venta', 'pedido', 'caja'], true) && $r['entidad_id']) {
            $r['documento'] = ['tipo' => $r['entidad'], 'id' => (int) $r['entidad_id'], 'numero' => '#' . $r['entidad_id']];
        }
        if ($r['entidad'] === 'stock' && $r['entidad_id']) $r['producto'] = ['id' => (int) $r['entidad_id']];
        return $r;
    }

    /** Usuarios del staff (para los filtros). */
    public function staff(): array
    {
        return $this->pdo->query("SELECT id, nombre FROM usuario ORDER BY nombre")->fetchAll();
    }

    // ================= helpers =================

    private function historialDe(string $entidad, int $id): array
    {
        return $this->filas("SELECT a.id, a.creado_en, a.accion, a.campo, a.valor_anterior, a.valor_nuevo, a.detalle, u.nombre AS usuario
                             FROM auditoria a LEFT JOIN usuario u ON u.id = a.usuario_id
                             WHERE a.entidad = ? AND a.entidad_id = ? ORDER BY a.id", [$entidad, $id]);
    }

    private function fechas(array &$w, array &$p, array $f, string $col): void
    {
        if (($f['desde'] ?? '') !== '') { $w[] = "$col >= ?"; $p[] = $f['desde'] . ' 00:00:00'; }
        if (($f['hasta'] ?? '') !== '') { $w[] = "$col <= ?"; $p[] = $f['hasta'] . ' 23:59:59'; }
    }

    private function paginar(string $base, array $w, array $p, string $orden, int $limit, int $offset): array
    {
        $where = $w ? ' WHERE ' . implode(' AND ', $w) : '';
        $c = $this->pdo->prepare("SELECT COUNT(*) FROM ($base$where) x");
        $c->execute($p);
        $st = $this->pdo->prepare("$base$where ORDER BY $orden LIMIT ? OFFSET ?");
        foreach (array_merge($p, [$limit, $offset]) as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => (int) $c->fetchColumn()];
    }

    private function filas(string $sql, array $p): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }
}

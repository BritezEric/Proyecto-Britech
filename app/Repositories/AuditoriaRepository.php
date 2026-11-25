<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Registro de auditoría: quién cambió qué y cuándo. Único lugar con su SQL.
 * Solo INSERT y SELECT: el historial no se edita ni se borra (la base además
 * lo impide con triggers, ver database/schema_auditoria.sql).
 */
class AuditoriaRepository
{
    /** Tope de filas para exportar (PDF/Excel), así un export nunca tumba el servidor. */
    public const MAX_EXPORT = 5000;

    /**
     * Acciones del filtro que agrupan registros por módulo: una venta del POS se guarda
     * como (venta, crear) y una orden de compra a proveedor como (compra, crear).
     * Filtro => [entidad, accion].
     */
    public const ACCIONES_FILTRO = [
        'venta'  => ['venta', 'crear'],
        'compra' => ['compra', 'crear'],
    ];

    private static ?array $columnas = null;

    /**
     * Inserta un registro y devuelve su id (null si no se pudo).
     * Con el script ampliado aplicado, además guarda el navegador (agente) y el
     * hash encadenado: hash = HMAC-SHA256(hash_anterior + contenido) con AUDIT_HMAC_KEY.
     * Un candado de MySQL (GET_LOCK) ordena los registros concurrentes de la cadena.
     */
    public function registrar(
        ?int $usuarioId, string $entidad, ?int $entidadId, string $accion,
        ?string $campo, ?string $anterior, ?string $nuevo, ?string $detalle, ?string $ip = null, ?string $agente = null
    ): ?int {
        $corta = fn(?string $v, int $n = 255) => $v === null ? null : mb_substr($v, 0, $n);
        $fila = [
            'usuario_id' => $usuarioId, 'entidad' => mb_substr($entidad, 0, 40), 'entidad_id' => $entidadId,
            'accion' => mb_substr($accion, 0, 30), 'campo' => $corta($campo, 50),
            'valor_anterior' => $corta($anterior), 'valor_nuevo' => $corta($nuevo), 'detalle' => $corta($detalle),
        ];
        // Columnas que agregan los scripts nuevos; si la base todavía no las tiene, se registra igual.
        if ($this->tieneColumna('ip')) $fila['ip'] = $corta($ip, 45);
        $v2 = $this->tieneColumna('hash');
        if ($v2) {
            $fila['agente'] = $corta($agente);
        }

        $pdo = Database::conexion();
        $candado = false;
        try {
            if ($v2) {
                // La hora la da MySQL (misma zona que el resto de la base) y se guarda explícita: forma parte del hash.
                $fila['creado_en'] = (string) $pdo->query('SELECT NOW()')->fetchColumn();
                $candado = (int) $pdo->query("SELECT GET_LOCK('britech_auditoria', 5)")->fetchColumn() === 1;
                $prev = $pdo->query("SELECT hash FROM auditoria WHERE hash IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
                $fila['hash_anterior'] = $prev === false ? null : (string) $prev;
                $fila['hash'] = self::hashFila($fila);
            }
            $cols = array_keys($fila);
            $pdo->prepare('INSERT INTO auditoria (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($fila));
            return (int) $pdo->lastInsertId();
        } finally {
            if ($candado) $pdo->query("SELECT RELEASE_LOCK('britech_auditoria')");
        }
    }

    /** Hash de una fila: HMAC con la clave del .env (o SHA-256 simple si no hay clave). */
    public static function hashFila(array $f): string
    {
        $partes = [];
        foreach (['hash_anterior', 'usuario_id', 'entidad', 'entidad_id', 'accion', 'campo', 'valor_anterior',
                  'valor_nuevo', 'detalle', 'ip', 'agente', 'creado_en'] as $k) {
            $partes[] = isset($f[$k]) ? (string) $f[$k] : "\0";
        }
        $datos = implode("\x1f", $partes);
        $clave = self::claveHmac();
        return $clave !== '' ? hash_hmac('sha256', $datos, $clave) : hash('sha256', $datos);
    }

    public static function claveHmac(): string
    {
        static $clave = null;
        if ($clave === null) {
            $cfg = require dirname(__DIR__, 2) . '/config/config.php';
            $clave = (string) ($cfg['auditoria']['hmac_key'] ?? '');
        }
        return $clave;
    }

    /**
     * Verifica la cadena: recalcula el hash de cada fila y controla que su
     * hash_anterior exista en la cadena. Detecta filas alteradas y filas borradas
     * del medio. NO detecta el borrado de las últimas filas ni a alguien que tenga
     * la clave HMAC y recalcule todo (ver docs/modulos/auditoria.md).
     */
    public function verificarIntegridad(): array
    {
        $pdo = Database::conexion();
        if (!$this->tieneColumna('hash')) return ['disponible' => false];
        $st = $pdo->query("SELECT id, usuario_id, entidad, entidad_id, accion, campo, valor_anterior, valor_nuevo,
                                  detalle, ip, agente, creado_en, hash_anterior, hash
                           FROM auditoria WHERE hash IS NOT NULL ORDER BY id");
        $vistos = []; $alteradas = []; $rotas = []; $n = 0; $primera = null; $ultima = null;
        while ($f = $st->fetch()) {
            $n++;
            $primera ??= (int) $f['id'];
            if (!hash_equals((string) $f['hash'], self::hashFila($f))) $alteradas[] = (int) $f['id'];
            if ($f['hash_anterior'] !== null && !isset($vistos[$f['hash_anterior']])) $rotas[] = (int) $f['id'];
            $vistos[$f['hash']] = true;
            $ultima = ['id' => (int) $f['id'], 'hash' => $f['hash'], 'fecha' => $f['creado_en']];
        }
        // La primera fila encadenada apunta a null: no cuenta como enlace roto.
        return [
            'disponible' => true, 'con_clave' => self::claveHmac() !== '',
            'verificadas' => $n, 'desde_id' => $primera, 'ultima' => $ultima,
            'sin_hash' => (int) $pdo->query("SELECT COUNT(*) FROM auditoria WHERE hash IS NULL")->fetchColumn(),
            'alteradas' => array_slice($alteradas, 0, 50), 'enlaces_rotos' => array_slice($rotas, 0, 50),
            'ok' => !$alteradas && !$rotas,
        ];
    }

    /** Listado paginado para el panel, con filtros. */
    public function listarPaginado(array $f, int $limit, int $offset): array
    {
        [$sqlWhere, $params] = $this->where($f);
        $pdo = Database::conexion();

        $stC = $pdo->prepare("SELECT COUNT(*) FROM auditoria a LEFT JOIN usuario u ON u.id = a.usuario_id $sqlWhere");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        return ['rows' => $this->filas($sqlWhere, $params, $limit, $offset), 'total' => $total];
    }

    /** Todas las filas que cumplen los filtros (hasta MAX_EXPORT), para exportar. */
    public function listarParaExportar(array $f): array
    {
        [$sqlWhere, $params] = $this->where($f);
        return $this->filas($sqlWhere, $params, self::MAX_EXPORT, 0);
    }

    /**
     * Resumen para las tarjetas del panel: total filtrado, cuántos de cada grupo
     * de acciones, intentos de login fallidos y usuarios distintos.
     * Los grupos los define el controlador (mapa accion => grupo).
     */
    public function resumen(array $f): array
    {
        [$sqlWhere, $params] = $this->where($f);
        $st = Database::conexion()->prepare(
            "SELECT a.accion, COUNT(*) AS n
             FROM auditoria a LEFT JOIN usuario u ON u.id = a.usuario_id $sqlWhere
             GROUP BY a.accion"
        );
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Usuarios distintos dentro del filtro (las tarjetas no pueden sumarlos por acción). */
    public function usuariosDistintos(array $f): int
    {
        [$sqlWhere, $params] = $this->where($f);
        $st = Database::conexion()->prepare(
            "SELECT COUNT(DISTINCT a.usuario_id) FROM auditoria a LEFT JOIN usuario u ON u.id = a.usuario_id $sqlWhere"
        );
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

    /** Valores presentes para armar los filtros del panel. */
    public function opciones(): array
    {
        $pdo = Database::conexion();
        return [
            'entidades' => $pdo->query("SELECT DISTINCT entidad FROM auditoria ORDER BY entidad")->fetchAll(\PDO::FETCH_COLUMN),
            // + "venta" y "compra": opciones fijas del filtro aunque todavía no haya registros.
            'acciones'  => array_values(array_unique(array_merge(
                $pdo->query("SELECT DISTINCT accion FROM auditoria ORDER BY accion")->fetchAll(\PDO::FETCH_COLUMN),
                array_keys(self::ACCIONES_FILTRO)
            ))),
            'usuarios'  => $pdo->query(
                "SELECT DISTINCT u.id, u.nombre FROM auditoria a JOIN usuario u ON u.id = a.usuario_id ORDER BY u.nombre"
            )->fetchAll(),
        ];
    }

    // ---------- internos ----------

    /** WHERE con consultas preparadas. Los valores ya vienen validados del controlador. */
    private function where(array $f): array
    {
        $where = [];
        $params = [];
        if (!empty($f['entidad']))    { $where[] = 'a.entidad = ?';    $params[] = $f['entidad']; }
        if (!empty($f['accion'])) {
            if (isset(self::ACCIONES_FILTRO[$f['accion']])) {   // "Ventas realizadas" / "Compras realizadas"
                $where[] = '(a.entidad = ? AND a.accion = ?)';
                array_push($params, ...self::ACCIONES_FILTRO[$f['accion']]);
            } else {
                $where[] = 'a.accion = ?'; $params[] = $f['accion'];
            }
        }
        if (!empty($f['usuario_id'])) { $where[] = 'a.usuario_id = ?'; $params[] = (int) $f['usuario_id']; }
        if (!empty($f['desde']))      { $where[] = 'a.creado_en >= ?'; $params[] = $f['desde'] . ' 00:00:00'; }
        if (!empty($f['hasta']))      { $where[] = 'a.creado_en <= ?'; $params[] = $f['hasta'] . ' 23:59:59'; }
        if (!empty($f['q'])) {
            $like = '%' . $f['q'] . '%';
            $or = 'a.detalle LIKE ? OR a.campo LIKE ? OR a.valor_anterior LIKE ? OR a.valor_nuevo LIKE ? OR u.nombre LIKE ?';
            array_push($params, $like, $like, $like, $like, $like);
            if (ctype_digit((string) $f['q'])) { $or = 'a.entidad_id = ? OR ' . $or; array_splice($params, count($params) - 5, 0, [(int) $f['q']]); }
            $where[] = "($or)";
        }
        return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
    }

    private function filas(string $sqlWhere, array $params, int $limit, int $offset): array
    {
        // "referencia": nombre legible del registro afectado cuando es un producto.
        $sql = "SELECT a.id, a.usuario_id, a.entidad, a.entidad_id, a.accion, a.campo,
                       a.valor_anterior, a.valor_nuevo, a.detalle, a.creado_en,
                       u.nombre AS usuario, u.email AS usuario_email,
                       CASE WHEN a.entidad IN ('producto', 'stock') THEN p.nombre END AS referencia
                FROM auditoria a
                LEFT JOIN usuario u ON u.id = a.usuario_id
                LEFT JOIN producto p ON p.id = a.entidad_id AND a.entidad IN ('producto', 'stock')
                $sqlWhere
                ORDER BY a.id DESC
                LIMIT ? OFFSET ?";
        $st = Database::conexion()->prepare($sql);
        $full = array_merge($params, [$limit, $offset]);
        foreach ($full as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $st->execute();
        return $st->fetchAll();
    }

    /** ¿La tabla auditoria ya tiene esta columna? (los scripts se aplican por separado). */
    private function tieneColumna(string $col): bool
    {
        if (self::$columnas === null) {
            self::$columnas = array_flip(Database::conexion()->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria'"
            )->fetchAll(\PDO::FETCH_COLUMN));
        }
        return isset(self::$columnas[$col]);
    }
}

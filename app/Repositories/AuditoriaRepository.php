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

    private static ?bool $tieneIp = null;

    public function registrar(
        ?int $usuarioId, string $entidad, ?int $entidadId, string $accion,
        ?string $campo, ?string $anterior, ?string $nuevo, ?string $detalle, ?string $ip = null
    ): void {
        $corta = fn(?string $v) => $v === null ? null : mb_substr($v, 0, 255);
        $cols = 'usuario_id, entidad, entidad_id, accion, campo, valor_anterior, valor_nuevo, detalle';
        $vals = [
            $usuarioId, mb_substr($entidad, 0, 40), $entidadId, mb_substr($accion, 0, 30),
            $campo === null ? null : mb_substr($campo, 0, 50), $corta($anterior), $corta($nuevo), $corta($detalle),
        ];
        // La columna ip la agrega el script actualizado; si la base todavía no lo tiene, se registra igual.
        if ($this->tieneIp()) { $cols .= ', ip'; $vals[] = $ip === null ? null : mb_substr($ip, 0, 45); }

        Database::conexion()
            ->prepare("INSERT INTO auditoria ($cols) VALUES (" . implode(', ', array_fill(0, count($vals), '?')) . ")")
            ->execute($vals);
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
            'acciones'  => $pdo->query("SELECT DISTINCT accion FROM auditoria ORDER BY accion")->fetchAll(\PDO::FETCH_COLUMN),
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
        if (!empty($f['accion']))     { $where[] = 'a.accion = ?';     $params[] = $f['accion']; }
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
                       u.nombre AS usuario,
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

    private function tieneIp(): bool
    {
        if (self::$tieneIp === null) {
            $st = Database::conexion()->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria' AND COLUMN_NAME = 'ip'"
            );
            self::$tieneIp = (int) $st->fetchColumn() > 0;
        }
        return self::$tieneIp;
    }
}

<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Alertas de auditoría (tabla auditoria_alerta). Único lugar con su SQL.
 * Solo INSERT, SELECT y la marca de "revisada": las alertas no se borran
 * y la base rechaza cualquier otro cambio (triggers en schema_auditoria.sql).
 */
class AlertaRepository
{
    public const NIVELES = ['informativa' => 'Informativa', 'advertencia' => 'Advertencia', 'critica' => 'Crítica'];

    /** Crea la alerta si su clave no existe (INSERT IGNORE: nunca duplica). Devuelve true si la creó. */
    public function crear(array $a): bool
    {
        $st = Database::conexion()->prepare(
            "INSERT IGNORE INTO auditoria_alerta
                (auditoria_id, tipo, nivel, usuario_id, titulo, descripcion, entidad, entidad_id, clave)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $st->execute([
            $a['auditoria_id'] ?? null, $a['tipo'], $a['nivel'], $a['usuario_id'] ?? null,
            mb_substr($a['titulo'], 0, 150), mb_substr($a['descripcion'], 0, 500),
            $a['entidad'] ?? null, $a['entidad_id'] ?? null, mb_substr($a['clave'], 0, 160),
        ]);
        return $st->rowCount() > 0;
    }

    /** Cuenta filas de auditoría con un WHERE ya armado (consultas del detector). */
    public function contarAuditoria(string $where, array $params): int
    {
        $st = Database::conexion()->prepare("SELECT COUNT(*) FROM auditoria WHERE $where");
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

    /** Listado paginado con filtros: nivel, estado (pendiente/revisada), usuario, tipo, fechas, búsqueda. */
    public function listar(array $f, int $limit, int $offset): array
    {
        [$w, $p] = $this->where($f);
        $pdo = Database::conexion();
        $c = $pdo->prepare("SELECT COUNT(*) FROM auditoria_alerta al LEFT JOIN usuario u ON u.id = al.usuario_id $w");
        $c->execute($p);
        $st = $pdo->prepare(
            "SELECT al.*, u.nombre AS usuario, u.email AS usuario_email, r.nombre AS revisor,
                    a.accion AS origen_accion, a.entidad AS origen_entidad, a.entidad_id AS origen_entidad_id,
                    a.detalle AS origen_detalle, a.creado_en AS origen_fecha
             FROM auditoria_alerta al
             LEFT JOIN usuario u ON u.id = al.usuario_id
             LEFT JOIN usuario r ON r.id = al.revisada_por
             LEFT JOIN auditoria a ON a.id = al.auditoria_id
             $w ORDER BY al.revisada ASC, FIELD(al.nivel, 'critica', 'advertencia', 'informativa'), al.id DESC
             LIMIT ? OFFSET ?"
        );
        foreach (array_merge($p, [$limit, $offset]) as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => (int) $c->fetchColumn()];
    }

    public function buscar(int $id): ?array
    {
        $r = $this->listar(['id' => $id], 1, 0)['rows'];
        return $r[0] ?? null;
    }

    /** Marca como revisada (una sola vez). Devuelve false si no existe o ya estaba revisada. */
    public function revisar(int $id, int $usuarioId, ?string $nota): bool
    {
        $st = Database::conexion()->prepare(
            "UPDATE auditoria_alerta SET revisada = 1, revisada_por = ?, revisada_en = NOW(), nota_revision = ?
             WHERE id = ? AND revisada = 0"
        );
        $st->execute([$usuarioId, $nota, $id]);
        return $st->rowCount() > 0;
    }

    /** Pendientes por nivel (tarjetas). */
    public function pendientesPorNivel(): array
    {
        $out = ['informativa' => 0, 'advertencia' => 0, 'critica' => 0];
        foreach (Database::conexion()->query(
            "SELECT nivel, COUNT(*) n FROM auditoria_alerta WHERE revisada = 0 GROUP BY nivel"
        )->fetchAll() as $r) $out[$r['nivel']] = (int) $r['n'];
        return $out;
    }

    /** Tipos presentes (para el filtro). */
    public function tipos(): array
    {
        return Database::conexion()->query("SELECT DISTINCT tipo FROM auditoria_alerta ORDER BY tipo")->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function where(array $f): array
    {
        $w = []; $p = [];
        if (!empty($f['id']))         { $w[] = 'al.id = ?';         $p[] = (int) $f['id']; }
        if (!empty($f['nivel']))      { $w[] = 'al.nivel = ?';      $p[] = $f['nivel']; }
        if (!empty($f['tipo']))       { $w[] = 'al.tipo = ?';       $p[] = $f['tipo']; }
        if (!empty($f['usuario_id'])) { $w[] = 'al.usuario_id = ?'; $p[] = (int) $f['usuario_id']; }
        if (($f['estado'] ?? '') === 'pendiente') $w[] = 'al.revisada = 0';
        if (($f['estado'] ?? '') === 'revisada')  $w[] = 'al.revisada = 1';
        if (!empty($f['desde']))      { $w[] = 'al.creado_en >= ?'; $p[] = $f['desde'] . ' 00:00:00'; }
        if (!empty($f['hasta']))      { $w[] = 'al.creado_en <= ?'; $p[] = $f['hasta'] . ' 23:59:59'; }
        if (!empty($f['q'])) {
            $w[] = '(al.titulo LIKE ? OR al.descripcion LIKE ? OR u.nombre LIKE ?)';
            $like = '%' . $f['q'] . '%'; array_push($p, $like, $like, $like);
        }
        return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
    }
}

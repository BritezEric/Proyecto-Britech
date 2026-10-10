<?php

namespace App\Repositories;

use App\Core\Database;

/** Registro de auditoría: quién cambió qué y cuándo. Único lugar con su SQL. */
class AuditoriaRepository
{
    public function registrar(
        ?int $usuarioId, string $entidad, ?int $entidadId, string $accion,
        ?string $campo, ?string $anterior, ?string $nuevo, ?string $detalle
    ): void {
        Database::conexion()->prepare(
            "INSERT INTO auditoria (usuario_id, entidad, entidad_id, accion, campo, valor_anterior, valor_nuevo, detalle)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $usuarioId, $entidad, $entidadId, $accion, $campo,
            $anterior === null ? null : mb_substr($anterior, 0, 255),
            $nuevo === null ? null : mb_substr($nuevo, 0, 255),
            $detalle === null ? null : mb_substr($detalle, 0, 255),
        ]);
    }

    /** Listado paginado para el panel, con filtros opcionales. */
    public function listarPaginado(array $f, int $limit, int $offset): array
    {
        $pdo = Database::conexion();
        $where = [];
        $params = [];
        if (!empty($f['entidad'])) { $where[] = 'a.entidad = ?';    $params[] = $f['entidad']; }
        if (!empty($f['accion']))  { $where[] = 'a.accion = ?';     $params[] = $f['accion']; }
        if (!empty($f['usuario_id'])) { $where[] = 'a.usuario_id = ?'; $params[] = (int) $f['usuario_id']; }
        if (!empty($f['desde']))   { $where[] = 'a.creado_en >= ?'; $params[] = $f['desde'] . ' 00:00:00'; }
        if (!empty($f['hasta']))   { $where[] = 'a.creado_en <= ?'; $params[] = $f['hasta'] . ' 23:59:59'; }
        if (!empty($f['q'])) {
            $where[] = '(a.entidad_id = ? OR a.detalle LIKE ? OR a.campo LIKE ?)';
            $params[] = (int) $f['q']; $params[] = '%' . $f['q'] . '%'; $params[] = '%' . $f['q'] . '%';
        }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stC = $pdo->prepare("SELECT COUNT(*) FROM auditoria a $sqlWhere");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        $sql = "SELECT a.id, a.entidad, a.entidad_id, a.accion, a.campo,
                       a.valor_anterior, a.valor_nuevo, a.detalle, a.creado_en,
                       u.nombre AS usuario
                FROM auditoria a
                LEFT JOIN usuario u ON u.id = a.usuario_id
                $sqlWhere
                ORDER BY a.id DESC
                LIMIT ? OFFSET ?";
        $st = $pdo->prepare($sql);
        $full = array_merge($params, [$limit, $offset]);
        foreach ($full as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** Entidades distintas presentes (para el filtro del panel). */
    public function entidades(): array
    {
        return Database::conexion()->query("SELECT DISTINCT entidad FROM auditoria ORDER BY entidad")
            ->fetchAll(\PDO::FETCH_COLUMN);
    }
}

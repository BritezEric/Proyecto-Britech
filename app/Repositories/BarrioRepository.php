<?php

namespace App\Repositories;

use App\Core\Database;

/** Barrios del envío Moto Express (cada uno con su costo fijo). */
class BarrioRepository
{
    /** Barrios activos para elegir en el checkout. */
    public function activos(): array
    {
        return Database::conexion()
            ->query("SELECT id, nombre, costo FROM barrio WHERE activo = 1 ORDER BY nombre")
            ->fetchAll();
    }

    public function buscarPorId(int $id): ?array
    {
        $st = Database::conexion()->prepare("SELECT * FROM barrio WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    // ---- ABM admin ----

    /** @return array{rows: array, total: int} */
    public function listarPaginado(?string $q, ?int $activo, int $limit, int $offset): array
    {
        $pdo = Database::conexion();
        $where = [];
        $params = [];
        if ($q !== null && $q !== '') { $where[] = "nombre LIKE ?"; $params[] = "%{$q}%"; }
        if ($activo !== null)         { $where[] = "activo = ?";    $params[] = $activo; }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stC = $pdo->prepare("SELECT COUNT(*) FROM barrio $sqlWhere");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        $st = $pdo->prepare("SELECT id, nombre, costo, activo, creado_en FROM barrio $sqlWhere ORDER BY nombre LIMIT ? OFFSET ?");
        $full = array_merge($params, [$limit, $offset]);
        foreach ($full as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    public function nombreExiste(string $nombre, int $excluirId = 0): bool
    {
        $st = Database::conexion()->prepare("SELECT COUNT(*) FROM barrio WHERE nombre = ? AND id <> ?");
        $st->execute([$nombre, $excluirId]);
        return (int) $st->fetchColumn() > 0;
    }

    public function crear(string $nombre, float $costo, int $activo): int
    {
        $pdo = Database::conexion();
        $pdo->prepare("INSERT INTO barrio (nombre, costo, activo) VALUES (?, ?, ?)")
            ->execute([$nombre, $costo, $activo]);
        return (int) $pdo->lastInsertId();
    }

    public function actualizar(int $id, string $nombre, float $costo, int $activo): void
    {
        Database::conexion()
            ->prepare("UPDATE barrio SET nombre = ?, costo = ?, activo = ? WHERE id = ?")
            ->execute([$nombre, $costo, $activo, $id]);
    }

    public function cambiarEstado(int $id, int $activo): void
    {
        Database::conexion()->prepare("UPDATE barrio SET activo = ? WHERE id = ?")->execute([$activo, $id]);
    }
}

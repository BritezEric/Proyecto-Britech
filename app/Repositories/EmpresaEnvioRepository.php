<?php

namespace App\Repositories;

use App\Core\Database;

/** Empresas / medios de envío (tabla maestra con ABM + lista para el checkout). */
class EmpresaEnvioRepository
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

        $stC = $pdo->prepare("SELECT COUNT(*) FROM empresa_envio $sqlWhere");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        $st = $pdo->prepare("SELECT id, nombre, costo_base, es_retiro, url_tracking, activo, creado_en
                             FROM empresa_envio $sqlWhere ORDER BY costo_base, nombre LIMIT ? OFFSET ?");
        $full = array_merge($params, [$limit, $offset]);
        foreach ($full as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    /** Empresas activas para el checkout (id, nombre, costo). */
    public function activas(): array
    {
        return Database::conexion()
            ->query("SELECT id, nombre, costo_base, es_retiro, es_moto FROM empresa_envio WHERE activo = 1 ORDER BY es_retiro DESC, costo_base, nombre")
            ->fetchAll();
    }

    public function buscarPorId(int $id): ?array
    {
        $st = Database::conexion()->prepare("SELECT * FROM empresa_envio WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function crear(string $nombre, float $costo, int $activo, int $esRetiro, ?string $urlTracking): int
    {
        $pdo = Database::conexion();
        $pdo->prepare("INSERT INTO empresa_envio (nombre, costo_base, activo, es_retiro, url_tracking) VALUES (?, ?, ?, ?, ?)")
            ->execute([$nombre, $costo, $activo, $esRetiro, $urlTracking]);
        return (int) $pdo->lastInsertId();
    }

    public function actualizar(int $id, string $nombre, float $costo, int $activo, int $esRetiro, ?string $urlTracking): void
    {
        Database::conexion()
            ->prepare("UPDATE empresa_envio SET nombre = ?, costo_base = ?, activo = ?, es_retiro = ?, url_tracking = ? WHERE id = ?")
            ->execute([$nombre, $costo, $activo, $esRetiro, $urlTracking, $id]);
    }

    public function cambiarEstado(int $id, int $activo): void
    {
        Database::conexion()->prepare("UPDATE empresa_envio SET activo = ? WHERE id = ?")->execute([$activo, $id]);
    }
}

<?php

namespace App\Repositories;

use App\Core\Database;

/** Repositorio de proveedores (tabla maestra). Único lugar con su SQL. */
class ProveedorRepository
{
    /** @return array{rows: array, total: int} */
    public function listarPaginado(?string $q, ?int $activo, int $limit, int $offset): array
    {
        $pdo = Database::conexion();
        $where = [];
        $params = [];
        if ($q !== null && $q !== '') {
            $where[] = "(nombre LIKE ? OR cuit LIKE ? OR email LIKE ?)";
            $like = "%{$q}%";
            array_push($params, $like, $like, $like);
        }
        if ($activo !== null) { $where[] = "activo = ?"; $params[] = $activo; }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stC = $pdo->prepare("SELECT COUNT(*) FROM proveedor $sqlWhere");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        $st = $pdo->prepare("SELECT id, nombre, cuit, email, telefono, direccion, activo, creado_en
                             FROM proveedor $sqlWhere
                             ORDER BY nombre LIMIT ? OFFSET ?");
        $full = array_merge($params, [$limit, $offset]);
        foreach ($full as $i => $v) {
            $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    public function buscarPorId(int $id): ?array
    {
        $st = Database::conexion()->prepare("SELECT * FROM proveedor WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function crear(array $d): int
    {
        $pdo = Database::conexion();
        $pdo->prepare("INSERT INTO proveedor (nombre, cuit, email, telefono, direccion, activo)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$d['nombre'], $d['cuit'], $d['email'], $d['telefono'], $d['direccion'], $d['activo']]);
        return (int) $pdo->lastInsertId();
    }

    public function actualizar(int $id, array $d): void
    {
        Database::conexion()
            ->prepare("UPDATE proveedor SET nombre = ?, cuit = ?, email = ?, telefono = ?,
                       direccion = ?, activo = ? WHERE id = ?")
            ->execute([$d['nombre'], $d['cuit'], $d['email'], $d['telefono'], $d['direccion'], $d['activo'], $id]);
    }

    public function cambiarEstado(int $id, int $activo): void
    {
        Database::conexion()
            ->prepare("UPDATE proveedor SET activo = ? WHERE id = ?")
            ->execute([$activo, $id]);
    }
}

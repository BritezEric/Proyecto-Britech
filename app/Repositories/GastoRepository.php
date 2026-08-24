<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Repositorio de gastos (finanzas). Único lugar con SQL de la tabla `gasto`.
 * Listado paginado con búsqueda (concepto/observación) + filtro por proveedor y
 * estado, y el total (SUM) del conjunto filtrado para el resumen.
 */
class GastoRepository
{
    /** Arma el WHERE + params comunes al listado y al total. */
    private function condiciones(?string $q, array $filtros): array
    {
        $where = [];
        $params = [];
        if ($q !== null && $q !== '') {
            $where[] = "(g.concepto LIKE ? OR g.observacion LIKE ?)";
            $like = "%{$q}%";
            array_push($params, $like, $like);
        }
        if (($filtros['proveedor_id'] ?? null) !== null) { $where[] = "g.proveedor_id = ?"; $params[] = $filtros['proveedor_id']; }
        if (($filtros['activo'] ?? null) !== null)       { $where[] = "g.activo = ?";       $params[] = $filtros['activo']; }
        return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
    }

    /** @return array{rows: array, total: int, suma: float} */
    public function listarPaginado(?string $q, array $filtros, int $limit, int $offset): array
    {
        $pdo = Database::conexion();
        [$sqlWhere, $params] = $this->condiciones($q, $filtros);

        $stC = $pdo->prepare("SELECT COUNT(*) AS c, COALESCE(SUM(g.monto),0) AS s FROM gasto g $sqlWhere");
        $stC->execute($params);
        $tot = $stC->fetch();

        $sql = "SELECT g.id, g.fecha, g.concepto, g.monto, g.observacion, g.activo,
                       g.producto_id, g.cantidad,
                       pv.nombre AS proveedor, pr.nombre AS producto
                FROM gasto g
                LEFT JOIN proveedor pv ON pv.id = g.proveedor_id
                LEFT JOIN producto  pr ON pr.id = g.producto_id
                $sqlWhere
                ORDER BY g.fecha DESC, g.id DESC
                LIMIT ? OFFSET ?";
        $st = $pdo->prepare($sql);
        $full = array_merge($params, [$limit, $offset]);
        foreach ($full as $i => $v) {
            $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => (int) $tot['c'], 'suma' => (float) $tot['s']];
    }

    public function buscarPorId(int $id): ?array
    {
        $st = Database::conexion()->prepare("SELECT * FROM gasto WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function crear(array $d): int
    {
        $pdo = Database::conexion();
        $pdo->prepare("INSERT INTO gasto (fecha, proveedor_id, producto_id, cantidad, concepto, monto, observacion, activo)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$d['fecha'], $d['proveedor_id'], $d['producto_id'], $d['cantidad'],
                       $d['concepto'], $d['monto'], $d['observacion'], $d['activo']]);
        return (int) $pdo->lastInsertId();
    }

    public function actualizar(int $id, array $d): void
    {
        Database::conexion()
            ->prepare("UPDATE gasto SET fecha = ?, proveedor_id = ?, producto_id = ?, cantidad = ?,
                       concepto = ?, monto = ?, observacion = ?, activo = ? WHERE id = ?")
            ->execute([$d['fecha'], $d['proveedor_id'], $d['producto_id'], $d['cantidad'],
                       $d['concepto'], $d['monto'], $d['observacion'], $d['activo'], $id]);
    }

    public function cambiarEstado(int $id, int $activo): void
    {
        Database::conexion()->prepare("UPDATE gasto SET activo = ? WHERE id = ?")->execute([$activo, $id]);
    }
}

<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Repositorio genérico para tablas maestras SIMPLES (solo id + nombre + activo):
 * categoría y marca. Comparten exactamente la misma estructura, así que reusamos
 * un solo repo en vez de duplicar código.
 *
 * SEGURIDAD: el nombre de tabla NO viene del usuario, viene de una constante del
 * controlador y además se valida contra una lista blanca. Nunca se interpola
 * nada del request en el SQL; los valores van siempre por prepared statements.
 */
class MaestraSimpleRepository
{
    private const TABLAS = ['categoria', 'marca'];
    // Tablas que además tienen columna `imagen` (para el carrusel de la tienda).
    private const CON_IMAGEN = ['categoria'];

    private string $tabla;

    private function tieneImagen(): bool
    {
        return in_array($this->tabla, self::CON_IMAGEN, true);
    }

    public function __construct(string $tabla)
    {
        if (!in_array($tabla, self::TABLAS, true)) {
            throw new \InvalidArgumentException("Tabla no permitida: $tabla");
        }
        $this->tabla = $tabla;   // seguro: ya está en la lista blanca
    }

    /** @return array{rows: array, total: int} */
    public function listarPaginado(?string $q, ?int $activo, int $limit, int $offset): array
    {
        $pdo = Database::conexion();
        $where = [];
        $params = [];
        if ($q !== null && $q !== '') { $where[] = "nombre LIKE ?"; $params[] = "%{$q}%"; }
        if ($activo !== null)         { $where[] = "activo = ?";    $params[] = $activo; }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stC = $pdo->prepare("SELECT COUNT(*) FROM {$this->tabla} $sqlWhere");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        $cols = 'id, nombre, activo, creado_en' . ($this->tieneImagen() ? ', imagen' : '');
        $st = $pdo->prepare("SELECT $cols
                             FROM {$this->tabla} $sqlWhere
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
        $st = Database::conexion()->prepare("SELECT * FROM {$this->tabla} WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** ¿Ya existe otra fila con ese nombre? (para no duplicar). */
    public function nombreExiste(string $nombre, int $exceptoId = 0): bool
    {
        $st = Database::conexion()->prepare(
            "SELECT COUNT(*) FROM {$this->tabla} WHERE nombre = ? AND id <> ?"
        );
        $st->execute([$nombre, $exceptoId]);
        return (int) $st->fetchColumn() > 0;
    }

    public function crear(string $nombre, int $activo, ?string $imagen = null): int
    {
        $pdo = Database::conexion();
        if ($this->tieneImagen()) {
            $pdo->prepare("INSERT INTO {$this->tabla} (nombre, activo, imagen) VALUES (?, ?, ?)")
                ->execute([$nombre, $activo, $imagen]);
        } else {
            $pdo->prepare("INSERT INTO {$this->tabla} (nombre, activo) VALUES (?, ?)")
                ->execute([$nombre, $activo]);
        }
        return (int) $pdo->lastInsertId();
    }

    public function actualizar(int $id, string $nombre, int $activo, ?string $imagen = null): void
    {
        if ($this->tieneImagen()) {
            Database::conexion()
                ->prepare("UPDATE {$this->tabla} SET nombre = ?, activo = ?, imagen = ? WHERE id = ?")
                ->execute([$nombre, $activo, $imagen, $id]);
        } else {
            Database::conexion()
                ->prepare("UPDATE {$this->tabla} SET nombre = ?, activo = ? WHERE id = ?")
                ->execute([$nombre, $activo, $id]);
        }
    }

    public function cambiarEstado(int $id, int $activo): void
    {
        Database::conexion()
            ->prepare("UPDATE {$this->tabla} SET activo = ? WHERE id = ?")
            ->execute([$activo, $id]);
    }
}

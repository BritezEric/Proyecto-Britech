<?php

namespace App\Repositories;

use App\Core\Database;

/** Solicitudes de acceso mayorista (tienda B2B). */
class SolicitudMayoristaRepository
{
    /** Estado de la solicitud más reciente del cliente (o null si no hizo ninguna). */
    public function ultimaDe(int $clienteId): ?string
    {
        $st = Database::conexion()->prepare(
            "SELECT estado FROM solicitud_mayorista WHERE cliente_id = ? ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$clienteId]);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function tienePendiente(int $clienteId): bool
    {
        $st = Database::conexion()->prepare(
            "SELECT COUNT(*) FROM solicitud_mayorista WHERE cliente_id = ? AND estado = 'pendiente'"
        );
        $st->execute([$clienteId]);
        return (int) $st->fetchColumn() > 0;
    }

    public function crear(int $clienteId, ?string $mensaje): int
    {
        $pdo = Database::conexion();
        $pdo->prepare("INSERT INTO solicitud_mayorista (cliente_id, mensaje) VALUES (?, ?)")
            ->execute([$clienteId, $mensaje]);
        return (int) $pdo->lastInsertId();
    }

    public function buscarPorId(int $id): ?array
    {
        $st = Database::conexion()->prepare("SELECT * FROM solicitud_mayorista WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function resolver(int $id, string $estado, int $adminId): void
    {
        Database::conexion()
            ->prepare("UPDATE solicitud_mayorista
                       SET estado = ?, resuelto_en = NOW(), resuelto_por = ? WHERE id = ?")
            ->execute([$estado, $adminId, $id]);
    }

    /** Listado paginado para el admin (con nombre/email del cliente). */
    public function listarPaginado(?string $q, ?string $estado, int $limit, int $offset): array
    {
        $pdo = Database::conexion();
        $where = [];
        $params = [];
        if ($q !== null && $q !== '') {
            $where[] = "(c.nombre LIKE ? OR c.email LIKE ?)";
            $like = "%{$q}%"; array_push($params, $like, $like);
        }
        if ($estado !== null && $estado !== '') { $where[] = "s.estado = ?"; $params[] = $estado; }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stC = $pdo->prepare("SELECT COUNT(*) FROM solicitud_mayorista s JOIN cliente c ON c.id = s.cliente_id $sqlWhere");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        $sql = "SELECT s.id, s.estado, s.mensaje, s.creado_en, s.cliente_id,
                       c.nombre AS cliente, c.email
                FROM solicitud_mayorista s
                JOIN cliente c ON c.id = s.cliente_id
                $sqlWhere
                ORDER BY (s.estado = 'pendiente') DESC, s.id DESC
                LIMIT ? OFFSET ?";
        $st = $pdo->prepare($sql);
        $full = array_merge($params, [$limit, $offset]);
        foreach ($full as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }
}

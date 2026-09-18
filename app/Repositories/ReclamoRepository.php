<?php

namespace App\Repositories;

use App\Core\Database;

/** Reclamos de clientes (sobre un pedido) + hilo de mensajes. */
class ReclamoRepository
{
    public function crear(int $clienteId, int $pedidoId, string $asunto): int
    {
        $pdo = Database::conexion();
        $pdo->prepare("INSERT INTO reclamo (cliente_id, pedido_id, asunto) VALUES (?, ?, ?)")
            ->execute([$clienteId, $pedidoId, $asunto]);
        return (int) $pdo->lastInsertId();
    }

    public function fijarNumero(int $id, string $numero): void
    {
        Database::conexion()->prepare("UPDATE reclamo SET numero = ? WHERE id = ?")->execute([$numero, $id]);
    }

    public function agregarMensaje(int $reclamoId, string $autor, ?int $usuarioId, string $mensaje): void
    {
        Database::conexion()
            ->prepare("INSERT INTO reclamo_mensaje (reclamo_id, autor, usuario_id, mensaje) VALUES (?, ?, ?, ?)")
            ->execute([$reclamoId, $autor, $usuarioId, $mensaje]);
        Database::conexion()->prepare("UPDATE reclamo SET actualizado_en = NOW() WHERE id = ?")->execute([$reclamoId]);
    }

    public function fijarEstado(int $id, string $estado): void
    {
        Database::conexion()
            ->prepare("UPDATE reclamo SET estado = ?, actualizado_en = NOW() WHERE id = ?")
            ->execute([$estado, $id]);
    }

    public function buscarPorId(int $id): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT r.*, c.nombre AS cliente, p.numero AS pedido_numero
             FROM reclamo r
             JOIN cliente c ON c.id = r.cliente_id
             JOIN pedido  p ON p.id = r.pedido_id
             WHERE r.id = ?"
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function mensajesDe(int $reclamoId): array
    {
        $st = Database::conexion()->prepare(
            "SELECT m.autor, m.mensaje, m.creado_en, u.nombre AS usuario
             FROM reclamo_mensaje m
             LEFT JOIN usuario u ON u.id = m.usuario_id
             WHERE m.reclamo_id = ? ORDER BY m.id"
        );
        $st->execute([$reclamoId]);
        return $st->fetchAll();
    }

    /** Reclamos de un cliente (para "mis reclamos" en la tienda). */
    public function porCliente(int $clienteId): array
    {
        $st = Database::conexion()->prepare(
            "SELECT r.id, r.numero, r.asunto, r.estado, r.creado_en, p.numero AS pedido_numero
             FROM reclamo r JOIN pedido p ON p.id = r.pedido_id
             WHERE r.cliente_id = ? ORDER BY r.id DESC"
        );
        $st->execute([$clienteId]);
        return $st->fetchAll();
    }

    /** Listado para el staff (paginado, filtro por estado). */
    public function listarPaginado(?string $estado, int $limit, int $offset): array
    {
        $pdo = Database::conexion();
        $where = []; $params = [];
        if ($estado !== null && $estado !== '') { $where[] = "r.estado = ?"; $params[] = $estado; }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stC = $pdo->prepare("SELECT COUNT(*) FROM reclamo r $sqlWhere");
        $stC->execute($params);
        $total = (int) $stC->fetchColumn();

        $sql = "SELECT r.id, r.numero, r.asunto, r.estado, r.creado_en,
                       c.nombre AS cliente, p.numero AS pedido_numero
                FROM reclamo r
                JOIN cliente c ON c.id = r.cliente_id
                JOIN pedido  p ON p.id = r.pedido_id
                $sqlWhere
                ORDER BY r.id DESC LIMIT ? OFFSET ?";
        $st = $pdo->prepare($sql);
        $full = array_merge($params, [$limit, $offset]);
        foreach ($full as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        $st->execute();
        return ['rows' => $st->fetchAll(), 'total' => $total];
    }
}

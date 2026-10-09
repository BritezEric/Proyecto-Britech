<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Seguimiento de envíos en tiempo real. Guarda la posición que emite el
 * repartidor (o un TrackingTag) y la sirve para el mapa del cliente y del admin.
 */
class TrackingRepository
{
    /** Devuelve el repartidor dueño de ese token (emisor autenticado) o null. */
    public function repartidorPorToken(string $token): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT id, nombre FROM repartidor WHERE token = ? AND activo = 1"
        );
        $st->execute([$token]);
        return $st->fetch() ?: null;
    }

    /** Guarda la posición actual del repartidor y la agrega al historial. */
    public function guardarPosicion(int $repartidorId, float $lat, float $lng): void
    {
        $pdo = Database::conexion();
        $pdo->prepare(
            "UPDATE repartidor SET ult_lat = ?, ult_lng = ?, ult_pos_en = NOW(), en_linea = 1 WHERE id = ?"
        )->execute([$lat, $lng, $repartidorId]);
        $pdo->prepare(
            "INSERT INTO repartidor_posicion (repartidor_id, lat, lng) VALUES (?, ?, ?)"
        )->execute([$repartidorId, $lat, $lng]);
    }

    /** Envíos en curso (despachado / en camino) con repartidor y su última posición. Para el mapa del admin. */
    public function enviosEnCurso(): array
    {
        $st = Database::conexion()->query(
            "SELECT e.id AS envio_id, e.estado, e.dest_lat, e.dest_lng,
                    COALESCE(p.numero, CONCAT('V-', e.venta_id)) AS referencia,
                    r.id AS repartidor_id, r.nombre AS repartidor,
                    r.ult_lat, r.ult_lng, r.ult_pos_en, r.en_linea
             FROM envio e
             JOIN repartidor r ON r.id = e.repartidor_id
             LEFT JOIN pedido p ON p.id = e.pedido_id
             WHERE e.estado IN ('despachado', 'en_camino')
               AND r.ult_lat IS NOT NULL
             ORDER BY r.ult_pos_en DESC"
        );
        return $st->fetchAll();
    }

    /** Seguimiento de UN pedido: su envío + la posición del repartidor asignado. */
    public function seguimientoPorPedido(int $pedidoId): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT e.id AS envio_id, e.estado, e.dest_lat, e.dest_lng,
                    r.nombre AS repartidor, r.ult_lat, r.ult_lng, r.ult_pos_en, r.en_linea
             FROM envio e
             LEFT JOIN repartidor r ON r.id = e.repartidor_id
             WHERE e.pedido_id = ?"
        );
        $st->execute([$pedidoId]);
        return $st->fetch() ?: null;
    }

    /** Últimas N posiciones de un repartidor (rastro del recorrido). */
    public function rastro(int $repartidorId, int $limit = 50): array
    {
        $st = Database::conexion()->prepare(
            "SELECT lat, lng, creado_en FROM repartidor_posicion
             WHERE repartidor_id = ? ORDER BY id DESC LIMIT ?"
        );
        $st->bindValue(1, $repartidorId, \PDO::PARAM_INT);
        $st->bindValue(2, $limit, \PDO::PARAM_INT);
        $st->execute();
        return array_reverse($st->fetchAll());
    }
}

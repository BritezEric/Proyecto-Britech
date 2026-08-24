<?php

namespace App\Repositories;

use App\Core\Database;

/** Envío de un pedido (dirección, costo, estado, seguimiento). */
class EnvioRepository
{
    public function crear(int $pedidoId, ?int $empresaId, string $direccion, ?string $localidad, float $costo): void
    {
        Database::conexion()
            ->prepare("INSERT INTO envio (pedido_id, empresa_envio_id, direccion, localidad, costo)
                       VALUES (?, ?, ?, ?, ?)")
            ->execute([$pedidoId, $empresaId, $direccion, $localidad, $costo]);
    }

    /** Datos del envío de un pedido (con el nombre de la empresa). */
    public function dePedido(int $pedidoId): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT e.empresa_envio_id, e.direccion, e.localidad, e.costo, e.estado, e.tracking,
                    em.nombre AS empresa
             FROM envio e
             LEFT JOIN empresa_envio em ON em.id = e.empresa_envio_id
             WHERE e.pedido_id = ?"
        );
        $st->execute([$pedidoId]);
        return $st->fetch() ?: null;
    }

    public function actualizar(int $pedidoId, string $estado, ?string $tracking): void
    {
        Database::conexion()
            ->prepare("UPDATE envio SET estado = ?, tracking = ? WHERE pedido_id = ?")
            ->execute([$estado, $tracking, $pedidoId]);
    }

    /** Actualiza estado + seguimiento + dirección/localidad. Si la dirección viene
     *  vacía (null), conserva la actual (no la pisa). */
    public function actualizarDatos(int $pedidoId, string $estado, ?string $tracking, ?string $direccion, ?string $localidad): void
    {
        Database::conexion()
            ->prepare("UPDATE envio SET estado = ?, tracking = ?,
                       direccion = COALESCE(?, direccion), localidad = ?
                       WHERE pedido_id = ?")
            ->execute([$estado, $tracking, $direccion, $localidad, $pedidoId]);
    }
}

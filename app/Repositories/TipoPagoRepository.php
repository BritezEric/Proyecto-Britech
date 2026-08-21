<?php

namespace App\Repositories;

use App\Core\Database;

/** Repositorio de medios de pago (efectivo, transferencia, …). */
class TipoPagoRepository
{
    public function listar(): array
    {
        return Database::conexion()
            ->query("SELECT id, nombre FROM tipo_pago WHERE activo = 1 ORDER BY id")
            ->fetchAll();
    }
}

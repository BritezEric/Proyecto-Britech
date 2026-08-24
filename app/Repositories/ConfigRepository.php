<?php

namespace App\Repositories;

use App\Core\Database;

/** Config de la tienda (clave/valor): datos de transferencia, etc. */
class ConfigRepository
{
    /** Devuelve toda la config como [clave => valor]. */
    public function todos(): array
    {
        $out = [];
        foreach (Database::conexion()->query("SELECT clave, valor FROM config_tienda") as $r) {
            $out[$r['clave']] = $r['valor'];
        }
        return $out;
    }

    /** Upsert de varias claves. */
    public function guardar(array $kv): void
    {
        $st = Database::conexion()->prepare(
            "INSERT INTO config_tienda (clave, valor) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE valor = VALUES(valor)"
        );
        foreach ($kv as $clave => $valor) { $st->execute([$clave, $valor]); }
    }
}

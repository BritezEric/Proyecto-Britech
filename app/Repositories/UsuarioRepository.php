<?php

namespace App\Repositories;

use App\Core\Database;

/** Repositorio de usuarios (los que entran al sistema: admin / vendedor). */
class UsuarioRepository
{
    public function buscarPorEmail(string $email): ?array
    {
        $stmt = Database::conexion()->prepare(
            "SELECT id, nombre, email, password_hash, rol_id, activo, email_verificado
             FROM usuario WHERE email = ?"
        );
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function buscarPorId(int $id): ?array
    {
        $stmt = Database::conexion()->prepare(
            "SELECT id, nombre, email, rol_id, activo, email_verificado
             FROM usuario WHERE id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}

<?php

namespace App\Repositories;

use App\Core\Database;

/** Repositorio de usuarios (los que entran al sistema: admin / vendedor). */
class UsuarioRepository
{
    public function buscarPorEmail(string $email): ?array
    {
        $stmt = Database::conexion()->prepare(
            "SELECT u.id, u.nombre, u.email, u.password_hash, u.rol_id,
                    u.activo, u.email_verificado, r.nombre AS rol
             FROM usuario u
             JOIN rol r ON r.id = u.rol_id
             WHERE u.email = ?"
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

    /** Crea un usuario SIN contraseña (la define él mismo al verificar). */
    public function crear(string $nombre, string $email, int $rolId): int
    {
        $pdo = Database::conexion();
        $pdo->prepare(
            "INSERT INTO usuario (nombre, email, password_hash, rol_id, email_verificado)
             VALUES (?, ?, '', ?, 0)"
        )->execute([$nombre, $email, $rolId]);
        return (int) $pdo->lastInsertId();
    }

    /** Define la contraseña y marca el email como verificado (al verificar). */
    public function definirPassword(int $id, string $passwordHash): void
    {
        Database::conexion()
            ->prepare("UPDATE usuario SET password_hash = ?, email_verificado = 1 WHERE id = ?")
            ->execute([$passwordHash, $id]);
    }

    /** Solo cambia la contraseña (al recuperar). */
    public function actualizarPassword(int $id, string $passwordHash): void
    {
        Database::conexion()
            ->prepare("UPDATE usuario SET password_hash = ? WHERE id = ?")
            ->execute([$passwordHash, $id]);
    }
}

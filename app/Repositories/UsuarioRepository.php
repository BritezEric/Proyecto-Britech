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

    /** Igual que buscarPorId pero incluye el nombre del rol (para armar la sesión). */
    public function buscarPorIdConRol(int $id): ?array
    {
        $stmt = Database::conexion()->prepare(
            "SELECT u.id, u.nombre, u.email, u.rol_id, u.activo, r.nombre AS rol
             FROM usuario u JOIN rol r ON r.id = u.rol_id
             WHERE u.id = ?"
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

    // ---- Telegram (avisos al staff) ----
    // Las columnas telegram_* las agrega database/schema_telegram.sql. Si todavía no
    // se corrió, estos métodos se tragan el error y el sistema sigue andando (no-op).

    /** chat_id de Telegram del usuario, o null si no está vinculado. */
    public function telegramChatId(int $id): ?string
    {
        try {
            $st = Database::conexion()->prepare("SELECT telegram_chat_id FROM usuario WHERE id = ?");
            $st->execute([$id]);
            $v = $st->fetchColumn();
            return $v !== false && $v !== null && $v !== '' ? (string) $v : null;
        } catch (\Throwable $e) { return null; }
    }

    /** Guarda (o regenera) el código temporal de vinculación del usuario. */
    public function fijarTelegramCodigo(int $id, string $codigo): void
    {
        try {
            Database::conexion()
                ->prepare("UPDATE usuario SET telegram_codigo = ? WHERE id = ?")
                ->execute([$codigo, $id]);
        } catch (\Throwable $e) { /* schema no aplicado: no-op */ }
    }

    /** Busca al usuario dueño de un código de vinculación (para completar el /start). */
    public function porTelegramCodigo(string $codigo): ?array
    {
        try {
            $st = Database::conexion()->prepare("SELECT id, nombre FROM usuario WHERE telegram_codigo = ? AND activo = 1");
            $st->execute([$codigo]);
            return $st->fetch() ?: null;
        } catch (\Throwable $e) { return null; }
    }

    /** Vincula el chat de Telegram al usuario y limpia el código ya usado. */
    public function vincularTelegram(int $id, string $chatId): void
    {
        try {
            Database::conexion()
                ->prepare("UPDATE usuario SET telegram_chat_id = ?, telegram_codigo = NULL WHERE id = ?")
                ->execute([$chatId, $id]);
        } catch (\Throwable $e) { /* schema no aplicado: no-op */ }
    }

    /** Usuario staff dueño de un chat de Telegram ya vinculado (para el asistente). */
    public function porTelegramChatId(string $chatId): ?array
    {
        try {
            $st = Database::conexion()->prepare(
                "SELECT id, nombre, rol_id FROM usuario WHERE telegram_chat_id = ? AND activo = 1"
            );
            $st->execute([$chatId]);
            return $st->fetch() ?: null;
        } catch (\Throwable $e) { return null; }
    }
}

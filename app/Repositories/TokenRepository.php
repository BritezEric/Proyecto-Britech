<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Tokens de un solo uso (verificación de email / reset de contraseña).
 * Se guarda el HASH del token, nunca el token en texto: si se filtra la base,
 * los tokens no sirven. El texto plano solo viaja en el link del correo.
 */
class TokenRepository
{
    /** Crea un token, guarda su hash y devuelve el token EN TEXTO (para el link). */
    public function crear(int $usuarioId, string $tipo, int $horasValidez): string
    {
        $token = bin2hex(random_bytes(32));           // 64 caracteres aleatorios
        $hash  = hash('sha256', $token);
        $expira = (new \DateTime("+{$horasValidez} hours"))->format('Y-m-d H:i:s');

        Database::conexion()
            ->prepare("INSERT INTO usuario_token (usuario_id, tipo, token_hash, expira_en)
                       VALUES (?, ?, ?, ?)")
            ->execute([$usuarioId, $tipo, $hash, $expira]);

        return $token;
    }

    /** Busca un token válido (del tipo dado, no usado y no vencido). */
    public function buscarValido(string $token, string $tipo): ?array
    {
        $stmt = Database::conexion()->prepare(
            "SELECT id, usuario_id FROM usuario_token
             WHERE token_hash = ? AND tipo = ? AND usado = 0 AND expira_en > NOW()"
        );
        $stmt->execute([hash('sha256', $token), $tipo]);
        return $stmt->fetch() ?: null;
    }

    public function marcarUsado(int $id): void
    {
        Database::conexion()
            ->prepare("UPDATE usuario_token SET usado = 1 WHERE id = ?")
            ->execute([$id]);
    }
}

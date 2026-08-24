<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Tokens de un solo uso para los CLIENTES de la tienda (verificación de email /
 * definir contraseña). Igual que TokenRepository pero sobre `cliente_token`.
 * Se guarda el HASH del token, nunca el token en texto.
 */
class ClienteTokenRepository
{
    /** Crea un token, guarda su hash y devuelve el token EN TEXTO (para el link). */
    public function crear(int $clienteId, string $tipo, int $horasValidez): string
    {
        $token  = bin2hex(random_bytes(32));
        $hash   = hash('sha256', $token);
        $expira = (new \DateTime("+{$horasValidez} hours"))->format('Y-m-d H:i:s');

        Database::conexion()
            ->prepare("INSERT INTO cliente_token (cliente_id, tipo, token_hash, expira_en)
                       VALUES (?, ?, ?, ?)")
            ->execute([$clienteId, $tipo, $hash, $expira]);

        return $token;
    }

    public function buscarValido(string $token, string $tipo): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT id, cliente_id FROM cliente_token
             WHERE token_hash = ? AND tipo = ? AND usado = 0 AND expira_en > NOW()"
        );
        $st->execute([hash('sha256', $token), $tipo]);
        return $st->fetch() ?: null;
    }

    public function marcarUsado(int $id): void
    {
        Database::conexion()->prepare("UPDATE cliente_token SET usado = 1 WHERE id = ?")->execute([$id]);
    }
}

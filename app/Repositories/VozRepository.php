<?php

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Bitácora del asistente de voz (auditoría para el admin): guarda cada
 * interacción (qué se dijo, qué respondió, quién, desde dónde). La tabla se
 * crea sola la primera vez, así se deploya sin migración manual.
 */
class VozRepository
{
    private function asegurarTabla(): void
    {
        Database::conexion()->exec(
            "CREATE TABLE IF NOT EXISTS voz_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT NULL,
                texto TEXT NOT NULL,
                respuesta TEXT NULL,
                origen VARCHAR(20) NOT NULL DEFAULT 'pos',
                creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_voz_log_fecha (creado_en)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** Guarda una interacción. Nunca rompe el flujo del asistente si falla. */
    public function registrar(?int $usuarioId, string $texto, string $respuesta, string $origen = 'pos'): void
    {
        try {
            $this->asegurarTabla();
            $st = Database::conexion()->prepare(
                "INSERT INTO voz_log (usuario_id, texto, respuesta, origen) VALUES (?, ?, ?, ?)"
            );
            $st->execute([$usuarioId ?: null, mb_substr($texto, 0, 1000), mb_substr($respuesta, 0, 2000), $origen]);
        } catch (\Throwable $e) {
            error_log('[Voz] no se pudo guardar el log: ' . $e->getMessage());
        }
    }

    /** Lista interacciones recientes (con el nombre del usuario si lo hay). */
    public function listar(int $limite, int $offset): array
    {
        $this->asegurarTabla();
        $st = Database::conexion()->prepare(
            "SELECT l.id, l.texto, l.respuesta, l.origen, l.creado_en,
                    COALESCE(u.nombre, 'Asistente') AS usuario
               FROM voz_log l
               LEFT JOIN usuario u ON u.id = l.usuario_id
              ORDER BY l.id DESC
              LIMIT ? OFFSET ?"
        );
        $st->bindValue(1, $limite, PDO::PARAM_INT);
        $st->bindValue(2, $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function total(): int
    {
        $this->asegurarTabla();
        return (int) Database::conexion()->query("SELECT COUNT(*) FROM voz_log")->fetchColumn();
    }
}

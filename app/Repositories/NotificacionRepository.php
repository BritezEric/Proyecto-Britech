<?php

namespace App\Repositories;

use App\Core\Database;

/** Notificaciones del panel admin (bandeja leídas / no leídas). */
class NotificacionRepository
{
    /** Registra un evento. No rompe el flujo si algo falla (se traga la excepción). */
    public function crear(string $tipo, string $titulo, ?string $ir = null, ?int $refId = null): void
    {
        try {
            Database::conexion()
                ->prepare("INSERT INTO notificacion (tipo, titulo, ir, ref_id) VALUES (?, ?, ?, ?)")
                ->execute([$tipo, mb_substr($titulo, 0, 200), $ir, $refId]);
        } catch (\Throwable $e) { /* una notificación nunca debe frenar la acción real */ }
    }

    public function contarNoLeidas(): int
    {
        return (int) Database::conexion()->query("SELECT COUNT(*) FROM notificacion WHERE leida = 0")->fetchColumn();
    }

    /** Notificaciones NO leídas (la campana es una bandeja de pendientes). */
    public function ultimas(int $limit = 20): array
    {
        $st = Database::conexion()->prepare(
            "SELECT id, tipo, titulo, ir, ref_id, leida, creado_en
             FROM notificacion WHERE leida = 0 ORDER BY id DESC LIMIT ?"
        );
        $st->bindValue(1, $limit, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function marcarLeida(int $id): void
    {
        Database::conexion()->prepare("UPDATE notificacion SET leida = 1 WHERE id = ?")->execute([$id]);
    }

    public function marcarTodasLeidas(): void
    {
        Database::conexion()->query("UPDATE notificacion SET leida = 1 WHERE leida = 0");
    }
}

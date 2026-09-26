<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Notificaciones por destinatario (fan-out). Cada fila es un aviso PARA UNA
 * persona: staff (usuario_id) o cliente (cliente_id). Así la marca de "leída"
 * es por persona. Una notificación nunca debe frenar la acción real: si algo
 * falla al notificar, se traga la excepción.
 */
class NotificacionRepository
{
    /** Inserta una fila (un aviso para una persona). Uso interno. */
    private function insertar(?int $usuarioId, ?int $clienteId, string $tipo, string $titulo, ?string $ir, ?int $refId, string $nivel): void
    {
        try {
            Database::conexion()
                ->prepare("INSERT INTO notificacion (usuario_id, cliente_id, tipo, nivel, titulo, ir, ref_id)
                           VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$usuarioId, $clienteId, $tipo, $nivel, mb_substr($titulo, 0, 200), $ir, $refId]);
        } catch (\Throwable $e) { /* no rompemos el flujo real por un aviso */ }
    }

    /** Avisa a TODO el staff activo (admin + vendedores). Firma compatible con el código previo. */
    public function crear(string $tipo, string $titulo, ?string $ir = null, ?int $refId = null, string $nivel = 'info'): void
    {
        foreach ($this->idsStaff() as $uid) {
            $this->insertar($uid, null, $tipo, $titulo, $ir, $refId, $nivel);
        }
    }

    /** Avisa a todos los usuarios de un rol (1 admin, 2 vendedor). */
    public function crearRol(int $rolId, string $tipo, string $titulo, ?string $ir = null, ?int $refId = null, string $nivel = 'info'): void
    {
        foreach ($this->idsPorRol($rolId) as $uid) {
            $this->insertar($uid, null, $tipo, $titulo, $ir, $refId, $nivel);
        }
    }

    /** Avisa a un usuario staff puntual. */
    public function crearUsuario(int $usuarioId, string $tipo, string $titulo, ?string $ir = null, ?int $refId = null, string $nivel = 'info'): void
    {
        $this->insertar($usuarioId, null, $tipo, $titulo, $ir, $refId, $nivel);
    }

    /** Avisa a un cliente de la tienda. */
    public function crearCliente(int $clienteId, string $tipo, string $titulo, ?string $ir = null, ?int $refId = null, string $nivel = 'info'): void
    {
        $this->insertar(null, $clienteId, $tipo, $titulo, $ir, $refId, $nivel);
    }

    /** Evita repetir el mismo aviso al staff (ej. stock_bajo): ¿hay alguno sin leer de ese tipo/ref? */
    public function existeNoLeida(string $tipo, int $refId): bool
    {
        $st = Database::conexion()->prepare(
            "SELECT 1 FROM notificacion WHERE leida = 0 AND usuario_id IS NOT NULL AND tipo = ? AND ref_id = ? LIMIT 1"
        );
        $st->execute([$tipo, $refId]);
        return (bool) $st->fetchColumn();
    }

    // ---- Bandeja del STAFF (por usuario) ----
    public function contarUsuario(int $usuarioId): int
    {
        $st = Database::conexion()->prepare("SELECT COUNT(*) FROM notificacion WHERE usuario_id = ? AND leida = 0");
        $st->execute([$usuarioId]);
        return (int) $st->fetchColumn();
    }

    public function paraUsuario(int $usuarioId, int $limit = 20): array
    {
        $st = Database::conexion()->prepare(
            "SELECT id, tipo, nivel, titulo, ir, ref_id, leida, creado_en
             FROM notificacion WHERE usuario_id = ? AND leida = 0 ORDER BY id DESC LIMIT ?"
        );
        $st->bindValue(1, $usuarioId, \PDO::PARAM_INT);
        $st->bindValue(2, $limit, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function marcarLeidaUsuario(int $id, int $usuarioId): void
    {
        Database::conexion()->prepare("UPDATE notificacion SET leida = 1 WHERE id = ? AND usuario_id = ?")
            ->execute([$id, $usuarioId]);
    }

    public function marcarTodasUsuario(int $usuarioId): void
    {
        Database::conexion()->prepare("UPDATE notificacion SET leida = 1 WHERE usuario_id = ? AND leida = 0")
            ->execute([$usuarioId]);
    }

    // ---- Bandeja del CLIENTE ----
    public function contarCliente(int $clienteId): int
    {
        $st = Database::conexion()->prepare("SELECT COUNT(*) FROM notificacion WHERE cliente_id = ? AND leida = 0");
        $st->execute([$clienteId]);
        return (int) $st->fetchColumn();
    }

    public function paraCliente(int $clienteId, int $limit = 20): array
    {
        $st = Database::conexion()->prepare(
            "SELECT id, tipo, nivel, titulo, ir, ref_id, leida, creado_en
             FROM notificacion WHERE cliente_id = ? AND leida = 0 ORDER BY id DESC LIMIT ?"
        );
        $st->bindValue(1, $clienteId, \PDO::PARAM_INT);
        $st->bindValue(2, $limit, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    public function marcarLeidaCliente(int $id, int $clienteId): void
    {
        Database::conexion()->prepare("UPDATE notificacion SET leida = 1 WHERE id = ? AND cliente_id = ?")
            ->execute([$id, $clienteId]);
    }

    public function marcarTodasCliente(int $clienteId): void
    {
        Database::conexion()->prepare("UPDATE notificacion SET leida = 1 WHERE cliente_id = ? AND leida = 0")
            ->execute([$clienteId]);
    }

    // ---- Helpers de destinatarios ----
    /** @return int[] ids de todo el staff activo. */
    public function idsStaff(): array
    {
        return array_map('intval', Database::conexion()
            ->query("SELECT id FROM usuario WHERE activo = 1")->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return int[] ids de los usuarios activos de un rol. */
    public function idsPorRol(int $rolId): array
    {
        $st = Database::conexion()->prepare("SELECT id FROM usuario WHERE activo = 1 AND rol_id = ?");
        $st->execute([$rolId]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }
}

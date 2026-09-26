<?php

namespace App\Services;

use App\Core\Mailer;
use App\Repositories\ClienteRepository;

/**
 * Avisos por correo al cliente (Fase 2 de notificaciones: in-app + email).
 * Un aviso nunca debe frenar la acción real: si el correo falla, se traga el error.
 * Complementa a la campana in-app (NotificacionRepository::crearCliente).
 */
class AvisoService
{
    /** Manda un correo al cliente. $cuerpo = HTML del contenido (sin <html>). */
    public static function email(int $clienteId, string $asunto, string $cuerpo): void
    {
        try {
            $c = (new ClienteRepository())->buscarCompleto($clienteId);
            $email = trim((string) ($c['email'] ?? ''));
            if ($email === '') return;   // cliente sin correo (ej. cargado por el admin)
            Mailer::enviar($email, $c['nombre'] ?? '', $asunto, self::plantilla($c['nombre'] ?? '', $cuerpo));
        } catch (\Throwable $e) { /* el aviso es secundario */ }
    }

    /** Envuelve el contenido en un saludo + pie común de la tienda. */
    private static function plantilla(string $nombre, string $cuerpo): string
    {
        $hola = $nombre !== '' ? "<p>Hola " . htmlspecialchars($nombre) . ",</p>" : '';
        return "<div style='font-family:Arial,sans-serif;max-width:480px'>
            {$hola}{$cuerpo}
            <hr style='border:none;border-top:1px solid #eee;margin:18px 0'>
            <p style='color:#888;font-size:12px'>Britech · seguí tus pedidos desde <em>Mi cuenta</em> en la tienda.</p>
        </div>";
    }
}

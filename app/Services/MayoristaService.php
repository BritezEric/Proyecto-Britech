<?php

namespace App\Services;

use App\Core\Mailer;
use App\Core\ValidacionException;
use App\Repositories\ClienteRepository;
use App\Repositories\SolicitudMayoristaRepository;

/**
 * Acceso mayorista B2B: el cliente (minorista por defecto) pide acceso, el admin
 * aprueba o rechaza. Aprobado => el cliente puede navegar con precios mayoristas.
 */
class MayoristaService
{
    private SolicitudMayoristaRepository $solic;
    private ClienteRepository $clientes;

    public function __construct()
    {
        $this->solic    = new SolicitudMayoristaRepository();
        $this->clientes = new ClienteRepository();
    }

    /** Estado para mostrarle al cliente: si ya es mayorista y cómo va su solicitud. */
    public function estadoCliente(int $clienteId): array
    {
        return [
            'mayorista_aprobado' => $this->clientes->esMayoristaAprobado($clienteId),
            'solicitud_estado'   => $this->solic->ultimaDe($clienteId),   // null|pendiente|aprobada|rechazada
        ];
    }

    public function solicitar(int $clienteId, ?string $mensaje): void
    {
        if ($this->clientes->esMayoristaAprobado($clienteId)) {
            throw new ValidacionException('Ya tenés acceso mayorista aprobado.');
        }
        if ($this->solic->tienePendiente($clienteId)) {
            throw new ValidacionException('Ya tenés una solicitud pendiente de aprobación.');
        }
        $this->solic->crear($clienteId, trim($mensaje ?? '') ?: null);
        (new \App\Repositories\NotificacionRepository())
            ->crear('solicitud', 'Nueva solicitud de cuenta mayorista', 'solicitudes', $clienteId);
    }

    public function resolver(int $id, string $estado, int $adminId): void
    {
        if (!in_array($estado, ['aprobada', 'rechazada'], true)) {
            throw new ValidacionException('Estado inválido.');
        }
        $s = $this->solic->buscarPorId($id);
        if ($s === null) {
            throw new ValidacionException('La solicitud no existe.');
        }
        if ($s['estado'] !== 'pendiente') {
            throw new ValidacionException('La solicitud ya fue resuelta.');
        }
        $this->solic->resolver($id, $estado, $adminId);
        $this->clientes->aprobarMayorista((int) $s['cliente_id'], $estado === 'aprobada' ? 1 : 0);

        // Avisar al cliente por correo. Si el correo falla, no rompe la resolución.
        $this->notificar((int) $s['cliente_id'], $estado);
    }

    /** Manda el correo al cliente avisando si su acceso mayorista fue aprobado o rechazado. */
    private function notificar(int $clienteId, string $estado): void
    {
        try {
            $c = $this->clientes->buscarCompleto($clienteId);
            $email = trim((string) ($c['email'] ?? ''));
            if ($email === '') return;   // cliente sin email (ej. cargado por el admin sin correo)
            $nombre = $c['nombre'] ?? '';

            $config    = require dirname(__DIR__, 2) . '/config/config.php';
            $urlTienda = $config['app']['url'] . '/tienda.html';

            if ($estado === 'aprobada') {
                $asunto = '¡Tu acceso mayorista fue aprobado! - Britech';
                $cuerpo = "<h2>¡Buenas noticias, {$nombre}!</h2>
                    <p>Aprobamos tu solicitud de <strong>acceso mayorista</strong>. Ya podés
                    activar el modo <strong>Mayorista</strong> desde tu cuenta y ver los precios
                    mayoristas en toda la tienda.</p>
                    <p><a href=\"{$urlTienda}\">Ir a la tienda</a></p>";
            } else {
                $asunto = 'Novedades sobre tu solicitud mayorista - Britech';
                $cuerpo = "<h2>Hola {$nombre}</h2>
                    <p>Por ahora no pudimos aprobar tu solicitud de <strong>acceso mayorista</strong>.
                    Si creés que es un error o querés más información, respondé este correo y te ayudamos.</p>
                    <p><a href=\"{$urlTienda}\">Ir a la tienda</a></p>";
            }
            Mailer::enviar($email, $nombre, $asunto, $cuerpo);
        } catch (\Throwable $e) {
            // El aviso es secundario: nunca debe frenar la aprobación/rechazo.
        }
    }
}

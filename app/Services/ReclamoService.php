<?php

namespace App\Services;

use App\Core\ValidacionException;
use App\Repositories\PedidoRepository;
use App\Repositories\ReclamoRepository;
use App\Repositories\NotificacionRepository;

/**
 * ReclamoService — reclamos de clientes sobre un pedido, con seguimiento.
 * El cliente abre el reclamo desde uno de SUS pedidos; el staff lo gestiona.
 */
class ReclamoService
{
    private const ESTADOS = ['abierto', 'en_revision', 'resuelto', 'rechazado'];

    private ReclamoRepository $repo;

    public function __construct()
    {
        $this->repo = new ReclamoRepository();
    }

    /** El cliente abre un reclamo sobre un pedido propio. */
    public function abrir(int $clienteId, int $pedidoId, string $asunto, string $descripcion): array
    {
        $asunto      = trim($asunto);
        $descripcion = trim($descripcion);
        if (mb_strlen($asunto) < 3)       throw new ValidacionException('Contanos el asunto del reclamo.');
        if (mb_strlen($descripcion) < 5)  throw new ValidacionException('Describí un poco más el problema.');

        $pedido = (new PedidoRepository())->buscarPorId($pedidoId);
        if ($pedido === null || (int) $pedido['cliente_id'] !== $clienteId) {
            throw new ValidacionException('Ese pedido no es tuyo.');
        }

        $id = $this->repo->crear($clienteId, $pedidoId, $asunto);
        $numero = 'R-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $this->repo->fijarNumero($id, $numero);
        // La descripción inicial queda como el primer mensaje del cliente.
        $this->repo->agregarMensaje($id, 'cliente', null, $descripcion);

        (new NotificacionRepository())->crear(
            'reclamo', "Nuevo reclamo {$numero} · pedido {$pedido['numero']}", 'reclamos', $id
        );

        return ['reclamo_id' => $id, 'numero' => $numero];
    }

    /** El cliente agrega un mensaje a un reclamo propio. */
    public function mensajeCliente(int $reclamoId, int $clienteId, string $mensaje): void
    {
        $mensaje = trim($mensaje);
        if ($mensaje === '') throw new ValidacionException('Escribí tu mensaje.');
        $r = $this->repo->buscarPorId($reclamoId);
        if ($r === null || (int) $r['cliente_id'] !== $clienteId) {
            throw new ValidacionException('Ese reclamo no es tuyo.');
        }
        $this->repo->agregarMensaje($reclamoId, 'cliente', null, $mensaje);
    }

    /** El staff responde un reclamo. */
    public function mensajeStaff(int $reclamoId, int $usuarioId, string $mensaje): void
    {
        $mensaje = trim($mensaje);
        if ($mensaje === '') throw new ValidacionException('Escribí una respuesta.');
        if ($this->repo->buscarPorId($reclamoId) === null) throw new ValidacionException('El reclamo no existe.');
        $this->repo->agregarMensaje($reclamoId, 'staff', $usuarioId, $mensaje);
    }

    /** El staff cambia el estado del reclamo. */
    public function cambiarEstado(int $reclamoId, string $estado): void
    {
        if (!in_array($estado, self::ESTADOS, true)) throw new ValidacionException('Estado inválido.');
        if ($this->repo->buscarPorId($reclamoId) === null) throw new ValidacionException('El reclamo no existe.');
        $this->repo->fijarEstado($reclamoId, $estado);
    }
}

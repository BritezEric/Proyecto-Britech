<?php

namespace App\Services;

use App\Repositories\RepartidorRepository;
use App\Core\Database;
use App\Core\ValidacionException;

/** Lógica + validaciones del ABM de repartidores y su reporte de paga. */
class RepartidorService
{
    private RepartidorRepository $repo;

    public function __construct()
    {
        $this->repo = new RepartidorRepository();
    }

    public function listar(?string $q, ?int $activo, int $limit, int $offset): array
    {
        return $this->repo->listarPaginado($q, $activo, $limit, $offset);
    }

    public function guardar(array $in): int
    {
        $nombre   = trim($in['nombre'] ?? '');
        $telefono = trim($in['telefono'] ?? '') ?: null;
        if (mb_strlen($nombre) < 2) {
            throw new ValidacionException('El nombre es obligatorio (mínimo 2 caracteres).');
        }
        $id = (int) ($in['id'] ?? 0);
        if ($this->repo->nombreExiste($nombre, $id)) {
            throw new ValidacionException('Ya existe un repartidor con ese nombre.');
        }
        $activo = isset($in['activo']) ? (int) (bool) $in['activo'] : 1;

        if ($id > 0) {
            if ($this->repo->buscarPorId($id) === null) {
                throw new ValidacionException('El repartidor no existe.');
            }
            $this->repo->actualizar($id, $nombre, $telefono, $activo);
            return $id;
        }
        return $this->repo->crear($nombre, $telefono, $activo);
    }

    public function baja(int $id): void
    {
        if ($this->repo->buscarPorId($id) === null) {
            throw new ValidacionException('El repartidor no existe.');
        }
        $this->repo->cambiarEstado($id, 0);
    }

    /** Detalle de un repartidor + su paga de un día (por defecto hoy). */
    public function detalle(int $id, ?string $fecha): array
    {
        $rep = $this->repo->buscarPorId($id);
        if ($rep === null) {
            throw new ValidacionException('El repartidor no existe.');
        }
        // "Hoy" según MySQL (los timestamps de envío usan su reloj; PHP puede estar
        // en otra zona horaria y arruinar la comparación cerca de medianoche).
        $fecha = ($fecha && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha))
            ? $fecha
            : Database::conexion()->query('SELECT CURDATE()')->fetchColumn();
        $porBarrio = $this->repo->pagaPorDia($id, $fecha);
        $total = 0.0; $envios = 0;
        foreach ($porBarrio as $b) { $total += (float) $b['subtotal']; $envios += (int) $b['cantidad']; }
        return [
            'repartidor'  => ['id' => (int) $rep['id'], 'nombre' => $rep['nombre'], 'telefono' => $rep['telefono']],
            'fecha'       => $fecha,
            'por_barrio'  => $porBarrio,
            'envios'      => $envios,
            'total'       => round($total, 2),
            'derivados'   => $this->repo->enviosDerivados($id, $fecha),
            'activos'     => $this->repo->enviosActivos($id),
            'serie'       => $this->repo->serieDias($id),
        ];
    }

    /** Envíos de moto activos sin repartidor (para el tablero de derivación). */
    public function sinAsignar(): array
    {
        return $this->repo->sinAsignar();
    }

    /** Cambia el estado de un envío (marcar salida/entregado desde el tablero). */
    public function cambiarEstadoEnvio(int $envioId, string $estado): void
    {
        $repo = new \App\Repositories\EnvioRepository();
        $env = $repo->buscarPorId($envioId);
        if ($env === null) {
            throw new ValidacionException('El envío no existe.');
        }
        if (!in_array($estado, ['pendiente', 'despachado', 'en_camino', 'entregado', 'cancelado'], true)) {
            throw new ValidacionException('Estado de envío inválido.');
        }
        // No se puede marcar "entregado" si el pago del pedido no está confirmado.
        // (Las ventas del POS se cobran al momento → siempre pagadas.)
        if ($estado === 'entregado' && !empty($env['pedido_id'])) {
            $ped = (new \App\Repositories\PedidoRepository())->buscarPorId((int) $env['pedido_id']);
            if ($ped !== null && ($ped['estado_pago'] ?? '') !== 'pagado') {
                throw new ValidacionException('No podés marcar entregado: el pago del pedido todavía no está confirmado.');
            }
        }
        $repo->cambiarEstadoPorId($envioId, $estado);
    }

    /** Deriva un envío a un repartidor (o lo desasigna si $repartidorId es null). */
    public function derivar(int $envioId, ?int $repartidorId): void
    {
        if ($envioId <= 0) {
            throw new ValidacionException('Envío inválido.');
        }
        if ($repartidorId !== null && $this->repo->buscarPorId($repartidorId) === null) {
            throw new ValidacionException('El repartidor no existe.');
        }
        (new \App\Repositories\EnvioRepository())->derivar($envioId, $repartidorId);
    }
}

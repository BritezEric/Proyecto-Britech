<?php

namespace App\Services;

use App\Core\Database;
use App\Core\ValidacionException;
use App\Repositories\PedidoRepository;
use App\Repositories\ProductoRepository;
use App\Repositories\EmpresaEnvioRepository;
use App\Repositories\EnvioRepository;

/**
 * Lógica de los pedidos de la tienda online.
 * Igual que en el POS: el precio se calcula SIEMPRE en el backend (según la
 * lista del cliente), nunca se confía en lo que manda el navegador.
 * Un pedido NO descuenta stock: es una solicitud que el admin gestionará.
 */
class PedidoService
{
    public const ESTADOS = ['pendiente', 'preparando', 'entregado', 'cancelado'];
    public const ESTADOS_ENVIO = ['pendiente', 'despachado', 'en_camino', 'entregado', 'cancelado'];

    private PedidoRepository $repo;

    public function __construct()
    {
        $this->repo = new PedidoRepository();
    }

    /** Crea un pedido (con su envío) a partir del carrito del cliente. */
    public function crear(int $clienteId, int $listaId, array $items, ?string $observacion, array $envio): array
    {
        if (!is_array($items) || count($items) === 0) {
            throw new ValidacionException('Tu carrito está vacío.');
        }

        $ids = array_map(static fn($i) => (int) $i['producto_id'], $items);
        $productos = (new ProductoRepository())->paraVenta($ids, $listaId);

        $lineas = [];
        $total = 0.0;
        foreach ($items as $item) {
            $pid  = (int) $item['producto_id'];
            $cant = (int) $item['cantidad'];
            $p    = $productos[$pid] ?? null;

            if ($p === null || (int) $p['activo'] !== 1) {
                throw new ValidacionException("Un producto ya no está disponible.");
            }
            if ($cant <= 0) {
                throw new ValidacionException("Cantidad inválida en {$p['nombre']}.");
            }
            if ($p['precio'] === null) {
                throw new ValidacionException("El producto {$p['nombre']} no tiene precio.");
            }
            // En modo mayorista se exige la cantidad mínima de cada producto.
            if ($listaId === 2 && $cant < (int) $p['min_mayorista']) {
                throw new ValidacionException(
                    "Compra mínima mayorista de {$p['nombre']}: {$p['min_mayorista']} unidades."
                );
            }
            $precio = (float) $p['precio'];
            $sub    = round($precio * $cant, 2);
            $total += $sub;
            $lineas[] = ['pid' => $pid, 'cant' => $cant, 'precio' => $precio, 'sub' => $sub];
        }
        $total = round($total, 2);

        // --- Envío: empresa + (dirección salvo retiro en local); el costo lo pone el backend ---
        $direccion = trim($envio['direccion'] ?? '');
        $localidad = trim($envio['localidad'] ?? '') ?: null;
        $empresaId = (int) ($envio['empresa_envio_id'] ?? 0) ?: null;
        $costoEnvio = 0.0;
        $esRetiro = false;
        if ($empresaId !== null) {
            $empresa = (new EmpresaEnvioRepository())->buscarPorId($empresaId);
            if ($empresa === null || (int) $empresa['activo'] !== 1) {
                throw new ValidacionException('El medio de envío no es válido.');
            }
            $esRetiro = (int) ($empresa['es_retiro'] ?? 0) === 1;
            $costoEnvio = round((float) $empresa['costo_base'], 2);
        }
        // La dirección solo es obligatoria si NO es retiro en el local.
        if (!$esRetiro && mb_strlen($direccion) < 4) {
            throw new ValidacionException('Ingresá una dirección de envío.');
        }
        if ($esRetiro && $direccion === '') { $direccion = 'Retiro en local'; }
        $totalFinal = round($total + $costoEnvio, 2);

        $obs = trim($observacion ?? '') ?: null;
        $pdo = Database::conexion();
        try {
            $pdo->beginTransaction();
            $pedidoId = $this->repo->crear($clienteId, $total, $obs);
            $numero   = 'P-' . str_pad((string) $pedidoId, 6, '0', STR_PAD_LEFT);
            $this->repo->actualizarNumero($pedidoId, $numero);
            foreach ($lineas as $l) {
                $this->repo->agregarDetalle($pedidoId, $l['pid'], $l['cant'], $l['precio'], $l['sub']);
            }
            (new EnvioRepository())->crear($pedidoId, $empresaId, $direccion, $localidad, $costoEnvio);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Correo de confirmación (el pedido ya está guardado: si el mail falla, no importa).
        try {
            $this->enviarConfirmacion($clienteId, $numero, $lineas, $productos, $costoEnvio, $totalFinal);
        } catch (\Throwable $e) { /* no rompemos la compra por un fallo de email */ }

        return [
            'pedido_id'    => $pedidoId,
            'numero'       => $numero,
            'total'        => $total,          // productos
            'envio_costo'  => $costoEnvio,
            'total_final'  => $totalFinal,     // productos + envío
        ];
    }

    /** Manda al cliente un correo con el resumen del pedido. */
    private function enviarConfirmacion(int $clienteId, string $numero, array $lineas, array $productos, float $envio, float $totalFinal): void
    {
        $cli = (new \App\Repositories\ClienteRepository())->buscarCompleto($clienteId);
        if (!$cli || empty($cli['email'])) return;

        $fmt = fn($n) => '$ ' . number_format((float) $n, 2, ',', '.');
        $filas = '';
        foreach ($lineas as $l) {
            $nombre = $productos[$l['pid']]['nombre'] ?? 'Producto';
            $filas .= "<tr><td>{$l['cant']} × " . htmlspecialchars($nombre) . "</td>"
                    . "<td style='text-align:right'>{$fmt($l['sub'])}</td></tr>";
        }
        if ($envio > 0) {
            $filas .= "<tr><td>Envío</td><td style='text-align:right'>{$fmt($envio)}</td></tr>";
        }

        $cuerpo = "<h2>¡Gracias por tu pedido!</h2>
            <p>Hola " . htmlspecialchars($cli['nombre']) . ", registramos tu pedido <strong>{$numero}</strong>.</p>
            <table style='border-collapse:collapse;width:100%;max-width:420px'>{$filas}
              <tr><td style='border-top:2px solid #ccc;padding-top:6px'><strong>Total</strong></td>
                  <td style='border-top:2px solid #ccc;padding-top:6px;text-align:right'><strong>{$fmt($totalFinal)}</strong></td></tr>
            </table>
            <p>Podés seguir el estado desde <em>Mis pedidos</em> en la tienda. ¡Gracias por comprar en Britech!</p>";

        \App\Core\Mailer::enviar($cli['email'], $cli['nombre'], "Confirmación de tu pedido {$numero} - Britech", $cuerpo);
    }

    public function cambiarEstado(int $id, string $estado): void
    {
        if (!in_array($estado, self::ESTADOS, true)) {
            throw new ValidacionException('Estado inválido.');
        }
        if ($this->repo->buscarPorId($id) === null) {
            throw new ValidacionException('El pedido no existe.');
        }
        $this->repo->cambiarEstado($id, $estado);
    }

    /** Actualiza el envío de un pedido (admin): estado, seguimiento y dirección. */
    public function actualizarEnvio(int $pedidoId, string $estado, ?string $tracking, ?string $direccion = null, ?string $localidad = null): void
    {
        if (!in_array($estado, self::ESTADOS_ENVIO, true)) {
            throw new ValidacionException('Estado de envío inválido.');
        }
        if ($this->repo->buscarPorId($pedidoId) === null) {
            throw new ValidacionException('El pedido no existe.');
        }
        (new EnvioRepository())->actualizarDatos(
            $pedidoId, $estado, trim($tracking ?? '') ?: null,
            trim($direccion ?? '') ?: null, trim($localidad ?? '') ?: null
        );
    }
}

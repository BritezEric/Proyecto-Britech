<?php

namespace App\Services;

use App\Core\Database;
use App\Core\ValidacionException;
use App\Repositories\PedidoRepository;
use App\Repositories\ProductoRepository;
use App\Repositories\EmpresaEnvioRepository;
use App\Repositories\EnvioRepository;
use App\Repositories\BarrioRepository;

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
    public function crear(int $clienteId, int $listaId, array $items, ?string $observacion, array $envio, string $metodoPago = 'transferencia'): array
    {
        $metodosOk = ['transferencia', 'mercadopago', 'tarjeta'];
        if (!in_array($metodoPago, $metodosOk, true)) {
            $metodoPago = 'transferencia';
        }
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

        // --- Envío: empresa + datos de entrega (obligatorios salvo retiro en local) ---
        $empresaId = (int) ($envio['empresa_envio_id'] ?? 0) ?: null;
        $costoEnvio = 0.0;
        $esRetiro = false;
        $esMoto = false;
        $barrioId = null;
        if ($empresaId !== null) {
            $empresa = (new EmpresaEnvioRepository())->buscarPorId($empresaId);
            if ($empresa === null || (int) $empresa['activo'] !== 1) {
                throw new ValidacionException('El medio de envío no es válido.');
            }
            $esRetiro = (int) ($empresa['es_retiro'] ?? 0) === 1;
            $esMoto   = (int) ($empresa['es_moto'] ?? 0) === 1;
            $costoEnvio = round((float) $empresa['costo_base'], 2);
        }

        // Moto Express: el cliente elige un BARRIO (fija la zona y el precio) y
        // escribe la dirección con altura (a dónde va el moto). El barrio = localidad.
        if ($esMoto) {
            $barrioId = (int) ($envio['barrio_id'] ?? 0) ?: null;
            $barrio = $barrioId ? (new BarrioRepository())->buscarPorId($barrioId) : null;
            if ($barrio === null || (int) $barrio['activo'] !== 1) {
                throw new ValidacionException('Elegí un barrio para el envío en moto.');
            }
            $costoEnvio = round((float) $barrio['costo'], 2);
            $g = fn($k) => trim($envio[$k] ?? '') ?: null;
            $datosEnvio = [
                'destinatario' => $g('destinatario'),
                'telefono'     => $g('telefono'),
                'direccion'    => $g('direccion'),
                'numero'       => $g('numero'),
                'referencia'   => $g('referencia'),
                'localidad'    => $barrio['nombre'],
                'provincia'    => null,
                'cp'           => null,
            ];
            $obligatorios = ['destinatario' => 'quién recibe', 'telefono' => 'un teléfono', 'direccion' => 'la calle', 'numero' => 'la altura'];
            $faltan = [];
            foreach ($obligatorios as $campo => $etiqueta) {
                if ($datosEnvio[$campo] === null) { $faltan[] = $etiqueta; }
            }
            if ($faltan) {
                throw new ValidacionException('Para el envío en moto falta: ' . implode(', ', $faltan) . '.');
            }
        } else {
            $datosEnvio = $this->datosEntrega($envio, $esRetiro);
        }
        $totalFinal = round($total + $costoEnvio, 2);

        $obs = trim($observacion ?? '') ?: null;
        $pdo = Database::conexion();
        try {
            $pdo->beginTransaction();
            $pedidoId = $this->repo->crear($clienteId, $total, $obs, $metodoPago);
            $numero   = 'P-' . str_pad((string) $pedidoId, 6, '0', STR_PAD_LEFT);
            $this->repo->actualizarNumero($pedidoId, $numero);
            foreach ($lineas as $l) {
                $this->repo->agregarDetalle($pedidoId, $l['pid'], $l['cant'], $l['precio'], $l['sub']);
            }
            (new EnvioRepository())->crear($pedidoId, $empresaId, $datosEnvio, $costoEnvio, $barrioId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Aviso para el admin (bandeja de notificaciones).
        (new \App\Repositories\NotificacionRepository())
            ->crear('pedido_nuevo', "Nuevo pedido {$numero} · " . number_format($totalFinal, 0, ',', '.'), 'pedidos', $pedidoId);

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

    /**
     * Valida y normaliza los datos de entrega. Con envío (no retiro) son
     * obligatorios: destinatario, teléfono, calle, número, localidad, provincia y CP.
     * La referencia (entre calles / piso / depto) queda opcional.
     */
    private function datosEntrega(array $envio, bool $esRetiro): array
    {
        $g = fn($k) => trim($envio[$k] ?? '') ?: null;
        $d = [
            'destinatario' => $g('destinatario'),
            'telefono'     => $g('telefono'),
            'direccion'    => $g('direccion'),
            'numero'       => $g('numero'),
            'referencia'   => $g('referencia'),
            'localidad'    => $g('localidad'),
            'provincia'    => $g('provincia'),
            'cp'           => $g('cp'),
        ];

        if ($esRetiro) {
            $d['direccion'] = $d['direccion'] ?? 'Retiro en local';
            return $d;
        }

        // Obligatorios para envío a domicilio.
        $obligatorios = [
            'destinatario' => 'el nombre de quién recibe',
            'telefono'     => 'un teléfono de contacto',
            'direccion'    => 'la calle',
            'numero'       => 'la altura (número)',
            'localidad'    => 'la localidad',
            'provincia'    => 'la provincia',
            'cp'           => 'el código postal',
        ];
        $faltan = [];
        foreach ($obligatorios as $campo => $etiqueta) {
            if ($d[$campo] === null) { $faltan[] = $etiqueta; }
        }
        if ($faltan) {
            throw new ValidacionException('Para el envío falta completar: ' . implode(', ', $faltan) . '.');
        }
        if (!preg_match('/^\d{4}$/', $d['cp']) && !preg_match('/^[A-Za-z]\d{4}[A-Za-z]{0,3}$/', $d['cp'])) {
            throw new ValidacionException('El código postal no es válido (ej: 3600 o A3600XYZ).');
        }
        return $d;
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

    /** Actualiza el envío de un pedido (gestor admin): estado, seguimiento, datos de entrega y repartidor. */
    public function actualizarEnvio(int $pedidoId, string $estado, ?string $tracking, array $datos = [], int|null|false $repartidorId = false): void
    {
        if (!in_array($estado, self::ESTADOS_ENVIO, true)) {
            throw new ValidacionException('Estado de envío inválido.');
        }
        if ($this->repo->buscarPorId($pedidoId) === null) {
            throw new ValidacionException('El pedido no existe.');
        }
        // Solo pisa los campos de entrega que vengan en la petición (los demás se conservan).
        $campos = ['destinatario', 'telefono', 'direccion', 'numero', 'referencia', 'localidad', 'provincia', 'cp'];
        $limpios = [];
        foreach ($campos as $c) {
            if (array_key_exists($c, $datos)) { $limpios[$c] = trim((string) $datos[$c]) ?: null; }
        }
        (new EnvioRepository())->actualizarDatos(
            $pedidoId, $estado, trim($tracking ?? '') ?: null, $limpios, $repartidorId
        );
    }
}

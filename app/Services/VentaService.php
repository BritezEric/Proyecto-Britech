<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\ClienteRepository;
use App\Repositories\ProductoRepository;
use App\Repositories\VentaRepository;
use App\Repositories\InventarioRepository;
use App\Repositories\EmpresaEnvioRepository;
use App\Repositories\BarrioRepository;
use App\Repositories\EnvioRepository;
use App\Core\ValidacionException;

/**
 * VentaService — la LÓGICA DE NEGOCIO de registrar una venta (patrón Service Layer).
 *
 * Regla de oro de seguridad: el precio y el stock se calculan SIEMPRE en el
 * backend a partir de la base, NUNCA se confía en lo que manda el frontend
 * (el navegador se puede manipular).
 *
 * Todo el guardado ocurre dentro de una TRANSACCIÓN: o se guarda todo
 * (venta + detalle + pagos + stock + comprobante), o no se guarda nada.
 */
class VentaService
{
    // ponytail: dinero como float con round(2). Alcanza a esta escala;
    // si hiciera falta precisión exacta, pasar a centavos enteros o bcmath.

    public function registrar(array $datos, int $usuarioId): array
    {
        $clienteId = (int) ($datos['cliente_id'] ?? 0);
        $items     = $datos['items'] ?? [];
        $pagos     = $datos['pagos'] ?? [];

        if (!is_array($items) || count($items) === 0) {
            throw new ValidacionException('El carrito está vacío.');
        }

        // 1) Cliente válido → define la lista de precios.
        $cliente = (new ClienteRepository())->buscar($clienteId);
        if ($cliente === null) {
            throw new ValidacionException('Cliente no válido.');
        }

        // 2) Traer productos desde la base (precio real + stock real).
        $ids = array_map(static fn($i) => (int) $i['producto_id'], $items);
        $productos = (new ProductoRepository())->paraVenta($ids, (int) $cliente['lista_precio_id']);

        // 3) Validar cada línea y calcular el total con precios del BACKEND.
        $lineas = [];
        $subtotal = 0.0;

        foreach ($items as $item) {
            $pid  = (int) $item['producto_id'];
            $cant = (int) $item['cantidad'];
            $p    = $productos[$pid] ?? null;

            if ($p === null || (int) $p['activo'] !== 1) {
                throw new ValidacionException("Producto no disponible (id $pid).");
            }
            if ($cant <= 0) {
                throw new ValidacionException("Cantidad inválida para {$p['nombre']}.");
            }
            if ($p['precio'] === null) {
                throw new ValidacionException("El producto {$p['nombre']} no tiene precio para este cliente.");
            }

            $sobrePedido = (int) $p['es_sobre_pedido'] === 1;
            if (!$sobrePedido && $cant > (int) $p['stock']) {
                throw new ValidacionException("Stock insuficiente de {$p['nombre']} (hay {$p['stock']}).");
            }

            $precio    = (float) $p['precio'];
            $descLinea = round((float) ($item['descuento_linea'] ?? 0), 2);
            $bruto     = round($precio * $cant, 2);

            if ($descLinea < 0 || $descLinea > $bruto) {
                throw new ValidacionException("Descuento inválido en {$p['nombre']}.");
            }

            $sub = round($bruto - $descLinea, 2);
            $subtotal += $sub;

            $lineas[] = [
                'producto_id'  => $pid,
                'cantidad'     => $cant,
                'precio'       => $precio,
                'desc'         => $descLinea,
                'sub'          => $sub,
                'estado'       => $sobrePedido ? 'sobre_pedido' : 'normal',
                'sobre_pedido' => $sobrePedido,
            ];
        }

        $subtotal  = round($subtotal, 2);
        $descuento = round((float) ($datos['descuento'] ?? 0), 2);   // descuento sobre el total
        if ($descuento < 0 || $descuento > $subtotal) {
            throw new ValidacionException('Descuento total inválido.');
        }
        $total     = round($subtotal - $descuento, 2);

        // 3b) Envío opcional: se valida y su costo se SUMA al total (sube el ticket).
        $envio = (!empty($datos['envio']) && is_array($datos['envio']))
            ? $this->resolverEnvio($datos['envio']) : null;
        $envioCosto = $envio['costo'] ?? 0.0;
        $total = round($total + $envioCosto, 2);

        // 4) Validar que los pagos sumen exactamente el total (productos + envío) (RN6).
        $sumaPagos = 0.0;
        foreach ($pagos as $pg) {
            $sumaPagos += (float) ($pg['monto'] ?? 0);
        }
        if (abs(round($sumaPagos, 2) - $total) > 0.001) {
            throw new ValidacionException('El pago no coincide con el total de la venta.');
        }

        // 5) TRANSACCIÓN: todo o nada.
        $pdo       = Database::conexion();
        $ventaRepo = new VentaRepository();
        $invRepo   = new InventarioRepository();

        try {
            $pdo->beginTransaction();

            $ventaId = $ventaRepo->crear($clienteId, $usuarioId, $subtotal, $descuento, $total);
            $numero  = 'V-' . str_pad((string) $ventaId, 6, '0', STR_PAD_LEFT);
            $ventaRepo->actualizarNumero($ventaId, $numero);

            foreach ($lineas as $l) {
                $ventaRepo->agregarDetalle(
                    $ventaId, $l['producto_id'], $l['cantidad'],
                    $l['precio'], $l['desc'], $l['estado'], $l['sub']
                );

                // Solo descuenta stock si NO es sobre pedido.
                if (!$l['sobre_pedido']) {
                    $invRepo->descontar($l['producto_id'], $l['cantidad']);
                    $invRepo->registrarMovimiento(
                        $l['producto_id'], 'egreso', $l['cantidad'],
                        "Venta $numero", $ventaId, $usuarioId
                    );
                }
            }

            foreach ($pagos as $pg) {
                $ventaRepo->agregarPago($ventaId, (int) $pg['tipo_pago_id'], round((float) $pg['monto'], 2));
            }

            $ventaRepo->crearComprobante($ventaId, $numero);

            // Envío opcional cargado por el vendedor (el repartidor se asigna después).
            if ($envio !== null && $envio['insertar']) {
                (new EnvioRepository())->crear(
                    null, $envio['empresa_id'], $envio['datos'], $envio['costo'], $envio['barrio_id'], $ventaId
                );
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            // Si algo falló, deshacemos TODO.
            $pdo->rollBack();
            throw $e;
        }

        return ['venta_id' => $ventaId, 'numero' => $numero, 'total' => $total, 'envio_costo' => $envioCosto];
    }

    /**
     * Valida el envío de una venta del POS y calcula su costo (moto = precio del
     * barrio; otros = costo base; retiro = 0). Devuelve el costo + los datos para
     * insertar el envío después de crear la venta. El costo SE SUMA al total de la
     * venta (el ticket sube). El repartidor se asigna luego desde Repartidores.
     *
     * @return array{costo: float, insertar: bool, empresa_id: ?int, barrio_id: ?int, datos: array}
     */
    private function resolverEnvio(array $envio): array
    {
        $vacio = ['costo' => 0.0, 'insertar' => false, 'empresa_id' => null, 'barrio_id' => null, 'datos' => []];

        $empresaId = (int) ($envio['empresa_envio_id'] ?? 0) ?: null;
        if ($empresaId === null) { return $vacio; }   // sin medio elegido → sin envío

        $empresa = (new EmpresaEnvioRepository())->buscarPorId($empresaId);
        if ($empresa === null || (int) $empresa['activo'] !== 1) {
            throw new ValidacionException('El medio de envío no es válido.');
        }
        if ((int) ($empresa['es_retiro'] ?? 0) === 1) { return $vacio; }   // retiro → sin envío ni costo

        $esMoto = (int) ($empresa['es_moto'] ?? 0) === 1;
        $g = fn($k) => trim($envio[$k] ?? '') ?: null;
        $barrioId = null;
        $costo = round((float) $empresa['costo_base'], 2);
        $localidad = $g('localidad');

        if ($esMoto) {
            $barrioId = (int) ($envio['barrio_id'] ?? 0) ?: null;
            $barrio = $barrioId ? (new BarrioRepository())->buscarPorId($barrioId) : null;
            if ($barrio === null || (int) $barrio['activo'] !== 1) {
                throw new ValidacionException('Elegí un barrio para el envío en moto.');
            }
            $costo = round((float) $barrio['costo'], 2);
            $localidad = $barrio['nombre'];
        }

        if ($g('destinatario') === null || $g('direccion') === null || $g('numero') === null) {
            throw new ValidacionException('Para el envío falta: quién recibe, calle y altura.');
        }

        return [
            'costo'      => $costo,
            'insertar'   => true,
            'empresa_id' => $empresaId,
            'barrio_id'  => $barrioId,
            'datos'      => [
                'destinatario' => $g('destinatario'),
                'telefono'     => $g('telefono'),
                'direccion'    => $g('direccion'),
                'numero'       => $g('numero'),
                'referencia'   => $g('referencia'),
                'localidad'    => $localidad,
                'provincia'    => $g('provincia'),
                'cp'           => $g('cp'),
            ],
        ];
    }

    /**
     * Anular una venta (solo admin, la ruta/controlador ya valida el rol).
     * Marca la venta como anulada, guarda el motivo y REINTEGRA el stock de
     * las líneas normales (las "sobre pedido" nunca descontaron stock).
     * Todo dentro de una transacción: o se anula entero, o nada.
     */
    public function anular(int $ventaId, string $motivo, int $usuarioId): array
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw new ValidacionException('El motivo de anulación es obligatorio.');
        }

        $ventaRepo = new VentaRepository();
        $venta = $ventaRepo->buscarPorId($ventaId);
        if ($venta === null) {
            throw new ValidacionException('La venta no existe.');
        }
        if ($venta['estado'] === 'anulada') {
            throw new ValidacionException('La venta ya está anulada.');
        }

        $detalle = $ventaRepo->detalleDe($ventaId);
        $pdo     = Database::conexion();
        $invRepo = new InventarioRepository();

        try {
            $pdo->beginTransaction();

            $ventaRepo->marcarAnulada($ventaId);
            $ventaRepo->registrarAnulacion($ventaId, $usuarioId, $motivo);

            foreach ($detalle as $l) {
                if ($l['estado'] === 'normal') {   // sobre_pedido no había descontado
                    $invRepo->reintegrar((int) $l['producto_id'], (int) $l['cantidad']);
                    $invRepo->registrarMovimiento(
                        (int) $l['producto_id'], 'ingreso', (int) $l['cantidad'],
                        "Anulación venta {$venta['numero']}", $ventaId, $usuarioId
                    );
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['venta_id' => $ventaId, 'numero' => $venta['numero']];
    }
}

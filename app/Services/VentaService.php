<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\ClienteRepository;
use App\Repositories\ProductoRepository;
use App\Repositories\VentaRepository;
use App\Repositories\InventarioRepository;
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

    public function registrar(array $datos): array
    {
        // TODO: cuando exista login, este será el vendedor logueado.
        $usuarioId = 1;

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

        // 4) Validar que los pagos sumen exactamente el total (RN6).
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

            $pdo->commit();
        } catch (\Throwable $e) {
            // Si algo falló, deshacemos TODO.
            $pdo->rollBack();
            throw $e;
        }

        return ['venta_id' => $ventaId, 'numero' => $numero, 'total' => $total];
    }
}

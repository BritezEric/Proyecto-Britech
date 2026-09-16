<?php

namespace App\Services;

use App\Core\Database;
use App\Core\ValidacionException;
use App\Repositories\CompraRepository;
use App\Repositories\InventarioRepository;
use App\Repositories\NotificacionRepository;
use App\Repositories\ProductoRepository;
use App\Repositories\ProveedorRepository;

/**
 * CompraService — lógica de negocio de las órdenes de compra a proveedores.
 *
 * Igual que en las ventas, todo el guardado ocurre dentro de una TRANSACCIÓN
 * (o se guarda todo, o nada) y el stock se toca SIEMPRE desde el backend.
 * La recepción puede ser parcial: cada línea acumula cuánto se recibió y la
 * orden avanza a 'parcial' o 'recibida' según el total.
 */
class CompraService
{
    /**
     * Crea una orden de compra a un proveedor con sus líneas.
     * @param array $datos ['proveedor_id'=>int, 'observacion'=>?string,
     *                      'items'=>[['producto_id'=>int,'cantidad'=>int,'costo_unitario'=>float], ...]]
     */
    public function crear(array $datos, int $usuarioId): array
    {
        $proveedorId = (int) ($datos['proveedor_id'] ?? 0);
        $items       = $datos['items'] ?? [];

        if ($proveedorId <= 0 || (new ProveedorRepository())->buscarPorId($proveedorId) === null) {
            throw new ValidacionException('Elegí un proveedor válido.');
        }
        if (!is_array($items) || count($items) === 0) {
            throw new ValidacionException('La orden no tiene productos.');
        }

        $ids = array_map(static fn($i) => (int) ($i['producto_id'] ?? 0), $items);
        $productos = (new ProductoRepository())->paraVenta($ids, 1);   // 1 = lista minorista (solo para nombre/activo)

        $lineas = [];
        $total  = 0.0;
        foreach ($items as $item) {
            $pid   = (int) ($item['producto_id'] ?? 0);
            $cant  = (int) ($item['cantidad'] ?? 0);
            $costo = round((float) ($item['costo_unitario'] ?? 0), 2);
            $p     = $productos[$pid] ?? null;

            if ($p === null || (int) $p['activo'] !== 1) {
                throw new ValidacionException("Producto no disponible (id $pid).");
            }
            if ($cant <= 0)  { throw new ValidacionException("Cantidad inválida para {$p['nombre']}."); }
            if ($costo < 0)  { throw new ValidacionException("Costo inválido para {$p['nombre']}."); }

            $total += $cant * $costo;
            $lineas[] = ['producto_id' => $pid, 'cantidad' => $cant, 'costo' => $costo];
        }
        $total = round($total, 2);

        $observacion = trim((string) ($datos['observacion'] ?? '')) ?: null;

        $pdo  = Database::conexion();
        $repo = new CompraRepository();
        try {
            $pdo->beginTransaction();
            $ordenId = $repo->crear($proveedorId, $total, $observacion, $usuarioId);
            $numero  = 'OC-' . str_pad((string) $ordenId, 6, '0', STR_PAD_LEFT);
            $repo->fijarNumero($ordenId, $numero);
            foreach ($lineas as $l) {
                $repo->agregarDetalle($ordenId, $l['producto_id'], $l['cantidad'], $l['costo']);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['orden_id' => $ordenId, 'numero' => $numero, 'total' => $total];
    }

    /**
     * Registra la recepción (total o parcial) de mercadería de una orden.
     * @param array $recepciones [['detalle_id'=>int, 'cantidad'=>int], ...] — lo que llegó ahora.
     */
    public function recibir(int $ordenId, array $recepciones, int $usuarioId): array
    {
        $repo  = new CompraRepository();
        $orden = $repo->buscarPorId($ordenId);
        if ($orden === null)              { throw new ValidacionException('La orden no existe.'); }
        if ($orden['estado'] === 'anulada')  { throw new ValidacionException('La orden está anulada.'); }
        if ($orden['estado'] === 'recibida') { throw new ValidacionException('La orden ya fue recibida por completo.'); }

        // Indexar el detalle actual por id para validar cada recepción.
        $porId = [];
        foreach ($repo->detalleDe($ordenId) as $d) { $porId[(int) $d['id']] = $d; }

        // Recortar cada recepción a lo que falta recibir de esa línea.
        $aplicar = [];
        foreach ($recepciones as $r) {
            $did  = (int) ($r['detalle_id'] ?? 0);
            $cant = (int) ($r['cantidad'] ?? 0);
            if ($cant <= 0) continue;
            $d = $porId[$did] ?? null;
            if ($d === null) { throw new ValidacionException('Línea de la orden inválida.'); }
            $pendiente = (int) $d['cantidad'] - (int) $d['cantidad_recibida'];
            if ($pendiente <= 0) continue;                 // esa línea ya está completa
            $cant = min($cant, $pendiente);                // nunca recibir más que lo pedido
            $aplicar[] = ['detalle_id' => $did, 'producto_id' => (int) $d['producto_id'], 'cantidad' => $cant];
        }
        if (count($aplicar) === 0) {
            throw new ValidacionException('No hay cantidades para recibir.');
        }

        $pdo = Database::conexion();
        $inv = new InventarioRepository();
        try {
            $pdo->beginTransaction();
            foreach ($aplicar as $a) {
                $repo->sumarRecibido($a['detalle_id'], $a['cantidad']);
                $inv->aumentar($a['producto_id'], $a['cantidad']);
                $inv->registrarMovimiento(
                    $a['producto_id'], 'ingreso', $a['cantidad'],
                    "Recepción {$orden['numero']}", null, $usuarioId
                );
            }

            // Recalcular estado leyendo el detalle ya actualizado (misma transacción).
            $completa = true;
            $algo = false;
            foreach ($repo->detalleDe($ordenId) as $d) {
                $rec = (int) $d['cantidad_recibida'];
                if ($rec < (int) $d['cantidad']) { $completa = false; }
                if ($rec > 0) { $algo = true; }
            }
            $estado = $completa ? 'recibida' : ($algo ? 'parcial' : 'enviada');
            $repo->fijarEstado($ordenId, $estado, $completa);

            (new NotificacionRepository())->crear(
                'compra_recibida',
                ($completa ? 'Mercadería recibida · ' : 'Recepción parcial · ') . $orden['numero'],
                'compras',
                $ordenId
            );

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['orden_id' => $ordenId, 'numero' => $orden['numero'], 'estado' => $estado];
    }

    /** Anula una orden. No revierte el stock ya recibido (la mercadería recibida es real). */
    public function anular(int $ordenId): array
    {
        $repo  = new CompraRepository();
        $orden = $repo->buscarPorId($ordenId);
        if ($orden === null)                 { throw new ValidacionException('La orden no existe.'); }
        if ($orden['estado'] === 'anulada')  { throw new ValidacionException('La orden ya está anulada.'); }
        if ($orden['estado'] === 'recibida') { throw new ValidacionException('No se puede anular una orden ya recibida.'); }

        $repo->fijarEstado($ordenId, 'anulada');
        return ['orden_id' => $ordenId, 'numero' => $orden['numero']];
    }
}

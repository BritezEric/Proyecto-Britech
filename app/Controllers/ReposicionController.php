<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ProductoRepository;
use App\Repositories\ReposicionRepository;

/**
 * Reposición: productos con stock en o por debajo de su mínimo, para armar el
 * pedido de compra a cada proveedor. El admin lee, ajusta cantidades / cambia
 * de proveedor y envía la solicitud por WhatsApp. Al enviar se guarda el pedido
 * (historial) para no volver a avisar un producto ya pedido hace poco.
 */
class ReposicionController
{
    /** GET /api/admin/reposicion — faltantes + proveedores activos. Solo admin. */
    public function index(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }

        $items = (new ProductoRepository())->stockBajo();
        $dias  = (new ReposicionRepository())->diasUltimoPedido();   // [producto_id => días]
        foreach ($items as &$it) {
            // Sugerimos reponer hasta el doble del mínimo (mínimo 1 unidad).
            $it['sugerido'] = max(1, (int) $it['stock_minimo'] * 2 - (int) $it['stock']);
            // Días desde el último pedido (null = no se pidió en la ventana reciente).
            $it['dias_ultimo_pedido'] = $dias[(int) $it['id']] ?? null;
        }
        unset($it);

        $proveedores = Database::conexion()
            ->query("SELECT id, nombre, telefono FROM proveedor WHERE activo = 1 ORDER BY nombre")
            ->fetchAll();

        Response::json(['ok' => true, 'items' => $items, 'proveedores' => $proveedores]);
    }

    /** POST /api/admin/reposicion — registra un pedido enviado. Solo admin. */
    public function registrar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }

        $in    = Request::json();
        $prov  = (int) ($in['proveedor_id'] ?? 0);
        $items = is_array($in['items'] ?? null) ? $in['items'] : [];
        if (!$items) { Response::json(['ok' => false, 'error' => 'Pedido vacío.'], 422); return; }

        $n = (new ReposicionRepository())->registrar($prov ?: null, $items);
        Response::json(['ok' => true, 'registrados' => $n]);
    }
}

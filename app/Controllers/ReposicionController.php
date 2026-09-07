<?php

namespace App\Controllers;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\ProductoRepository;

/**
 * Reposición: productos con stock en o por debajo de su mínimo, para armar el
 * pedido de compra a cada proveedor. El admin lee, ajusta cantidades / cambia
 * de proveedor y envía la solicitud por WhatsApp (no se persiste la orden).
 */
class ReposicionController
{
    /** GET /api/admin/reposicion — faltantes + proveedores activos. Solo admin. */
    public function index(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }

        $items = (new ProductoRepository())->stockBajo();
        // Cada ítem sugiere reponer hasta el doble del mínimo (mínimo 1 unidad).
        foreach ($items as &$it) {
            $it['sugerido'] = max(1, (int) $it['stock_minimo'] * 2 - (int) $it['stock']);
        }
        unset($it);

        $proveedores = Database::conexion()
            ->query("SELECT id, nombre, telefono FROM proveedor WHERE activo = 1 ORDER BY nombre")
            ->fetchAll();

        Response::json(['ok' => true, 'items' => $items, 'proveedores' => $proveedores]);
    }
}

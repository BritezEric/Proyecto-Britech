<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\VentaService;
use App\Core\ValidacionException;

/**
 * Controlador de ventas. Recibe el pedido de confirmación (JSON), llama al
 * servicio y devuelve el resultado. No lleva lógica ni SQL.
 */
class VentaController
{
    public function crear(): void
    {
        $datos = Request::json();

        try {
            // El vendedor es el usuario logueado (la ruta está protegida).
            $resultado = (new VentaService())->registrar($datos, (int) Session::usuarioId());
            Response::json(['ok' => true, 'venta' => $resultado], 201);
        } catch (ValidacionException $e) {
            // Errores de validación (stock, pago, cliente…): 422.
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // Cualquier otra falla: 500 (no exponemos el detalle interno).
            Response::json(['ok' => false, 'error' => 'No se pudo registrar la venta.'], 500);
        }
    }
}

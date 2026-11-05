<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\VendedorDashboardRepository;

/**
 * Dashboard INDIVIDUAL del vendedor: cada usuario ve solo SU desempeño.
 * El id sale siempre de la sesión (nunca del request), así nadie puede ver el de otro.
 */
class VendedorDashboardController
{
    /** GET /api/vendedor/dashboard?periodo=dia|semana|mes|ano */
    public function resumen(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }

        $periodo = Request::query('periodo', 'semana');
        if (!in_array($periodo, VendedorDashboardRepository::PERIODOS, true)) { $periodo = 'semana'; }

        $yo = (int) Session::usuarioId();
        $r = new VendedorDashboardRepository();
        Response::json([
            'ok'                  => true,
            'periodo'             => $periodo,
            'vendedor'            => Session::usuario()['nombre'] ?? '',
            'resumen'             => $r->resumen($yo, $periodo),
            'ventas'              => $r->frecuenciaVentas($yo, $periodo),
            'compras'             => $r->frecuenciaCompras($yo, $periodo),
            'clientes_frecuentes' => $r->clientesFrecuentes($yo, $periodo),
            'cajas'               => $r->cajas($yo, $periodo),
        ]);
    }
}

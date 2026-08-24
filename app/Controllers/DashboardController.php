<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\DashboardRepository;

/** Métricas del panel admin (solo admin). */
class DashboardController
{
    public function resumen(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }

        $r = new DashboardRepository();
        Response::json([
            'ok' => true,
            'ventas_hoy'            => $r->ventasHoy(),
            'ventas_mes'            => $r->ventasMes(),
            'ventas_online_mes'     => $r->ventasOnlineMes(),
            'ticket_promedio_mes'   => $r->ticketPromedioMes(),
            'pedidos_pendientes'    => $r->pedidosPendientes(),
            'solicitudes_pendientes'=> $r->solicitudesPendientes(),
            'sin_stock'             => $r->sinStock(),
            'stock_bajo'            => $r->stockBajo(),
            'top_productos'         => $r->topProductos(),
            'serie'                 => $r->serieVentas('semana'),   // serie inicial
            'por_vendedor'          => $r->ventasPorVendedor(),
            'comparativa'           => $r->comparativaMes(),
            'sin_movimiento'        => $r->sinMovimiento(),
            'clientes'              => $r->clientes(),
            'totales'               => $r->totales(),
            'serie_finanzas'        => $r->serieFinanzas(),
            'ventas_categoria'      => $r->ventasPorCategoria(),
            'gastos_recientes'      => $r->gastosRecientes(),
        ]);
    }

    /** Serie de ventas (físicas vs online) según el período. Para el toggle del gráfico. */
    public function serie(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $periodo = Request::query('periodo', 'semana');
        Response::json(['ok' => true, 'serie' => (new DashboardRepository())->serieVentas($periodo)]);
    }
}

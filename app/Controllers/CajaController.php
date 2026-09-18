<?php

namespace App\Controllers;

use App\Core\Paginacion;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidacionException;
use App\Repositories\CajaRepository;
use App\Services\CajaService;

/**
 * Caja del vendedor: apertura, movimientos y cierre (arqueo). Operativa = staff.
 * El historial de todas las cajas es solo-admin (supervisión).
 */
class CajaController
{
    /** GET /api/caja/estado — caja abierta del vendedor + resumen del turno. */
    public function estado(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        $r = (new CajaService())->estado((int) Session::usuarioId());
        Response::json(['ok' => true] + $r);
    }

    /** POST /api/caja/abrir — abre la caja con un monto inicial. */
    public function abrir(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        try {
            $monto = (float) (Request::json()['monto'] ?? 0);
            $r = (new CajaService())->abrir((int) Session::usuarioId(), $monto);
            Response::json(['ok' => true, 'caja' => $r], 201);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** POST /api/caja/movimiento — retiro o ingreso de efectivo. */
    public function movimiento(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        $in = Request::json();
        try {
            (new CajaService())->movimiento(
                (int) Session::usuarioId(),
                (string) ($in['tipo'] ?? ''),
                (float) ($in['monto'] ?? 0),
                $in['motivo'] ?? null
            );
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** POST /api/caja/cerrar — arqueo y cierre. */
    public function cerrar(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        $in = Request::json();
        try {
            $r = (new CajaService())->cerrar(
                (int) Session::usuarioId(),
                (float) ($in['contado'] ?? 0),
                $in['observacion'] ?? null
            );
            Response::json(['ok' => true, 'cierre' => $r]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** GET /api/admin/cajas — historial de cajas. Solo admin. */
    public function adminListar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        [$page, $perPage, $offset] = Paginacion::desde();
        $r = (new CajaRepository())->listarPaginado($perPage, $offset);
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage));
    }

    /** GET /api/admin/cajas/detalle?id= — cierre + movimientos + resumen. Solo admin. */
    public function adminDetalle(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $repo = new CajaRepository();
        $id = (int) Request::query('id', '0');
        $caja = $repo->buscarPorId($id);
        if ($caja === null) { Response::json(['ok' => false, 'error' => 'La caja no existe.'], 404); return; }
        Response::json([
            'ok'          => true,
            'caja'        => $caja,
            'resumen'     => $repo->resumen($id),
            'movimientos' => $repo->movimientosDe($id),
        ]);
    }
}

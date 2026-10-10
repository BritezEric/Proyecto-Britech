<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidacionException;
use App\Services\PrecioMasivoService;

/** Actualización masiva de precios (por marca / categoría / todo). Solo admin. */
class PrecioController
{
    public function previsualizar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            Response::json(['ok' => true] + (new PrecioMasivoService())->previsualizar(Request::json()));
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function aplicar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            $r = (new PrecioMasivoService())->aplicar(Request::json(), (int) Session::usuarioId());
            Response::json(['ok' => true] + $r);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo aplicar el cambio de precios.'], 500);
        }
    }
}

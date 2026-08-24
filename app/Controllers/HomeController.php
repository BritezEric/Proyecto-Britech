<?php

namespace App\Controllers;

use App\Core\Response;
use App\Core\Session;
use App\Services\HomeService;

/** Home pública de la tienda (bloques). */
class HomeController
{
    public function home(): void
    {
        // Precios según el modo del cliente (mayorista solo si está aprobado).
        $lista = Session::clienteModo() === 'mayorista' ? 2 : 1;
        Response::json(['ok' => true, 'bloques' => (new HomeService())->home($lista)]);
    }
}

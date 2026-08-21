<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\TipoPagoRepository;

class TipoPagoController
{
    public function index(): void
    {
        $repo = new TipoPagoRepository();
        Response::json(['datos' => $repo->listar()]);
    }
}

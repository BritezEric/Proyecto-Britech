<?php

namespace App\Controllers;

use App\Core\Response;
use App\Repositories\ClienteRepository;

/**
 * Controlador de clientes. Devuelve JSON.
 */
class ClienteController
{
    public function index(): void
    {
        $repo = new ClienteRepository();
        Response::json(['datos' => $repo->listar()]);
    }
}

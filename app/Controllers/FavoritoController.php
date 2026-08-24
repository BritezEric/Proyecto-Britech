<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\FavoritoRepository;

/** Favoritos / wishlist del cliente logueado en la tienda. */
class FavoritoController
{
    private function clienteId(): ?int
    {
        return Session::cliente()['id'] ?? null;
    }

    private function lista(): int
    {
        return Session::clienteModo() === 'mayorista' ? 2 : 1;
    }

    public function toggle(): void
    {
        $id = $this->clienteId();
        if ($id === null) { Response::json(['ok' => false, 'error' => 'Iniciá sesión para usar favoritos.'], 401); return; }

        $productoId = (int) (Request::json()['producto_id'] ?? 0);
        if ($productoId <= 0) { Response::json(['ok' => false, 'error' => 'Producto inválido.'], 422); return; }

        $fav = (new FavoritoRepository())->toggle($id, $productoId);
        Response::json(['ok' => true, 'favorito' => $fav]);
    }

    public function listar(): void
    {
        $id = $this->clienteId();
        if ($id === null) { Response::json(['ok' => true, 'ids' => [], 'productos' => []]); return; }

        $repo = new FavoritoRepository();
        Response::json([
            'ok'        => true,
            'ids'       => $repo->idsDe($id),
            'productos' => $repo->listar($id, $this->lista()),
        ]);
    }
}

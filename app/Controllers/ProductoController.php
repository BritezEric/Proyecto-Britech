<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\ProductoRepository;

/**
 * Controlador de productos. Recibe la peticion, pide los datos al
 * repositorio, y devuelve JSON. No lleva logica de negocio ni SQL.
 */
class ProductoController
{
    public function index(): void
    {
        $repo = new ProductoRepository();
        Response::json(['datos' => $repo->listar()]);
    }

    /**
     * Busca productos para el POS (por codigo de barras o nombre/SKU).
     * Ej: GET /api/productos/buscar?q=auricular&lista=1
     */
    public function buscar(): void
    {
        $q     = trim((string) Request::query('q', ''));
        $lista = (int) Request::query('lista', '1');
        if ($lista <= 0) {
            $lista = 1; // por defecto: lista minorista
        }

        // Sin texto no buscamos nada.
        if ($q === '') {
            Response::json(['datos' => []]);
            return;
        }

        $repo = new ProductoRepository();
        Response::json(['datos' => $repo->buscar($q, $lista)]);
    }

    /**
     * Recalcula precios cuando cambia el cliente.
     * Ej: GET /api/productos/precios?ids=1,2,3&lista=2
     */
    public function precios(): void
    {
        $lista = (int) Request::query('lista', '1');
        if ($lista <= 0) {
            $lista = 1;
        }

        // "1,2,3" -> [1, 2, 3] (solo enteros válidos)
        $idsTexto = (string) Request::query('ids', '');
        $ids = array_values(array_filter(array_map('intval', explode(',', $idsTexto))));

        $repo = new ProductoRepository();
        Response::json(['datos' => $repo->preciosDe($ids, $lista)]);
    }
}

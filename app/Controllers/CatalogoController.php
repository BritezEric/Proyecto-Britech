<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Paginacion;
use App\Repositories\ProductoRepository;

/** Catálogo público de la tienda online (navegar productos). */
class CatalogoController
{
    /** GET /api/tienda/catalogo — productos activos con precio. Público. */
    public function index(): void
    {
        // El precio depende del MODO de navegación elegido por el cliente:
        // 'mayorista' (solo si tiene acceso aprobado, ver TiendaAuthController::modo) => lista 2.
        $lista = Session::clienteModo() === 'mayorista' ? 2 : 1;

        [$page, $perPage, $offset] = Paginacion::desde(12, 48);
        $q   = Request::query('q');
        $cat = Request::query('categoria_id');
        $min = Request::query('precio_min');
        $max = Request::query('precio_max');

        $marcasRaw = Request::query('marca_ids');   // lista separada por comas: "3,7"
        $marcaIds = $marcasRaw ? array_values(array_filter(array_map('intval', explode(',', $marcasRaw)))) : null;

        $r = (new ProductoRepository())->catalogo(
            $q,
            $cat === null || $cat === '' ? null : (int) $cat,
            $lista, $perPage, $offset,
            ($min === null || $min === '') ? null : (float) $min,
            ($max === null || $max === '') ? null : (float) $max,
            Request::query('orden'),
            $marcaIds,
            Request::query('solo_stock') === '1'
        );
        $resp = Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage);
        $resp['lista_precio_id'] = $lista;
        $resp['marcas'] = $r['marcas'];   // facetas para el filtro de marca
        $resp['tope'] = $r['tope'];       // precio máximo (para el slider)
        Response::json($resp);
    }

    /** GET /api/tienda/producto?id= — ficha de un producto (detalle + imágenes). Público. */
    public function producto(): void
    {
        $lista = Session::clienteModo() === 'mayorista' ? 2 : 1;
        $p = (new ProductoRepository())->detalleTienda((int) Request::query('id', '0'), $lista);
        if ($p === null) { Response::json(['ok' => false, 'error' => 'Producto no encontrado.'], 404); return; }
        Response::json(['ok' => true, 'producto' => $p]);
    }

    /** GET /api/tienda/categorias — categorías activas (para el filtro). Público. */
    public function categorias(): void
    {
        $pdo = \App\Core\Database::conexion();
        $filas = $pdo->query("SELECT id, nombre FROM categoria WHERE activo = 1 ORDER BY nombre")->fetchAll();
        Response::json(['ok' => true, 'categorias' => $filas]);
    }
}

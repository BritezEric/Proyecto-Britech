<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Paginacion;
use App\Core\ValidacionException;
use App\Repositories\ProductoRepository;
use App\Services\ProductoService;

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

    // ===== ABM (panel admin) =====

    public function admin(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }

        [$page, $perPage, $offset] = Paginacion::desde();
        $filtros = [];
        foreach (['activo', 'categoria_id', 'marca_id', 'proveedor_id'] as $k) {
            $v = Request::query($k);
            $filtros[$k] = ($v === null || $v === '') ? null : (int) $v;
        }
        $r = (new ProductoService())->listar(Request::query('q'), $filtros, $perPage, $offset);
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage));
    }

    /** Producto completo (con precios y stock) para el formulario de edición. */
    public function detalle(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $p = (new ProductoService())->detalle((int) Request::query('id', '0'));
        if ($p === null) { Response::json(['ok' => false, 'error' => 'No existe.'], 404); return; }
        Response::json(['ok' => true, 'producto' => $p]);
    }

    public function guardar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            $id = (new ProductoService())->guardar(Request::json(), (int) Session::usuarioId());
            Response::json(['ok' => true, 'id' => $id]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo guardar el producto.'], 500);
        }
    }

    public function baja(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        try {
            (new ProductoService())->baja((int) (Request::json()['id'] ?? 0));
            Response::json(['ok' => true]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Sube una imagen de producto (archivo local o pegada con Ctrl+V) y devuelve
     * su URL. Solo admin. Valida que sea una imagen real, el tamaño, y guarda con
     * un nombre aleatorio (nunca se confía en el nombre original).
     */
    public function subirImagen(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }

        $f = $_FILES['imagen'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            Response::json(['ok' => false, 'error' => 'No se recibió la imagen.'], 422);
            return;
        }
        if ($f['size'] > 5 * 1024 * 1024) {
            Response::json(['ok' => false, 'error' => 'La imagen supera los 5 MB.'], 422);
            return;
        }

        // El tipo se determina por el CONTENIDO, no por la extensión ni el nombre.
        $info = @getimagesize($f['tmp_name']);
        $permitidos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = $info['mime'] ?? '';
        if (!isset($permitidos[$mime])) {
            Response::json(['ok' => false, 'error' => 'Formato no permitido. Usá JPG, PNG, WEBP o GIF.'], 422);
            return;
        }

        $dir = dirname(__DIR__, 2) . '/public/uploads/productos';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

        $nombre  = bin2hex(random_bytes(16)) . '.' . $permitidos[$mime];   // nombre seguro
        $destino = $dir . '/' . $nombre;

        if (!move_uploaded_file($f['tmp_name'], $destino)) {
            Response::json(['ok' => false, 'error' => 'No se pudo guardar la imagen.'], 500);
            return;
        }
        Response::json(['ok' => true, 'url' => '/uploads/productos/' . $nombre]);
    }
}

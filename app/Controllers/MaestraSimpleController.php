<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Paginacion;
use App\Core\ValidacionException;
use App\Repositories\MaestraSimpleRepository;

/**
 * ABM base para tablas maestras simples (categoría, marca). Las subclases solo
 * definen $tabla (constante, no viene del usuario) y $entidad (etiqueta legible).
 * Todo el CRUD + paginación + búsqueda vive acá una sola vez.
 */
abstract class MaestraSimpleController
{
    protected string $tabla;    // 'categoria' | 'marca'  (definido por la subclase)
    protected string $entidad;  // etiqueta para los mensajes

    private function repo(): MaestraSimpleRepository
    {
        return new MaestraSimpleRepository($this->tabla);
    }

    public function admin(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }

        [$page, $perPage, $offset] = Paginacion::desde();
        $q      = Request::query('q');
        $activo = Request::query('activo');

        $r = $this->repo()->listarPaginado(
            $q,
            $activo === null || $activo === '' ? null : (int) $activo,
            $perPage, $offset
        );
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage));
    }

    public function guardar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }

        $in     = Request::json();
        $id     = (int) ($in['id'] ?? 0);
        $nombre = trim($in['nombre'] ?? '');
        $activo = isset($in['activo']) ? (int) (bool) $in['activo'] : 1;
        // El widget de imágenes puede mandar varias líneas; tomamos la primera.
        $imagen = trim(explode("\n", (string) ($in['imagen'] ?? ''))[0]);
        if ($imagen === '') {
            $imagen = null;
        } elseif (!preg_match('#^(https?://|/)#i', $imagen)) {
            $imagen = null;   // solo URLs http/https o imágenes subidas (/uploads/...)
        }

        try {
            if (mb_strlen($nombre) < 2) {
                throw new ValidacionException("El nombre de {$this->entidad} es obligatorio (mínimo 2 caracteres).");
            }
            if ($this->repo()->nombreExiste($nombre, $id)) {
                throw new ValidacionException("Ya existe una {$this->entidad} con ese nombre.");
            }
            if ($id > 0) {
                if ($this->repo()->buscarPorId($id) === null) {
                    throw new ValidacionException("La {$this->entidad} no existe.");
                }
                $this->repo()->actualizar($id, $nombre, $activo, $imagen);
            } else {
                $id = $this->repo()->crear($nombre, $activo, $imagen);
            }
            Response::json(['ok' => true, 'id' => $id]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => "No se pudo guardar la {$this->entidad}."], 500);
        }
    }

    public function baja(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        $id = (int) (Request::json()['id'] ?? 0);
        if ($this->repo()->buscarPorId($id) === null) {
            Response::json(['ok' => false, 'error' => "La {$this->entidad} no existe."], 422);
            return;
        }
        $this->repo()->cambiarEstado($id, 0);
        Response::json(['ok' => true]);
    }
}

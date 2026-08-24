<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidacionException;
use App\Repositories\BloqueRepository;

/** ABM de bloques de la home (panel admin). Solo admin. */
class BloqueController
{
    private const TIPOS = ['hero', 'banner', 'video', 'carrusel_categorias', 'carrusel_productos', 'grid_productos'];

    private function repo(): BloqueRepository { return new BloqueRepository(); }
    private function guard(): bool
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return false; }
        return true;
    }

    public function listar(): void
    {
        if (!$this->guard()) return;
        Response::json(['ok' => true, 'bloques' => $this->repo()->listarTodos()]);
    }

    public function guardar(): void
    {
        if (!$this->guard()) return;
        $d = Request::json();
        try {
            $tipo   = $d['tipo'] ?? '';
            $titulo = trim($d['titulo'] ?? '') ?: null;
            $config = is_array($d['config'] ?? null) ? $d['config'] : [];
            $activo = isset($d['activo']) ? (int) (bool) $d['activo'] : 1;

            $id = (int) ($d['id'] ?? 0);
            if ($id > 0) {
                if ($this->repo()->buscarPorId($id) === null) throw new ValidacionException('El bloque no existe.');
                $this->repo()->actualizar($id, $titulo, $config, $activo);
            } else {
                if (!in_array($tipo, self::TIPOS, true)) throw new ValidacionException('Tipo de bloque inválido.');
                $id = $this->repo()->crear($tipo, $titulo, $config, $activo);
            }
            Response::json(['ok' => true, 'id' => $id]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Response::json(['ok' => false, 'error' => 'No se pudo guardar el bloque.'], 500);
        }
    }

    public function estado(): void
    {
        if (!$this->guard()) return;
        $d = Request::json();
        $this->repo()->cambiarEstado((int) ($d['id'] ?? 0), (int) (bool) ($d['activo'] ?? 0));
        Response::json(['ok' => true]);
    }

    public function mover(): void
    {
        if (!$this->guard()) return;
        $d = Request::json();
        $dir = ($d['dir'] ?? '') === 'arriba' ? 'arriba' : 'abajo';
        $this->repo()->mover((int) ($d['id'] ?? 0), $dir);
        Response::json(['ok' => true]);
    }

    /** Reordena TODOS los bloques según el orden de ids que manda el drag & drop. */
    public function reordenar(): void
    {
        if (!$this->guard()) return;
        $ids = Request::json()['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $this->repo()->reordenar(array_map('intval', $ids));
        Response::json(['ok' => true]);
    }

    public function borrar(): void
    {
        if (!$this->guard()) return;
        $this->repo()->borrar((int) (Request::json()['id'] ?? 0));
        Response::json(['ok' => true]);
    }

    // ===== Slides (hero) =====

    public function slides(): void
    {
        if (!$this->guard()) return;
        Response::json(['ok' => true, 'slides' => $this->repo()->slidesDe((int) Request::query('bloque_id', '0'))]);
    }

    public function guardarSlide(): void
    {
        if (!$this->guard()) return;
        $in = Request::json();
        $d = [
            'imagen_desktop' => trim($in['imagen_desktop'] ?? '') ?: null,
            'imagen_mobile'  => trim($in['imagen_mobile'] ?? '') ?: null,
            'titulo'         => trim($in['titulo'] ?? '') ?: null,
            'subtitulo'      => trim($in['subtitulo'] ?? '') ?: null,
            'boton_texto'    => trim($in['boton_texto'] ?? '') ?: null,
            'url'            => trim($in['url'] ?? '') ?: null,
            'activo'         => isset($in['activo']) ? (int) (bool) $in['activo'] : 1,
            'desde'          => trim($in['desde'] ?? '') ?: null,
            'hasta'          => trim($in['hasta'] ?? '') ?: null,
        ];
        try {
            $id = (int) ($in['id'] ?? 0);
            if ($id > 0) { $this->repo()->actualizarSlide($id, $d); }
            else {
                $bloqueId = (int) ($in['bloque_id'] ?? 0);
                if (!$d['imagen_desktop']) throw new ValidacionException('La imagen (desktop) es obligatoria.');
                $id = $this->repo()->crearSlide($bloqueId, $d);
            }
            Response::json(['ok' => true, 'id' => $id]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function borrarSlide(): void
    {
        if (!$this->guard()) return;
        $this->repo()->borrarSlide((int) (Request::json()['id'] ?? 0));
        Response::json(['ok' => true]);
    }
}

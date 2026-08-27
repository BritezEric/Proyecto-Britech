<?php

namespace App\Services;

use App\Repositories\BarrioRepository;
use App\Core\ValidacionException;

/** Lógica + validaciones del ABM de barrios (Moto Express). */
class BarrioService
{
    private BarrioRepository $repo;

    public function __construct()
    {
        $this->repo = new BarrioRepository();
    }

    public function listar(?string $q, ?int $activo, int $limit, int $offset): array
    {
        return $this->repo->listarPaginado($q, $activo, $limit, $offset);
    }

    public function guardar(array $in): int
    {
        $nombre = trim($in['nombre'] ?? '');
        $costo  = $in['costo'] ?? '';
        if (mb_strlen($nombre) < 2) {
            throw new ValidacionException('El nombre del barrio es obligatorio (mínimo 2 caracteres).');
        }
        if ($costo === '' || !is_numeric($costo) || (float) $costo < 0) {
            throw new ValidacionException('El precio debe ser un número ≥ 0.');
        }
        $id = (int) ($in['id'] ?? 0);
        if ($this->repo->nombreExiste($nombre, $id)) {
            throw new ValidacionException('Ya existe un barrio con ese nombre.');
        }
        $costo  = round((float) $costo, 2);
        $activo = isset($in['activo']) ? (int) (bool) $in['activo'] : 1;

        if ($id > 0) {
            if ($this->repo->buscarPorId($id) === null) {
                throw new ValidacionException('El barrio no existe.');
            }
            $this->repo->actualizar($id, $nombre, $costo, $activo);
            return $id;
        }
        return $this->repo->crear($nombre, $costo, $activo);
    }

    public function baja(int $id): void
    {
        if ($this->repo->buscarPorId($id) === null) {
            throw new ValidacionException('El barrio no existe.');
        }
        $this->repo->cambiarEstado($id, 0);
    }
}

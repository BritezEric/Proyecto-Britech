<?php

namespace App\Services;

use App\Repositories\EmpresaEnvioRepository;
use App\Core\ValidacionException;

/** Lógica + validaciones del ABM de empresas de envío. */
class EmpresaEnvioService
{
    private EmpresaEnvioRepository $repo;

    public function __construct()
    {
        $this->repo = new EmpresaEnvioRepository();
    }

    public function listar(?string $q, ?int $activo, int $limit, int $offset): array
    {
        return $this->repo->listarPaginado($q, $activo, $limit, $offset);
    }

    public function guardar(array $in): int
    {
        $nombre = trim($in['nombre'] ?? '');
        $costo  = $in['costo_base'] ?? '';
        if (mb_strlen($nombre) < 2) {
            throw new ValidacionException('El nombre es obligatorio (mínimo 2 caracteres).');
        }
        if ($costo === '' || !is_numeric($costo) || (float) $costo < 0) {
            throw new ValidacionException('El costo debe ser un número ≥ 0.');
        }
        $costo    = round((float) $costo, 2);
        $activo   = isset($in['activo']) ? (int) (bool) $in['activo'] : 1;
        $esRetiro = isset($in['es_retiro']) ? (int) (bool) $in['es_retiro'] : 0;
        $urlTrack = trim($in['url_tracking'] ?? '') ?: null;
        $id       = (int) ($in['id'] ?? 0);

        if ($id > 0) {
            if ($this->repo->buscarPorId($id) === null) {
                throw new ValidacionException('La empresa de envío no existe.');
            }
            $this->repo->actualizar($id, $nombre, $costo, $activo, $esRetiro, $urlTrack);
            return $id;
        }
        return $this->repo->crear($nombre, $costo, $activo, $esRetiro, $urlTrack);
    }

    public function baja(int $id): void
    {
        if ($this->repo->buscarPorId($id) === null) {
            throw new ValidacionException('La empresa de envío no existe.');
        }
        $this->repo->cambiarEstado($id, 0);
    }
}

<?php

namespace App\Services;

use App\Repositories\ProveedorRepository;
use App\Core\ValidacionException;

/** Lógica + validaciones del ABM de Proveedores. */
class ProveedorService
{
    private ProveedorRepository $repo;

    public function __construct()
    {
        $this->repo = new ProveedorRepository();
    }

    public function listar(?string $q, ?int $activo, int $limit, int $offset): array
    {
        return $this->repo->listarPaginado($q, $activo, $limit, $offset);
    }

    public function guardar(array $in): int
    {
        $d  = $this->validar($in);
        $id = (int) ($in['id'] ?? 0);
        if ($id > 0) {
            if ($this->repo->buscarPorId($id) === null) {
                throw new ValidacionException('El proveedor no existe.');
            }
            $this->repo->actualizar($id, $d);
            return $id;
        }
        return $this->repo->crear($d);
    }

    public function baja(int $id): void
    {
        if ($this->repo->buscarPorId($id) === null) {
            throw new ValidacionException('El proveedor no existe.');
        }
        $this->repo->cambiarEstado($id, 0);
    }

    private function validar(array $in): array
    {
        $nombre    = trim($in['nombre'] ?? '');
        $cuit      = trim($in['cuit'] ?? '');
        $email     = trim($in['email'] ?? '');
        $telefono  = trim($in['telefono'] ?? '');
        $direccion = trim($in['direccion'] ?? '');

        if (mb_strlen($nombre) < 2) {
            throw new ValidacionException('El nombre del proveedor es obligatorio (mínimo 2 caracteres).');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidacionException('El email no tiene un formato válido.');
        }
        if ($cuit !== '' && !preg_match('/^[0-9\-]{6,20}$/', $cuit)) {
            throw new ValidacionException('El CUIT solo puede tener números y guiones.');
        }

        return [
            'nombre'    => $nombre,
            'cuit'      => $cuit ?: null,
            'email'     => $email ?: null,
            'telefono'  => $telefono ?: null,
            'direccion' => $direccion ?: null,
            'activo'    => isset($in['activo']) ? (int) (bool) $in['activo'] : 1,
        ];
    }
}

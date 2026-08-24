<?php

namespace App\Services;

use App\Repositories\ClienteRepository;
use App\Core\ValidacionException;

/**
 * Lógica de negocio + validaciones del ABM de Clientes.
 * Regla: validar SIEMPRE en el backend (el navegador se puede saltear).
 */
class ClienteService
{
    private ClienteRepository $repo;

    public function __construct()
    {
        $this->repo = new ClienteRepository();
    }

    public function listar(?string $q, ?int $activo, ?int $listaId, int $limit, int $offset): array
    {
        return $this->repo->listarPaginado($q, $activo, $listaId, $limit, $offset);
    }

    /** Crea (si no viene id) o actualiza. Devuelve el id del cliente. */
    public function guardar(array $in): int
    {
        $d = $this->validar($in);
        $id = (int) ($in['id'] ?? 0);

        if ($id > 0) {
            if ($this->repo->buscarCompleto($id) === null) {
                throw new ValidacionException('El cliente no existe.');
            }
            $this->repo->actualizar($id, $d);
            return $id;
        }
        return $this->repo->crear($d);
    }

    public function baja(int $id): void
    {
        if ($this->repo->buscarCompleto($id) === null) {
            throw new ValidacionException('El cliente no existe.');
        }
        $this->repo->cambiarEstado($id, 0);
    }

    /** Normaliza y valida los datos. Devuelve el array listo para el repo. */
    private function validar(array $in): array
    {
        $nombre    = trim($in['nombre'] ?? '');
        $documento = trim($in['documento'] ?? '');
        $email     = trim($in['email'] ?? '');
        $telefono  = trim($in['telefono'] ?? '');
        $direccion = trim($in['direccion'] ?? '');
        $localidad = trim($in['localidad'] ?? '');
        $listaId   = (int) ($in['lista_precio_id'] ?? 0);

        if (mb_strlen($nombre) < 2) {
            throw new ValidacionException('El nombre es obligatorio (mínimo 2 caracteres).');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidacionException('El email no tiene un formato válido.');
        }
        if ($telefono !== '' && !preg_match('/^[0-9()+\-\s]{6,30}$/', $telefono)) {
            throw new ValidacionException('El teléfono solo puede tener números, espacios y ( ) + -.');
        }
        if ($listaId <= 0) {
            throw new ValidacionException('Elegí una lista de precios.');
        }

        return [
            'nombre'          => $nombre,
            'documento'       => $documento ?: null,
            'email'           => $email ?: null,
            'telefono'        => $telefono ?: null,
            'direccion'       => $direccion ?: null,
            'localidad'       => $localidad ?: null,
            'lista_precio_id' => $listaId,
            'activo'          => isset($in['activo']) ? (int) (bool) $in['activo'] : 1,
        ];
    }
}

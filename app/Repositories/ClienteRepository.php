<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Repositorio de clientes: único lugar que consulta la tabla cliente.
 */
class ClienteRepository
{
    public function listar(): array
    {
        $pdo = Database::conexion();

        // Traemos también la lista de precio del cliente (minorista/mayorista),
        // porque de eso depende qué precio se le aplica.
        $sql = "SELECT c.id,
                       c.nombre,
                       c.lista_precio_id,
                       lp.nombre AS lista
                FROM cliente c
                JOIN lista_precio lp ON lp.id = c.lista_precio_id
                WHERE c.activo = 1
                ORDER BY c.id";

        return $pdo->query($sql)->fetchAll();
    }

    /** Busca un cliente activo por id. Devuelve null si no existe. */
    public function buscar(int $id): ?array
    {
        $pdo = Database::conexion();
        $stmt = $pdo->prepare(
            "SELECT id, nombre, lista_precio_id FROM cliente WHERE id = ? AND activo = 1"
        );
        $stmt->execute([$id]);
        $fila = $stmt->fetch();
        return $fila ?: null;
    }
}

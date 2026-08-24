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

    // ===== ABM (panel admin) =====

    /**
     * Listado paginado con búsqueda y filtros para el ABM.
     * @return array{rows: array, total: int}
     */
    public function listarPaginado(?string $q, ?int $activo, ?int $listaId, int $limit, int $offset): array
    {
        $pdo = Database::conexion();

        $where  = [];
        $params = [];
        if ($q !== null && $q !== '') {
            $where[] = "(c.nombre LIKE ? OR c.documento LIKE ? OR c.email LIKE ? OR c.localidad LIKE ?)";
            $like = "%{$q}%";
            array_push($params, $like, $like, $like, $like);
        }
        if ($activo !== null)  { $where[] = "c.activo = ?";          $params[] = $activo; }
        if ($listaId !== null) { $where[] = "c.lista_precio_id = ?"; $params[] = $listaId; }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = (int) $this->contar("FROM cliente c $sqlWhere", $params);

        $sql = "SELECT c.id, c.nombre, c.documento, c.email, c.telefono,
                       c.direccion, c.localidad, c.lista_precio_id, c.activo,
                       c.email_verificado, c.creado_en,
                       lp.nombre AS lista
                FROM cliente c
                JOIN lista_precio lp ON lp.id = c.lista_precio_id
                $sqlWhere
                ORDER BY c.id DESC
                LIMIT ? OFFSET ?";
        $st = $pdo->prepare($sql);
        $this->bindLista($st, array_merge($params, [$limit, $offset]));
        $st->execute();

        return ['rows' => $st->fetchAll(), 'total' => $total];
    }

    public function buscarCompleto(int $id): ?array
    {
        $st = Database::conexion()->prepare("SELECT * FROM cliente WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function crear(array $d): int
    {
        $pdo = Database::conexion();
        $pdo->prepare("INSERT INTO cliente
                       (nombre, documento, email, telefono, direccion, localidad, lista_precio_id, activo)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$d['nombre'], $d['documento'], $d['email'], $d['telefono'],
                       $d['direccion'], $d['localidad'], $d['lista_precio_id'], $d['activo']]);
        return (int) $pdo->lastInsertId();
    }

    public function actualizar(int $id, array $d): void
    {
        Database::conexion()
            ->prepare("UPDATE cliente SET
                       nombre = ?, documento = ?, email = ?, telefono = ?,
                       direccion = ?, localidad = ?, lista_precio_id = ?, activo = ?
                       WHERE id = ?")
            ->execute([$d['nombre'], $d['documento'], $d['email'], $d['telefono'],
                       $d['direccion'], $d['localidad'], $d['lista_precio_id'], $d['activo'], $id]);
    }

    public function cambiarEstado(int $id, int $activo): void
    {
        Database::conexion()
            ->prepare("UPDATE cliente SET activo = ? WHERE id = ?")
            ->execute([$activo, $id]);
    }

    // ===== Tienda online (login / registro de clientes) =====

    /** Trae al cliente por email para verificar la contraseña en el login. */
    public function buscarParaLogin(string $email): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT c.id, c.nombre, c.email, c.password_hash, c.email_verificado, c.lista_precio_id, c.activo
             FROM cliente c WHERE c.email = ? LIMIT 1"
        );
        $st->execute([$email]);
        return $st->fetch() ?: null;
    }

    /** Busca un cliente por email (para registro/reenvío). */
    public function buscarPorEmail(string $email): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT id, nombre, email, password_hash, email_verificado FROM cliente WHERE email = ? LIMIT 1"
        );
        $st->execute([$email]);
        return $st->fetch() ?: null;
    }

    /** Registro desde la tienda: crea el cliente SIN contraseña y SIN verificar.
     *  La contraseña se define y la cuenta se activa desde el link del email. */
    public function registrar(string $nombre, string $email, ?string $telefono): int
    {
        $pdo = Database::conexion();
        // lista_precio_id = 1 (minorista) por defecto; password null hasta activar.
        $pdo->prepare("INSERT INTO cliente (nombre, email, telefono, password_hash, email_verificado, lista_precio_id, activo)
                       VALUES (?, ?, ?, NULL, 0, 1, 1)")
            ->execute([$nombre, $email, $telefono]);
        return (int) $pdo->lastInsertId();
    }

    /** Define la contraseña del cliente y marca su email como verificado (activa la cuenta). */
    public function definirPassword(int $clienteId, string $hash): void
    {
        Database::conexion()
            ->prepare("UPDATE cliente SET password_hash = ?, email_verificado = 1 WHERE id = ?")
            ->execute([$hash, $clienteId]);
    }

    /** ¿El cliente tiene acceso mayorista aprobado? */
    public function esMayoristaAprobado(int $clienteId): bool
    {
        $st = Database::conexion()->prepare("SELECT mayorista_aprobado FROM cliente WHERE id = ?");
        $st->execute([$clienteId]);
        return (int) $st->fetchColumn() === 1;
    }

    public function aprobarMayorista(int $clienteId, int $aprobado): void
    {
        Database::conexion()
            ->prepare("UPDATE cliente SET mayorista_aprobado = ? WHERE id = ?")
            ->execute([$aprobado, $clienteId]);
    }

    // -- helpers de conteo/bind (compartidos por los listados paginados) --

    private function contar(string $fromWhere, array $params): int
    {
        $st = Database::conexion()->prepare("SELECT COUNT(*) $fromWhere");
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

    /** LIMIT/OFFSET deben ir como enteros; PDO por defecto los manda como texto. */
    private function bindLista(\PDOStatement $st, array $params): void
    {
        foreach ($params as $i => $v) {
            $st->bindValue($i + 1, $v, is_int($v) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
    }
}

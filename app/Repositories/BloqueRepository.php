<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Bloques de la home (page builder). La home es una lista ordenada de bloques;
 * cada bloque tiene un `tipo` y un `config` (JSON) con sus ajustes.
 */
class BloqueRepository
{
    // ===== Público (home) =====

    /** Bloques activos, ordenados, con el config ya decodificado. */
    public function activos(): array
    {
        $filas = Database::conexion()
            ->query("SELECT id, tipo, titulo, config, orden FROM bloque WHERE activo = 1 ORDER BY orden, id")
            ->fetchAll();
        foreach ($filas as &$b) { $b['config'] = $b['config'] ? json_decode($b['config'], true) : []; }
        return $filas;
    }

    /** Slides activos de un hero (respetando fechas desde/hasta). */
    public function slidesActivos(int $bloqueId): array
    {
        $st = Database::conexion()->prepare(
            "SELECT imagen_desktop, imagen_mobile, titulo, subtitulo, boton_texto, url
             FROM bloque_slide
             WHERE bloque_id = ? AND activo = 1
               AND (desde IS NULL OR desde <= CURDATE())
               AND (hasta IS NULL OR hasta >= CURDATE())
             ORDER BY orden, id"
        );
        $st->execute([$bloqueId]);
        return $st->fetchAll();
    }

    // ===== Admin (ABM) =====

    public function listarTodos(): array
    {
        $filas = Database::conexion()
            ->query("SELECT id, tipo, titulo, config, activo, orden FROM bloque ORDER BY orden, id")
            ->fetchAll();
        foreach ($filas as &$b) { $b['config'] = $b['config'] ? json_decode($b['config'], true) : []; }
        return $filas;
    }

    public function buscarPorId(int $id): ?array
    {
        $st = Database::conexion()->prepare("SELECT * FROM bloque WHERE id = ?");
        $st->execute([$id]);
        $b = $st->fetch();
        if (!$b) return null;
        $b['config'] = $b['config'] ? json_decode($b['config'], true) : [];
        return $b;
    }

    public function crear(string $tipo, ?string $titulo, array $config, int $activo): int
    {
        $pdo = Database::conexion();
        $orden = (int) $pdo->query("SELECT COALESCE(MAX(orden),0)+1 FROM bloque")->fetchColumn();
        $pdo->prepare("INSERT INTO bloque (tipo, titulo, config, activo, orden) VALUES (?,?,?,?,?)")
            ->execute([$tipo, $titulo, json_encode($config, JSON_UNESCAPED_UNICODE), $activo, $orden]);
        return (int) $pdo->lastInsertId();
    }

    public function actualizar(int $id, ?string $titulo, array $config, int $activo): void
    {
        Database::conexion()
            ->prepare("UPDATE bloque SET titulo = ?, config = ?, activo = ? WHERE id = ?")
            ->execute([$titulo, json_encode($config, JSON_UNESCAPED_UNICODE), $activo, $id]);
    }

    public function cambiarEstado(int $id, int $activo): void
    {
        Database::conexion()->prepare("UPDATE bloque SET activo = ? WHERE id = ?")->execute([$activo, $id]);
    }

    public function borrar(int $id): void
    {
        Database::conexion()->prepare("DELETE FROM bloque WHERE id = ?")->execute([$id]);
    }

    /** Intercambia el orden con el bloque vecino (arriba/abajo). */
    public function mover(int $id, string $dir): void
    {
        $pdo = Database::conexion();
        $st = $pdo->prepare("SELECT orden FROM bloque WHERE id = ?");
        $st->execute([$id]);
        $orden = $st->fetchColumn();
        if ($orden === false) return;

        $cmp = $dir === 'arriba' ? '<' : '>';
        $ord = $dir === 'arriba' ? 'DESC' : 'ASC';
        $st = $pdo->prepare("SELECT id, orden FROM bloque WHERE orden $cmp ? ORDER BY orden $ord LIMIT 1");
        $st->execute([$orden]);
        $vecino = $st->fetch();
        if (!$vecino) return;   // ya está en el borde

        $up = $pdo->prepare("UPDATE bloque SET orden = ? WHERE id = ?");
        $up->execute([$vecino['orden'], $id]);
        $up->execute([$orden, $vecino['id']]);
    }

    /** Fija el orden de los bloques según la lista de ids recibida (drag & drop). */
    public function reordenar(array $ids): void
    {
        $pdo = Database::conexion();
        $up = $pdo->prepare("UPDATE bloque SET orden = ? WHERE id = ?");
        $orden = 1;
        foreach ($ids as $id) {
            $up->execute([$orden++, (int) $id]);
        }
    }

    // ===== Slides (admin) =====

    public function slidesDe(int $bloqueId): array
    {
        $st = Database::conexion()->prepare("SELECT * FROM bloque_slide WHERE bloque_id = ? ORDER BY orden, id");
        $st->execute([$bloqueId]);
        return $st->fetchAll();
    }

    public function crearSlide(int $bloqueId, array $d): int
    {
        $pdo = Database::conexion();
        $st = $pdo->prepare("SELECT COALESCE(MAX(orden),0)+1 FROM bloque_slide WHERE bloque_id = ?");
        $st->execute([$bloqueId]);
        $orden = (int) $st->fetchColumn();
        $pdo->prepare("INSERT INTO bloque_slide
            (bloque_id, imagen_desktop, imagen_mobile, titulo, subtitulo, boton_texto, url, activo, orden, desde, hasta)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$bloqueId, $d['imagen_desktop'], $d['imagen_mobile'], $d['titulo'], $d['subtitulo'],
                       $d['boton_texto'], $d['url'], $d['activo'], $orden, $d['desde'], $d['hasta']]);
        return (int) $pdo->lastInsertId();
    }

    public function actualizarSlide(int $id, array $d): void
    {
        Database::conexion()->prepare("UPDATE bloque_slide SET
            imagen_desktop=?, imagen_mobile=?, titulo=?, subtitulo=?, boton_texto=?, url=?, activo=?, desde=?, hasta=?
            WHERE id=?")
            ->execute([$d['imagen_desktop'], $d['imagen_mobile'], $d['titulo'], $d['subtitulo'], $d['boton_texto'],
                       $d['url'], $d['activo'], $d['desde'], $d['hasta'], $id]);
    }

    public function borrarSlide(int $id): void
    {
        Database::conexion()->prepare("DELETE FROM bloque_slide WHERE id = ?")->execute([$id]);
    }
}

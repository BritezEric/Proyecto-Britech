<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\BloqueRepository;
use App\Repositories\ProductoRepository;

/**
 * Arma el contenido de la home: recorre los bloques activos (en orden) y
 * resuelve los datos que necesita cada tipo (hero→slides, carrusel_productos→
 * productos de una categoría, etc.). El front solo tiene que renderizar.
 */
class HomeService
{
    public function home(int $listaId): array
    {
        $repo = new BloqueRepository();
        $prod = new ProductoRepository();
        $salida = [];

        foreach ($repo->activos() as $b) {
            $cfg  = $b['config'] ?? [];
            $data = [];

            switch ($b['tipo']) {
                case 'hero':
                    $data['slides'] = $repo->slidesActivos((int) $b['id']);
                    break;
                case 'carrusel_categorias':
                    $data['categorias'] = $this->categorias();
                    break;
                case 'carrusel_productos':
                case 'grid_productos':
                    $data['productos'] = $prod->porCategoria(
                        isset($cfg['categoria_id']) ? (int) $cfg['categoria_id'] : null,
                        $listaId,
                        (int) ($cfg['limite'] ?? 10),
                        !empty($cfg['solo_ofertas'])
                    );
                    break;
                // 'banner' y 'video' no necesitan resolver nada: el config ya trae todo.
            }

            $salida[] = [
                'id'     => (int) $b['id'],
                'tipo'   => $b['tipo'],
                'titulo' => $b['titulo'],
                'config' => $cfg,
                'data'   => $data,
            ];
        }
        return $salida;
    }

    /** Categorías activas con imagen, ordenadas (para el carrusel de categorías). */
    private function categorias(): array
    {
        return Database::conexion()->query(
            "SELECT id, nombre, imagen FROM categoria
             WHERE activo = 1 AND imagen IS NOT NULL
             ORDER BY orden, nombre"
        )->fetchAll();
    }
}

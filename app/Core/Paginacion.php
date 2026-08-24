<?php

namespace App\Core;

/**
 * Paginación: lee ?page= y ?per_page= de la URL y arma la respuesta estándar
 * de un listado paginado. Lo usan todos los ABM del panel admin.
 *
 * Respuesta: { ok, data:[...], page, per_page, total, total_pages }
 */
class Paginacion
{
    /** Lee page/per_page de la URL. Devuelve [page, perPage, offset]. */
    public static function desde(int $porDefecto = 10, int $maximo = 100): array
    {
        $page    = max(1, (int) Request::query('page', '1'));
        $perPage = (int) Request::query('per_page', (string) $porDefecto);
        $perPage = max(1, min($maximo, $perPage));   // techo: nunca más de $maximo
        $offset  = ($page - 1) * $perPage;
        return [$page, $perPage, $offset];
    }

    /** Arma la respuesta paginada estándar. */
    public static function respuesta(array $filas, int $total, int $page, int $perPage): array
    {
        return [
            'ok'          => true,
            'data'        => $filas,
            'page'        => $page,
            'per_page'    => $perPage,
            'total'       => $total,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }
}

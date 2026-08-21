<?php

namespace App\Core;

/**
 * Router: decide QUE controlador atiende CADA ruta.
 *
 * Se registran rutas asi:
 *   $router->get('/api/productos', [ProductoController::class, 'index']);
 *
 * Y cuando llega una peticion, "despachar" busca la ruta y ejecuta
 * el metodo del controlador correspondiente.
 */
class Router
{
    private array $rutas = [];

    public function get(string $ruta, array $accion): void
    {
        $this->rutas['GET'][$ruta] = $accion;
    }

    public function post(string $ruta, array $accion): void
    {
        $this->rutas['POST'][$ruta] = $accion;
    }

    public function despachar(string $metodo, string $ruta): void
    {
        $accion = $this->rutas[$metodo][$ruta] ?? null;

        if ($accion === null) {
            Response::json(['error' => 'Ruta no encontrada'], 404);
            return;
        }

        // $accion = [NombreControlador::class, 'metodo']
        [$clase, $metodoAccion] = $accion;
        (new $clase())->$metodoAccion();
    }
}

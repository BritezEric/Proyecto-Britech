<?php

namespace App\Core;

/**
 * Router: decide QUE controlador atiende CADA ruta, y actúa como middleware
 * de autenticación: si una ruta requiere login y no hay sesión, corta con 401.
 *
 *   $router->get('/api/productos', [ProductoController::class, 'index'], true);
 *                                                                        ^^^^ requiere login
 */
class Router
{
    private array $rutas = [];

    public function get(string $ruta, array $accion, bool $auth = false): void
    {
        $this->rutas['GET'][$ruta] = ['accion' => $accion, 'auth' => $auth];
    }

    public function post(string $ruta, array $accion, bool $auth = false): void
    {
        $this->rutas['POST'][$ruta] = ['accion' => $accion, 'auth' => $auth];
    }

    public function despachar(string $metodo, string $ruta): void
    {
        $r = $this->rutas[$metodo][$ruta] ?? null;

        if ($r === null) {
            Response::json(['error' => 'Ruta no encontrada'], 404);
            return;
        }

        // "Middleware" de auth: ruta protegida sin sesión → 401.
        if ($r['auth'] && Session::usuarioId() === null) {
            Response::json(['error' => 'No autenticado'], 401);
            return;
        }

        [$clase, $metodoAccion] = $r['accion'];
        (new $clase())->$metodoAccion();
    }
}

<?php

namespace App\Services;

use App\Core\Session;
use App\Core\ValidacionException;
use App\Repositories\UsuarioRepository;

/**
 * Lógica de autenticación (login / logout).
 */
class AuthService
{
    public function login(string $email, string $password): array
    {
        $usuario = (new UsuarioRepository())->buscarPorEmail(trim($email));

        // Mensaje genérico: no revelamos si falló el email o la contraseña.
        if ($usuario === null || !password_verify($password, $usuario['password_hash'])) {
            throw new ValidacionException('Email o contraseña incorrectos.');
        }
        if ((int) $usuario['activo'] !== 1) {
            throw new ValidacionException('El usuario está inactivo.');
        }
        if ((int) $usuario['email_verificado'] !== 1) {
            throw new ValidacionException('Tenés que verificar tu correo antes de entrar.');
        }

        Session::login((int) $usuario['id'], [
            'nombre' => $usuario['nombre'],
            'email'  => $usuario['email'],
            'rol_id' => (int) $usuario['rol_id'],
        ]);

        return ['id' => (int) $usuario['id'], 'nombre' => $usuario['nombre'], 'rol_id' => (int) $usuario['rol_id']];
    }

    public function logout(): void
    {
        Session::logout();
    }
}

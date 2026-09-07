<?php

namespace App\Services;

use App\Core\Session;
use App\Core\Mailer;
use App\Core\ValidacionException;
use App\Repositories\UsuarioRepository;
use App\Repositories\TokenRepository;

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
            'rol'    => $usuario['rol'],   // nombre del rol (admin / vendedor)
        ]);

        return ['id' => (int) $usuario['id'], 'nombre' => $usuario['nombre'], 'rol_id' => (int) $usuario['rol_id']];
    }

    public function logout(): void
    {
        Session::logout();
    }

    /**
     * Pide recuperar contraseña. Si el email existe (y está verificado), manda
     * un correo con un link. No revela si el email existe o no (seguridad).
     */
    public function solicitarReset(string $email): void
    {
        $usuario = (new UsuarioRepository())->buscarPorEmail(trim($email));
        if ($usuario === null || (int) $usuario['email_verificado'] !== 1) {
            return; // en silencio, para no filtrar qué emails existen
        }

        $token = (new TokenRepository())->crear((int) $usuario['id'], 'reset', 1); // 1 hora
        $this->enviarReset($usuario['email'], $usuario['nombre'], $token);
    }

    /** Restablece la contraseña con el token del correo. */
    public function restablecer(string $token, string $password): void
    {
        if (strlen($password) < 8) {
            throw new ValidacionException('La contraseña debe tener al menos 8 caracteres.');
        }

        $tokens = new TokenRepository();
        $t = $tokens->buscarValido($token, 'reset');
        if ($t === null) {
            throw new ValidacionException('El enlace no es válido o ya venció.');
        }

        (new UsuarioRepository())->actualizarPassword(
            (int) $t['usuario_id'],
            password_hash($password, PASSWORD_DEFAULT)
        );
        $tokens->marcarUsado((int) $t['id']);
    }

    private function enviarReset(string $email, string $nombre, string $token): void
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $url = $config['app']['url'] . '/restablecer.html?token=' . $token;

        $cuerpo = "<h2>Recuperar contraseña</h2>
            <p>Hola {$nombre}, para elegir una nueva contraseña hacé clic acá:</p>
            <p><a href=\"{$url}\">Restablecer contraseña</a></p>
            <p>Si no pediste esto, ignorá este correo. El enlace vence en 1 hora.</p>";

        Mailer::enviar($email, $nombre, 'Recuperar contraseña - Britech', $cuerpo);
    }
}

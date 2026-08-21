<?php

namespace App\Services;

use App\Core\Mailer;
use App\Core\ValidacionException;
use App\Repositories\UsuarioRepository;
use App\Repositories\TokenRepository;

/**
 * Alta de usuarios (el admin crea) + verificación de correo.
 * El admin nunca define la contraseña: el usuario la pone desde el link del email.
 */
class UsuarioService
{
    public function crear(string $nombre, string $email, int $rolId): array
    {
        $nombre = trim($nombre);
        $email  = trim($email);

        if ($nombre === '') {
            throw new ValidacionException('El nombre es obligatorio.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidacionException('El email no es válido.');
        }
        if (!in_array($rolId, [1, 2], true)) { // 1 = admin, 2 = vendedor
            throw new ValidacionException('Rol no válido.');
        }

        $repo = new UsuarioRepository();
        if ($repo->buscarPorEmail($email) !== null) {
            throw new ValidacionException('Ya existe un usuario con ese email.');
        }

        $id    = $repo->crear($nombre, $email, $rolId);
        $token = (new TokenRepository())->crear($id, 'verificacion', 24);
        $this->enviarVerificacion($email, $nombre, $token);

        return ['id' => $id, 'nombre' => $nombre, 'email' => $email];
    }

    public function verificar(string $token, string $password): void
    {
        if (strlen($password) < 8) {
            throw new ValidacionException('La contraseña debe tener al menos 8 caracteres.');
        }

        $tokens = new TokenRepository();
        $t = $tokens->buscarValido($token, 'verificacion');
        if ($t === null) {
            throw new ValidacionException('El enlace no es válido o ya venció.');
        }

        (new UsuarioRepository())->definirPassword(
            (int) $t['usuario_id'],
            password_hash($password, PASSWORD_DEFAULT)
        );
        $tokens->marcarUsado((int) $t['id']);
    }

    private function enviarVerificacion(string $email, string $nombre, string $token): void
    {
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $url = $config['app']['url'] . '/verificar.html?token=' . $token;

        $cuerpo = "<h2>Bienvenido a Britech</h2>
            <p>Hola {$nombre}, se creó tu cuenta. Para activarla y definir tu contraseña,
            hacé clic en el siguiente enlace:</p>
            <p><a href=\"{$url}\">Activar mi cuenta</a></p>
            <p>Si el botón no funciona, copiá esta dirección: {$url}</p>
            <p>El enlace vence en 24 horas.</p>";

        Mailer::enviar($email, $nombre, 'Activá tu cuenta - Britech', $cuerpo);
    }
}

<?php

namespace App\Services;

use App\Core\Mailer;
use App\Core\ValidacionException;
use App\Repositories\ClienteRepository;
use App\Repositories\ClienteTokenRepository;

/**
 * Registro / activación / login de los CLIENTES de la tienda online.
 * Flujo: el cliente se registra con nombre + email → se le manda un correo →
 * desde el link define SU contraseña y la cuenta queda verificada/activa.
 * (El login del staff admin/vendedor es otro: AuthService.)
 */
class TiendaAuthService
{
    private ClienteRepository $repo;
    private ClienteTokenRepository $tokens;

    public function __construct()
    {
        $this->repo   = new ClienteRepository();
        $this->tokens = new ClienteTokenRepository();
    }

    /**
     * Registro: crea el cliente sin contraseña (sin verificar) y le manda el
     * correo de activación. No inicia sesión (primero tiene que activar).
     */
    public function registrar(string $nombre, string $email, ?string $telefono): void
    {
        $nombre = trim($nombre);
        $email  = trim(mb_strtolower($email));

        if (mb_strlen($nombre) < 2) {
            throw new ValidacionException('Ingresá tu nombre.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidacionException('El email no es válido.');
        }

        $existente = $this->repo->buscarPorEmail($email);
        if ($existente) {
            // Si ya está verificado, no se puede volver a registrar.
            if ((int) $existente['email_verificado'] === 1) {
                throw new ValidacionException('Ya existe una cuenta con ese email. Iniciá sesión.');
            }
            // Si existe pero no activó, reenviamos el correo a ese mismo cliente.
            $this->enviarActivacion((int) $existente['id'], $nombre ?: $existente['nombre'], $email);
            return;
        }

        $id = $this->repo->registrar($nombre, $email, trim($telefono ?? '') ?: null);
        $this->enviarActivacion($id, $nombre, $email);
    }

    /**
     * Invita a activar la cuenta a un cliente creado desde el panel admin.
     * Manda el correo de activación si el cliente tiene email y no está verificado.
     * Devuelve true si lo mandó, false si no correspondía (sin email o ya verificado).
     */
    public function invitarActivacion(int $clienteId): bool
    {
        $c = $this->repo->buscarCompleto($clienteId);
        if ($c === null) return false;
        $email = trim((string) ($c['email'] ?? ''));
        if ($email === '' || (int) ($c['email_verificado'] ?? 0) === 1) return false;
        $this->enviarActivacion($clienteId, $c['nombre'], $email);
        return true;
    }

    /** Reenvía el correo de activación (si el email existe y no está verificado). */
    public function reenviar(string $email): void
    {
        $email = trim(mb_strtolower($email));
        $c = $this->repo->buscarPorEmail($email);
        // No revelamos si el email existe; solo mandamos si corresponde.
        if ($c && (int) $c['email_verificado'] !== 1) {
            $this->enviarActivacion((int) $c['id'], $c['nombre'], $email);
        }
    }

    /**
     * Activa la cuenta con el token del correo: define la contraseña elegida y
     * marca el email como verificado. Devuelve el cliente para iniciar sesión.
     */
    public function activar(string $token, string $password): array
    {
        if (mb_strlen($password) < 6) {
            throw new ValidacionException('La contraseña debe tener al menos 6 caracteres.');
        }
        $t = $this->tokens->buscarValido($token, 'verificacion');
        if ($t === null) {
            throw new ValidacionException('El enlace no es válido o ya venció.');
        }

        $clienteId = (int) $t['cliente_id'];
        $this->repo->definirPassword($clienteId, password_hash($password, PASSWORD_DEFAULT));
        $this->tokens->marcarUsado((int) $t['id']);

        return $this->sesionDe($clienteId);   // datos para iniciar sesión automáticamente
    }

    /** "Olvidé mi contraseña": si el email existe y está verificado, manda el link de reset. */
    public function olvide(string $email): void
    {
        $email = trim(mb_strtolower($email));
        $c = $this->repo->buscarPorEmail($email);
        if ($c && (int) $c['email_verificado'] === 1) {
            $token  = $this->tokens->crear((int) $c['id'], 'reset', 2);   // vence en 2 h
            $config = require dirname(__DIR__, 2) . '/config/config.php';
            $url = $config['app']['url'] . '/tienda-reset.html?token=' . $token;
            $cuerpo = "<h2>Restablecer contraseña</h2>
                <p>Hola {$c['nombre']}, pediste cambiar tu contraseña. Hacé clic en el
                enlace para elegir una nueva:</p>
                <p><a href=\"{$url}\">Cambiar mi contraseña</a></p>
                <p>Si no fuiste vos, ignorá este correo. El enlace vence en 2 horas.</p>";
            Mailer::enviar($email, $c['nombre'], 'Restablecer contraseña - Britech', $cuerpo);
        }
        // No revelamos si el email existe.
    }

    /** Cambia la contraseña con el token de reset. Devuelve el cliente (login automático). */
    public function restablecer(string $token, string $password): array
    {
        if (mb_strlen($password) < 6) {
            throw new ValidacionException('La contraseña debe tener al menos 6 caracteres.');
        }
        $t = $this->tokens->buscarValido($token, 'reset');
        if ($t === null) {
            throw new ValidacionException('El enlace no es válido o ya venció.');
        }
        $clienteId = (int) $t['cliente_id'];
        $this->repo->definirPassword($clienteId, password_hash($password, PASSWORD_DEFAULT));
        $this->tokens->marcarUsado((int) $t['id']);
        return $this->sesionDe($clienteId);
    }

    public function login(string $email, string $password): array
    {
        $email = trim(mb_strtolower($email));
        $c = $this->repo->buscarParaLogin($email);

        // Sin contraseña definida = cuenta no activada.
        if ($c && $c['password_hash'] === null) {
            throw new ValidacionException('Tenés que activar tu cuenta desde el correo que te enviamos.');
        }
        // Mensaje genérico (no revelamos si el email existe).
        if ($c === null || !password_verify($password, $c['password_hash'])) {
            throw new ValidacionException('Email o contraseña incorrectos.');
        }
        if ((int) $c['email_verificado'] !== 1) {
            throw new ValidacionException('Tenés que verificar tu correo antes de ingresar.');
        }
        if ((int) $c['activo'] !== 1) {
            throw new ValidacionException('La cuenta está deshabilitada.');
        }

        return [
            'id'              => (int) $c['id'],
            'nombre'          => $c['nombre'],
            'email'           => $c['email'],
            'lista_precio_id' => (int) $c['lista_precio_id'],
        ];
    }

    /** Arma los datos de sesión de un cliente por id. */
    private function sesionDe(int $clienteId): array
    {
        $c = $this->repo->buscarCompleto($clienteId);
        return [
            'id'              => (int) $c['id'],
            'nombre'          => $c['nombre'],
            'email'           => $c['email'],
            'lista_precio_id' => (int) $c['lista_precio_id'],
        ];
    }

    private function enviarActivacion(int $clienteId, string $nombre, string $email): void
    {
        $token  = $this->tokens->crear($clienteId, 'verificacion', 48);
        $config = require dirname(__DIR__, 2) . '/config/config.php';
        $url = $config['app']['url'] . '/tienda-activar.html?token=' . $token;

        $cuerpo = "<h2>Bienvenido/a a Britech</h2>
            <p>Hola {$nombre}, gracias por registrarte. Para activar tu cuenta y
            elegir tu contraseña, hacé clic en el siguiente enlace:</p>
            <p><a href=\"{$url}\">Activar mi cuenta y crear contraseña</a></p>
            <p>Si el botón no funciona, copiá esta dirección: {$url}</p>
            <p>El enlace vence en 48 horas.</p>";

        Mailer::enviar($email, $nombre, 'Activá tu cuenta - Britech Tienda', $cuerpo);
    }
}

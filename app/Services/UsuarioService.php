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
    private const ROLES = [1 => 'admin', 2 => 'vendedor'];

    public function listar(array $f, int $limit, int $offset): array
    {
        return (new UsuarioRepository())->listarAdmin($f, $limit, $offset);
    }

    /**
     * Cambia nombre, rol (permisos) y estado de un usuario. Lo hace un admin.
     * Reglas: nadie se quita a sí mismo el rol admin ni se desactiva, y el sistema
     * nunca queda sin un administrador activo. Cada cambio queda en la auditoría.
     */
    public function guardar(int $id, string $nombre, int $rolId, int $activo, int $actorId): void
    {
        $nombre = trim($nombre);
        if ($nombre === '' || mb_strlen($nombre) > 100) throw new ValidacionException('El nombre es obligatorio (hasta 100 letras).');
        if (!isset(self::ROLES[$rolId])) throw new ValidacionException('Rol no válido.');
        $activo = $activo === 1 ? 1 : 0;

        $repo = new UsuarioRepository();
        $antes = $repo->buscarPorId($id);
        if ($antes === null) throw new ValidacionException('El usuario no existe.');

        $eraAdminActivo = (int) $antes['rol_id'] === 1 && (int) $antes['activo'] === 1;
        $dejaDeSerlo = $eraAdminActivo && ($rolId !== 1 || $activo !== 1);
        if ($dejaDeSerlo && $id === $actorId) {
            throw new ValidacionException('No podés quitarte el rol de administrador ni desactivarte a vos mismo.');
        }
        if ($dejaDeSerlo && $repo->contarAdminsActivos() <= 1) {
            throw new ValidacionException('Tiene que quedar al menos un administrador activo.');
        }

        $repo->actualizarDatos($id, $nombre, $rolId, $activo);

        $leer = fn(array $u) => [
            'nombre' => $u['nombre'],
            'rol'    => self::ROLES[(int) $u['rol_id']] ?? (string) $u['rol_id'],
            'activo' => (int) $u['activo'] === 1 ? 'Activo' : 'Inactivo',
        ];
        Auditoria::cambios('usuario', $id, $leer($antes),
            $leer(['nombre' => $nombre, 'rol_id' => $rolId, 'activo' => $activo]),
            ['nombre' => 'nombre', 'rol' => 'rol', 'activo' => 'estado'], $actorId);
    }

    /** Baja lógica: desactiva el usuario (no se borra: sus ventas lo referencian). */
    public function baja(int $id, int $actorId): void
    {
        $u = (new UsuarioRepository())->buscarPorId($id);
        if ($u === null) throw new ValidacionException('El usuario no existe.');
        $this->guardar($id, $u['nombre'], (int) $u['rol_id'], 0, $actorId);
    }

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
        Auditoria::evento('usuario', $id, 'crear', "Alta: {$nombre} ({$email}) como " . self::ROLES[$rolId]);
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
        Auditoria::evento('usuario', (int) $t['usuario_id'], 'activar',
            'Verificó su correo y activó la cuenta', (int) $t['usuario_id']);
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

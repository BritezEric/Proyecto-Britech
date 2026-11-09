<?php

/**
 * Pruebas del bot de Telegram (avisos al staff). No tocan la red: validan que
 * el servicio sea seguro cuando el bot NO está configurado (no-op, nunca rompe)
 * y que la vinculación por código funcione contra la DB.
 *
 *   1) Sin TG_TOKEN: activo()=false, enviar()/avisar() no rompen y no mandan nada.
 *   2) procesarUpdate() tolera updates raros (sin message) y devuelve el update_id.
 *   3) "/start CODIGO" de un código válido vincula el chat_id del usuario.
 *   4) Un código inexistente no vincula a nadie.
 *
 * Inserta dentro de una transacción y hace ROLLBACK. Requiere schema_telegram.sql.
 *   DB_HOST=127.0.0.1 DB_NAME=britech_v2 php tests/telegram_test.php
 */

// Forzamos el bot "desactivado" para que el test sea determinístico y NO mande
// nada real por red, aunque el .env ya tenga TG_TOKEN configurado.
putenv('TG_TOKEN='); $_ENV['TG_TOKEN'] = '';

require dirname(__DIR__) . '/config/config.php';

use App\Core\Database;
use App\Repositories\UsuarioRepository;
use App\Services\TelegramService;

$fallos = 0; $total = 0;
function check(string $nombre, callable $fn): void {
    global $fallos, $total; $total++;
    try { $fn(); echo "  OK    $nombre\n"; }
    catch (\Throwable $e) { $fallos++; echo "  FALLA $nombre → {$e->getMessage()}\n"; }
}
function verdad($cond, string $que): void { if (!$cond) throw new \Exception($que); }

$pdo  = Database::conexion();
$repo = new UsuarioRepository();
$tg   = new TelegramService();   // sin TG_TOKEN en el entorno de test = desactivado

check('sin token el bot está desactivado y no rompe', function () use ($tg) {
    verdad($tg->activo() === false, 'activo() debería ser false sin TG_TOKEN');
    verdad($tg->enviar('123', 'hola') === false, 'enviar() debería devolver false desactivado');
    $tg->avisar(1, 'prueba');   // no debe lanzar
});

check('procesarUpdate tolera un update sin mensaje', function () use ($tg) {
    $id = $tg->procesarUpdate(['update_id' => 42]);
    verdad($id === 42, 'debería devolver el update_id');
});

$pdo->beginTransaction();
try {
    // Usuario de prueba (rol 2 = vendedor) con un código de vinculación.
    $codigo = 'TEST' . strtoupper(bin2hex(random_bytes(3)));
    $email  = 'tg_test_' . bin2hex(random_bytes(4)) . '@britech.local';
    $pdo->prepare("INSERT INTO usuario (nombre, email, password_hash, rol_id, email_verificado, activo, telegram_codigo)
                   VALUES ('TG Test', ?, '', 2, 1, 1, ?)")->execute([$email, $codigo]);
    $uid = (int) $pdo->lastInsertId();

    check('/start CODIGO válido vincula el chat del usuario', function () use ($tg, $repo, $uid, $codigo) {
        $tg->procesarUpdate([
            'update_id' => 100,
            'message'   => ['chat' => ['id' => 987654321], 'text' => '/start ' . $codigo],
        ]);
        verdad($repo->telegramChatId($uid) === '987654321', 'no guardó el chat_id del código válido');
    });

    check('código pegado solo (sin /start) también vincula', function () use ($tg, $repo, $pdo) {
        $cod2 = strtoupper(bin2hex(random_bytes(4)));   // 8 hex, como genera el panel
        $em2  = 'tg_test2_' . bin2hex(random_bytes(4)) . '@britech.local';
        $pdo->prepare("INSERT INTO usuario (nombre, email, password_hash, rol_id, email_verificado, activo, telegram_codigo)
                       VALUES ('TG Test 2', ?, '', 2, 1, 1, ?)")->execute([$em2, $cod2]);
        $uid2 = (int) $pdo->lastInsertId();
        $tg->procesarUpdate(['update_id' => 200, 'message' => ['chat' => ['id' => 111222333], 'text' => $cod2]]);
        verdad($repo->telegramChatId($uid2) === '111222333', 'el código pegado solo no vinculó');
    });

    check('código inexistente no vincula a nadie', function () use ($tg, $pdo) {
        $antes = (int) $pdo->query("SELECT COUNT(*) FROM usuario WHERE telegram_chat_id = '555000'")->fetchColumn();
        $tg->procesarUpdate([
            'update_id' => 101,
            'message'   => ['chat' => ['id' => 555000], 'text' => '/start NO_EXISTE_XYZ'],
        ]);
        $despues = (int) $pdo->query("SELECT COUNT(*) FROM usuario WHERE telegram_chat_id = '555000'")->fetchColumn();
        verdad($antes === $despues, 'un código inválido no debería vincular ningún usuario');
    });

} finally {
    $pdo->rollBack();
}

echo "\n$total pruebas, $fallos fallas\n";
exit($fallos === 0 ? 0 : 1);

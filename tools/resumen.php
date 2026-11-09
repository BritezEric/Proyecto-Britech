<?php
/**
 * Manda el resumen de caja a los admin (campana + Telegram).
 *   php tools/resumen.php dia      (consolidado del día — correr al cierre, ej. 23:00)
 *   php tools/resumen.php semana   (últimos 7 días — correr domingo a la noche)
 *
 * Programar con el Programador de tareas de Windows o un cron.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\ResumenService;

$periodo = ($argv[1] ?? 'dia') === 'semana' ? 'semana' : 'dia';
$r = (new ResumenService())->enviar($periodo);
echo "Resumen {$periodo} enviado · {$r['ventas']} ventas · total local \$ {$r['total_local']}"
    . ($r['total_online'] > 0 ? " · online \$ {$r['total_online']}" : '') . "\n";

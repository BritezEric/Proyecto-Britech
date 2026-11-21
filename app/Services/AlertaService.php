<?php

namespace App\Services;

use App\Core\Database;
use App\Repositories\AlertaRepository;

/**
 * Detector de actividades que requieren revisión.
 *
 * Lo llama Auditoria cada vez que se guarda un registro (backend, nunca la pantalla):
 * mira ese evento y, si hace falta, cuenta eventos parecidos recientes en la base.
 * Todos los números salen de las tablas reales (auditoria, venta, venta_anulacion).
 *
 * Una alerta NO acusa a nadie: describe un comportamiento para que un administrador
 * lo revise. La "persona relacionada" es a quien afecta o quien hizo la operación.
 *
 * Sin duplicados: cada alerta tiene una clave única (situación + persona + ventana de tiempo).
 * Si se supera un umbral mayor, se crea una alerta nueva de nivel más alto.
 */
class AlertaService
{
    /** Umbrales (ajustables). Ventanas en minutos. */
    public const UMBRALES = [
        'login_fallido'   => ['ventana' => 15, 'advertencia' => 3, 'critica' => 5],
        'acceso_denegado' => ['ventana' => 10, 'advertencia' => 3, 'critica' => 10],
        'precio_masivo'   => ['ventana' => 10, 'advertencia' => 10, 'critica' => 50],
        'anulaciones_dia' => ['advertencia' => 3, 'critica' => 5],
        'ajustes_dia'     => ['advertencia' => 5, 'critica' => 10],
        'ajuste_inusual'  => ['unidades' => 20, 'porcentaje' => 50, 'minimo' => 10],
        'caja_critica'    => 10000,   // $ de diferencia desde la cual la alerta es crítica
    ];

    public const TIPOS = [
        'login_fallido' => 'Intentos fallidos de inicio de sesión', 'bloqueo_ip' => 'IP bloqueada por intentos fallidos',
        'acceso_denegado' => 'Accesos sin autorización', 'precio_masivo' => 'Modificación masiva de precios',
        'anulaciones' => 'Anulaciones repetidas', 'ajustes_stock' => 'Ajustes manuales de stock frecuentes',
        'ajuste_inusual' => 'Ajuste de stock inusual', 'caja_diferencia' => 'Cierre de caja con diferencia',
        'cambio_rol' => 'Cambio de rol', 'cuenta' => 'Alta o desactivación de cuentas',
        'nuevo_acceso' => 'Acceso desde un dispositivo o IP nuevos',
    ];

    public static function analizar(int $auditoriaId, array $e): void
    {
        try {
            (new self())->evaluar($auditoriaId, $e);
        } catch (\Throwable $ex) {
            error_log('[Alertas] ' . $ex->getMessage());   // una alerta nunca frena la operación
        }
    }

    private AlertaRepository $repo;

    public function __construct() { $this->repo = new AlertaRepository(); }

    private function evaluar(int $aid, array $e): void
    {
        $k = $e['entidad'] . '/' . $e['accion'];
        match (true) {
            $k === 'sesion/login_fallido'   => $this->loginFallido($aid, $e),
            $k === 'sesion/bloqueo_ip'      => $this->bloqueoIp($aid, $e),
            $k === 'sesion/acceso_denegado' => $this->accesoDenegado($aid, $e),
            $k === 'sesion/login'           => $this->nuevoAcceso($aid, $e),
            $k === 'producto/precio_masivo' => $this->precioMasivo($aid, $e),
            $k === 'venta/anular'           => $this->anulaciones($aid, $e),
            $k === 'stock/ajuste'           => $this->ajusteStock($aid, $e),
            $k === 'caja/cerrar'            => $this->cajaDiferencia($aid, $e),
            $e['entidad'] === 'usuario'     => $this->usuario($aid, $e),
            default                         => null,
        };
    }

    // ---------- Reglas ----------

    private function loginFallido(int $aid, array $e): void
    {
        $u = self::UMBRALES['login_fallido'];
        $desde = self::haceMinutos($u['ventana']);
        $porIp = $e['ip'] ? $this->repo->contarAuditoria(
            "entidad = 'sesion' AND accion = 'login_fallido' AND ip = ? AND creado_en >= ?", [$e['ip'], $desde]) : 0;
        $porCuenta = $e['entidad_id'] ? $this->repo->contarAuditoria(
            "entidad = 'sesion' AND accion = 'login_fallido' AND entidad_id = ? AND creado_en >= ?", [$e['entidad_id'], $desde]) : 0;
        $n = max($porIp, $porCuenta);
        $nivel = $this->nivel($n, $u);
        if (!$nivel) return;
        $sujeto = $porCuenta >= $porIp && $e['entidad_id'] ? 'cuenta ' . $e['entidad_id'] : 'IP ' . ($e['ip'] ?? '?');
        $this->alerta($aid, 'login_fallido', $nivel, $e['entidad_id'],
            "$n intentos fallidos de inicio de sesión en {$u['ventana']} min",
            "Se registraron $n intentos fallidos" . ($porCuenta >= $porIp && $e['entidad_id'] ? ' sobre la misma cuenta' : ' desde la misma IP')
            . ' (' . ($e['ip'] ?? 'IP desconocida') . ") en los últimos {$u['ventana']} minutos. Último intento: {$e['detalle']}.",
            'sesion', $e['entidad_id'], "login_fallido|$sujeto|$nivel|" . self::ahora('YmdH'));
    }

    private function bloqueoIp(int $aid, array $e): void
    {
        $this->alerta($aid, 'bloqueo_ip', 'critica', null, 'IP bloqueada temporalmente por intentos fallidos',
            'El sistema bloqueó temporalmente los inicios de sesión desde ' . ($e['ip'] ?? 'una IP desconocida')
            . ' por superar el máximo de intentos fallidos.', 'sesion', null, 'bloqueo_ip|' . ($e['ip'] ?? '?') . '|' . self::ahora('YmdH'));
    }

    private function accesoDenegado(int $aid, array $e): void
    {
        if (!$e['usuario_id']) return;
        $u = self::UMBRALES['acceso_denegado'];
        $n = $this->repo->contarAuditoria(
            "entidad = 'sesion' AND accion = 'acceso_denegado' AND usuario_id = ? AND creado_en >= ?",
            [$e['usuario_id'], self::haceMinutos($u['ventana'])]);
        $nivel = $this->nivel($n, $u);
        if (!$nivel) return;
        $this->alerta($aid, 'acceso_denegado', $nivel, $e['usuario_id'],
            "$n intentos de acceder a funciones sin autorización",
            "El servidor rechazó $n pedidos de esta cuenta por falta de permisos en {$u['ventana']} minutos. Último: {$e['detalle']}.",
            'usuario', $e['usuario_id'], "acceso_denegado|{$e['usuario_id']}|$nivel|" . self::ahora('YmdH'));
    }

    /** Login correcto desde una IP o un navegador que esa cuenta nunca había usado. */
    private function nuevoAcceso(int $aid, array $e): void
    {
        $uid = $e['entidad_id'] ?? $e['usuario_id'];
        if (!$uid) return;
        $previos = $this->repo->contarAuditoria(
            "entidad = 'sesion' AND accion = 'login' AND entidad_id = ? AND id < ?", [$uid, $aid]);
        if ($previos === 0) return;   // primer ingreso de la cuenta: no hay con qué comparar

        $nuevo = [];
        if ($e['ip'] && $this->repo->contarAuditoria(
                "entidad = 'sesion' AND accion = 'login' AND entidad_id = ? AND id < ? AND ip = ?", [$uid, $aid, $e['ip']]) === 0) {
            $nuevo[] = 'una IP nueva (' . $e['ip'] . ')';
        }
        // Dispositivo: solo se compara si ya hay ingresos con navegador registrado.
        if ($e['agente'] && $this->repo->contarAuditoria(
                "entidad = 'sesion' AND accion = 'login' AND entidad_id = ? AND id < ? AND agente IS NOT NULL", [$uid, $aid]) > 0
            && $this->repo->contarAuditoria(
                "entidad = 'sesion' AND accion = 'login' AND entidad_id = ? AND id < ? AND agente = ?", [$uid, $aid, $e['agente']]) === 0) {
            $nuevo[] = 'un navegador o dispositivo nuevo (' . self::dispositivo($e['agente']) . ')';
        }
        if (!$nuevo) return;
        $this->alerta($aid, 'nuevo_acceso', 'informativa', $uid, 'Inicio de sesión desde ' . implode(' y ', $nuevo),
            'La cuenta inició sesión desde ' . implode(' y ', $nuevo) . ' que no figuraban en sus ingresos anteriores.'
            . ' No hay datos de ubicación geográfica: solo IP y navegador.', 'usuario', $uid, "nuevo_acceso|$aid");
    }

    private function precioMasivo(int $aid, array $e): void
    {
        if (!$e['usuario_id']) return;
        $u = self::UMBRALES['precio_masivo'];
        $n = $this->repo->contarAuditoria(
            "entidad = 'producto' AND accion = 'precio_masivo' AND usuario_id = ? AND creado_en >= ?",
            [$e['usuario_id'], self::haceMinutos($u['ventana'])]);
        $nivel = $this->nivel($n, $u);
        if (!$nivel) return;
        $this->alerta($aid, 'precio_masivo', $nivel, $e['usuario_id'], "Modificación masiva de precios: $n cambios",
            "Se registraron $n cambios de precio por actualización masiva en {$u['ventana']} minutos. Revisá que el ajuste haya sido el previsto.",
            'producto', $e['entidad_id'], "precio_masivo|{$e['usuario_id']}|$nivel|" . self::ahora('YmdH'));
    }

    /** Anulaciones del día de ventas de un mismo vendedor (sale de venta + venta_anulacion). */
    private function anulaciones(int $aid, array $e): void
    {
        $pdo = Database::conexion();
        $st = $pdo->prepare("SELECT usuario_id, numero FROM venta WHERE id = ?");
        $st->execute([$e['entidad_id']]);
        $v = $st->fetch();
        if (!$v) return;
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM venta_anulacion va JOIN venta v ON v.id = va.venta_id
             WHERE v.usuario_id = ? AND va.creado_en >= CURDATE()");
        $st->execute([$v['usuario_id']]);
        $n = (int) $st->fetchColumn();
        $nivel = $this->nivel($n, self::UMBRALES['anulaciones_dia']);
        if (!$nivel) return;
        $this->alerta($aid, 'anulaciones', $nivel, (int) $v['usuario_id'], "$n ventas anuladas hoy del mismo vendedor",
            "Hoy se anularon $n ventas registradas por este vendedor (última: {$v['numero']}). Conviene revisar los motivos.",
            'venta', $e['entidad_id'], "anulaciones|{$v['usuario_id']}|$nivel|" . self::ahora('Ymd'));
    }

    private function ajusteStock(int $aid, array $e): void
    {
        if (str_starts_with((string) $e['detalle'], 'Stock inicial')) return;   // alta de producto, no es un ajuste
        $antes = (int) $e['anterior']; $despues = (int) $e['nuevo']; $dif = $despues - $antes;

        // Ajuste inusual: muchas unidades de golpe, o baja de más de la mitad del stock.
        $u = self::UMBRALES['ajuste_inusual'];
        if (abs($dif) >= $u['unidades'] || ($dif < 0 && $antes >= $u['minimo'] && -$dif * 100 >= $antes * $u['porcentaje'])) {
            $this->alerta($aid, 'ajuste_inusual', 'advertencia', $e['usuario_id'],
                'Ajuste manual de stock inusual (' . ($dif > 0 ? '+' : '') . "$dif u.)",
                "El stock del producto #{$e['entidad_id']} pasó de $antes a $despues por un ajuste manual, sin una venta ni una compra asociada.",
                'producto', $e['entidad_id'], "ajuste_inusual|$aid");
        }

        if (!$e['usuario_id']) return;
        $n = $this->repo->contarAuditoria(
            "entidad = 'stock' AND accion = 'ajuste' AND usuario_id = ? AND creado_en >= CURDATE()
             AND (detalle IS NULL OR detalle NOT LIKE 'Stock inicial%')", [$e['usuario_id']]);
        $nivel = $this->nivel($n, self::UMBRALES['ajustes_dia']);
        if (!$nivel) return;
        $this->alerta($aid, 'ajustes_stock', $nivel, $e['usuario_id'], "$n ajustes manuales de stock hoy",
            "Esta cuenta hizo $n ajustes manuales de stock hoy. Los ajustes no tienen una venta ni una compra que los respalde.",
            'producto', $e['entidad_id'], "ajustes_stock|{$e['usuario_id']}|$nivel|" . self::ahora('Ymd'));
    }

    /** Cierre de caja: anterior = esperado, nuevo = declarado (lo registra CajaService). */
    private function cajaDiferencia(int $aid, array $e): void
    {
        $esperado = (float) $e['anterior']; $declarado = (float) $e['nuevo'];
        $faltante = round($esperado - $declarado, 2);   // Esperado − Declarado
        if (abs($faltante) < 0.005) return;
        $tipo = $faltante > 0 ? 'faltante' : 'sobrante';
        $monto = number_format(abs($faltante), 2, ',', '.');
        $this->alerta($aid, 'caja_diferencia', abs($faltante) >= self::UMBRALES['caja_critica'] ? 'critica' : 'advertencia',
            $e['usuario_id'], "Caja #{$e['entidad_id']} cerrada con $tipo de \$ $monto",
            'Esperado $ ' . number_format($esperado, 2, ',', '.') . ' · Declarado $ ' . number_format($declarado, 2, ',', '.')
            . " · Diferencia (esperado − declarado) \$ " . number_format($faltante, 2, ',', '.') . '.',
            'caja', $e['entidad_id'], "caja_diferencia|{$e['entidad_id']}");
    }

    /** Cambios sobre cuentas de usuario: rol, desactivación/reactivación y alta de administradores. */
    private function usuario(int $aid, array $e): void
    {
        $uid = $e['entidad_id'];
        if ($e['accion'] === 'editar' && $e['campo'] === 'rol') {
            $admin = $e['nuevo'] === 'admin';
            $this->alerta($aid, 'cambio_rol', $admin ? 'critica' : 'advertencia', $uid,
                $admin ? 'Se otorgó el rol de administrador' : 'Cambio de rol de usuario',
                "El rol de la cuenta #$uid cambió de \"{$e['anterior']}\" a \"{$e['nuevo']}\". Revisá que el cambio esté autorizado.",
                'usuario', $uid, "cambio_rol|$aid");
        } elseif ($e['accion'] === 'editar' && $e['campo'] === 'estado') {
            $baja = $e['nuevo'] === 'Inactivo';
            $this->alerta($aid, 'cuenta', $baja ? 'advertencia' : 'informativa', $uid,
                $baja ? 'Cuenta de usuario desactivada' : 'Cuenta de usuario reactivada',
                "La cuenta #$uid pasó de \"{$e['anterior']}\" a \"{$e['nuevo']}\".", 'usuario', $uid, "cuenta|$aid");
        } elseif ($e['accion'] === 'crear' && str_ends_with((string) $e['detalle'], 'como admin')) {
            $this->alerta($aid, 'cuenta', 'advertencia', $uid, 'Alta de un usuario administrador',
                "Se creó una cuenta con rol de administrador. {$e['detalle']}.", 'usuario', $uid, "cuenta|$aid");
        }
    }

    // ---------- helpers ----------

    /** Fecha y hora de hace N minutos según MySQL (misma zona horaria que los registros). */
    private static function haceMinutos(int $min): string
    {
        $st = Database::conexion()->prepare('SELECT DATE_SUB(NOW(), INTERVAL ? MINUTE)');
        $st->execute([$min]);
        return (string) $st->fetchColumn();
    }

    /** Hora actual de MySQL con formato de PHP (para las ventanas de las claves). */
    private static function ahora(string $formato): string
    {
        return date($formato, strtotime((string) Database::conexion()->query('SELECT NOW()')->fetchColumn()));
    }

    private function nivel(int $n, array $u): ?string
    {
        if ($n >= $u['critica']) return 'critica';
        if ($n >= $u['advertencia']) return 'advertencia';
        return null;
    }

    private function alerta(int $aid, string $tipo, string $nivel, ?int $usuarioId, string $titulo, string $desc,
                            ?string $entidad, ?int $entidadId, string $clave): void
    {
        $this->repo->crear([
            'auditoria_id' => $aid, 'tipo' => $tipo, 'nivel' => $nivel, 'usuario_id' => $usuarioId ?: null,
            'titulo' => $titulo, 'descripcion' => $desc, 'entidad' => $entidad, 'entidad_id' => $entidadId, 'clave' => $clave,
        ]);
    }

    /** "Chrome · Windows" a partir del User-Agent (solo para mostrar). */
    public static function dispositivo(?string $ua): string
    {
        if (!$ua) return '—';
        $nav = match (true) {
            str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox', str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari', str_contains($ua, 'curl/') => 'curl', default => 'Otro navegador',
        };
        $so = match (true) {
            str_contains($ua, 'Windows') => 'Windows', str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS', str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux', default => '',
        };
        return $so ? "$nav · $so" : $nav;
    }
}

<?php

namespace App\Services;

use App\Core\Database;
use App\Core\ValidacionException;
use App\Repositories\CajaRepository;
use App\Repositories\GastoRepository;

/**
 * CajaService — lógica de la caja del vendedor (apertura, movimientos, cierre).
 *
 * Efectivo esperado (arqueo físico) =
 *     apertura + ventas en efectivo + ingresos − retiros − gastos
 *
 * Al cerrar, el vendedor cuenta el efectivo (billete por billete o el total) y
 * verifica el resto de las formas de pago (transferencias, etc.). Se guarda una
 * foto por forma de pago (esperado / declarado / diferencia) para el reporte.
 */
class CajaService
{
    /** Billetes y monedas en circulación (ARS) para el conteo del arqueo. */
    public const DENOMINACIONES = [20000, 10000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];

    private CajaRepository $repo;

    public function __construct()
    {
        $this->repo = new CajaRepository();
    }

    /** Caja abierta del usuario + resumen + movimientos del turno + efectivo esperado. */
    public function estado(int $usuarioId): array
    {
        $caja = $this->repo->abiertaDe($usuarioId);
        if ($caja === null) {
            return ['abierta' => false, 'denominaciones' => self::DENOMINACIONES,
                    'ultimo_cierre' => $this->repo->ultimoCierreDe($usuarioId)];
        }

        return ['abierta' => true, 'denominaciones' => self::DENOMINACIONES] + $this->detalle($caja);
    }

    /** Todo lo de una caja: resumen, esperado, línea de tiempo y (si cerró) su arqueo por forma. */
    public function detalle(array $caja): array
    {
        $id       = (int) $caja['id'];
        $resumen  = $this->repo->resumen($id);
        $esperado = $this->esperado((float) $caja['monto_apertura'], $resumen);
        $ventas   = $this->repo->ventasDe($id);
        $movs     = $this->repo->movimientosDe($id);

        $cierre = $caja['estado'] === 'cerrada' ? $this->repo->cierrePorForma($id) : [];
        if ($caja['estado'] === 'cerrada' && !$cierre) {
            // Cajas cerradas antes de la v2 (sin foto): armamos una con lo que hay.
            $cierre = [[
                'tipo_pago_id' => null, 'nombre' => 'Efectivo', 'operaciones' => null,
                'esperado' => $caja['monto_esperado'], 'declarado' => $caja['monto_contado'], 'diferencia' => $caja['diferencia'],
            ]];
        }
        if (isset($caja['conteo_efectivo']) && is_string($caja['conteo_efectivo'])) {
            $caja['conteo_efectivo'] = json_decode($caja['conteo_efectivo'], true);
        }

        return [
            'caja'        => $caja,
            'resumen'     => $resumen,
            'esperado'    => $esperado,
            'movimientos' => $movs,
            'timeline'    => $this->timeline($caja, $ventas, $movs),
            'cierre'      => $cierre,
        ];
    }

    /** Detalle por id, validando que el usuario pueda verla (admin, o el dueño). */
    public function detallePara(int $cajaId, int $usuarioId, bool $esAdmin): array
    {
        $caja = $this->repo->buscarPorId($cajaId);
        if ($caja === null) throw new ValidacionException('La caja no existe.');
        if (!$esAdmin && (int) $caja['usuario_id'] !== $usuarioId) {
            throw new ValidacionException('No podés ver una caja de otro vendedor.');
        }
        return $this->detalle($caja);
    }

    public function abrir(int $usuarioId, float $monto): array
    {
        if ($this->repo->abiertaDe($usuarioId) !== null) {
            throw new ValidacionException('Ya tenés una caja abierta.');
        }
        if ($monto < 0) throw new ValidacionException('El monto de apertura no puede ser negativo.');

        $id = $this->repo->abrir($usuarioId, round($monto, 2));
        Auditoria::campo('caja', $id, 'monto_apertura', null, round($monto, 2), 'abrir', $usuarioId, 'Apertura de caja');
        return ['caja_id' => $id];
    }

    /**
     * Retiro / ingreso / gasto de efectivo. Un retiro o gasto no puede superar el
     * efectivo que hay en la caja. El gasto además queda registrado en Gastos.
     */
    public function movimiento(int $usuarioId, string $tipo, float $monto, ?string $motivo): array
    {
        $caja = $this->repo->abiertaDe($usuarioId);
        if ($caja === null) throw new ValidacionException('No tenés una caja abierta.');
        if (!in_array($tipo, ['retiro', 'ingreso', 'gasto'], true)) throw new ValidacionException('Tipo de movimiento inválido.');
        if ($monto <= 0) throw new ValidacionException('El monto tiene que ser mayor a 0.');
        $monto  = round($monto, 2);
        $motivo = trim((string) $motivo);
        if ($motivo === '' && $tipo !== 'ingreso') {
            throw new ValidacionException($tipo === 'gasto' ? 'Indicá en qué se gastó.' : 'Indicá el motivo del retiro.');
        }
        if (mb_strlen($motivo) > 200) throw new ValidacionException('El motivo es muy largo (máx. 200).');

        $cajaId = (int) $caja['id'];
        if ($tipo !== 'ingreso') {
            $disponible = $this->esperado((float) $caja['monto_apertura'], $this->repo->resumen($cajaId));
            if ($monto > $disponible + 0.001) {
                throw new ValidacionException('No hay tanto efectivo en la caja (disponible: $ '
                    . number_format($disponible, 2, ',', '.') . ').');
            }
        }

        $pdo = Database::conexion();
        $pdo->beginTransaction();
        try {
            $gastoId = null;
            if ($tipo === 'gasto') {
                $gastoId = (new GastoRepository())->crear([
                    'fecha' => date('Y-m-d'), 'proveedor_id' => null, 'producto_id' => null,
                    'cantidad' => null, 'concepto' => $motivo, 'monto' => $monto,
                    'observacion' => "Pagado con efectivo de la caja #{$cajaId}", 'activo' => 1,
                ]);
            }
            $movId = $this->repo->agregarMovimiento($cajaId, $tipo, $monto, $motivo ?: null, $usuarioId, $gastoId);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $etiq = ['retiro' => 'Retiro de efectivo', 'ingreso' => 'Ingreso de efectivo', 'gasto' => 'Gasto pagado con la caja'][$tipo];
        Auditoria::campo('caja', $cajaId, $tipo, null, $monto, $tipo, $usuarioId, $etiq . ($motivo !== '' ? ': ' . $motivo : ''));
        return ['ok' => true, 'movimiento_id' => $movId];
    }

    /**
     * Arqueo y cierre.
     * $in = {
     *   billetes?:   { "20000": 3, "1000": 5, ... }   // conteo por denominación (opcional)
     *   contado?:    float                             // total de efectivo si no se cuentan billetes
     *   declarados?: { "<tipo_pago_id>": monto }       // verificación del resto de las formas
     *   observacion?: string                           // obligatoria si algo no cuadra
     * }
     */
    public function cerrar(int $usuarioId, array $in): array
    {
        $caja = $this->repo->abiertaDe($usuarioId);
        if ($caja === null) throw new ValidacionException('No tenés una caja abierta.');
        return $this->cerrarCaja($caja, $usuarioId, $in);
    }

    /**
     * Cierre administrativo: un admin cierra la caja abierta de OTRO vendedor
     * (ej: se olvidó de cerrarla). Mismo arqueo que el vendedor, pero la
     * observación es siempre obligatoria y queda registrado quién la cerró.
     */
    public function cerrarComoAdmin(int $cajaId, int $adminId, string $adminNombre, array $in): array
    {
        $caja = $this->repo->buscarPorId($cajaId);
        if ($caja === null) throw new ValidacionException('La caja no existe.');
        if ($caja['estado'] !== 'abierta') throw new ValidacionException('Esa caja ya está cerrada.');
        if (trim((string) ($in['observacion'] ?? '')) === '') {
            throw new ValidacionException('Contá por qué cerrás la caja de otra persona (ej: se olvidó de cerrarla).');
        }
        return $this->cerrarCaja($caja, $adminId, $in, $adminNombre);
    }

    /** Arqueo + cierre de una caja abierta. $adminNombre != null = cierre administrativo. */
    private function cerrarCaja(array $caja, int $usuarioId, array $in, ?string $adminNombre = null): array
    {
        $cajaId = (int) $caja['id'];
        $vendedorId = (int) $caja['usuario_id'];
        $forzado = $adminNombre !== null && $vendedorId !== $usuarioId;

        // 1) Efectivo contado: por billetes (se recalcula acá, no se confía en el total del front) o total directo.
        $conteo = null;
        if (!empty($in['billetes']) && is_array($in['billetes'])) {
            $conteo = [];
            $contado = 0.0;
            foreach ($in['billetes'] as $den => $cant) {
                $den = (int) $den; $cant = (int) $cant;
                if (!in_array($den, self::DENOMINACIONES, true)) throw new ValidacionException("Denominación inválida: {$den}.");
                if ($cant < 0) throw new ValidacionException('Las cantidades de billetes no pueden ser negativas.');
                if ($cant === 0) continue;
                $conteo[(string) $den] = $cant;
                $contado += $den * $cant;
            }
            if (isset($in['monedas_sueltas']) && (float) $in['monedas_sueltas'] > 0) {   // centavos / sueltos
                $conteo['sueltos'] = round((float) $in['monedas_sueltas'], 2);
                $contado += $conteo['sueltos'];
            }
        } else {
            if (!isset($in['contado']) || $in['contado'] === '' || !is_numeric($in['contado'])) {
                throw new ValidacionException('Ingresá el efectivo contado.');
            }
            $contado = (float) $in['contado'];
        }
        if ($contado < 0) throw new ValidacionException('El efectivo contado no puede ser negativo.');
        $contado = round($contado, 2);

        // 2) Esperado vs. declarado por cada forma de pago.
        $resumen  = $this->repo->resumen($cajaId);
        $esperado = $this->esperado((float) $caja['monto_apertura'], $resumen);
        $declarados = is_array($in['declarados'] ?? null) ? $in['declarados'] : [];

        $formas = [];
        $hayDiferencia = false;
        foreach ($resumen['formas'] as $f) {
            if ($f['es_efectivo']) {
                $esp = $esperado; $dec = $contado;   // en efectivo se arquea la caja física entera
            } else {
                $esp = $f['total'];
                $raw = $declarados[(string) $f['tipo_pago_id']] ?? null;
                $dec = ($raw === null || $raw === '') ? $esp : (float) $raw;   // sin dato = se da por verificado
                if ($dec < 0) throw new ValidacionException("El monto declarado de {$f['nombre']} no puede ser negativo.");
                if ($esp == 0.0 && $dec == 0.0 && $f['operaciones'] === 0) continue;   // forma sin uso
            }
            $dif = round($dec - $esp, 2);
            if (abs($dif) >= 0.01) $hayDiferencia = true;
            $formas[] = [
                'tipo_pago_id' => $f['tipo_pago_id'], 'nombre' => $f['nombre'], 'operaciones' => $f['operaciones'],
                'esperado' => round($esp, 2), 'declarado' => round($dec, 2), 'diferencia' => $dif,
            ];
        }
        // Siempre hay fila de efectivo, aunque la forma esté desactivada.
        if (!array_filter($formas, fn($f) => mb_strtolower($f['nombre']) === 'efectivo')) {
            throw new ValidacionException('No existe la forma de pago "Efectivo" (revisá Tipos de pago).');
        }

        $observacion = trim((string) ($in['observacion'] ?? ''));
        if ($hayDiferencia && $observacion === '') {
            throw new ValidacionException('La caja no cuadra: dejá una observación explicando la diferencia.');
        }
        if (mb_strlen($observacion) > 250) throw new ValidacionException('La observación es muy larga (máx. 250).');

        $diferencia = round($contado - $esperado, 2);

        $pdo = Database::conexion();
        $pdo->beginTransaction();
        try {
            // Si otro (el vendedor o un admin) la cerró justo antes, no se pisa: se cancela todo.
            if (!$this->repo->cerrar($cajaId, $contado, $conteo, $esperado, $diferencia, $observacion ?: null, $formas, $usuarioId)) {
                throw new ValidacionException('Esa caja ya fue cerrada por otra persona. Recargá para ver el cierre.');
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Esperado → contado: la diferencia del arqueo queda a la vista en la auditoría (una fila por forma).
        $accion = $forzado ? 'cerrar_admin' : 'cerrar';
        $prefijo = $forzado ? "Cierre administrativo (caja de {$caja['vendedor']})" : 'Cierre de caja';
        foreach ($formas as $f) {
            Auditoria::campo('caja', $cajaId, mb_strtolower($f['nombre']), $f['esperado'], $f['declarado'], $accion, $usuarioId,
                "{$prefijo} · {$f['nombre']} · diferencia $ " . number_format($f['diferencia'], 2, ',', '.'));
        }

        // Aviso a los admin: se cerró una caja (alerta si algo no cuadra).
        $notif = new \App\Repositories\NotificacionRepository();
        $difTotal = round(array_sum(array_column($formas, 'diferencia')), 2);
        $dif = number_format($difTotal, 2, ',', '.');
        $quien = $forzado ? " (la cerró {$adminNombre})" : '';
        $notif->crearRol(
            1, 'caja_cierre',
            self::corto(!$hayDiferencia ? "Caja #{$cajaId} de {$caja['vendedor']} cerrada y cuadrada ✓{$quien}"
                                        : "Caja #{$cajaId} de {$caja['vendedor']} cerrada · diferencia \$ {$dif}{$quien}"),
            'cajas', $cajaId, !$hayDiferencia ? 'exito' : 'alerta'
        );

        // Aviso al vendedor dueño de la caja, con el detalle del cierre.
        $m = fn($n) => number_format((float) $n, 2, ',', '.');
        $porForma = implode(' · ', array_map(fn($f) => "{$f['nombre']} \$ {$m($f['declarado'])}", $formas));
        $detalle = ($forzado ? "{$adminNombre} cerró tu caja #{$cajaId} · motivo: {$observacion} · " : 'Cierre de tu caja · ')
            . "{$resumen['ventas']} ventas · {$porForma} · "
            . "Efectivo esperado \$ {$m($esperado)} · Contado \$ {$m($contado)} · Diferencia \$ {$dif}";
        $notif->crearUsuario(
            $vendedorId, 'caja_cierre_detalle', self::corto($detalle),
            'cajas', $cajaId, !$hayDiferencia && !$forzado ? 'exito' : 'alerta'
        );

        return [
            'caja_id'    => $cajaId,
            'esperado'   => $esperado,
            'contado'    => $contado,
            'diferencia' => $diferencia,
            'formas'     => $formas,
            'resumen'    => $resumen,
        ];
    }

    /**
     * Cierre del día (Z): consolida todas las cajas abiertas en la fecha.
     * Para las cerradas usa la foto del cierre; para las abiertas, lo vivo.
     */
    public function cierreDelDia(string $fecha): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
            throw new ValidacionException('Fecha inválida.');
        }
        $cajas = $this->repo->cajasDelDia($fecha);
        $ids = array_map(fn($c) => (int) $c['id'], $cajas);
        $resumen = $this->repo->resumenDe($ids);

        $filas = [];
        $apertura = 0.0; $esperadoEf = 0.0; $contadoEf = 0.0; $difEf = 0.0; $abiertas = 0;
        foreach ($cajas as $c) {
            $r = $this->repo->resumen((int) $c['id']);
            $esp = $c['estado'] === 'cerrada' && $c['monto_esperado'] !== null
                ? (float) $c['monto_esperado'] : $this->esperado((float) $c['monto_apertura'], $r);
            $apertura += (float) $c['monto_apertura'];
            $esperadoEf += $esp;
            if ($c['estado'] === 'cerrada') { $contadoEf += (float) $c['monto_contado']; $difEf += (float) $c['diferencia']; }
            else $abiertas++;
            $filas[] = [
                'id' => (int) $c['id'], 'vendedor' => $c['vendedor'], 'estado' => $c['estado'],
                'abierta_en' => $c['abierta_en'], 'cerrada_en' => $c['cerrada_en'],
                'apertura' => (float) $c['monto_apertura'], 'ventas' => $r['ventas'], 'total_ventas' => $r['total_ventas'],
                'esperado' => round($esp, 2),
                'contado' => $c['monto_contado'] !== null ? (float) $c['monto_contado'] : null,
                'diferencia' => $c['diferencia'] !== null ? (float) $c['diferencia'] : null,
                // Diferencia de TODAS las formas (efectivo + transferencias…); en cajas viejas, solo efectivo.
                'diferencia_total' => $c['estado'] !== 'cerrada' ? null
                    : (($snap = $this->repo->cierrePorForma((int) $c['id']))
                        ? round(array_sum(array_column($snap, 'diferencia')), 2) : (float) $c['diferencia']),
            ];
        }

        return [
            'fecha'    => $fecha,
            'cajas'    => $filas,
            'resumen'  => $resumen,
            'efectivo' => [
                'apertura'   => round($apertura, 2),
                'esperado'   => round($esperadoEf, 2),
                'contado'    => round($contadoEf, 2),
                'diferencia' => round($difEf, 2),
            ],
            'abiertas' => $abiertas,
        ];
    }

    /** El título de una notificación admite hasta 200 caracteres (notificacion.titulo). */
    private static function corto(string $s): string
    {
        return mb_strlen($s) > 200 ? mb_substr($s, 0, 199) . '…' : $s;
    }

    /** Efectivo esperado en la caja física. */
    public function esperado(float $apertura, array $resumen): float
    {
        return round($apertura + $resumen['efectivo'] + $resumen['ingresos'] - $resumen['retiros'] - $resumen['gastos'], 2);
    }

    /**
     * Línea de tiempo del turno: apertura, ventas, anulaciones, retiros/ingresos/gastos
     * y cierre, en orden, con el efecto en el efectivo y el saldo acumulado.
     */
    private function timeline(array $caja, array $ventas, array $movs): array
    {
        $ev = [];
        $ev[] = ['fecha' => $caja['abierta_en'], 'orden' => 0, 'tipo' => 'apertura',
                 'detalle' => 'Apertura de caja', 'monto' => (float) $caja['monto_apertura'],
                 'efectivo' => (float) $caja['monto_apertura'], 'ref' => null, 'formas' => []];

        foreach ($ventas as $v) {
            $ef = 0.0;
            foreach ($v['pagos'] as $p) if (mb_strtolower($p['nombre']) === 'efectivo') $ef += (float) $p['monto'];
            $formas = array_map(fn($p) => ['nombre' => $p['nombre'], 'monto' => (float) $p['monto']], $v['pagos']);
            $ev[] = ['fecha' => $v['creado_en'], 'orden' => 1, 'tipo' => 'venta',
                     'detalle' => 'Venta ' . $v['numero'] . ($v['cliente'] !== '' ? ' · ' . $v['cliente'] : ''),
                     'monto' => (float) $v['total'], 'efectivo' => round($ef, 2), 'ref' => (int) $v['id'],
                     'formas' => $formas, 'anulada' => $v['estado'] === 'anulada'];
            if ($v['estado'] === 'anulada') {
                $ev[] = ['fecha' => $v['anulada_en'] ?? $v['creado_en'], 'orden' => 2, 'tipo' => 'anulacion',
                         'detalle' => 'Anulación ' . $v['numero'] . ($v['motivo_anulacion'] ? ' · ' . $v['motivo_anulacion'] : ''),
                         'monto' => -(float) $v['total'], 'efectivo' => -round($ef, 2), 'ref' => (int) $v['id'], 'formas' => $formas];
            }
        }

        $etiq = ['retiro' => 'Retiro', 'ingreso' => 'Ingreso', 'gasto' => 'Gasto'];
        foreach ($movs as $m) {
            $signo = $m['tipo'] === 'ingreso' ? 1 : -1;
            $ev[] = ['fecha' => $m['creado_en'], 'orden' => 1, 'tipo' => $m['tipo'],
                     'detalle' => $etiq[$m['tipo']] . ($m['motivo'] ? ' · ' . $m['motivo'] : ''),
                     'monto' => $signo * (float) $m['monto'], 'efectivo' => $signo * (float) $m['monto'],
                     'ref' => (int) $m['id'], 'formas' => []];
        }

        if ($caja['estado'] === 'cerrada') {
            $ev[] = ['fecha' => $caja['cerrada_en'], 'orden' => 9, 'tipo' => 'cierre',
                     'detalle' => 'Cierre de caja (contado)', 'monto' => (float) $caja['monto_contado'],
                     'efectivo' => 0.0, 'ref' => null, 'formas' => []];
        }

        // Orden cronológico; el cierre siempre al final (aunque coincida el segundo).
        usort($ev, fn($a, $b) => [$a['tipo'] === 'cierre', $a['fecha'], $a['orden']]
                             <=> [$b['tipo'] === 'cierre', $b['fecha'], $b['orden']]);

        $saldo = 0.0;
        foreach ($ev as &$e) {
            if ($e['tipo'] !== 'cierre') $saldo = round($saldo + $e['efectivo'], 2);
            $e['saldo'] = $saldo;
            unset($e['orden']);
        }
        return $ev;
    }
}

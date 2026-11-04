<?php

namespace App\Services;

use App\Core\ValidacionException;
use App\Repositories\ReporteOperativoRepository;

/**
 * Reportes y Control Operativo (solo admin): medios de pago, cajas y anulaciones,
 * vendedores, embudo de la tienda online, reclamos, envíos sin asignar y carga
 * por barrio.
 *
 * Todos los reportes devuelven la MISMA estructura, que usan igual la pantalla,
 * el CSV y el PDF:
 *   kpis    → [{k, label, valor, tipo, nota, tono}]
 *   grafico → {tipo, titulo, labels, series:[{label, data}], formato}
 *   tablas  → [{id, titulo, columnas:[{k, t, tipo}], filas, total, recortada}]
 *   notas   → aclaraciones de cálculo (qué se cuenta y qué dato no existe)
 * Los filtros se validan acá (listas cerradas, fechas estrictas, enteros con
 * rango): nunca se confía en lo que manda el navegador.
 */
class ReporteOperativoService
{
    public const REPORTES = [
        'pagos'      => 'Ventas por medio de pago',
        'cajas'      => 'Anulaciones y arqueo de cajas',
        'vendedores' => 'Rendimiento de vendedores',
        'pedidos'    => 'Embudo de la tienda online',
        'reclamos'   => 'Reclamos',
        'envios'     => 'Envíos sin asignar',
        'carga'      => 'Carga de envíos por barrio',
    ];

    /** Máximo de filas de detalle que viajan a la pantalla (los totales usan todas). */
    public const MAX_FILAS = 3000;
    private const MAX_DIAS = 366;

    private const ETAPAS = [
        'pendiente_pago' => ['Pendiente de pago',   'warn',    'Confirmar el pago'],
        'por_preparar'   => ['Pagado, a preparar',  'info',    'Preparar el pedido'],
        'en_preparacion' => ['En preparación',      'pend',    'Despachar o avisar retiro'],
        'listo_retirar'  => ['Listo para retirar',  'info',    'Entregar al cliente'],
        'enviado'        => ['Enviado',             'pend',    'Seguir el envío'],
        'entregado'      => ['Entregado',           'ok',      ''],
        'cancelado'      => ['Cancelado',           'off',     ''],
    ];
    private const ESTADO_PAGO_PEDIDO = [
        'pendiente' => ['Pendiente', 'warn'], 'en_revision' => ['En revisión', 'pend'],
        'pagado' => ['Pagado', 'ok'], 'rechazado' => ['Rechazado', 'off'],
    ];
    private const ESTADO_PAGO = [
        'acreditado' => ['Acreditado', 'ok'], 'pendiente' => ['Pendiente', 'warn'],
        'en_revision' => ['En revisión', 'pend'], 'rechazado' => ['Rechazado', 'off'], 'anulado' => ['Anulado', 'off'],
    ];
    private const RECLAMO = [
        'abierto' => ['Abierto', 'warn'], 'en_revision' => ['En revisión', 'pend'],
        'resuelto' => ['Resuelto', 'ok'], 'rechazado' => ['Rechazado (cerrado)', 'off'],
    ];
    private const ENVIO = [
        'pendiente' => ['Pendiente', 'warn'], 'despachado' => ['Despachado', 'pend'], 'en_camino' => ['En camino', 'info'],
        'entregado' => ['Entregado', 'ok'], 'cancelado' => ['Cancelado', 'off'],
    ];
    private const CARGA = [
        'pendiente' => ['Pendiente (sin repartidor)', 'warn'], 'asignado' => ['Asignado', 'info'],
        'en_reparto' => ['En reparto', 'pend'], 'entregado' => ['Entregado', 'ok'], 'cancelado' => ['Cancelado', 'off'],
    ];

    private ReporteOperativoRepository $repo;
    private ?string $ahora = null;

    public function __construct()
    {
        $this->repo = new ReporteOperativoRepository();
    }

    public function ahora(): string
    {
        return $this->ahora ??= $this->repo->ahora();
    }

    /** Listas para los filtros de la pantalla. */
    public function opciones(): array
    {
        $tipos = array_map(fn($t) => $this->medio((string) $t['nombre']), $this->repo->tiposPago());
        $medios = [];
        foreach (array_merge([$this->medio('Efectivo'), $this->medio('Transferencia'), $this->medio('Mercado Pago')], $tipos) as $m) {
            $medios[$m['k']] = $m['t'];
        }
        return [
            'hoy'          => substr($this->ahora(), 0, 10),
            'reportes'     => self::REPORTES,
            'usuarios'     => $this->repo->usuariosStaff(),
            'barrios'      => $this->repo->barrios(),
            'repartidores' => $this->repo->repartidores(),
            'medios'       => array_map(fn($k, $t) => ['k' => $k, 't' => $t], array_keys($medios), $medios),
            'etapas'       => array_map(fn($k, $v) => ['k' => $k, 't' => $v[0]], array_keys(self::ETAPAS), self::ETAPAS),
            'estados_pago' => array_map(fn($k, $v) => ['k' => $k, 't' => $v[0]], array_keys(self::ESTADO_PAGO_PEDIDO), self::ESTADO_PAGO_PEDIDO),
            'estados_reclamo' => array_map(fn($k, $v) => ['k' => $k, 't' => $v[0]], array_keys(self::RECLAMO), self::RECLAMO),
            'estados_envio'   => array_map(fn($k, $v) => ['k' => $k, 't' => $v[0]], array_keys(self::ENVIO), self::ENVIO),
            'estados_carga'   => array_map(fn($k, $v) => ['k' => $k, 't' => $v[0]], array_keys(self::CARGA), self::CARGA),
        ];
    }

    /** Genera un reporte por su clave (lista cerrada). $recortar = limitar filas de detalle (pantalla y PDF). */
    public function generar(string $reporte, array $q, bool $recortar = true): array
    {
        if (!isset(self::REPORTES[$reporte])) throw new ValidacionException('Reporte no válido.');
        $f = $this->periodo($q);
        $datos = match ($reporte) {
            'pagos'      => $this->pagos($f, $q),
            'cajas'      => $this->cajas($f, $q),
            'vendedores' => $this->vendedores($f, $q),
            'pedidos'    => $this->pedidos($f, $q),
            'reclamos'   => $this->reclamos($f, $q),
            'envios'     => $this->envios($f, $q),
            'carga'      => $this->carga($f, $q),
        };
        foreach ($datos['tablas'] as &$t) {
            $t['total'] = count($t['filas']);
            $t['recortada'] = $recortar && $t['total'] > self::MAX_FILAS;
            if ($t['recortada']) $t['filas'] = array_slice($t['filas'], 0, self::MAX_FILAS);
        }
        unset($t);
        return [
            'reporte' => $reporte,
            'titulo'  => self::REPORTES[$reporte],
            'periodo' => ['desde' => $f['desde_d']->format('Y-m-d'), 'hasta' => $f['hasta_d']->format('Y-m-d')],
            'generado' => $this->ahora(),
        ] + $datos;
    }

    // =================================================================
    // 1) Ventas por medio de pago
    // =================================================================

    private function pagos(array $f, array $q): array
    {
        $canal  = $this->enumero($q, 'canal', ['local', 'online']);
        $estado = $this->enumero($q, 'estado', array_keys(self::ESTADO_PAGO));
        $medioF = $this->texto($q, 'medio', 60);
        $vend   = $this->entero($q, 'vendedor_id', 1);

        $filas = [];
        foreach ($this->repo->pagos($f['desde'], $f['hasta']) as $r) {
            $m = $this->medio((string) $r['medio_nombre']);
            $est = $r['canal'] === 'local'
                ? ($r['estado_op'] === 'anulada' ? 'anulado' : 'acreditado')
                : ($r['estado_op'] === 'cancelado' ? 'anulado'
                    : ($r['estado_pago'] === 'pagado' ? 'acreditado' : (string) $r['estado_pago']));
            if ($canal && $r['canal'] !== $canal) continue;
            if ($estado && $est !== $estado) continue;
            if ($medioF && $m['k'] !== $medioF) continue;
            if ($vend && (int) $r['vendedor_id'] !== $vend) continue;
            $filas[] = [
                'fecha' => $r['fecha'], 'numero' => $r['numero'], 'op' => $r['canal'] . ':' . $r['op_id'],
                'op_id' => (int) $r['op_id'],
                'canal' => $r['canal'] === 'local' ? 'Local (POS)' : 'Tienda online', 'canal_k' => $r['canal'],
                'vendedor' => $r['vendedor'] ?? 'Tienda online', 'cliente' => $r['cliente'],
                'medio' => $m['t'], 'medio_k' => $m['k'], 'importe' => round((float) $r['importe'], 2),
                'estado' => self::ESTADO_PAGO[$est][0] ?? $est, 'estado_tono' => self::ESTADO_PAGO[$est][1] ?? 'neutral',
                'estado_k' => $est,
            ];
        }

        // Totales SOLO con lo acreditado. Operaciones = ventas/pedidos distintos.
        $porMedio = [];
        foreach (['efectivo' => 'Efectivo', 'transferencia' => 'Transferencia', 'mercadopago' => 'Mercado Pago'] as $k => $t) {
            $porMedio[$k] = ['t' => $t, 'total' => 0.0, 'ops' => []];
        }
        $total = 0.0; $ops = []; $noCont = 0.0; $noContN = [];
        foreach ($filas as $x) {
            if ($x['estado_k'] !== 'acreditado') { $noCont += $x['importe']; $noContN[$x['op']] = true; continue; }
            $porMedio[$x['medio_k']] ??= ['t' => $x['medio'], 'total' => 0.0, 'ops' => []];
            $porMedio[$x['medio_k']]['total'] += $x['importe'];
            $porMedio[$x['medio_k']]['ops'][$x['op']] = true;
            $total += $x['importe'];
            $ops[$x['op']] = true;
        }

        $kpis = [
            $this->kpi('total', 'Total ingresado', $total, 'money', 'Solo pagos acreditados', 'ok'),
            $this->kpi('operaciones', 'Operaciones', count($ops), 'int', 'Ventas del local + pedidos pagados'),
        ];
        $resumen = [];
        foreach ($porMedio as $k => $m) {
            $pct = $total > 0 ? round($m['total'] / $total * 100, 1) : 0.0;
            $n = count($m['ops']);
            $kpis[] = $this->kpi('medio_' . $k, $m['t'], round($m['total'], 2), 'money',
                $n . ($n === 1 ? ' operación' : ' operaciones') . ' · ' . number_format($pct, 1, ',', '.') . '%');
            $resumen[] = ['medio' => $m['t'], 'medio_k' => $k, 'operaciones' => $n, 'total' => round($m['total'], 2), 'pct' => $pct];
        }
        $kpis[] = $this->kpi('no_contabilizado', 'No contabilizado', round($noCont, 2), 'money',
            count($noContN) . ' pendientes, rechazados o anulados', $noCont > 0 ? 'warn' : null);

        return [
            'kpis' => $kpis,
            'grafico' => [
                'tipo' => 'doughnut', 'titulo' => 'Ingresos acreditados por medio de pago', 'formato' => 'money',
                'labels' => array_column($resumen, 'medio'),
                'series' => [['label' => 'Ingresado', 'data' => array_column($resumen, 'total')]],
            ],
            'tablas' => [
                [
                    'id' => 'movimientos', 'titulo' => 'Detalle de cobros',
                    'columnas' => [
                        $this->col('fecha', 'Fecha y hora', 'fecha'), $this->col('numero', 'Venta / pedido'),
                        $this->col('canal', 'Canal'), $this->col('vendedor', 'Vendedor'), $this->col('cliente', 'Cliente'),
                        $this->col('medio', 'Medio de pago'), $this->col('importe', 'Importe', 'money'),
                        $this->col('estado', 'Estado del pago', 'badge'),
                    ],
                    'filas' => $filas,
                ],
                [
                    'id' => 'resumen', 'titulo' => 'Resumen por medio de pago',
                    'columnas' => [
                        $this->col('medio', 'Medio de pago'), $this->col('operaciones', 'Operaciones', 'int'),
                        $this->col('total', 'Total ingresado', 'money'), $this->col('pct', '% del total', 'pct'),
                    ],
                    'filas' => $resumen,
                ],
            ],
            'filtros_txt' => $this->filtrosTxt($f, [
                'Canal' => $canal ? ($canal === 'local' ? 'Local (POS)' : 'Tienda online') : null,
                'Medio' => $medioF ? $this->medioPorClave($medioF) : null,
                'Estado' => $estado ? self::ESTADO_PAGO[$estado][0] : null,
                'Vendedor' => $vend ? $this->nombreUsuario($vend) : null,
            ]),
            'notas' => [
                'Local (POS): cada pago de la venta con su monto; una venta pagada con dos medios aparece en ambos, pero cuenta como una sola operación.',
                'Tienda online: importe del pedido + envío. Solo suma si el pago está confirmado ("pagado"). Pendientes, en revisión, rechazados, ventas anuladas y pedidos cancelados se listan pero no suman.',
                'Mercado Pago = pedidos con pago confirmado por Mercado Pago, o el medio "Mercado Pago" si existe en Tipos de pago del POS.',
                'El sistema no guarda la fecha de acreditación de un pedido online: se toma la fecha del pedido.',
            ],
        ];
    }

    // =================================================================
    // 2) Anulaciones y arqueo de cajas
    // =================================================================

    private function cajas(array $f, array $q): array
    {
        $cajaId = $this->entero($q, 'caja_id', 1);
        $usr    = $this->entero($q, 'usuario_id', 1);
        $hoy = substr($this->ahora(), 0, 10);
        $incluyeHoy = $f['desde_d']->format('Y-m-d') <= $hoy && $hoy <= $f['hasta_d']->format('Y-m-d');

        $cajas = [];
        $c = ['abiertas' => 0, 'cerradas' => 0, 'faltante' => 0, 'sobrante' => 0, 'coincide' => 0];
        $esperadoTot = 0.0; $declaradoTot = 0.0; $difTot = 0.0; $faltanteTot = 0.0; $sobranteTot = 0.0;
        foreach ($this->repo->cajas($f['desde'], $f['hasta'], $incluyeHoy, $cajaId, $usr) as $r) {
            $abierta = $r['estado'] === 'abierta';
            // Misma fórmula que CajaService: apertura + ventas en efectivo − retiros + ingresos.
            // Si está cerrada se muestra lo que quedó registrado en el cierre.
            $esperado = $abierta
                ? round((float) $r['monto_apertura'] + (float) $r['ventas_efectivo'] - (float) $r['retiros'] + (float) $r['ingresos'], 2)
                : (float) $r['monto_esperado'];
            $declarado = $abierta ? null : (float) $r['monto_contado'];
            $dif = $abierta ? null : round((float) $r['diferencia'], 2);   // declarado − esperado
            if ($abierta) {
                $est = ['abierta', 'Abierta (sin arqueo)', 'neutral']; $c['abiertas']++;
            } else {
                $c['cerradas']++;
                $esperadoTot += $esperado; $declaradoTot += (float) $declarado; $difTot += $dif;
                if ($dif < -0.004)     { $est = ['faltante', 'Faltante', 'off']; $c['faltante']++; $faltanteTot += $dif; }
                elseif ($dif > 0.004)  { $est = ['sobrante', 'Sobrante', 'warn']; $c['sobrante']++; $sobranteTot += $dif; }
                else                   { $est = ['coincide', 'Coincide', 'ok']; $c['coincide']++; }
            }
            $cajas[] = [
                'id' => (int) $r['id'], 'caja' => 'Caja #' . $r['id'], 'cajero' => $r['cajero'],
                'abierta_en' => $r['abierta_en'], 'cerrada_en' => $r['cerrada_en'],
                'apertura' => (float) $r['monto_apertura'], 'ventas_efectivo' => (float) $r['ventas_efectivo'],
                'ingresos' => (float) $r['ingresos'], 'retiros' => (float) $r['retiros'],
                'ventas' => (int) $r['ventas'],
                'anuladas' => (int) $r['anuladas'] . ((int) $r['anuladas'] ? ' · $ ' . number_format((float) $r['importe_anulado'], 2, ',', '.') : ''),
                'esperado' => $esperado, 'declarado' => $declarado, 'diferencia' => $dif,
                'estado' => $est[1], 'estado_tono' => $est[2], 'estado_k' => $est[0],
                'observacion' => $r['observacion'],
            ];
        }

        $anul = [];
        $anulTot = 0.0;
        foreach ($this->repo->anulaciones($f['desde'], $f['hasta'], $cajaId, $usr) as $r) {
            $anulTot += (float) $r['total'];
            $anul[] = [
                'id' => (int) $r['id'], 'fecha' => $r['fecha_anulacion'], 'numero' => $r['numero'],
                'caja' => $r['caja_id'] ? 'Caja #' . $r['caja_id'] : '—', 'vendedor' => $r['vendedor'],
                'anulado_por' => $r['anulado_por'] ?? '—', 'cliente' => $r['cliente'],
                'motivo' => $r['motivo'] ?? '(sin motivo registrado)', 'total' => (float) $r['total'],
            ];
        }

        $cerradas = array_values(array_filter($cajas, fn($x) => $x['estado_k'] !== 'abierta'));
        return [
            'kpis' => [
                $this->kpi('anuladas', 'Ventas anuladas', count($anul), 'int', 'Importe: $ ' . number_format($anulTot, 2, ',', '.'), count($anul) ? 'warn' : null),
                $this->kpi('importe_anulado', 'Importe anulado', round($anulTot, 2), 'money', 'No se borran: quedan como historial'),
                $this->kpi('abiertas', 'Cajas abiertas', $c['abiertas'], 'int', 'Todavía sin arqueo'),
                $this->kpi('cerradas', 'Cajas cerradas', $c['cerradas'], 'int', 'Con arqueo registrado'),
                $this->kpi('esperado', 'Dinero esperado', round($esperadoTot, 2), 'money', 'Suma de cajas cerradas'),
                $this->kpi('declarado', 'Dinero declarado', round($declaradoTot, 2), 'money', 'Lo que contó cada cajero'),
                $this->kpi('diferencia', 'Diferencia neta', round($difTot, 2), 'money', 'Declarado − esperado',
                    $difTot < -0.004 ? 'danger' : ($difTot > 0.004 ? 'warn' : 'ok')),
                $this->kpi('faltantes', 'Cajas con faltante', $c['faltante'], 'int', 'Total: $ ' . number_format($faltanteTot, 2, ',', '.'), $c['faltante'] ? 'danger' : null),
                $this->kpi('sobrantes', 'Cajas con sobrante', $c['sobrante'], 'int', 'Total: $ ' . number_format($sobranteTot, 2, ',', '.'), $c['sobrante'] ? 'warn' : null),
                $this->kpi('coinciden', 'Cajas que coinciden', $c['coincide'], 'int', 'Diferencia $ 0', $c['coincide'] ? 'ok' : null),
            ],
            'grafico' => [
                'tipo' => 'bar', 'titulo' => 'Esperado vs. declarado por caja cerrada', 'formato' => 'money',
                'labels' => array_map(fn($x) => $x['caja'] . ' · ' . $x['cajero'], $cerradas),
                'series' => [
                    ['label' => 'Esperado', 'data' => array_column($cerradas, 'esperado')],
                    ['label' => 'Declarado', 'data' => array_column($cerradas, 'declarado')],
                ],
            ],
            'tablas' => [
                [
                    'id' => 'cajas', 'titulo' => 'Arqueo por caja',
                    'columnas' => [
                        $this->col('caja', 'Caja'), $this->col('cajero', 'Cajero'),
                        $this->col('abierta_en', 'Apertura', 'fecha'), $this->col('cerrada_en', 'Cierre', 'fecha'),
                        $this->col('apertura', 'Saldo inicial', 'money'), $this->col('ventas_efectivo', 'Ventas en efectivo', 'money'),
                        $this->col('ingresos', 'Ingresos manuales', 'money'), $this->col('retiros', 'Retiros', 'money'),
                        $this->col('anuladas', 'Ventas anuladas'),
                        $this->col('esperado', 'Esperado', 'money'), $this->col('declarado', 'Declarado', 'money'),
                        $this->col('diferencia', 'Diferencia', 'money'), $this->col('estado', 'Arqueo', 'badge'),
                    ],
                    'filas' => $cajas,
                ],
                [
                    'id' => 'anulaciones', 'titulo' => 'Ventas anuladas',
                    'columnas' => [
                        $this->col('fecha', 'Fecha de anulación', 'fecha'), $this->col('numero', 'Venta'), $this->col('caja', 'Caja'),
                        $this->col('vendedor', 'Vendedor'), $this->col('anulado_por', 'Anulada por'), $this->col('cliente', 'Cliente'),
                        $this->col('motivo', 'Motivo'), $this->col('total', 'Importe', 'money'),
                    ],
                    'filas' => $anul,
                ],
            ],
            'filtros_txt' => $this->filtrosTxt($f, [
                'Caja' => $cajaId ? '#' . $cajaId : null,
                'Cajero' => $usr ? $this->nombreUsuario($usr) : null,
            ]),
            'notas' => [
                'Esperado = saldo inicial + ventas en efectivo (no anuladas) − retiros + ingresos manuales; es la misma cuenta que usa el cierre de caja. Transferencias y otros medios no entran a la caja física.',
                'Diferencia = dinero declarado − dinero esperado: negativa = faltante, positiva = sobrante, cero = coincide. Para las cajas cerradas se muestra lo registrado en el cierre.',
                'Se listan las cajas abiertas en el período; si el período incluye hoy, también las que siguen abiertas de días anteriores.',
                'Las anulaciones se fechan por el momento en que se anularon. Las ventas anuladas no se eliminan: quedan con estado "anulada" y su motivo.',
            ],
        ];
    }

    // =================================================================
    // 3) Rendimiento de vendedores
    // =================================================================

    private function vendedores(array $f, array $q): array
    {
        $vend = $this->entero($q, 'vendedor_id', 1);
        $ventas = $this->repo->ventas($f['desde'], $f['hasta'], $vend);

        $por = [];
        $ops = [];
        foreach ($ventas as $v) {
            $id = (int) $v['usuario_id'];
            $por[$id] ??= ['id' => $id, 'vendedor' => $v['vendedor'], 'ventas' => 0, 'total' => 0.0, 'anuladas' => 0, 'importe_anulado' => 0.0];
            $anulada = $v['estado'] === 'anulada';
            if ($anulada) { $por[$id]['anuladas']++; $por[$id]['importe_anulado'] += (float) $v['total']; }
            else          { $por[$id]['ventas']++;   $por[$id]['total'] += (float) $v['total']; }
            $ops[] = [
                'id' => (int) $v['id'], 'fecha' => $v['creado_en'], 'numero' => $v['numero'], 'vendedor' => $v['vendedor'],
                'vendedor_id' => $id, 'cliente' => $v['cliente'], 'medios' => $v['medios'] ?? '—',
                'total' => (float) $v['total'],
                'estado' => $anulada ? 'Anulada' : 'Registrada', 'estado_tono' => $anulada ? 'off' : 'ok',
            ];
        }

        $filas = [];
        $tv = 0; $tt = 0.0; $ta = 0; $tai = 0.0;
        foreach ($por as $p) {
            // Ticket promedio = total vendido / ventas válidas (las anuladas no entran).
            $ticket = $p['ventas'] > 0 ? round($p['total'] / $p['ventas'], 2) : null;
            $todas = $p['ventas'] + $p['anuladas'];
            $filas[] = [
                'id' => $p['id'], 'vendedor' => $p['vendedor'], 'ventas' => $p['ventas'], 'total' => round($p['total'], 2),
                'ticket' => $ticket, 'anuladas' => $p['anuladas'], 'importe_anulado' => round($p['importe_anulado'], 2),
                'pct_anul' => $todas > 0 ? round($p['anuladas'] / $todas * 100, 1) : 0.0,
            ];
            $tv += $p['ventas']; $tt += $p['total']; $ta += $p['anuladas']; $tai += $p['importe_anulado'];
        }
        // Ordenado por rendimiento: más vendido primero (desempate: más ventas, menos anulaciones).
        usort($filas, fn($a, $b) => [$b['total'], $b['ventas'], $a['anuladas']] <=> [$a['total'], $a['ventas'], $b['anuladas']]);
        $pctGlobal = ($tv + $ta) > 0 ? round($ta / ($tv + $ta) * 100, 1) : 0.0;
        $mejor = $filas[0] ?? null;

        return [
            'kpis' => [
                $this->kpi('vendedores', 'Vendedores con ventas', count(array_filter($filas, fn($x) => $x['ventas'] > 0)), 'int'),
                $this->kpi('ventas', 'Ventas válidas', $tv, 'int', 'Sin contar anuladas'),
                $this->kpi('total', 'Total vendido', round($tt, 2), 'money', 'Ventas registradas del POS', 'ok'),
                $this->kpi('ticket', 'Ticket promedio', $tv > 0 ? round($tt / $tv, 2) : null, 'money', 'Total vendido ÷ ventas válidas'),
                $this->kpi('anuladas', 'Anulaciones', $ta, 'int', 'Importe: $ ' . number_format($tai, 2, ',', '.'), $ta ? 'warn' : null),
                $this->kpi('pct_anul', '% de anulaciones', $pctGlobal, 'pct', 'Anuladas ÷ ventas totales',
                    $pctGlobal >= 10 ? 'danger' : ($pctGlobal > 0 ? 'warn' : 'ok')),
                $this->kpi('mejor', 'Más vendió', $mejor && $mejor['ventas'] > 0 ? $mejor['vendedor'] : '—', 'text',
                    $mejor && $mejor['ventas'] > 0 ? '$ ' . number_format($mejor['total'], 2, ',', '.') : null),
            ],
            'grafico' => [
                'tipo' => 'bar', 'titulo' => 'Total vendido por vendedor', 'formato' => 'money',
                'labels' => array_column($filas, 'vendedor'),
                'series' => [['label' => 'Total vendido', 'data' => array_column($filas, 'total')]],
                'extra'  => ['label' => '% anulaciones', 'data' => array_column($filas, 'pct_anul')],
            ],
            'tablas' => [
                [
                    'id' => 'vendedores', 'titulo' => 'Ranking de vendedores',
                    'columnas' => [
                        $this->col('vendedor', 'Vendedor'), $this->col('ventas', 'Ventas', 'int'),
                        $this->col('total', 'Total vendido', 'money'), $this->col('ticket', 'Ticket promedio', 'money'),
                        $this->col('anuladas', 'Anulaciones', 'int'), $this->col('importe_anulado', 'Importe anulado', 'money'),
                        $this->col('pct_anul', '% anulaciones', 'pct'),
                    ],
                    'filas' => $filas,
                ],
                [
                    'id' => 'operaciones', 'titulo' => $vend ? 'Operaciones de ' . $this->nombreUsuario($vend) : 'Operaciones del período',
                    'columnas' => [
                        $this->col('fecha', 'Fecha y hora', 'fecha'), $this->col('numero', 'Venta'), $this->col('vendedor', 'Vendedor'),
                        $this->col('cliente', 'Cliente'), $this->col('medios', 'Medios de pago'),
                        $this->col('total', 'Total', 'money'), $this->col('estado', 'Estado', 'badge'),
                    ],
                    'filas' => $ops,
                ],
            ],
            'filtros_txt' => $this->filtrosTxt($f, ['Vendedor' => $vend ? $this->nombreUsuario($vend) : null]),
            'notas' => [
                'Solo ventas del POS: los pedidos de la tienda online no tienen vendedor y no se cuentan acá.',
                'Total vendido = total cobrado de las ventas registradas (incluye el envío a domicilio si la venta lo tenía). Las anuladas se informan aparte y no entran en el ticket promedio.',
                '% de anulaciones = ventas anuladas ÷ (ventas válidas + anuladas) del vendedor.',
                'Britech no maneja sucursales ni puntos de venta separados, por eso no hay filtro de sucursal.',
            ],
        ];
    }

    // =================================================================
    // 4) Embudo de la tienda online
    // =================================================================

    private function pedidos(array $f, array $q): array
    {
        $etapaF  = $this->enumero($q, 'etapa', array_keys(self::ETAPAS));
        $pagoF   = $this->enumero($q, 'estado_pago', array_keys(self::ESTADO_PAGO_PEDIDO));
        $cliente = $this->texto($q, 'cliente', 80);
        $activos = $this->bandera($q, 'incluir_activos', true);
        $horas   = $this->entero($q, 'horas_alerta', 1, 720) ?? 24;
        $ahora = $this->ahora();
        $desde = $f['desde']; $hasta = $f['hasta'];

        $porEtapa = array_map(fn() => ['n' => 0, 'importe' => 0.0], self::ETAPAS);
        $filas = []; $sinAct = 0; $canceladosPeriodo = 0;
        foreach ($this->repo->pedidos($desde, $hasta, $activos, $cliente) as $p) {
            $etapa = $this->etapa($p);
            if ($etapaF && $etapa !== $etapaF) continue;
            if ($pagoF && $p['estado_pago'] !== $pagoF) continue;
            $importe = round((float) $p['total'] + (float) $p['envio_costo'], 2);
            $ultimo = $p['actualizado_en'] ?: $p['creado_en'];
            $minSinAct = $this->minutos($ultimo, $ahora);
            $final = in_array($etapa, ['entregado', 'cancelado'], true);
            $atrasado = !$final && $minSinAct !== null && $minSinAct >= $horas * 60;
            if ($atrasado) $sinAct++;
            if ($etapa === 'cancelado' && $p['creado_en'] >= $desde && $p['creado_en'] < $hasta) $canceladosPeriodo++;
            $porEtapa[$etapa]['n']++;
            $porEtapa[$etapa]['importe'] += $importe;
            [$etTxt, $etTono, $accion] = self::ETAPAS[$etapa];
            $filas[] = [
                'id' => (int) $p['id'], 'numero' => $p['numero'], 'cliente' => $p['cliente'], 'fecha' => $p['creado_en'],
                'importe' => $importe,
                'pago' => self::ESTADO_PAGO_PEDIDO[$p['estado_pago']][0] ?? $p['estado_pago'],
                'pago_tono' => self::ESTADO_PAGO_PEDIDO[$p['estado_pago']][1] ?? 'neutral',
                'etapa' => $etTxt, 'etapa_tono' => $etTono, 'etapa_k' => $etapa,
                'entrega' => $p['empresa'] ?? 'Sin envío cargado',
                'transcurrido' => $this->minutos($p['creado_en'], $ahora),
                'sin_actualizar' => $minSinAct, 'alerta' => $atrasado,
                'accion' => $accion ?: '—',
            ];
        }
        // Lo que requiere acción primero; dentro de cada etapa, lo más viejo arriba.
        $orden = array_flip(array_keys(self::ETAPAS));
        usort($filas, fn($a, $b) => [$orden[$a['etapa_k']], $a['fecha']] <=> [$orden[$b['etapa_k']], $b['fecha']]);

        $kpis = [];
        foreach (self::ETAPAS as $k => [$t, $tono]) {
            $n = $porEtapa[$k]['n'];
            $kpis[] = $this->kpi($k, $t, $n, 'int', '$ ' . number_format($porEtapa[$k]['importe'], 2, ',', '.'),
                $n && in_array($k, ['pendiente_pago', 'por_preparar'], true) ? 'warn' : null);
        }
        $kpis[] = $this->kpi('sin_actualizar', "Sin actualizar hace +{$horas} h", $sinAct, 'int', 'Pedidos activos', $sinAct ? 'danger' : 'ok');
        $kpis[] = $this->kpi('cancelados_periodo', 'Cancelados en el período', $canceladosPeriodo, 'int', 'Creados en el período');

        return [
            'kpis' => $kpis,
            'grafico' => [
                'tipo' => 'bar', 'horizontal' => true, 'titulo' => 'Pedidos por etapa', 'formato' => 'int',
                'labels' => array_column(self::ETAPAS, 0),
                'series' => [['label' => 'Pedidos', 'data' => array_column($porEtapa, 'n')]],
            ],
            'tablas' => [[
                'id' => 'pedidos', 'titulo' => 'Pedidos',
                'columnas' => [
                    $this->col('numero', 'Pedido'), $this->col('cliente', 'Cliente'), $this->col('fecha', 'Fecha y hora', 'fecha'),
                    $this->col('importe', 'Importe total', 'money'), $this->col('pago', 'Estado del pago', 'badge'),
                    $this->col('etapa', 'Estado del pedido', 'badge'), $this->col('entrega', 'Entrega'),
                    $this->col('transcurrido', 'Desde la creación', 'min'), $this->col('sin_actualizar', 'Sin actualizar', 'min'),
                    $this->col('accion', 'Acción sugerida'),
                ],
                'filas' => $filas,
            ]],
            'filtros_txt' => $this->filtrosTxt($f, [
                'Estado del pedido' => $etapaF ? self::ETAPAS[$etapaF][0] : null,
                'Estado del pago' => $pagoF ? self::ESTADO_PAGO_PEDIDO[$pagoF][0] : null,
                'Cliente' => $cliente,
                'Pedidos activos de días anteriores' => $activos ? 'Incluidos' : 'No incluidos',
                'Alerta' => "Sin actualizar hace {$horas} h o más",
            ]),
            'notas' => [
                'Cada pedido figura en una sola etapa, en este orden: cancelado → entregado (pedido o envío entregado) → enviado (envío despachado o en camino) → listo para retirar (retiro en local marcado como despachado/en camino) → pendiente de pago (pago pendiente, en revisión o rechazado) → pagado a preparar (estado "pendiente") → en preparación.',
                'Britech no tiene estados propios de "listo para retirar" ni "enviado": se deducen del estado del envío del pedido.',
                'Importe = productos + costo de envío (lo que paga el cliente). El reporte no cambia ningún estado: para gestionar un pedido se abre su detalle.',
                'Sin actualizar = tiempo desde la última modificación del pedido (si la base todavía no registra ese dato, desde su creación).',
            ],
        ];
    }

    /** Etapa única de un pedido según su estado, su pago y su envío (ver notas). */
    private function etapa(array $p): string
    {
        $env = $p['envio_estado'];
        if ($p['estado'] === 'cancelado') return 'cancelado';
        if ($p['estado'] === 'entregado' || $env === 'entregado') return 'entregado';
        $enCurso = in_array($env, ['despachado', 'en_camino'], true);
        if ($enCurso && !(int) $p['es_retiro']) return 'enviado';
        if ($enCurso && (int) $p['es_retiro']) return 'listo_retirar';
        if ($p['estado_pago'] !== 'pagado') return 'pendiente_pago';
        return $p['estado'] === 'preparando' ? 'en_preparacion' : 'por_preparar';
    }

    // =================================================================
    // 5) Reclamos
    // =================================================================

    private function reclamos(array $f, array $q): array
    {
        $estadoF = $this->enumero($q, 'estado', array_merge(array_keys(self::RECLAMO), ['sin_responder']));
        $cliente = $this->texto($q, 'cliente', 80);
        $motivo  = $this->texto($q, 'motivo', 80);
        $activos = $this->bandera($q, 'incluir_activos', true);
        $horas   = $this->entero($q, 'horas_alerta', 1, 720) ?? 48;
        $ahora = $this->ahora();

        $cnt = ['abierto' => 0, 'en_revision' => 0, 'resuelto' => 0, 'rechazado' => 0];
        $sinResp = 0; $vencidos = 0; $recibidos = 0;
        $filas = [];
        foreach ($this->repo->reclamos($f['desde'], $f['hasta'], $activos, $cliente) as $r) {
            if ($motivo && mb_stripos((string) $r['asunto'], $motivo) === false) continue;
            $activo = in_array($r['estado'], ['abierto', 'en_revision'], true);
            // Sin responder: está activo y el staff nunca contestó o lo último lo escribió el cliente.
            $sinResponder = $activo && ((int) $r['respuestas_staff'] === 0 || $r['ultimo_autor'] === 'cliente');
            if ($estadoF === 'sin_responder' ? !$sinResponder : ($estadoF && $r['estado'] !== $estadoF)) continue;

            $ultimaAct = max(array_filter([$r['creado_en'], $r['actualizado_en'], $r['ultimo_en']]));
            $abiertoMin = $this->minutos($r['creado_en'], $ahora);
            $vencido = $activo && $abiertoMin !== null && $abiertoMin >= $horas * 60;
            $cnt[$r['estado']] = ($cnt[$r['estado']] ?? 0) + 1;
            if ($sinResponder) $sinResp++;
            if ($vencido) $vencidos++;
            if ($r['creado_en'] >= $f['desde'] && $r['creado_en'] < $f['hasta']) $recibidos++;

            $ultima = '—';
            if ($r['ultimo_en']) {
                $ultima = ($r['ultimo_autor'] === 'staff' ? 'Staff' : 'Cliente') . ' · ' . date('d/m H:i', strtotime($r['ultimo_en']))
                    . ' — ' . trim(preg_replace('/\s+/', ' ', (string) $r['ultimo_mensaje']));
            }
            $filas[] = [
                'id' => (int) $r['id'], 'numero' => $r['numero'] ?: '#' . $r['id'], 'cliente' => $r['cliente'],
                'pedido' => $r['pedido_numero'], 'pedido_id' => (int) $r['pedido_id'], 'fecha' => $r['creado_en'],
                'motivo' => $r['asunto'],
                'estado' => self::RECLAMO[$r['estado']][0] ?? $r['estado'], 'estado_tono' => self::RECLAMO[$r['estado']][1] ?? 'neutral',
                'estado_k' => $r['estado'],
                'respuesta' => $sinResponder ? 'Sin responder' : 'Respondido',
                'respuesta_tono' => $sinResponder ? 'off' : 'ok',
                'ultima' => $ultima, 'sin_actualizar' => $this->minutos($ultimaAct, $ahora),
                'alerta' => $sinResponder || $vencido, 'vencido' => $vencido,
            ];
        }
        // Sin responder y más viejos primero.
        usort($filas, fn($a, $b) => [(int) !$a['alerta'], $a['fecha']] <=> [(int) !$b['alerta'], $b['fecha']]);

        return [
            'kpis' => [
                $this->kpi('total', 'Reclamos', count($filas), 'int', 'Con los filtros elegidos'),
                $this->kpi('abiertos', 'Abiertos', $cnt['abierto'], 'int', null, $cnt['abierto'] ? 'warn' : null),
                $this->kpi('sin_responder', 'Sin responder', $sinResp, 'int', 'Esperan respuesta del staff', $sinResp ? 'danger' : 'ok'),
                $this->kpi('en_revision', 'En revisión', $cnt['en_revision'], 'int', 'En proceso'),
                $this->kpi('resueltos', 'Resueltos', $cnt['resuelto'], 'int', null, $cnt['resuelto'] ? 'ok' : null),
                $this->kpi('rechazados', 'Rechazados (cerrados)', $cnt['rechazado'], 'int'),
                $this->kpi('recibidos', 'Recibidos en el período', $recibidos, 'int'),
                $this->kpi('vencidos', "Abiertos hace +{$horas} h", $vencidos, 'int', 'Llevan demasiado tiempo', $vencidos ? 'danger' : 'ok'),
            ],
            'grafico' => [
                'tipo' => 'doughnut', 'titulo' => 'Reclamos por estado', 'formato' => 'int',
                'labels' => array_map(fn($k) => self::RECLAMO[$k][0], array_keys($cnt)),
                'series' => [['label' => 'Reclamos', 'data' => array_values($cnt)]],
            ],
            'tablas' => [[
                'id' => 'reclamos', 'titulo' => 'Reclamos',
                'columnas' => [
                    $this->col('numero', 'Reclamo'), $this->col('cliente', 'Cliente'), $this->col('pedido', 'Pedido'),
                    $this->col('fecha', 'Creado', 'fecha'), $this->col('motivo', 'Motivo'),
                    $this->col('estado', 'Estado', 'badge'), $this->col('respuesta', 'Respuesta', 'badge'),
                    $this->col('ultima', 'Último mensaje'), $this->col('sin_actualizar', 'Sin actualizar', 'min'),
                ],
                'filas' => $filas,
            ]],
            'filtros_txt' => $this->filtrosTxt($f, [
                'Estado' => $estadoF ? ($estadoF === 'sin_responder' ? 'Sin responder' : self::RECLAMO[$estadoF][0]) : null,
                'Cliente' => $cliente, 'Motivo contiene' => $motivo,
                'Reclamos activos de días anteriores' => $activos ? 'Incluidos' : 'No incluidos',
                'Alerta' => "Abiertos hace {$horas} h o más",
            ]),
            'notas' => [
                'Sin responder = reclamo abierto o en revisión en el que el staff todavía no respondió, o cuyo último mensaje es del cliente.',
                'Los estados son los del módulo de reclamos: abierto, en revisión (en proceso), resuelto y rechazado (cerrado sin solución).',
                'El motivo es el asunto que escribió el cliente. Para responder se abre el reclamo: la respuesta le llega al cliente como notificación y por email, igual que desde el módulo de reclamos.',
                'El historial de reclamos y mensajes nunca se borra.',
            ],
        ];
    }

    // =================================================================
    // 6) Envíos sin asignar
    // =================================================================

    private function envios(array $f, array $q): array
    {
        $barrio = $this->entero($q, 'barrio_id', 1);
        $estadoF = $this->enumero($q, 'estado', array_keys(self::ENVIO));
        $repF = $this->repartidorFiltro($q);
        $esperaMin = $this->entero($q, 'espera_min', 0, 100000) ?? 0;
        $activos = $this->bandera($q, 'incluir_activos', true);
        $ahora = $this->ahora();

        $filas = []; $porEstado = array_map(fn() => 0, self::ENVIO);
        $sinAsig = 0; $sinAsigPeriodo = 0; $esperas = []; $asignacion = []; $mayor = null;
        foreach ($this->repo->enviosMoto($f['desde'], $f['hasta'], $activos) as $e) {
            $estado = (int) $e['op_cancelada'] ? 'cancelado' : $e['estado'];
            if ($barrio && (int) $e['barrio_id'] !== $barrio) continue;
            if ($estadoF && $estado !== $estadoF) continue;
            if ($repF === 'sin' && $e['repartidor_id'] !== null) continue;
            if (is_int($repF) && (int) $e['repartidor_id'] !== $repF) continue;

            $activo = in_array($estado, ['pendiente', 'despachado', 'en_camino'], true);
            $esperando = $activo && $e['repartidor_id'] === null;
            if ($esperando) {
                $espera = $this->minutos($e['creado_en'], $ahora);
                $esperaTxt = 'Esperando';
            } elseif ($e['repartidor_id'] !== null && $e['asignado_en']) {
                $espera = $this->minutos($e['creado_en'], $e['asignado_en']);
                $esperaTxt = 'Esperó hasta asignarse';
            } else {
                $espera = null;
                $esperaTxt = $e['repartidor_id'] !== null ? 'Asignado (sin hora registrada)' : 'Sin repartidor';
            }
            if ($esperaMin > 0 && ($espera === null || $espera < $esperaMin)) continue;

            $porEstado[$estado]++;
            if ($esperando) {
                $sinAsig++;
                $esperas[] = $espera;
                if ($e['creado_en'] >= $f['desde'] && $e['creado_en'] < $f['hasta']) $sinAsigPeriodo++;
                if ($mayor === null || $espera > $mayor['min']) $mayor = ['min' => $espera, 'op' => $e['numero_op']];
            } elseif ($e['repartidor_id'] !== null && $e['asignado_en']) {
                $asignacion[] = $espera;
            }
            $filas[] = [
                'id' => (int) $e['id'], 'envio' => '#' . $e['id'], 'numero' => $e['numero_op'],
                'pedido_id' => $e['pedido_id'] !== null ? (int) $e['pedido_id'] : null,
                'canal' => $e['canal'] === 'online' ? 'Tienda' : 'Local',
                'cliente' => $e['destinatario'] ?: $e['cliente'],
                'direccion' => trim($e['direccion'] . ' ' . ($e['altura'] ?? '')) . ($e['referencia'] ? ' (' . $e['referencia'] . ')' : ''),
                'barrio' => $e['barrio'], 'barrio_id' => (int) $e['barrio_id'], 'fecha' => $e['creado_en'],
                'espera' => $espera, 'espera_txt' => $esperaTxt,
                'estado' => self::ENVIO[$estado][0], 'estado_tono' => self::ENVIO[$estado][1], 'estado_k' => $estado,
                'repartidor' => $e['repartidor'] ?? 'Sin asignar', 'sin_asignar' => $esperando, 'alerta' => $esperando,
            ];
        }
        usort($filas, fn($a, $b) => [(int) !$a['sin_asignar'], -($a['espera'] ?? 0)] <=> [(int) !$b['sin_asignar'], -($b['espera'] ?? 0)]);
        $prom = fn(array $xs) => $xs ? (int) round(array_sum($xs) / count($xs)) : null;

        $kpis = [
            $this->kpi('sin_asignar', 'Sin asignar', $sinAsig, 'int', 'Activos sin repartidor', $sinAsig ? 'danger' : 'ok'),
            $this->kpi('sin_asignar_periodo', 'Sin asignar del período', $sinAsigPeriodo, 'int', 'Creados en el período'),
            $this->kpi('espera_prom', 'Espera promedio', $prom($esperas), 'min', 'De los que esperan ahora'),
            $this->kpi('espera_max', 'Mayor espera', $mayor['min'] ?? null, 'min', $mayor ? 'Envío de ' . $mayor['op'] : null, $mayor ? 'warn' : null),
            $this->kpi('asignacion_prom', 'Tiempo promedio de asignación', $prom($asignacion), 'min',
                count($asignacion) . ' envíos con hora de asignación'),
        ];
        foreach (self::ENVIO as $k => [$t]) {
            $kpis[] = $this->kpi('estado_' . $k, $t, $porEstado[$k], 'int', 'Envíos en este estado');
        }

        return [
            'kpis' => $kpis,
            'grafico' => [
                'tipo' => 'bar', 'titulo' => 'Envíos de Moto Express por estado', 'formato' => 'int',
                'labels' => array_column(self::ENVIO, 0),
                'series' => [['label' => 'Envíos', 'data' => array_values($porEstado)]],
            ],
            'tablas' => [[
                'id' => 'envios', 'titulo' => 'Envíos',
                'columnas' => [
                    $this->col('envio', 'Envío'), $this->col('numero', 'Pedido / venta'), $this->col('cliente', 'Cliente / recibe'),
                    $this->col('direccion', 'Dirección de entrega'), $this->col('barrio', 'Barrio'),
                    $this->col('fecha', 'Creado', 'fecha'), $this->col('espera', 'Tiempo de espera', 'min'),
                    $this->col('estado', 'Estado del envío', 'badge'), $this->col('repartidor', 'Repartidor'),
                ],
                'filas' => $filas,
            ]],
            'filtros_txt' => $this->filtrosTxt($f, [
                'Barrio' => $barrio ? $this->nombreDe($this->repo->barrios(), $barrio) : null,
                'Estado' => $estadoF ? self::ENVIO[$estadoF][0] : null,
                'Repartidor' => $repF === 'sin' ? 'Sin asignar' : (is_int($repF) ? $this->nombreDe($this->repo->repartidores(), $repF) : null),
                'Espera mínima' => $esperaMin ? $esperaMin . ' min' : null,
                'Sin asignar de días anteriores' => $activos ? 'Incluidos' : 'No incluidos',
            ]),
            'notas' => [
                'Solo envíos de Moto Express (con barrio): son los únicos que se asignan a un repartidor. Los envíos por correo no llevan repartidor.',
                'Tiempo de espera: si todavía no tiene repartidor, desde que se creó hasta ahora; si ya se asignó, lo que tardó en asignarse.',
                'La hora de asignación se registra desde que se aplicó database/schema_reportes_operativos.sql; los envíos asignados antes figuran "sin hora registrada".',
                'El reporte no asigna nada solo: la asignación se hace desde el tablero de Repartos.',
            ],
        ];
    }

    // =================================================================
    // 7) Carga de envíos por día y barrio
    // =================================================================

    private function carga(array $f, array $q): array
    {
        $barrio = $this->entero($q, 'barrio_id', 1);
        $estadoF = $this->enumero($q, 'estado', array_keys(self::CARGA));
        $repF = $this->repartidorFiltro($q);

        $por = []; $tot = array_map(fn() => 0, self::CARGA);
        $envios = [];
        foreach ($this->repo->enviosMoto($f['desde'], $f['hasta'], false) as $e) {
            $k = $this->bucketCarga($e);
            if ($barrio && (int) $e['barrio_id'] !== $barrio) continue;
            if ($estadoF && $k !== $estadoF) continue;
            if ($repF === 'sin' && $e['repartidor_id'] !== null) continue;
            if (is_int($repF) && (int) $e['repartidor_id'] !== $repF) continue;

            $bid = (int) $e['barrio_id'];
            $por[$bid] ??= ['id' => $bid, 'barrio' => $e['barrio'], 'total' => 0] + array_map(fn() => 0, self::CARGA);
            $por[$bid]['total']++;
            $por[$bid][$k]++;
            $tot[$k]++;
            $envios[] = [
                'id' => (int) $e['id'], 'numero' => $e['numero_op'],
                'pedido_id' => $e['pedido_id'] !== null ? (int) $e['pedido_id'] : null,
                'cliente' => $e['destinatario'] ?: $e['cliente'],
                'direccion' => trim($e['direccion'] . ' ' . ($e['altura'] ?? '')) . ($e['referencia'] ? ' (' . $e['referencia'] . ')' : ''),
                'barrio' => $e['barrio'], 'barrio_id' => $bid,
                'estado' => self::CARGA[$k][0], 'estado_tono' => self::CARGA[$k][1], 'estado_k' => $k,
                'repartidor' => $e['repartidor'] ?? 'Sin asignar', 'fecha' => $e['creado_en'],
            ];
        }
        $filas = array_values($por);
        usort($filas, fn($a, $b) => [$b['total'], $a['barrio']] <=> [$a['total'], $b['barrio']]);
        $total = array_sum($tot);

        $kpis = [$this->kpi('total', 'Envíos del período', $total, 'int', count($filas) . ' barrios con envíos')];
        foreach (self::CARGA as $k => [$t]) {
            $kpis[] = $this->kpi($k, $t, $tot[$k], 'int',
                $total ? number_format($tot[$k] / $total * 100, 1, ',', '.') . '% del total' : null,
                $k === 'pendiente' && $tot[$k] ? 'warn' : null);
        }

        return [
            'kpis' => $kpis,
            'grafico' => [
                'tipo' => 'bar', 'apilado' => true, 'titulo' => 'Envíos por barrio', 'formato' => 'int',
                'labels' => array_column($filas, 'barrio'),
                'series' => array_map(fn($k) => ['label' => self::CARGA[$k][0], 'k' => $k, 'data' => array_column($filas, $k)], array_keys(self::CARGA)),
            ],
            'tablas' => [
                [
                    'id' => 'barrios', 'titulo' => 'Carga por barrio',
                    'columnas' => [
                        $this->col('barrio', 'Barrio'), $this->col('total', 'Total de envíos', 'int'),
                        $this->col('pendiente', 'Pendientes', 'int'), $this->col('asignado', 'Asignados', 'int'),
                        $this->col('en_reparto', 'En reparto', 'int'), $this->col('entregado', 'Entregados', 'int'),
                        $this->col('cancelado', 'Cancelados', 'int'),
                    ],
                    'filas' => $filas,
                ],
                [
                    'id' => 'detalle', 'titulo' => 'Envíos del barrio',
                    'columnas' => [
                        $this->col('numero', 'Pedido / venta'), $this->col('cliente', 'Cliente / recibe'),
                        $this->col('direccion', 'Dirección'), $this->col('barrio', 'Barrio'),
                        $this->col('estado', 'Estado del envío', 'badge'), $this->col('repartidor', 'Repartidor'),
                        $this->col('fecha', 'Creado', 'fecha'),
                    ],
                    'filas' => $envios,
                ],
            ],
            'filtros_txt' => $this->filtrosTxt($f, [
                'Barrio' => $barrio ? $this->nombreDe($this->repo->barrios(), $barrio) : null,
                'Estado' => $estadoF ? self::CARGA[$estadoF][0] : null,
                'Repartidor' => $repF === 'sin' ? 'Sin asignar' : (is_int($repF) ? $this->nombreDe($this->repo->repartidores(), $repF) : null),
            ]),
            'notas' => [
                'Envíos de Moto Express creados en el período, agrupados por el barrio elegido en el pedido o la venta (no se inventan barrios ni direcciones).',
                'Cada envío está en un solo grupo: pendiente (sin repartidor), asignado (con repartidor, todavía no salió), en reparto (despachado o en camino), entregado o cancelado (también si la venta se anuló o el pedido se canceló).',
                'Britech no registra una fecha prevista de entrega: por eso no se muestra esa columna.',
            ],
        ];
    }

    private function bucketCarga(array $e): string
    {
        if ((int) $e['op_cancelada'] || $e['estado'] === 'cancelado') return 'cancelado';
        if ($e['estado'] === 'entregado') return 'entregado';
        if (in_array($e['estado'], ['despachado', 'en_camino'], true)) return 'en_reparto';
        return $e['repartidor_id'] !== null ? 'asignado' : 'pendiente';
    }

    // =================================================================
    // Filtros y utilidades
    // =================================================================

    /** Período [desde, hasta]: Y-m-d estricto, hasta ≥ desde, máximo 366 días. Por defecto, hoy. */
    public function periodo(array $q): array
    {
        $hoy = substr($this->ahora(), 0, 10);
        $leer = function (string $k) use ($q, $hoy): \DateTimeImmutable {
            $v = trim((string) ($q[$k] ?? ''));
            if ($v === '') $v = $hoy;
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if (!$d || $d->format('Y-m-d') !== $v) throw new ValidacionException('Fecha inválida: usá el formato AAAA-MM-DD.');
            return $d;
        };
        $desde = $leer('desde');
        $hasta = $leer('hasta');
        if ($hasta < $desde) throw new ValidacionException('La fecha "hasta" no puede ser anterior a "desde".');
        if ($desde->diff($hasta)->days > self::MAX_DIAS) throw new ValidacionException('El período no puede superar un año.');
        return [
            'desde_d' => $desde, 'hasta_d' => $hasta,
            'desde' => $desde->format('Y-m-d 00:00:00'),
            'hasta' => $hasta->modify('+1 day')->format('Y-m-d 00:00:00'),   // exclusivo
        ];
    }

    private function entero(array $q, string $k, int $min, int $max = PHP_INT_MAX): ?int
    {
        $v = trim((string) ($q[$k] ?? ''));
        if ($v === '') return null;
        if (!preg_match('/^\d{1,9}$/', $v) || (int) $v < $min || (int) $v > $max) {
            throw new ValidacionException("Valor inválido en el filtro \"$k\".");
        }
        return (int) $v;
    }

    private function enumero(array $q, string $k, array $validos): ?string
    {
        $v = trim((string) ($q[$k] ?? ''));
        if ($v === '') return null;
        if (!in_array($v, $validos, true)) throw new ValidacionException("Valor inválido en el filtro \"$k\".");
        return $v;
    }

    private function texto(array $q, string $k, int $max): ?string
    {
        $v = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($q[$k] ?? '')) ?? '');
        if ($v === '') return null;
        return mb_substr($v, 0, $max);
    }

    private function bandera(array $q, string $k, bool $porDefecto): bool
    {
        if (!isset($q[$k]) || $q[$k] === '') return $porDefecto;
        return in_array((string) $q[$k], ['1', 'true', 'si'], true);
    }

    /** Filtro de repartidor: '' = todos, 'sin' = sin asignar, o un id. */
    private function repartidorFiltro(array $q): int|string|null
    {
        if (($q['repartidor_id'] ?? '') === 'sin') return 'sin';
        return $this->entero($q, 'repartidor_id', 1);
    }

    /** Medio de pago normalizado: clave estable + nombre para mostrar. */
    private function medio(string $nombre): array
    {
        $n = mb_strtolower(trim(strtr($nombre, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u'])));
        if ($n === 'efectivo') return ['k' => 'efectivo', 't' => 'Efectivo'];
        if (str_contains($n, 'transfer')) return ['k' => 'transferencia', 't' => 'Transferencia'];
        if (str_contains($n, 'mercado')) return ['k' => 'mercadopago', 't' => 'Mercado Pago'];
        $t = trim($nombre) === '' ? 'Sin medio' : mb_strtoupper(mb_substr(trim($nombre), 0, 1)) . mb_substr(trim($nombre), 1);
        return ['k' => 'otro_' . preg_replace('/[^a-z0-9]+/', '_', $n), 't' => $t];
    }

    private function medioPorClave(string $k): string
    {
        foreach ($this->opciones()['medios'] as $m) if ($m['k'] === $k) return $m['t'];
        return $k;
    }

    private function nombreUsuario(int $id): string
    {
        return $this->nombreDe($this->repo->usuariosStaff(), $id);
    }

    private function nombreDe(array $lista, int $id): string
    {
        foreach ($lista as $x) if ((int) $x['id'] === $id) return (string) $x['nombre'];
        return '#' . $id;
    }

    /** Minutos entre dos fechas 'Y-m-d H:i:s' (null si falta alguna). */
    private function minutos(?string $desde, ?string $hasta): ?int
    {
        if (!$desde || !$hasta) return null;
        return max(0, intdiv(strtotime($hasta) - strtotime($desde), 60));
    }

    private function kpi(string $k, string $label, $valor, string $tipo, ?string $nota = null, ?string $tono = null): array
    {
        return compact('k', 'label', 'valor', 'tipo', 'nota', 'tono');
    }

    private function col(string $k, string $t, string $tipo = 'text'): array
    {
        return compact('k', 't', 'tipo');
    }

    private function filtrosTxt(array $f, array $extra): array
    {
        $txt = ['Período' => $f['desde_d']->format('d/m/Y') . ' al ' . $f['hasta_d']->format('d/m/Y')];
        foreach ($extra as $k => $v) if ($v !== null && $v !== '') $txt[$k] = $v;
        return $txt;
    }
}

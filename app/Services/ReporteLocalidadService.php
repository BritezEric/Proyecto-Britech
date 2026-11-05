<?php

namespace App\Services;

use App\Core\ValidacionException;
use App\Repositories\ReporteLocalidadRepository;
use DateTimeImmutable;

/**
 * Reglas del Reporte de Localidades:
 *  - valida los filtros (fechas, enums, ids) y arma el rango [desde, hasta+1día);
 *  - aplica el ALCANCE del rol (lo decide la sesión, nunca un parámetro):
 *      admin    → todo.
 *      vendedor → sus propias ventas del POS + los pedidos online (que ya gestiona
 *                 en "Pedidos"); sin cobros, costos de envío, paga ni repartidores.
 *  - calcula el período de comparación y las variaciones (sin dividir por cero).
 */
class ReporteLocalidadService
{
    public const ESTADOS_PEDIDO = ['pendiente', 'preparando', 'entregado', 'cancelado'];
    public const ESTADOS_ENVIO  = ['pendiente', 'despachado', 'en_camino', 'entregado', 'cancelado'];
    private const TIPOS_ZONA    = ['localidad', 'barrio', 'local', 'sin'];
    private const RANGO_MAX_DIAS = 1100;   // ~3 años

    /** Campos que el vendedor NO ve (financieros / internos del admin). */
    private const SOLO_ADMIN = ['cobrado', 'pendiente_cobro', 'envio_cobrado', 'costo_cobrado', 'paga_moto'];

    private ReporteLocalidadRepository $repo;

    public function __construct(private bool $esAdmin, private int $usuarioId)
    {
        $this->repo = new ReporteLocalidadRepository();
    }

    // ===================== Filtros =====================

    /**
     * Valida y normaliza los filtros que llegan por la URL.
     * Devuelve el array que entiende el Repository + datos para mostrar.
     */
    public function filtros(array $q): array
    {
        $hoy = new DateTimeImmutable($this->repo->hoy());
        $desde = $this->fecha($q['desde'] ?? null, 'Fecha desde') ?? $hoy->modify('first day of this month');
        $hasta = $this->fecha($q['hasta'] ?? null, 'Fecha hasta') ?? $hoy;
        $this->validarRango($desde, $hasta);

        $f = [
            'desde_d'          => $desde,
            'hasta_d'          => $hasta,
            'base'             => $this->enum($q['base'] ?? '', ['entrega', 'cliente'], 'Base') ?? 'entrega',
            'canal'            => $this->enum($q['canal'] ?? '', ['pos', 'online'], 'Canal') ?? 'todos',
            'zona'             => $this->zona($q['zona'] ?? null),
            'tipo_zona'        => $this->enum($q['tipo_zona'] ?? '', self::TIPOS_ZONA, 'Tipo de zona'),
            'estado_pedido'    => $this->enum($q['estado_pedido'] ?? '', self::ESTADOS_PEDIDO, 'Estado del pedido'),
            'estado_envio'     => $this->enum($q['estado_envio'] ?? '', self::ESTADOS_ENVIO, 'Estado del envío'),
            'empresa_envio_id' => $this->id($q['empresa_envio_id'] ?? null, 'Medio de envío'),
            'producto_id'      => $this->id($q['producto_id'] ?? null, 'Producto'),
            'dias_demora'      => $this->entero($q['dias_demora'] ?? null, 1, 60, 3, 'Días de demora'),
            // Alcance del rol: el vendedor solo ve SUS ventas del POS. No viene de la URL.
            'vendedor_id'      => $this->esAdmin ? null : $this->usuarioId,
        ];
        return $this->conRango($f, $desde, $hasta);
    }

    /** Período contra el que se compara (o null si no se pidió comparación). */
    public function periodoComparacion(array $f, array $q): ?array
    {
        $modo = $this->enum($q['comparar'] ?? '', ['anterior', 'anio', 'personalizado', 'ninguna'], 'Comparación') ?? 'anterior';
        $desde = $f['desde_d'];
        $hasta = $f['hasta_d'];

        switch ($modo) {
            case 'ninguna':
                return null;
            case 'anio':
                [$cd, $ch] = [$desde->modify('-1 year'), $hasta->modify('-1 year')];
                $txt = 'Mismo período del año anterior';
                break;
            case 'personalizado':
                $cd = $this->fecha($q['comp_desde'] ?? null, 'Comparar desde');
                $ch = $this->fecha($q['comp_hasta'] ?? null, 'Comparar hasta');
                if ($cd === null || $ch === null) {
                    throw new ValidacionException('Para comparar con otro rango completá las dos fechas.');
                }
                $this->validarRango($cd, $ch);
                $txt = 'Rango personalizado';
                break;
            default:   // 'anterior'
                if ($desde->format('j') === '1' && $desde->format('Y-m') === $hasta->format('Y-m')) {
                    // Mes (o parte del mes) → mismos días del mes anterior. Ej: 1–27 oct vs 1–27 sep.
                    $cd = $desde->modify('first day of last month');
                    $finMes = $cd->modify('last day of this month');
                    $ch = $cd->setDate((int) $cd->format('Y'), (int) $cd->format('m'),
                        min((int) $hasta->format('j'), (int) $finMes->format('j')));
                    $txt = 'Mismos días del mes anterior';
                } else {
                    // Rango libre → el bloque inmediatamente anterior de igual duración.
                    $dias = (int) $desde->diff($hasta)->days + 1;
                    $ch = $desde->modify('-1 day');
                    $cd = $ch->modify('-' . ($dias - 1) . ' days');
                    $txt = "Los {$dias} días anteriores";
                }
        }
        return $this->conRango(array_merge($f, ['texto' => $txt, 'modo' => $modo]), $cd, $ch);
    }

    // ===================== Consultas =====================

    /** Indicadores + comparación con el período anterior. */
    public function resumen(array $f, ?array $comp): array
    {
        $act = $this->numeros($this->repo->indicadores($f));
        $out = ['actual' => $this->recortar($act), 'anterior' => null, 'variacion' => null];
        if ($comp !== null) {
            $ant = $this->numeros($this->repo->indicadores($comp));
            $out['anterior'] = $this->recortar($ant);
            $var = [];
            foreach (['operaciones', 'importe', 'pedidos_online', 'ventas_pos', 'envios', 'clientes', 'zonas_activas'] as $k) {
                $var[$k] = $this->variacion($act[$k], $ant[$k]);
            }
            $out['variacion'] = $var;
        }
        return $out;
    }

    /** Tabla de zonas paginada, con la variación de importe por zona. */
    public function zonas(array $f, ?array $comp, array $q, int $page, int $perPage): array
    {
        $busca = trim((string) ($q['q'] ?? ''));
        $busca = $busca === '' ? null : mb_substr($busca, 0, 80);
        $orden = (string) ($q['orden'] ?? 'importe');
        $dir   = ($q['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $r = $this->repo->zonas($f, $busca, $orden, $dir, $perPage, ($page - 1) * $perPage);
        $prev = null;
        if ($comp !== null) {
            $prev = [];
            foreach ($this->repo->totalesPorZona($comp) as $k => $v) { $prev[self::clave($k)] = $v; }
        }

        $rows = array_map(function ($z) use ($prev) {
            $z = $this->numeros($z);
            $z['estado_entregas'] = $this->estadoEntregas($z);
            if ($prev !== null) {
                $p = $prev[self::clave($z['zona_key'])] ?? ['importe' => 0, 'operaciones' => 0];
                $z['variacion_importe'] = $this->variacion($z['importe'], (float) $p['importe']);
                $z['variacion_operaciones'] = $this->variacion($z['operaciones'], (float) $p['operaciones']);
            }
            return $this->recortar($z);
        }, $r['rows']);

        return ['rows' => $rows, 'total' => $r['total']];
    }

    /** Datos de los gráficos: top zonas, canal por zona y evolución en el tiempo. */
    public function graficos(array $f): array
    {
        $top = $this->repo->zonas($f, null, 'importe', 'desc', 12, 0)['rows'];
        $top = array_map(fn($z) => $this->recortar($this->numeros($z)), $top);

        // Evolución: granularidad según el largo del rango.
        $dias = (int) $f['desde_d']->diff($f['hasta_d'])->days + 1;
        $grano = $dias <= 31 ? 'dia' : ($dias <= 180 ? 'semana' : 'mes');
        $filas = $this->repo->serie($f, $grano);

        // Las 5 zonas con más importe tienen su línea; el resto va a "Otras zonas".
        // (la clave se normaliza: cada día puede traer otra variante de escritura de la misma localidad)
        $tot = [];
        foreach ($filas as $r) { $c = self::clave($r['zona_key']); $tot[$c] = ($tot[$c] ?? 0) + (float) $r['importe']; }
        arsort($tot);
        $principales = array_slice(array_keys($tot), 0, 5);

        $buckets = $this->buckets($f['desde_d'], $f['hasta_d'], $grano);
        $series = [];
        foreach ($filas as $r) {
            $c = self::clave($r['zona_key']);
            $k = in_array($c, $principales, true) ? $c : '_otras';
            $series[$k] ??= ['key' => $k, 'nombre' => $k === '_otras' ? 'Otras zonas' : $r['zona_nombre'],
                             'datos' => array_fill_keys($buckets, 0.0)];
            if (isset($series[$k]['datos'][$r['bucket']])) {
                $series[$k]['datos'][$r['bucket']] += (float) $r['importe'];
            }
        }
        // Orden: principales por importe y "Otras" al final.
        $ordenadas = [];
        foreach ($principales as $k) { if (isset($series[$k])) $ordenadas[] = $series[$k]; }
        if (isset($series['_otras'])) $ordenadas[] = $series['_otras'];
        foreach ($ordenadas as &$s) { $s['datos'] = array_values($s['datos']); }

        return [
            'zonas'     => $top,
            'evolucion' => ['grano' => $grano, 'etiquetas' => $buckets, 'series' => $ordenadas],
        ];
    }

    public function productos(array $f, string $orden = 'unidades'): array
    {
        return array_map(fn($p) => $this->numeros($p), $this->repo->productos($f, 15, $orden));
    }

    /** Logística por zona y medio. El vendedor no ve costos ni paga. */
    public function logistica(array $f): array
    {
        $rows = array_map(fn($r) => $this->recortar($this->numeros($r)), $this->repo->logistica($f));
        $repartidores = $this->esAdmin
            ? array_map(fn($r) => $this->numeros($r), $this->repo->repartidores($f))
            : null;
        return ['rows' => $rows, 'repartidores' => $repartidores];
    }

    /** KPIs de dos zonas lado a lado (mismos filtros, distinta zona). */
    public function compararZonas(array $f, string $zonaA, string $zonaB): array
    {
        $out = [];
        foreach (['a' => $zonaA, 'b' => $zonaB] as $lado => $z) {
            $zona = $this->zona($z);
            if ($zona === null) { throw new ValidacionException('Elegí las dos zonas a comparar.'); }
            $fz = array_merge($f, ['zona' => $zona]);
            $k = $this->numeros($this->repo->indicadores($fz));
            $k['ticket'] = $k['operaciones'] > 0 ? round($k['importe'] / $k['operaciones'], 2) : null;
            $k['top_productos'] = array_slice($this->productos($fz), 0, 3);
            $out[$lado] = $this->recortar($k);
        }
        return $out;
    }

    public function opciones(string $base): array
    {
        $base = $base === 'cliente' ? 'cliente' : 'entrega';
        return [
            'zonas'     => $this->repo->zonasDisponibles($base),
            'empresas'  => $this->repo->empresasEnvio(),
            'productos' => $this->repo->productosVendidos(),
            'estados_pedido' => self::ESTADOS_PEDIDO,
            'estados_envio'  => self::ESTADOS_ENVIO,
        ];
    }

    /** Descripción legible de los filtros aplicados (para el PDF / CSV). */
    public function describirFiltros(array $f, array $opciones): array
    {
        $nombre = function (array $lista, $v) {
            foreach ($lista as $o) { if ((string) $o['v'] === (string) $v) return $o['t']; }
            return (string) $v;
        };
        $d = [
            'Período'  => $f['desde_d']->format('d/m/Y') . ' al ' . $f['hasta_d']->format('d/m/Y'),
            'Base geográfica' => $f['base'] === 'cliente' ? 'Domicilio declarado del cliente' : 'Destino de la entrega',
            'Canal'    => ['todos' => 'Todos', 'pos' => 'POS (mostrador)', 'online' => 'Tienda online'][$f['canal']],
        ];
        if ($f['zona'] !== null)             $d['Zona'] = $nombre($opciones['zonas'], $f['zona']);
        if ($f['tipo_zona'] !== null)        $d['Tipo de zona'] = $f['tipo_zona'];
        if ($f['estado_pedido'] !== null)    $d['Estado del pedido'] = $f['estado_pedido'];
        if ($f['estado_envio'] !== null)     $d['Estado del envío'] = str_replace('_', ' ', $f['estado_envio']);
        if ($f['empresa_envio_id'] !== null) $d['Medio de envío'] = $nombre($opciones['empresas'], $f['empresa_envio_id']);
        if ($f['producto_id'] !== null)      $d['Producto'] = $nombre($opciones['productos'], $f['producto_id']);
        $d['Demora'] = 'Envío activo con más de ' . $f['dias_demora'] . ' días';
        if (!$this->esAdmin)                 $d['Alcance'] = 'Vendedor: solo sus ventas del POS + pedidos online';
        return $d;
    }

    public function esAdmin(): bool { return $this->esAdmin; }

    /** Fecha y hora actual de la base (misma zona horaria que los registros). */
    public function ahora(): string { return $this->repo->ahora(); }

    // ===================== Helpers =====================

    private function conRango(array $f, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $f['desde_d']    = $desde;
        $f['hasta_d']    = $hasta;
        $f['desde']      = $desde->format('Y-m-d 00:00:00');
        $f['hasta_excl'] = $hasta->modify('+1 day')->format('Y-m-d 00:00:00');   // límite exclusivo
        return $f;
    }

    private function fecha($v, string $campo): ?DateTimeImmutable
    {
        if ($v === null || $v === '') return null;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new ValidacionException("$campo no es una fecha válida (AAAA-MM-DD).");
        }
        return $d;
    }

    private function validarRango(DateTimeImmutable $desde, DateTimeImmutable $hasta): void
    {
        if ($desde > $hasta) {
            throw new ValidacionException('La fecha "desde" no puede ser posterior a la fecha "hasta".');
        }
        if ($desde->diff($hasta)->days > self::RANGO_MAX_DIAS) {
            throw new ValidacionException('El rango máximo de consulta es de 3 años.');
        }
    }

    private function enum($v, array $permitidos, string $campo): ?string
    {
        if ($v === null || $v === '' || $v === 'todos') return null;
        if (!in_array($v, $permitidos, true)) {
            throw new ValidacionException("$campo: valor no permitido.");
        }
        return $v;
    }

    private function id($v, string $campo): ?int
    {
        if ($v === null || $v === '') return null;
        if (!ctype_digit((string) $v) || (int) $v < 1) {
            throw new ValidacionException("$campo: valor no válido.");
        }
        return (int) $v;
    }

    private function entero($v, int $min, int $max, int $def, string $campo): int
    {
        if ($v === null || $v === '') return $def;
        if (!ctype_digit((string) $v) || (int) $v < $min || (int) $v > $max) {
            throw new ValidacionException("$campo: debe estar entre $min y $max.");
        }
        return (int) $v;
    }

    /** Clave de zona: 'local', 'sin', 'b:<id>' o 'l:<localidad>'. */
    private function zona($v): ?string
    {
        if ($v === null || $v === '') return null;
        $v = (string) $v;
        if ($v === 'local' || $v === 'sin' || preg_match('/^b:\d{1,10}$/', $v)) return $v;
        if (preg_match('/^l:.{1,100}$/su', $v) && trim(mb_substr($v, 2)) !== '') {
            return 'l:' . trim(mb_substr($v, 2));
        }
        throw new ValidacionException('Zona: valor no válido.');
    }

    /**
     * Clave de zona comparable en PHP: minúsculas y sin acentos, igual que la
     * collation de la base ("l:Córdoba" y "l:cordoba" son la misma zona).
     */
    private static function clave(string $k): string
    {
        return strtr(mb_strtolower($k), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    /** Variación absoluta y %; si el período anterior es 0 no hay % (no se divide por cero). */
    private function variacion(float $actual, float $anterior): array
    {
        return [
            'anterior' => $anterior,
            'abs'      => round($actual - $anterior, 2),
            'pct'      => $anterior != 0.0 ? round(($actual - $anterior) / abs($anterior) * 100, 1) : null,
            'sin_base' => $anterior == 0.0,
        ];
    }

    /** Resumen de una palabra del estado de las entregas de una zona. */
    private function estadoEntregas(array $z): string
    {
        if (($z['zona_tipo'] ?? '') === 'local') return 'Sin envío (retiro / mostrador)';
        if ($z['envios'] == 0) return 'Sin envíos';
        if ($z['demorados'] > 0) return 'Con demoras';
        if ($z['activos'] > 0) return 'En curso';
        return 'Al día';
    }

    /** Pasa los números que vienen como string de PDO a int/float. */
    private function numeros(array $r): array
    {
        foreach ($r as $k => $v) {
            if (is_string($v) && is_numeric($v) && !in_array($k, ['zona_key', 'zona_nombre', 'nombre', 'provincias', 'empresa'], true)) {
                $r[$k] = str_contains($v, '.') ? (float) $v : (int) $v;
            }
        }
        foreach (['importe', 'importe_pos', 'importe_online', 'cobrado', 'pendiente_cobro', 'envio_cobrado',
                  'costo_cobrado', 'paga_moto', 'ticket'] as $k) {
            if (array_key_exists($k, $r) && $r[$k] !== null) $r[$k] = round((float) $r[$k], 2);
        }
        return $r;
    }

    /** Quita los campos financieros/internos si el usuario no es admin. */
    private function recortar(array $r): array
    {
        if ($this->esAdmin) return $r;
        foreach (self::SOLO_ADMIN as $k) unset($r[$k]);
        return $r;
    }

    /** Etiquetas del eje X (todas, aunque un día no tenga ventas). */
    private function buckets(DateTimeImmutable $desde, DateTimeImmutable $hasta, string $grano): array
    {
        $out = [];
        if ($grano === 'mes') {
            $d = $desde->modify('first day of this month');
            $paso = '+1 month';
        } elseif ($grano === 'semana') {
            $d = $desde->modify('-' . ((int) $desde->format('N') - 1) . ' days');   // lunes
            $paso = '+1 week';
        } else {
            $d = $desde;
            $paso = '+1 day';
        }
        while ($d <= $hasta) { $out[] = $d->format('Y-m-d'); $d = $d->modify($paso); }
        return $out;
    }
}

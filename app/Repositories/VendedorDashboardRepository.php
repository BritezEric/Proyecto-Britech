<?php

namespace App\Repositories;

use App\Core\Database;

/**
 * Métricas del dashboard INDIVIDUAL del vendedor: todo se filtra por el usuario
 * logueado (sus ventas, los clientes que le compraron a él, sus cajas).
 * Solo lecturas agregadas (COUNT). El período ('dia' | 'semana' | 'mes' | 'ano')
 * define el eje X de los gráficos.
 */
class VendedorDashboardRepository
{
    public const PERIODOS = ['dia', 'semana', 'mes', 'ano'];
    private const CONSUMIDOR_FINAL = 1;   // cliente genérico del POS (no es un cliente "real")
    private const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    private const DIAS  = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];

    /**
     * Arma los "baldes" del período (clave => etiqueta), la expresión SQL que
     * produce la MISMA clave a partir de una columna fecha, y la fecha de inicio.
     * Misma lógica que DashboardRepository::serieVentas (admin), más el período 'dia' (por hora).
     * @param string[] $campos  campos numéricos de cada balde (arrancan en 0)
     * @return array{0:array,1:callable,2:string}  [baldes, fn(columna) => expr SQL, desde]
     */
    private function baldes(string $periodo, array $campos): array
    {
        $ceros = array_fill_keys($campos, 0);
        $b = [];
        if ($periodo === 'ano') {
            for ($i = 11; $i >= 0; $i--) {
                $t = strtotime("first day of -$i month");
                $b[date('Y-m', $t)] = ['label' => self::MESES[(int) date('n', $t) - 1]] + $ceros;
            }
            $grp = fn (string $col) => "DATE_FORMAT($col,'%Y-%m')";
            $desde = date('Y-m-01', strtotime('first day of -11 month'));
        } elseif ($periodo === 'mes') {
            for ($i = 3; $i >= 0; $i--) {
                $t = strtotime("monday -$i week");
                $b[date('oW', $t)] = ['label' => 'Sem ' . date('d/m', $t)] + $ceros;
            }
            $grp = fn (string $col) => "DATE_FORMAT($col,'%x%v')";   // año-semana ISO (= date('oW'))
            $desde = date('Y-m-d', strtotime('monday -3 week'));
        } elseif ($periodo === 'dia') {
            for ($h = 0; $h < 24; $h++) {
                $b[(string) $h] = ['label' => $h . 'h'] + $ceros;
            }
            $grp = fn (string $col) => "HOUR($col)";                   // hoy, por hora
            $desde = date('Y-m-d');
        } else { // semana
            for ($i = 6; $i >= 0; $i--) {
                $t = strtotime("-$i day");
                $b[date('Y-m-d', $t)] = ['label' => self::DIAS[(int) date('w', $t)] . ' ' . date('d', $t)] + $ceros;
            }
            $grp = fn (string $col) => "DATE($col)";
            $desde = date('Y-m-d', strtotime('-6 day'));
        }
        return [$b, $grp, $desde];
    }

    /** Corre una consulta agrupada (k, n) y SUMA cada n en el balde de su clave. */
    private function contarEn(array &$baldes, string $campo, string $sql, array $params): void
    {
        $st = Database::conexion()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $r) {
            $k = (string) $r['k'];
            if (isset($baldes[$k])) { $baldes[$k][$campo] += (int) $r['n']; }
        }
    }

    private function contar(string $sql, array $params): int
    {
        $st = Database::conexion()->prepare($sql);
        $st->execute($params);
        return (int) $st->fetchColumn();
    }

    /**
     * Frecuencia de MIS ventas (POS, registradas) por balde, y las anuladas aparte
     * para que el vendedor vea también lo que se le cayó.
     * @return array<int,array{label:string,ventas:int,anuladas:int}>
     */
    public function frecuenciaVentas(int $usuarioId, string $periodo): array
    {
        [$b, $grp, $desde] = $this->baldes($periodo, ['ventas', 'anuladas']);
        $g = $grp('creado_en');
        $this->contarEn($b, 'ventas',
            "SELECT $g AS k, COUNT(*) AS n FROM venta
             WHERE estado = 'registrada' AND usuario_id = ? AND creado_en >= ? GROUP BY k", [$usuarioId, $desde]);
        $this->contarEn($b, 'anuladas',
            "SELECT $g AS k, COUNT(*) AS n FROM venta
             WHERE estado = 'anulada' AND usuario_id = ? AND creado_en >= ? GROUP BY k", [$usuarioId, $desde]);
        return array_values($b);
    }

    /**
     * Frecuencia de compras de los clientes que atendió el vendedor: cuántas de
     * sus ventas fueron a clientes IDENTIFICADOS vs. al "Consumidor Final".
     * @return array<int,array{label:string,identificados:int,consumidor_final:int}>
     */
    public function frecuenciaCompras(int $usuarioId, string $periodo): array
    {
        [$b, $grp, $desde] = $this->baldes($periodo, ['identificados', 'consumidor_final']);
        $g = $grp('creado_en');
        $this->contarEn($b, 'identificados',
            "SELECT $g AS k, COUNT(*) AS n FROM venta
             WHERE estado = 'registrada' AND usuario_id = ? AND cliente_id <> ? AND creado_en >= ? GROUP BY k",
            [$usuarioId, self::CONSUMIDOR_FINAL, $desde]);
        $this->contarEn($b, 'consumidor_final',
            "SELECT $g AS k, COUNT(*) AS n FROM venta
             WHERE estado = 'registrada' AND usuario_id = ? AND cliente_id = ? AND creado_en >= ? GROUP BY k",
            [$usuarioId, self::CONSUMIDOR_FINAL, $desde]);
        return array_values($b);
    }

    /** Mis clientes que más me compraron en el período (sin Consumidor Final), de mayor a menor. Top 5. */
    public function clientesFrecuentes(int $usuarioId, string $periodo): array
    {
        $sql = "SELECT c.nombre AS cliente, COUNT(*) AS compras
                FROM venta v JOIN cliente c ON c.id = v.cliente_id
                WHERE v.estado = 'registrada' AND v.usuario_id = ? AND v.cliente_id <> ? AND v.creado_en >= ?
                GROUP BY v.cliente_id, c.nombre
                ORDER BY compras DESC, c.nombre
                LIMIT 5";
        $st = Database::conexion()->prepare($sql);
        $st->execute([$usuarioId, self::CONSUMIDOR_FINAL, $this->baldes($periodo, [])[2]]);
        return $st->fetchAll();
    }

    /**
     * MIS cajas abiertas vs cerradas.
     *  - abiertas: las mías que siguen abiertas ahora (sin importar cuándo las abrí).
     *  - cerradas: las mías que cerré dentro del período.
     * La serie reparte las abiertas por fecha de apertura y las cerradas por fecha de cierre.
     * @return array{abiertas:int,cerradas:int,serie:array}
     */
    public function cajas(int $usuarioId, string $periodo): array
    {
        [$b, $grp, $desde] = $this->baldes($periodo, ['abiertas', 'cerradas']);
        $this->contarEn($b, 'abiertas',
            "SELECT {$grp('abierta_en')} AS k, COUNT(*) AS n FROM caja
             WHERE usuario_id = ? AND estado = 'abierta' AND abierta_en >= ? GROUP BY k", [$usuarioId, $desde]);
        $this->contarEn($b, 'cerradas',
            "SELECT {$grp('cerrada_en')} AS k, COUNT(*) AS n FROM caja
             WHERE usuario_id = ? AND estado = 'cerrada' AND cerrada_en >= ? GROUP BY k", [$usuarioId, $desde]);

        return [
            'abiertas' => $this->contar("SELECT COUNT(*) FROM caja WHERE usuario_id = ? AND estado = 'abierta'", [$usuarioId]),
            'cerradas' => $this->contar("SELECT COUNT(*) FROM caja WHERE usuario_id = ? AND estado = 'cerrada' AND cerrada_en >= ?", [$usuarioId, $desde]),
            'serie'    => array_values($b),
        ];
    }

    /** Totales del período para las tarjetas de arriba: ventas, anuladas, clientes distintos y ticket promedio. */
    public function resumen(int $usuarioId, string $periodo): array
    {
        $st = Database::conexion()->prepare(
            "SELECT COALESCE(SUM(estado = 'registrada'),0) AS ventas,
                    COALESCE(SUM(estado = 'anulada'),0)    AS anuladas,
                    COUNT(DISTINCT CASE WHEN estado = 'registrada' AND cliente_id <> ? THEN cliente_id END) AS clientes,
                    COALESCE(AVG(CASE WHEN estado = 'registrada' THEN total END),0) AS ticket
             FROM venta WHERE usuario_id = ? AND creado_en >= ?");
        $st->execute([self::CONSUMIDOR_FINAL, $usuarioId, $this->baldes($periodo, [])[2]]);
        $r = $st->fetch() ?: [];
        return [
            'ventas'   => (int) ($r['ventas'] ?? 0),
            'anuladas' => (int) ($r['anuladas'] ?? 0),
            'clientes' => (int) ($r['clientes'] ?? 0),
            'ticket'   => (float) ($r['ticket'] ?? 0),
        ];
    }
}

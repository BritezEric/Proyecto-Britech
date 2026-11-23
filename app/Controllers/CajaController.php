<?php

namespace App\Controllers;

use App\Core\Paginacion;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidacionException;
use App\Repositories\CajaRepository;
use App\Services\CajaService;

/**
 * Caja del vendedor: apertura, movimientos y cierre (arqueo). Operativa = staff.
 * El historial de todas las cajas y el cierre del día son solo-admin (supervisión).
 */
class CajaController
{
    /** GET /api/caja/estado — caja abierta del vendedor + resumen + movimientos del turno. */
    public function estado(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        if (!$this->esquemaOk()) return;
        $r = (new CajaService())->estado((int) Session::usuarioId());
        Response::json(['ok' => true] + $r);
    }

    /** POST /api/caja/abrir — abre la caja con un monto inicial. */
    public function abrir(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        if (!$this->esquemaOk()) return;
        try {
            $monto = (float) (Request::json()['monto'] ?? 0);
            $r = (new CajaService())->abrir((int) Session::usuarioId(), $monto);
            Response::json(['ok' => true, 'caja' => $r], 201);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** POST /api/caja/movimiento — retiro, ingreso o gasto de efectivo. */
    public function movimiento(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        if (!$this->esquemaOk()) return;
        $in = Request::json();
        try {
            $r = (new CajaService())->movimiento(
                (int) Session::usuarioId(),
                (string) ($in['tipo'] ?? ''),
                (float) ($in['monto'] ?? 0),
                $in['motivo'] ?? null
            );
            Response::json(['ok' => true] + $r);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** POST /api/caja/cerrar — arqueo por forma de pago y cierre. */
    public function cerrar(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        if (!$this->esquemaOk()) return;
        try {
            $r = (new CajaService())->cerrar((int) Session::usuarioId(), Request::json());
            Response::json(['ok' => true, 'cierre' => $r]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** GET /api/caja/detalle?id= — detalle de una caja propia (o cualquiera si es admin). */
    public function detalle(): void
    {
        if (!Session::esStaff()) { Response::json(['ok' => false, 'error' => 'Solo staff.'], 403); return; }
        if (!$this->esquemaOk()) return;
        try {
            $r = (new CajaService())->detallePara((int) Request::query('id', '0'), (int) Session::usuarioId(), Session::esAdmin());
            Response::json(['ok' => true] + $r);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 404);
        }
    }

    /** GET /api/caja/reporte?id= — reporte de cierre en PDF (A4). */
    public function reporte(): void
    {
        if (!Session::esStaff()) { http_response_code(403); echo 'Solo staff.'; return; }
        if (!CajaRepository::esquemaListo()) { http_response_code(503); echo self::FALTA_SCRIPT; return; }
        try {
            $d = (new CajaService())->detallePara((int) Request::query('id', '0'), (int) Session::usuarioId(), Session::esAdmin());
        } catch (ValidacionException $e) {
            http_response_code(404); echo htmlspecialchars($e->getMessage()); return;
        }
        $this->pdf($this->htmlReporte($d), 'cierre-caja-' . $d['caja']['id'] . '.pdf');
    }

    /** GET /api/admin/cajas — historial de cajas con filtros. Solo admin. */
    public function adminListar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        if (!$this->esquemaOk()) return;
        [$page, $perPage, $offset] = Paginacion::desde(20);
        $filtros = [
            'desde'      => $this->fecha(Request::query('desde')),
            'hasta'      => $this->fecha(Request::query('hasta')),
            'usuario_id' => (int) Request::query('usuario_id', '0') ?: null,
            'estado'     => in_array(Request::query('estado'), ['abierta', 'cerrada'], true) ? Request::query('estado') : null,
            'con_diferencia' => Request::query('con_diferencia') === '1',
        ];
        $repo = new CajaRepository();
        $r = $repo->listarPaginado($perPage, $offset, $filtros);
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage) + [
            'vendedores' => $repo->vendedores(),
            'resumen'    => $repo->resumenListado($filtros),
            'olvidadas'  => $repo->abiertasHaceMas(14),   // abiertas hace más de un turno largo
        ]);
    }

    /** GET /api/admin/cajas/detalle?id= — cierre + movimientos + resumen. Solo admin. */
    public function adminDetalle(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        if (!$this->esquemaOk()) return;
        try {
            $r = (new CajaService())->detallePara((int) Request::query('id', '0'), (int) Session::usuarioId(), true);
            Response::json(['ok' => true] + $r);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 404);
        }
    }

    /**
     * POST /api/admin/cajas/cerrar — cierre administrativo de la caja abierta de otro
     * vendedor (ej: se olvidó de cerrarla). Body: {caja_id, contado | billetes, declarados?, observacion}.
     */
    public function adminCerrar(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        if (!$this->esquemaOk()) return;
        $in = Request::json();
        try {
            $r = (new CajaService())->cerrarComoAdmin(
                (int) ($in['caja_id'] ?? 0),
                (int) Session::usuarioId(),
                (string) (Session::usuario()['nombre'] ?? 'Un administrador'),
                $in
            );
            Response::json(['ok' => true, 'cierre' => $r]);
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** GET /api/admin/cajas/dia?fecha=YYYY-MM-DD[&pdf=1] — cierre del día (Z). Solo admin. */
    public function adminDia(): void
    {
        if (!Session::esAdmin()) { Response::json(['ok' => false, 'error' => 'Solo admin.'], 403); return; }
        if (!$this->esquemaOk()) return;
        try {
            $r = (new CajaService())->cierreDelDia((string) Request::query('fecha', date('Y-m-d')));
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422); return;
        }
        if (Request::query('pdf') === '1') { $this->pdf($this->htmlDia($r), 'cierre-dia-' . $r['fecha'] . '.pdf'); return; }
        Response::json(['ok' => true] + $r);
    }

    // ---------------------------------------------------------------- helpers

    private const FALTA_SCRIPT = 'La base de datos no está actualizada para el cierre de caja. '
        . 'Corré el script database/schema_caja_cierre.sql (ver docs/modulos/caja.md).';

    /** Si falta el script de la base, responde un error claro (503) en vez de romper. */
    private function esquemaOk(): bool
    {
        if (CajaRepository::esquemaListo()) return true;
        Response::json(['ok' => false, 'error' => self::FALTA_SCRIPT, 'codigo' => 'falta_migracion'], 503);
        return false;
    }

    private function fecha(?string $s): ?string
    {
        return ($s && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) ? $s : null;
    }

    private function pdf(string $html, string $nombre): void
    {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $nombre . '"');
        echo $dompdf->output();
    }

    private static function m($n): string
    {
        $n = (float) $n;
        return ($n < 0 ? '−$ ' : '$ ') . number_format(abs($n), 2, ',', '.');
    }

    private static function e($s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    private static function f(?string $dt, string $fmt = 'd/m/Y H:i'): string
    {
        return $dt ? date($fmt, strtotime($dt)) : '—';
    }

    private function estilos(): string
    {
        return "<style>
            * { font-family: 'DejaVu Sans', sans-serif; }
            body { font-size: 10px; color: #111; margin: 24px 28px; }
            h1 { font-size: 18px; margin: 0; } h2 { font-size: 12px; margin: 16px 0 6px; border-bottom: 1px solid #999; padding-bottom: 3px; }
            .sub { color: #555; margin: 2px 0 0; }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 3px 4px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
            th { background: #f1f1f1; font-size: 9px; text-transform: uppercase; }
            .r { text-align: right; white-space: nowrap; }
            .tot td { font-weight: bold; border-top: 2px solid #333; }
            .mal { color: #b00; font-weight: bold; } .ok { color: #176b38; font-weight: bold; }
            .tach { text-decoration: line-through; color: #888; }
            .firma { margin-top: 48px; } .firma td { border: none; width: 50%; padding-top: 30px; text-align: center; }
            .firma span { border-top: 1px solid #333; padding: 4px 40px 0; }
        </style>";
    }

    private static function dif(float $d): string
    {
        if (abs($d) < 0.01) return "<span class='ok'>OK</span>";
        return "<span class='mal'>" . ($d > 0 ? 'Sobra ' : 'Falta ') . self::m(abs($d)) . '</span>';
    }

    private function htmlReporte(array $d): string
    {
        $c = $d['caja']; $r = $d['resumen'];
        $m = fn($n) => self::m($n); $e = fn($s) => self::e($s);
        $cerrada = $c['estado'] === 'cerrada';

        // Ventas por forma de pago.
        $formas = '';
        foreach ($r['formas'] as $f) {
            $formas .= "<tr><td>{$e($f['nombre'])}</td><td class='r'>{$f['operaciones']}</td><td class='r'>{$m($f['total'])}</td></tr>";
        }

        // Arqueo por forma.
        $arqueo = '';
        if ($cerrada) {
            $filas = '';
            foreach ($d['cierre'] as $f) {
                $filas .= "<tr><td>{$e($f['nombre'])}</td><td class='r'>{$m($f['esperado'])}</td>"
                        . "<td class='r'>{$m($f['declarado'])}</td><td class='r'>" . self::dif((float) $f['diferencia']) . '</td></tr>';
            }
            $arqueo = "<h2>Arqueo por forma de pago</h2><table><tr><th>Forma</th><th class='r'>Esperado</th><th class='r'>Declarado</th><th class='r'>Diferencia</th></tr>{$filas}</table>";

            $conteo = is_array($c['conteo_efectivo'] ?? null) ? $c['conteo_efectivo'] : [];
            if ($conteo) {
                $cf = '';
                foreach ($conteo as $den => $cant) {
                    if ($den === 'sueltos') { $cf .= "<tr><td>Sueltos / centavos</td><td class='r'></td><td class='r'>{$m($cant)}</td></tr>"; continue; }
                    $cf .= "<tr><td>{$m($den)}</td><td class='r'>{$cant}</td><td class='r'>{$m($den * $cant)}</td></tr>";
                }
                $arqueo .= "<h2>Conteo de efectivo</h2><table><tr><th>Billete / moneda</th><th class='r'>Cantidad</th><th class='r'>Subtotal</th></tr>{$cf}"
                         . "<tr class='tot'><td colspan='2'>Total contado</td><td class='r'>{$m($c['monto_contado'])}</td></tr></table>";
            }
        }

        // Movimientos del turno.
        // Si el turno abarca más de un día, mostramos también la fecha de cada movimiento.
        $fmtHora = substr((string) $c['abierta_en'], 0, 10) === substr((string) ($c['cerrada_en'] ?? date('Y-m-d H:i:s')), 0, 10)
            ? 'H:i' : 'd/m H:i';
        $movs = '';
        foreach ($d['timeline'] as $t) {
            $cls = !empty($t['anulada']) ? " class='tach'" : '';
            $formasTxt = $t['formas'] ? implode(', ', array_map(fn($p) => $p['nombre'] . ' ' . self::m($p['monto']), $t['formas'])) : '';
            $movs .= "<tr><td style='white-space:nowrap'>" . self::f($t['fecha'], $fmtHora) . "</td><td{$cls}>{$e($t['detalle'])}</td><td>{$e($formasTxt)}</td>"
                   . "<td class='r'>{$m($t['monto'])}</td><td class='r'>" . ($t['tipo'] === 'cierre' ? '' : $m($t['efectivo'])) . "</td>"
                   . "<td class='r'>{$m($t['saldo'])}</td></tr>";
        }

        $obs = $c['observacion'] ? "<p><b>Observación:</b> {$e($c['observacion'])}</p>" : '';
        $estado = $cerrada ? 'Cerrada el ' . self::f($c['cerrada_en']) : '<b>ABIERTA</b> (reporte parcial)';
        // Cierre administrativo: la cerró alguien que no es el dueño de la caja.
        if ($cerrada && !empty($c['cerrada_por']) && (int) $c['cerrada_por'] !== (int) $c['usuario_id']) {
            $estado .= ' · <b>cierre administrativo</b> por ' . $e($c['cerrada_por_nombre'] ?? '¿?');
        }

        return "<html><head><meta charset='utf-8'>{$this->estilos()}</head><body>
            <h1>BRITECH · Cierre de caja #{$c['id']}</h1>
            <p class='sub'>Vendedor: {$e($c['vendedor'])} · Apertura: " . self::f($c['abierta_en']) . " · {$estado}</p>

            <h2>Resumen del turno</h2>
            <table>
              <tr><td>Ventas registradas</td><td class='r'>{$r['ventas']}</td></tr>
              <tr><td>Total vendido</td><td class='r'>{$m($r['total_ventas'])}</td></tr>
              <tr><td>Descuentos otorgados</td><td class='r'>{$m($r['descuentos'])}</td></tr>
              <tr><td>Ticket promedio</td><td class='r'>{$m($r['ticket_promedio'])}</td></tr>
              <tr><td>Ventas anuladas</td><td class='r'>{$r['anuladas']} ({$m($r['total_anulado'])})</td></tr>
            </table>

            <h2>Ventas por forma de pago</h2>
            <table><tr><th>Forma</th><th class='r'>Operaciones</th><th class='r'>Total</th></tr>{$formas}
              <tr class='tot'><td>Total</td><td></td><td class='r'>{$m($r['total_ventas'])}</td></tr></table>

            <h2>Efectivo en caja</h2>
            <table>
              <tr><td>Apertura</td><td class='r'>{$m($c['monto_apertura'])}</td></tr>
              <tr><td>+ Ventas en efectivo</td><td class='r'>{$m($r['efectivo'])}</td></tr>
              <tr><td>+ Ingresos ({$r['mov_ingresos']})</td><td class='r'>{$m($r['ingresos'])}</td></tr>
              <tr><td>− Retiros ({$r['mov_retiros']})</td><td class='r'>{$m($r['retiros'])}</td></tr>
              <tr><td>− Gastos ({$r['mov_gastos']})</td><td class='r'>{$m($r['gastos'])}</td></tr>
              <tr class='tot'><td>Efectivo esperado</td><td class='r'>{$m($d['esperado'])}</td></tr>
            </table>
            {$arqueo}
            {$obs}

            <h2>Movimientos del turno</h2>
            <table><tr><th>Hora</th><th>Detalle</th><th>Formas de pago</th><th class='r'>Monto</th><th class='r'>Efectivo</th><th class='r'>Saldo ef.</th></tr>{$movs}</table>

            <table class='firma'><tr><td><span>Firma vendedor</span></td><td><span>Firma supervisor</span></td></tr></table>
            <p class='sub'>Generado el " . date('d/m/Y H:i') . "</p>
        </body></html>";
    }

    private function htmlDia(array $z): string
    {
        $m = fn($n) => self::m($n); $e = fn($s) => self::e($s);
        $r = $z['resumen'];
        $formas = '';
        foreach ($r['formas'] as $f) {
            $formas .= "<tr><td>{$e($f['nombre'])}</td><td class='r'>{$f['operaciones']}</td><td class='r'>{$m($f['total'])}</td></tr>";
        }
        $cajas = '';
        foreach ($z['cajas'] as $c) {
            $cajas .= "<tr><td>#{$c['id']}</td><td>{$e($c['vendedor'])}</td><td>" . self::f($c['abierta_en'], 'H:i') . ' – ' . self::f($c['cerrada_en'], 'H:i') . "</td>"
                    . "<td class='r'>{$c['ventas']}</td><td class='r'>{$m($c['total_ventas'])}</td><td class='r'>{$m($c['esperado'])}</td>"
                    . "<td class='r'>" . ($c['contado'] === null ? 'abierta' : $m($c['contado'])) . "</td>"
                    . "<td class='r'>" . ($c['diferencia'] === null ? '—' : self::dif($c['diferencia'])) . '</td></tr>';
        }
        $ef = $z['efectivo'];
        $aviso = $z['abiertas'] ? "<p class='mal'>Atención: {$z['abiertas']} caja(s) siguen abiertas; sus montos son parciales.</p>" : '';

        return "<html><head><meta charset='utf-8'>{$this->estilos()}</head><body>
            <h1>BRITECH · Cierre del día</h1>
            <p class='sub'>Fecha: " . self::f($z['fecha'], 'd/m/Y') . ' · ' . count($z['cajas']) . " caja(s)</p>
            {$aviso}
            <h2>Ventas por forma de pago</h2>
            <table><tr><th>Forma</th><th class='r'>Operaciones</th><th class='r'>Total</th></tr>{$formas}
              <tr class='tot'><td>Total ({$r['ventas']} ventas)</td><td></td><td class='r'>{$m($r['total_ventas'])}</td></tr></table>
            <h2>Movimientos de efectivo</h2>
            <table>
              <tr><td>Aperturas</td><td class='r'>{$m($ef['apertura'])}</td></tr>
              <tr><td>Ingresos ({$r['mov_ingresos']})</td><td class='r'>{$m($r['ingresos'])}</td></tr>
              <tr><td>Retiros ({$r['mov_retiros']})</td><td class='r'>{$m($r['retiros'])}</td></tr>
              <tr><td>Gastos ({$r['mov_gastos']})</td><td class='r'>{$m($r['gastos'])}</td></tr>
              <tr><td>Descuentos otorgados</td><td class='r'>{$m($r['descuentos'])}</td></tr>
              <tr><td>Anuladas</td><td class='r'>{$r['anuladas']} ({$m($r['total_anulado'])})</td></tr>
              <tr class='tot'><td>Efectivo esperado / contado</td><td class='r'>{$m($ef['esperado'])} / {$m($ef['contado'])}</td></tr>
              <tr><td>Diferencia de efectivo (cajas cerradas)</td><td class='r'>" . self::dif($ef['diferencia']) . "</td></tr>
            </table>
            <h2>Cajas del día</h2>
            <table><tr><th>Caja</th><th>Vendedor</th><th>Horario</th><th class='r'>Ventas</th><th class='r'>Vendido</th><th class='r'>Esperado ef.</th><th class='r'>Contado</th><th class='r'>Dif.</th></tr>{$cajas}</table>
            <p class='sub'>Generado el " . date('d/m/Y H:i') . "</p>
        </body></html>";
    }
}

<?php

namespace App\Controllers;

use App\Core\Paginacion;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidacionException;
use App\Core\Xlsx;
use App\Repositories\AuditoriaRepository;
use Dompdf\Dompdf;

/**
 * Visor de auditoría del panel (quién hizo qué, cuándo y qué cambió).
 * Solo admin y solo lectura: no existen acciones para editar ni borrar registros.
 */
class AuditoriaController
{
    /** Nombres legibles de los módulos (entidad) y de las acciones. */
    public const MODULOS = [
        'sesion' => 'Sesiones', 'usuario' => 'Usuarios', 'producto' => 'Productos', 'stock' => 'Stock',
        'venta' => 'Ventas', 'pedido' => 'Pedidos', 'caja' => 'Caja', 'cliente' => 'Clientes',
        'proveedor' => 'Proveedores', 'repartidor' => 'Repartidores', 'config' => 'Configuración',
        'compra' => 'Compras', 'alerta' => 'Alertas', 'auditoria' => 'Auditoría',
    ];
    public const ACCIONES = [
        'login' => 'Inicio de sesión', 'logout' => 'Cierre de sesión', 'login_fallido' => 'Login fallido',
        'crear' => 'Alta', 'editar' => 'Modificación', 'baja' => 'Baja', 'anular' => 'Anulación',
        'activar' => 'Activación', 'cambio_password' => 'Cambio de contraseña',
        'precio_masivo' => 'Precio masivo', 'estado' => 'Cambio de estado', 'estado_pago' => 'Estado de pago',
        'entrada' => 'Entrada de stock', 'salida' => 'Salida de stock', 'ajuste' => 'Ajuste de stock',
        'abrir' => 'Apertura de caja', 'cerrar' => 'Cierre de caja', 'ingreso' => 'Ingreso de caja', 'retiro' => 'Retiro de caja',
        'aprobar' => 'Aprobación', 'rechazar' => 'Rechazo',
        'acceso_denegado' => 'Acceso denegado', 'bloqueo_ip' => 'Bloqueo de IP', 'revisar' => 'Alerta revisada',
        'exportar' => 'Exportación', 'verificar' => 'Verificación de integridad',
        // Solo para el filtro (ver AuditoriaRepository::ACCIONES_FILTRO).
        'venta' => 'Ventas realizadas', 'compra' => 'Compras realizadas',
    ];
    /** Tipo de operación de cada acción (define el color en el panel y las tarjetas del resumen). */
    public const TIPOS = [
        'alta'   => ['crear', 'activar', 'abrir', 'aprobar'],
        'cambio' => ['editar', 'precio_masivo', 'estado', 'estado_pago', 'cambio_password', 'ingreso', 'retiro', 'cerrar'],
        'baja'   => ['baja', 'anular', 'rechazar'],
        'stock'  => ['entrada', 'salida', 'ajuste'],
        'sesion' => ['login', 'logout', 'login_fallido', 'acceso_denegado', 'bloqueo_ip'],
    ];
    private const MAX_PDF = 1000;

    /** Listado paginado + resumen + opciones de los filtros. */
    public function listar(): void
    {
        if (!$this->soloAdmin()) return;
        $f = $this->filtros();
        if ($f === null) return;
        [$page, $perPage, $offset] = Paginacion::desde(20);
        $repo = new AuditoriaRepository();
        $r = $repo->listarPaginado($f, $perPage, $offset);
        Response::json(Paginacion::respuesta($r['rows'], $r['total'], $page, $perPage) + [
            'resumen'  => $this->resumen($repo, $f, $r['total']),
            'opciones' => $repo->opciones(),
            'modulos'  => self::MODULOS,
            'acciones' => self::ACCIONES,
            'tipos'    => self::TIPOS,
        ]);
    }

    /** PDF con los mismos filtros de la pantalla. */
    public function pdf(): void
    {
        if (!$this->soloAdmin()) return;
        $f = $this->filtros();
        if ($f === null) return;
        try {
            $repo = new AuditoriaRepository();
            $filas = $repo->listarParaExportar($f);
            \App\Services\Auditoria::evento('auditoria', null, 'exportar', 'PDF · Historial general · ' . count($filas) . ' fila(s) · ' . $this->filtrosTexto($f));
            $html = $this->htmlPdf($f, $filas, $this->resumen($repo, $f, $repo->listarPaginado($f, 1, 0)['total']));
            $dompdf = new Dompdf();
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'landscape');
            $dompdf->render();
        } catch (\Throwable $e) {
            error_log('[Auditoria] pdf: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'No se pudo generar el PDF.'], 500);
            return;
        }
        header('Cache-Control: no-store');
        $dompdf->stream('auditoria-' . date('Ymd-His') . '.pdf', ['Attachment' => true]);
    }

    /** Planilla Excel (.xlsx) con los mismos filtros de la pantalla. */
    public function excel(): void
    {
        if (!$this->soloAdmin()) return;
        $f = $this->filtros();
        if ($f === null) return;
        try {
            $filas = (new AuditoriaRepository())->listarParaExportar($f);
            \App\Services\Auditoria::evento('auditoria', null, 'exportar', 'Excel · Historial general · ' . count($filas) . ' fila(s) · ' . $this->filtrosTexto($f));
            $datos = array_map(fn($a) => [
                $a['id'], $this->fecha($a['creado_en']), $a['usuario'] ?? 'Sistema',
                self::ACCIONES[$a['accion']] ?? $a['accion'], self::MODULOS[$a['entidad']] ?? $a['entidad'],
                $this->registro($a), $this->descripcion($a), $a['valor_anterior'], $a['valor_nuevo'],
            ], $filas);
            $info = [
                'Britech · Auditoría del sistema',
                'Generado: ' . date('d/m/Y H:i') . ' por ' . (Session::usuario()['nombre'] ?? 'admin'),
                'Filtros: ' . $this->filtrosTexto($f),
                count($filas) . ' registro(s)' . (count($filas) >= AuditoriaRepository::MAX_EXPORT ? ' (tope de exportación alcanzado: acotá las fechas)' : ''),
            ];
            $xlsx = Xlsx::generar('Auditoría', $info,
                ['N°', 'Fecha y hora', 'Usuario', 'Acción', 'Módulo', 'Registro afectado', 'Descripción', 'Valor anterior', 'Valor nuevo'],
                $datos, [8, 17, 20, 20, 14, 26, 46, 22, 22]);
        } catch (\Throwable $e) {
            error_log('[Auditoria] excel: ' . $e->getMessage());
            Response::json(['ok' => false, 'error' => 'No se pudo generar el Excel.'], 500);
            return;
        }
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="auditoria-' . date('Ymd-His') . '.xlsx"');
        header('Content-Length: ' . strlen($xlsx));
        header('Cache-Control: no-store');
        echo $xlsx;
    }

    // ---------- internos ----------

    private function soloAdmin(): bool
    {
        if (Session::esAdmin()) return true;
        Response::json(['ok' => false, 'error' => 'Solo el administrador puede ver la auditoría.'], 403);
        return false;
    }

    /** Lee y valida los filtros. Devuelve null (y responde 422) si algo es inválido. */
    private function filtros(): ?array
    {
        try {
            $txt = fn(string $k) => trim((string) Request::query($k, ''));
            $f = [
                'entidad' => $txt('entidad'), 'accion' => $txt('accion'), 'usuario_id' => $txt('usuario_id'),
                'desde' => $txt('desde'), 'hasta' => $txt('hasta'), 'q' => mb_substr($txt('q'), 0, 100),
            ];
            foreach (['entidad', 'accion'] as $k) {
                if ($f[$k] !== '' && !preg_match('/^[a-z_]{1,40}$/', $f[$k])) throw new ValidacionException('Filtro inválido.');
            }
            if ($f['usuario_id'] !== '' && !ctype_digit($f['usuario_id'])) throw new ValidacionException('Usuario inválido.');
            foreach (['desde', 'hasta'] as $k) {
                if ($f[$k] === '') continue;
                $d = \DateTime::createFromFormat('!Y-m-d', $f[$k]);
                if (!$d || $d->format('Y-m-d') !== $f[$k]) throw new ValidacionException('La fecha tiene que ser AAAA-MM-DD.');
            }
            if ($f['desde'] !== '' && $f['hasta'] !== '' && $f['desde'] > $f['hasta']) {
                throw new ValidacionException('La fecha "desde" no puede ser posterior a "hasta".');
            }
            return $f;
        } catch (ValidacionException $e) {
            Response::json(['ok' => false, 'error' => $e->getMessage()], 422);
            return null;
        }
    }

    /** Tarjetas del resumen: totales por tipo de operación. */
    private function resumen(AuditoriaRepository $repo, array $f, int $total): array
    {
        $porAccion = [];
        foreach ($repo->resumen($f) as $r) $porAccion[$r['accion']] = (int) $r['n'];
        $out = ['total' => $total, 'usuarios' => $repo->usuariosDistintos($f), 'login_fallido' => $porAccion['login_fallido'] ?? 0];
        foreach (self::TIPOS as $tipo => $acciones) {
            $out[$tipo] = array_sum(array_map(fn($a) => $porAccion[$a] ?? 0, $acciones));
        }
        return $out;
    }

    private function fecha(string $f): string
    {
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $f);
        return $d ? $d->format('d/m/Y H:i') : $f;
    }

    private function registro(array $a): string
    {
        if ($a['entidad_id'] === null) return '—';
        return '#' . $a['entidad_id'] . (!empty($a['referencia']) ? ' · ' . $a['referencia'] : '');
    }

    private function descripcion(array $a): string
    {
        $partes = [];
        if (!empty($a['campo'])) $partes[] = 'Campo: ' . $a['campo'];
        if (!empty($a['detalle'])) $partes[] = $a['detalle'];
        return $partes ? implode(' · ', $partes) : '—';
    }

    private function filtrosTexto(array $f): string
    {
        $t = [];
        if ($f['desde'] !== '' || $f['hasta'] !== '') $t[] = 'Fechas: ' . ($f['desde'] ?: 'inicio') . ' a ' . ($f['hasta'] ?: 'hoy');
        if ($f['usuario_id'] !== '') {
            $nombres = array_column((new AuditoriaRepository())->opciones()['usuarios'], 'nombre', 'id');
            $t[] = 'Usuario: ' . ($nombres[(int) $f['usuario_id']] ?? '#' . $f['usuario_id']);
        }
        if ($f['accion'] !== '') $t[] = 'Acción: ' . (self::ACCIONES[$f['accion']] ?? $f['accion']);
        if ($f['entidad'] !== '') $t[] = 'Módulo: ' . (self::MODULOS[$f['entidad']] ?? $f['entidad']);
        if ($f['q'] !== '') $t[] = 'Búsqueda: "' . $f['q'] . '"';
        return $t ? implode(' · ', $t) : 'sin filtros (todo el historial)';
    }

    private function htmlPdf(array $f, array $filas, array $res): string
    {
        $e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $logo = '';
        $ruta = dirname(__DIR__, 2) . '/public/assets/img/britech-logo.png';
        if (is_file($ruta)) $logo = "<img class='logo' src='data:image/png;base64," . base64_encode((string) file_get_contents($ruta)) . "'>";

        $tipoDe = [];
        foreach (self::TIPOS as $tipo => $acciones) foreach ($acciones as $a) $tipoDe[$a] = $tipo;

        $kpis = '';
        foreach ([['Registros', $res['total']], ['Altas', $res['alta']], ['Modificaciones', $res['cambio']],
                  ['Bajas y anulaciones', $res['baja']], ['Movimientos de stock', $res['stock']],
                  ['Logins fallidos', $res['login_fallido']], ['Usuarios', $res['usuarios']]] as [$l, $v]) {
            $kpis .= "<td class='kpi'><div class='kt'>" . $e($l) . "</div><div class='kv'>" . number_format((int) $v, 0, ',', '.') . "</div></td>";
        }

        $tr = '';
        foreach (array_slice($filas, 0, self::MAX_PDF) as $a) {
            $tipo = $tipoDe[$a['accion']] ?? 'otro';
            $tr .= '<tr><td>' . $e($this->fecha($a['creado_en'])) . '</td><td>' . $e($a['usuario'] ?? 'Sistema') . '</td>'
                . "<td><span class='tag $tipo'>" . $e(self::ACCIONES[$a['accion']] ?? $a['accion']) . '</span></td>'
                . '<td>' . $e(self::MODULOS[$a['entidad']] ?? $a['entidad']) . '</td><td>' . $e($this->registro($a)) . '</td>'
                . '<td>' . $e($this->descripcion($a)) . '</td><td>' . $e($a['valor_anterior'] ?? '—') . '</td><td>' . $e($a['valor_nuevo'] ?? '—') . '</td></tr>';
        }
        if (!$filas) $tr = "<tr><td colspan='8' class='mute'>No hay registros para los filtros elegidos.</td></tr>";
        if (count($filas) > self::MAX_PDF) {
            $tr .= "<tr><td colspan='8' class='mute'>Se muestran " . self::MAX_PDF . ' de ' . count($filas)
                . ' registros. El detalle completo está en la exportación a Excel.</td></tr>';
        }

        return "<!doctype html><html><head><meta charset='utf-8'><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1c1c1c; }
            .cab { width: 100%; border-bottom: 2px solid #5b3fb0; margin-bottom: 8px; }
            .logo { height: 26px; } h1 { font-size: 16px; margin: 0; } .sub { color: #666; font-size: 9px; }
            .filtros { color: #444; margin: 4px 0 8px; }
            table.k { width: 100%; border-collapse: separate; border-spacing: 4px; margin-bottom: 8px; }
            .kpi { border: 1px solid #ddd; border-radius: 4px; padding: 5px; }
            .kt { color: #666; font-size: 8px; text-transform: uppercase; } .kv { font-size: 14px; font-weight: bold; }
            table.d { width: 100%; border-collapse: collapse; }
            table.d th { background: #f0f0f0; text-align: left; padding: 4px; border-bottom: 1px solid #ccc; font-size: 8px; text-transform: uppercase; }
            table.d td { padding: 4px; border-bottom: 1px solid #e6e6e6; vertical-align: top; }
            .tag { padding: 1px 4px; border-radius: 3px; font-size: 8px; white-space: nowrap; }
            .alta { background: #e3f4ea; color: #1d6b3f; } .cambio { background: #e6eefb; color: #24508f; }
            .baja { background: #fbe6e6; color: #9b2222; } .stock { background: #fbf0dc; color: #8a5a00; }
            .sesion { background: #efefef; color: #444; } .otro { background: #efefef; color: #444; }
            .mute { color: #888; text-align: center; padding: 10px; }
            .pie { margin-top: 8px; color: #888; font-size: 8px; }
        </style></head><body>
            <table class='cab'><tr><td>$logo</td><td style='text-align:right'><h1>Auditoría del sistema</h1>
                <div class='sub'>Generado el " . date('d/m/Y H:i') . ' por ' . $e(Session::usuario()['nombre'] ?? 'admin') . "</div></td></tr></table>
            <div class='filtros'><b>Filtros:</b> " . $e($this->filtrosTexto($f)) . "</div>
            <table class='k'><tr>$kpis</tr></table>
            <table class='d'><tr><th>Fecha y hora</th><th>Usuario</th><th>Acción</th><th>Módulo</th><th>Registro</th>
                <th>Descripción</th><th>Valor anterior</th><th>Valor nuevo</th></tr>$tr</table>
            <div class='pie'>Registro de solo lectura: el historial de auditoría no se puede modificar ni borrar.</div>
        </body></html>";
    }
}

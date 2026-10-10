<?php

namespace App\Services;

use App\Core\Database;
use App\Core\ValidacionException;
use App\Repositories\ProductoRepository;

/**
 * Actualización masiva de precios: aplica un porcentaje (+/-) a todos los
 * productos de una marca, una categoría o a todo el catálogo, con redondeo.
 * previsualizar() muestra el impacto; aplicar() lo guarda (transaccional) y lo
 * audita precio por precio.
 */
class PrecioMasivoService
{
    private const LISTAS = ['minorista' => 1, 'mayorista' => 2];

    private ProductoRepository $repo;

    public function __construct()
    {
        $this->repo = new ProductoRepository();
    }

    /** Devuelve el impacto del cambio sin guardarlo. */
    public function previsualizar(array $in): array
    {
        [$tipo, $refId, $pct, $listas, $redondeo] = $this->parametros($in);
        $items = [];
        foreach ($listas as $nombreLista => $listaId) {
            foreach ($this->repo->preciosDeGrupo($tipo, $refId, $listaId) as $p) {
                $actual = (float) $p['precio'];
                $nuevo  = $this->redondear($actual * (1 + $pct / 100), $redondeo);
                $items[] = [
                    'id' => (int) $p['id'], 'nombre' => $p['nombre'], 'lista' => $nombreLista,
                    'actual' => round($actual, 2), 'nuevo' => $nuevo,
                ];
            }
        }
        return ['resumen' => ['productos' => count($items), 'pct' => $pct, 'redondeo' => $redondeo], 'items' => $items];
    }

    /** Aplica el cambio en una transacción y lo audita. Devuelve cuántos precios cambió. */
    public function aplicar(array $in, int $usuarioId): array
    {
        [$tipo, $refId, $pct, $listas, $redondeo] = $this->parametros($in);
        $pdo = Database::conexion();
        $cambiados = 0;
        try {
            $pdo->beginTransaction();
            foreach ($listas as $nombreLista => $listaId) {
                $campo = 'precio_' . $nombreLista;
                foreach ($this->repo->preciosDeGrupo($tipo, $refId, $listaId) as $p) {
                    $actual = (float) $p['precio'];
                    $nuevo  = $this->redondear($actual * (1 + $pct / 100), $redondeo);
                    if (round($actual, 2) === $nuevo) continue;   // sin cambio real
                    $this->repo->fijarPrecio((int) $p['id'], $listaId, $nuevo);
                    Auditoria::campo('producto', (int) $p['id'], $campo, $actual, $nuevo, 'precio_masivo', $usuarioId);
                    $cambiados++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['cambiados' => $cambiados];
    }

    /** Valida y normaliza la entrada. */
    private function parametros(array $in): array
    {
        $tipo = $in['tipo'] ?? '';
        if (!in_array($tipo, ['marca', 'categoria', 'todos'], true)) {
            throw new ValidacionException('Elegí marca, categoría o todo el catálogo.');
        }
        $refId = ($tipo === 'todos') ? null : ((int) ($in['ref_id'] ?? 0) ?: null);
        if ($tipo !== 'todos' && $refId === null) {
            throw new ValidacionException('Elegí la ' . $tipo . '.');
        }
        $pct = $in['pct'] ?? '';
        if (!is_numeric($pct) || (float) $pct == 0.0) {
            throw new ValidacionException('El porcentaje debe ser un número distinto de 0 (ej: 10 o -5).');
        }
        if ((float) $pct < -90 || (float) $pct > 500) {
            throw new ValidacionException('El porcentaje debe estar entre -90% y 500%.');
        }
        $lista = $in['lista'] ?? 'ambas';
        $listas = $lista === 'ambas' ? self::LISTAS : array_intersect_key(self::LISTAS, [$lista => 1]);
        if (!$listas) { throw new ValidacionException('Lista de precios inválida.'); }
        $redondeo = in_array($in['redondeo'] ?? '', ['ninguno', 'entero', 'decena', 'centena'], true)
            ? $in['redondeo'] : 'ninguno';
        return [$tipo, $refId, (float) $pct, $listas, $redondeo];
    }

    private function redondear(float $v, string $modo): float
    {
        return match ($modo) {
            'entero'  => (float) round($v),
            'decena'  => (float) (round($v / 10) * 10),
            'centena' => (float) (round($v / 100) * 100),
            default   => round($v, 2),
        };
    }
}

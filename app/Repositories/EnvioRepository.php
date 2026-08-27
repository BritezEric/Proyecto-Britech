<?php

namespace App\Repositories;

use App\Core\Database;

/** Envío de un pedido: datos de entrega completos, costo, estado y seguimiento. */
class EnvioRepository
{
    /** Campos de entrega que se cargan en el checkout y edita el gestor. */
    private const CAMPOS = ['destinatario', 'telefono', 'direccion', 'numero',
        'referencia', 'localidad', 'provincia', 'cp'];

    /**
     * Crea el envío de un pedido (tienda) O de una venta (POS): pasar uno u otro.
     * $datos trae los campos de CAMPOS (los que falten van null).
     */
    public function crear(?int $pedidoId, ?int $empresaId, array $datos, float $costo, ?int $barrioId = null, ?int $ventaId = null): void
    {
        $cols = array_merge(['pedido_id', 'venta_id', 'empresa_envio_id', 'barrio_id'], self::CAMPOS, ['costo']);
        $vals = array_merge(
            [$pedidoId, $ventaId, $empresaId, $barrioId],
            array_map(fn($c) => $datos[$c] ?? null, self::CAMPOS),
            [$costo]
        );
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        Database::conexion()
            ->prepare('INSERT INTO envio (' . implode(', ', $cols) . ") VALUES ($ph)")
            ->execute($vals);
    }

    /** Deriva (asigna) un envío a un repartidor. $repartidorId null = sin asignar. */
    public function derivar(int $envioId, ?int $repartidorId): void
    {
        Database::conexion()
            ->prepare("UPDATE envio SET repartidor_id = ? WHERE id = ?")
            ->execute([$repartidorId, $envioId]);
    }

    /** Cambia el estado de un envío por su id (sirve para pedido o venta). */
    public function cambiarEstadoPorId(int $envioId, string $estado): void
    {
        Database::conexion()
            ->prepare("UPDATE envio SET estado = ? WHERE id = ?")
            ->execute([$estado, $envioId]);
    }

    public function buscarPorId(int $envioId): ?array
    {
        $st = Database::conexion()->prepare("SELECT * FROM envio WHERE id = ?");
        $st->execute([$envioId]);
        return $st->fetch() ?: null;
    }

    /** Datos del envío de un pedido: todo + nombre de empresa + link de seguimiento armado. */
    public function dePedido(int $pedidoId): ?array
    {
        $st = Database::conexion()->prepare(
            "SELECT e.*, em.nombre AS empresa, em.url_tracking, em.es_retiro, em.es_moto,
                    b.nombre AS barrio, b.costo AS barrio_costo,
                    r.nombre AS repartidor
             FROM envio e
             LEFT JOIN empresa_envio em ON em.id = e.empresa_envio_id
             LEFT JOIN barrio b         ON b.id  = e.barrio_id
             LEFT JOIN repartidor r     ON r.id  = e.repartidor_id
             WHERE e.pedido_id = ?"
        );
        $st->execute([$pedidoId]);
        $e = $st->fetch();
        if (!$e) return null;
        // Link público de seguimiento: reemplaza {tracking} por el nº de seguimiento.
        $e['seguimiento_url'] = ($e['tracking'] && $e['url_tracking'])
            ? str_replace('{tracking}', rawurlencode($e['tracking']), $e['url_tracking'])
            : null;
        return $e;
    }

    /**
     * Actualiza estado + seguimiento + datos de entrega (gestor admin).
     * Si $repartidorId no es false, también reasigna el repartidor (null = sin asignar).
     */
    public function actualizarDatos(int $pedidoId, string $estado, ?string $tracking, array $datos, int|null|false $repartidorId = false): void
    {
        $sets = ['estado = ?', 'tracking = ?'];
        $vals = [$estado, $tracking];
        foreach (self::CAMPOS as $c) {
            if (array_key_exists($c, $datos)) { $sets[] = "$c = ?"; $vals[] = $datos[$c]; }
        }
        if ($repartidorId !== false) { $sets[] = 'repartidor_id = ?'; $vals[] = $repartidorId; }
        $vals[] = $pedidoId;
        Database::conexion()
            ->prepare('UPDATE envio SET ' . implode(', ', $sets) . ' WHERE pedido_id = ?')
            ->execute($vals);
    }
}

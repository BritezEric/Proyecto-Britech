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
    public function crear(?int $pedidoId, ?int $empresaId, array $datos, float $costo, ?int $barrioId = null, ?int $ventaId = null): int
    {
        $cols = array_merge(['pedido_id', 'venta_id', 'empresa_envio_id', 'barrio_id'], self::CAMPOS, ['costo', 'token']);
        $vals = array_merge(
            [$pedidoId, $ventaId, $empresaId, $barrioId],
            array_map(fn($c) => $datos[$c] ?? null, self::CAMPOS),
            [$costo, bin2hex(random_bytes(16))]   // token aleatorio para el link público de seguimiento
        );
        $ph = implode(', ', array_fill(0, count($cols), '?'));
        $pdo = Database::conexion();
        $pdo->prepare('INSERT INTO envio (' . implode(', ', $cols) . ") VALUES ($ph)")->execute($vals);
        return (int) $pdo->lastInsertId();
    }

    /** Fija las coordenadas de destino del envío de un pedido (geocodificación o pin manual). */
    public function fijarDestino(int $pedidoId, float $lat, float $lng): void
    {
        Database::conexion()
            ->prepare("UPDATE envio SET dest_lat = ?, dest_lng = ? WHERE pedido_id = ?")
            ->execute([$lat, $lng, $pedidoId]);
    }

    /** Fija el destino por id de envío (corrección manual del admin desde el mapa). */
    public function fijarDestinoPorId(int $envioId, float $lat, float $lng): void
    {
        Database::conexion()
            ->prepare("UPDATE envio SET dest_lat = ?, dest_lng = ? WHERE id = ?")
            ->execute([$lat, $lng, $envioId]);
    }

    /** Deriva (asigna) un envío a un repartidor. $repartidorId null = sin asignar. */
    public function derivar(int $envioId, ?int $repartidorId): void
    {
        if (self::tieneAsignadoEn()) {
            // asignado_en va ANTES que repartidor_id: MySQL evalúa el SET en orden.
            Database::conexion()
                ->prepare("UPDATE envio SET " . self::SET_ASIGNADO . ", repartidor_id = ? WHERE id = ?")
                ->execute([$repartidorId, $repartidorId, $envioId]);
            return;
        }
        Database::conexion()
            ->prepare("UPDATE envio SET repartidor_id = ? WHERE id = ?")
            ->execute([$repartidorId, $envioId]);
    }

    /**
     * Momento de la PRIMERA asignación (para medir cuánto esperó el envío):
     * se fija al pasar de "sin repartidor" a uno; si se reasigna se conserva,
     * y si se desasigna vuelve a NULL. Usa un parámetro: el repartidor nuevo.
     */
    private const SET_ASIGNADO = "asignado_en = CASE WHEN ? IS NULL THEN NULL
                                                    WHEN repartidor_id IS NULL THEN NOW()
                                                    ELSE asignado_en END";

    /** La columna asignado_en la agrega schema_reportes_operativos.sql: sin él, todo sigue andando. */
    private static function tieneAsignadoEn(): bool
    {
        static $tiene = null;
        if ($tiene === null) {
            $tiene = (bool) Database::conexion()->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'envio' AND COLUMN_NAME = 'asignado_en'"
            )->fetchColumn();
        }
        return $tiene;
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
        if ($repartidorId !== false) {
            if (self::tieneAsignadoEn()) { $sets[] = self::SET_ASIGNADO; $vals[] = $repartidorId; }
            $sets[] = 'repartidor_id = ?'; $vals[] = $repartidorId;
        }
        $vals[] = $pedidoId;
        Database::conexion()
            ->prepare('UPDATE envio SET ' . implode(', ', $sets) . ' WHERE pedido_id = ?')
            ->execute($vals);
    }
}

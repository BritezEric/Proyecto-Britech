<?php

namespace App\Services;

use App\Core\Database;
use App\Core\ValidacionException;
use App\Repositories\ProductoRepository;
use App\Repositories\InventarioRepository;

/**
 * Lógica + validaciones del ABM de Productos.
 * Guardar un producto toca 3 tablas (producto, precio, inventario), así que va
 * en una TRANSACCIÓN: o se guarda todo, o nada. El stock inicial y los ajustes
 * quedan registrados en el libro mayor (movimiento_inventario).
 */
class ProductoService
{
    private ProductoRepository $repo;
    private InventarioRepository $inv;

    public function __construct()
    {
        $this->repo = new ProductoRepository();
        $this->inv  = new InventarioRepository();
    }

    public function listar(?string $q, array $filtros, int $limit, int $offset): array
    {
        return $this->repo->listarPaginado($q, $filtros, $limit, $offset);
    }

    public function detalle(int $id): ?array
    {
        return $this->repo->buscarCompleto($id);
    }

    public function guardar(array $in, int $usuarioId): int
    {
        $d = $this->validar($in);
        $id = (int) ($in['id'] ?? 0);
        $editando = $id > 0;

        if ($editando && $this->repo->buscarCompleto($id) === null) {
            throw new ValidacionException('El producto no existe.');
        }
        if ($this->repo->skuOcodigoEnUso($d['sku'], $d['codigo_barras'], $id)) {
            throw new ValidacionException('El SKU o el código de barras ya está en uso por otro producto.');
        }

        $pdo = Database::conexion();
        try {
            $pdo->beginTransaction();

            if ($editando) {
                $this->repo->actualizar($id, $d);
            } else {
                $id = $this->repo->crear($d);
            }

            // Precios (minorista siempre; mayorista si vino)
            $this->repo->fijarPrecio($id, 1, $d['precio_minorista']);
            if ($d['precio_mayorista'] !== null) {
                $this->repo->fijarPrecio($id, 2, $d['precio_mayorista']);
            }

            // Imágenes (galería de la ficha): reemplaza la lista completa.
            $this->repo->reemplazarImagenes($id, $d['imagenes']);

            // Stock: fijar cantidad y registrar el movimiento (libro mayor)
            $anterior = $editando ? $this->inv->cantidadDe($id) : 0;
            $nuevo    = $d['stock'];
            if (!$editando) {
                $this->inv->establecer($id, $nuevo);
                if ($nuevo > 0) {
                    $this->inv->registrarMovimiento($id, 'ingreso', $nuevo, 'Stock inicial', null, $usuarioId);
                }
            } elseif ($nuevo !== $anterior) {
                $this->inv->establecer($id, $nuevo);
                $delta = abs($nuevo - $anterior);
                $tipo  = $nuevo > $anterior ? 'ingreso' : 'egreso';
                $this->inv->registrarMovimiento($id, $tipo, $delta, 'Ajuste de stock (ABM)', null, $usuarioId);
            }

            $pdo->commit();
        } catch (ValidacionException $e) {
            $pdo->rollBack();
            throw $e;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    public function baja(int $id): void
    {
        if ($this->repo->buscarCompleto($id) === null) {
            throw new ValidacionException('El producto no existe.');
        }
        // baja lógica (no borramos: hay ventas que lo referencian)
        $this->repo->actualizar($id, $this->soloEstado($id, 0));
    }

    /** Arma el array de actualización conservando los datos actuales y cambiando activo. */
    private function soloEstado(int $id, int $activo): array
    {
        $p = $this->repo->buscarCompleto($id);
        return [
            'sku' => $p['sku'], 'codigo_barras' => $p['codigo_barras'], 'nombre' => $p['nombre'],
            'descripcion' => $p['descripcion'], 'categoria_id' => $p['categoria_id'],
            'marca_id' => $p['marca_id'], 'proveedor_id' => $p['proveedor_id'],
            'es_sobre_pedido' => $p['es_sobre_pedido'], 'min_mayorista' => $p['min_mayorista'],
            'precio_anterior' => $p['precio_anterior'], 'activo' => $activo,
        ];
    }

    private function validar(array $in): array
    {
        $nombre = trim($in['nombre'] ?? '');
        if (mb_strlen($nombre) < 2) {
            throw new ValidacionException('El nombre del producto es obligatorio (mínimo 2 caracteres).');
        }

        $precioMin = $in['precio_minorista'] ?? '';
        if ($precioMin === '' || !is_numeric($precioMin) || (float) $precioMin < 0) {
            throw new ValidacionException('El precio minorista es obligatorio y debe ser un número ≥ 0.');
        }
        $precioMay = $in['precio_mayorista'] ?? '';
        if ($precioMay !== '' && (!is_numeric($precioMay) || (float) $precioMay < 0)) {
            throw new ValidacionException('El precio mayorista debe ser un número ≥ 0.');
        }

        $stock = $in['stock'] ?? 0;
        if (!is_numeric($stock) || (int) $stock < 0) {
            throw new ValidacionException('El stock debe ser un número entero ≥ 0.');
        }

        $min = $in['min_mayorista'] ?? 1;
        if (!is_numeric($min) || (int) $min < 1) {
            throw new ValidacionException('La cantidad mínima mayorista debe ser un entero ≥ 1.');
        }

        // Precio anterior (oferta): opcional. Si viene, debe ser > que el precio actual.
        $precioAnt = $in['precio_anterior'] ?? '';
        if ($precioAnt !== '' && (!is_numeric($precioAnt) || (float) $precioAnt < 0)) {
            throw new ValidacionException('El precio anterior debe ser un número ≥ 0.');
        }
        if ($precioAnt !== '' && (float) $precioAnt > 0 && (float) $precioAnt <= (float) $precioMin) {
            throw new ValidacionException('El precio anterior debe ser mayor al precio minorista (para que sea una oferta).');
        }

        // Imágenes: una URL por línea (deben ser http/https).
        $imagenes = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) ($in['imagenes'] ?? '')) as $u) {
            $u = trim($u);
            if ($u === '') continue;
            // Acepta URLs externas (http/https) o imágenes subidas al propio sitio (/uploads/...).
            if (!preg_match('#^(https?://|/)#i', $u)) {
                throw new ValidacionException('Cada imagen debe ser una URL (http/https) o una imagen subida.');
            }
            $imagenes[] = mb_substr($u, 0, 500);
        }

        return [
            'sku'             => trim($in['sku'] ?? '') ?: null,
            'codigo_barras'   => trim($in['codigo_barras'] ?? '') ?: null,
            'nombre'          => $nombre,
            'descripcion'     => trim($in['descripcion'] ?? '') ?: null,
            'categoria_id'    => (int) ($in['categoria_id'] ?? 0) ?: null,
            'marca_id'        => (int) ($in['marca_id'] ?? 0) ?: null,
            'proveedor_id'    => (int) ($in['proveedor_id'] ?? 0) ?: null,
            'es_sobre_pedido' => isset($in['es_sobre_pedido']) ? (int) (bool) $in['es_sobre_pedido'] : 0,
            'min_mayorista'   => (int) $min,
            'precio_anterior' => ($precioAnt === '' || (float) $precioAnt <= 0) ? null : round((float) $precioAnt, 2),
            'activo'          => isset($in['activo']) ? (int) (bool) $in['activo'] : 1,
            'precio_minorista'=> round((float) $precioMin, 2),
            'precio_mayorista'=> $precioMay === '' ? null : round((float) $precioMay, 2),
            'stock'           => (int) $stock,
            'imagenes'        => $imagenes,
        ];
    }
}

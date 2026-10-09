# Modelo de datos — Britech (`britech_v2`)

> Base MySQL 8.4, motor **InnoDB**, diseño normalizado a **3FN**, integridad
> referencial por claves foráneas. **42 tablas**, creadas por **27 scripts SQL**
> que se importan en orden (ver `README.md`).

## Tablas por área

### Seguridad y usuarios
| Tabla | Para qué |
|---|---|
| `rol` | Roles del staff (admin, vendedor) |
| `usuario` | Staff que inicia sesión (email, hash, rol) |
| `usuario_token` | Tokens de verificación/recuperación del staff |
| `intento_acceso` | Registro de intentos para rate limiting |

### Clientes
| Tabla | Para qué |
|---|---|
| `cliente` | Entidad comercial / cuenta de la tienda (con `password_hash`) |
| `cliente_token` | Tokens de activación/recuperación del cliente |
| `lista_precio` | Listas de precio (minorista, mayorista) |

### Catálogo y tablas maestras
| Tabla | Para qué |
|---|---|
| `producto` | Producto (nombre, costo, stock mínimo, etc.) |
| `precio` | Precio del producto por lista |
| `producto_imagen` | Imágenes del producto |
| `categoria` | Categoría (+ imagen y orden para la home) |
| `marca` | Marca (+ logo) |
| `proveedor` | Proveedor |

### Inventario
| Tabla | Para qué |
|---|---|
| `inventario` | Stock actual por producto (pool único) |
| `movimiento_inventario` | Libro mayor de movimientos (ingresos/egresos) |

### Ventas (POS)
| Tabla | Para qué |
|---|---|
| `venta` | Venta del local (ligada a una caja) |
| `venta_detalle` | Líneas de la venta (precio congelado) |
| `venta_anulacion` | Registro de anulaciones (motivo, usuario) |
| `tipo_pago` | Medios del POS (Efectivo, Transferencia) |
| `pago` | Pago de una venta (tipo + monto) |
| `comprobante` | Comprobante/ticket interno de la venta |

### Tienda y pedidos
| Tabla | Para qué |
|---|---|
| `pedido` | Pedido online (estado, estado_pago, metodo_pago, comprobante_url, mp_payment_id) |
| `pedido_detalle` | Líneas del pedido |
| `config_tienda` | Config de la tienda (alias/CBU para transferencia) |
| `favorito` | Favoritos del cliente |
| `solicitud_mayorista` | Solicitudes de acceso mayorista |

### Envíos (Moto Express)
| Tabla | Para qué |
|---|---|
| `empresa_envio` | Medios de envío (correo, retiro, moto) |
| `envio` | Envío de un pedido **o** de una venta |
| `barrio` | Zonas de Moto Express (costo por zona) |
| `repartidor` | Repartidores (ABM, sin login) |

### Compras
| Tabla | Para qué |
|---|---|
| `orden_compra` | Orden de compra a proveedor (estado) |
| `orden_compra_detalle` | Líneas de la orden (con cantidad recibida) |
| `reposicion_pedido` | Historial de pedidos de reposición |

### Caja
| Tabla | Para qué |
|---|---|
| `caja` | Caja del vendedor (apertura, arqueo, cierre) |
| `caja_movimiento` | Retiros e ingresos de la caja |

### Reclamos
| Tabla | Para qué |
|---|---|
| `reclamo` | Reclamo del cliente sobre un pedido (estado) |
| `reclamo_mensaje` | Hilo de mensajes del reclamo (cliente/staff) |

### Notificaciones y finanzas
| Tabla | Para qué |
|---|---|
| `notificacion` | Aviso por destinatario (usuario_id o cliente_id) |
| `gasto` | Gastos del negocio (incluye sueldos etiquetados) |

### Home builder
| Tabla | Para qué |
|---|---|
| `bloque` | Bloques de la home (tipo + config JSON) |
| `bloque_slide` | Slides del hero |

## Decisiones de modelado
- **Venta ≠ Pedido**: la venta (POS) descuenta stock; el pedido (tienda) es una
  solicitud a gestionar y no descuenta stock.
- **Stock**: pool único (`inventario`) + libro mayor (`movimiento_inventario`).
- **Dinero en DECIMAL**, nunca FLOAT; precio **congelado** en `venta_detalle`.
- **Precio por cliente** vía `lista_precio_id`.
- **Pago del pedido online**: en columnas del propio `pedido`. **Pago del POS**: en `pago`.
- **Notificaciones**: una fila por destinatario (leída por persona).

## Scripts SQL (orden de importación)
Los 27 scripts y su orden exacto están en `README.md`. Resumen:
`schema_ventas` (base) → `auth` → `datos_maestros` → `min_mayorista` → `imagenes` →
`tienda` → `pagos` → `envios` → `envios_datos` → `favoritos` → `mayorista` →
`gastos` → `empleados` → `bloques` → `marca_logo` → `metodo_pago` → `moto_barrios` →
`envio_venta` → `notificaciones` → `cliente_perfil` → `reposicion` →
`reposicion_pedidos` → `compras` → `caja` → `reclamos` → `mercadopago` →
`notificaciones_v2`.

# Módulo Ventas — Diseño de la base de datos

> Estado: **APROBADO E IMPLEMENTADO** (2026-08-19).
> SQL: `database/schema_ventas.sql` — importado y probado en MySQL 8.4.
> Base de datos: **`britech_v2`** (nueva, no toca los proyectos viejos).
>
> **Importar / reimportar:**
> ```
> mysql -u root < database/schema_ventas.sql
> ```
> Probado: la resolución de precio por lista (minorista/mayorista) y el stock funcionan.

---

## 0. Conceptos que vas a ver acá (mini-glosario)

Antes de las tablas, para que puedas *leer* el diseño:

- **PK (Primary Key / clave primaria):** columna que identifica **de forma única**
  cada fila. Casi siempre un `id` numérico que se autoincrementa.
- **FK (Foreign Key / clave foránea):** columna que **apunta a la PK de otra tabla**.
  Es lo que conecta las tablas. Ej: `venta.cliente_id` apunta a `cliente.id`.
- **UNIQUE:** no se puede repetir ese valor (ej: dos productos no pueden tener el
  mismo código de barras).
- **NOT NULL:** ese dato es obligatorio, no puede quedar vacío.
- **DECIMAL(12,2):** tipo para **dinero** (12 dígitos, 2 decimales). ⚠️ **Nunca**
  usamos `FLOAT` para plata porque redondea mal (0.1 + 0.2 ≠ 0.3). Regla de oro.
- **ENUM('a','b'):** columna que solo acepta valores de una lista fija.
- **DATETIME:** fecha + hora.
- **Índice:** una "guía telefónica" interna para que las búsquedas sean rápidas.
  Las PK y FK ya llevan índice; agregamos índices donde busquemos seguido.
- **3FN (Tercera Forma Normal):** el dato vive en **un solo lugar**. El precio no
  se copia en cada producto; el nombre del cliente no se repite en cada venta.
  Se referencia con FK. Así no hay datos duplicados ni contradictorios.

---

## 1. Mapa de tablas (14)

```
Accesos:     rol · usuario
Comercial:   cliente · producto · lista_precio · precio
Inventario:  inventario · movimiento_inventario
Venta:       venta · venta_detalle · tipo_pago · pago · comprobante · venta_anulacion
```

---

## 2. Tablas explicadas

### `rol`
Los roles del sistema. Para Ventas solo necesitamos distinguir admin de vendedor.

| Columna | Tipo | Notas |
|---|---|---|
| id | INT PK AI | |
| nombre | VARCHAR(50) UNIQUE NOT NULL | 'admin', 'vendedor' |

> **Decisión (simplicidad):** por ahora un usuario tiene **un** rol (columna
> `rol_id` en `usuario`). El sistema completo de **permisos** (RBAC, "ventas.crear")
> lo sumamos con el módulo de Usuarios. Todavía no lo necesitamos para vender.

### `usuario`
Quién entra al sistema (vendedor o admin).

| Columna | Tipo | Notas |
|---|---|---|
| id | INT PK AI | |
| nombre | VARCHAR(100) NOT NULL | nombre visible del vendedor |
| email | VARCHAR(150) UNIQUE NOT NULL | login |
| password_hash | VARCHAR(255) NOT NULL | hash, **nunca** la contraseña en texto |
| rol_id | INT FK → rol.id NOT NULL | |
| activo | TINYINT(1) NOT NULL DEFAULT 1 | 1 = puede entrar |
| creado_en | DATETIME NOT NULL | |

### `cliente`
A quién se le vende. "Consumidor Final" es simplemente un cliente por defecto.

| Columna | Tipo | Notas |
|---|---|---|
| id | INT PK AI | |
| nombre | VARCHAR(150) NOT NULL | |
| documento | VARCHAR(20) NULL | DNI/CUIT (opcional por ahora) |
| lista_precio_id | INT FK → lista_precio.id NOT NULL | **qué precios ve** (minorista/mayorista) |
| activo | TINYINT(1) NOT NULL DEFAULT 1 | |
| creado_en | DATETIME NOT NULL | |

> **Cómo se resuelve el precio (importante):** el cliente **no** guarda "minorista/
> mayorista" como texto suelto. Guarda `lista_precio_id`, que apunta a su lista de
> precios. Un mayorista aprobado = cliente con `lista_precio_id` = Mayorista. Esto
> es 3FN: el "tipo" se representa con una FK, no duplicando textos.

### `producto`
Qué se vende. **No tiene precio** (el precio vive en `precio`).

| Columna | Tipo | Notas |
|---|---|---|
| id | INT PK AI | |
| sku | VARCHAR(50) UNIQUE NULL | código interno |
| codigo_barras | VARCHAR(50) UNIQUE NULL | EAN/UPC, para el scanner |
| nombre | VARCHAR(150) NOT NULL | |
| es_sobre_pedido | TINYINT(1) NOT NULL DEFAULT 0 | 1 = se puede vender sin stock |
| activo | TINYINT(1) NOT NULL DEFAULT 1 | |
| creado_en | DATETIME NOT NULL | |

### `lista_precio`
Las listas de precios. Filas iniciales: **Minorista** y **Mayorista**.

| Columna | Tipo | Notas |
|---|---|---|
| id | INT PK AI | |
| nombre | VARCHAR(50) UNIQUE NOT NULL | 'Minorista', 'Mayorista' |
| activo | TINYINT(1) NOT NULL DEFAULT 1 | |

### `precio`
El precio de **un producto** en **una lista**. Un producto tiene un precio por lista.

| Columna | Tipo | Notas |
|---|---|---|
| id | INT PK AI | |
| producto_id | INT FK → producto.id NOT NULL | |
| lista_precio_id | INT FK → lista_precio.id NOT NULL | |
| precio | DECIMAL(12,2) NOT NULL | dinero |
| **UNIQUE(producto_id, lista_precio_id)** | | un solo precio vigente por combinación |

> **Precios históricos:** por ahora hay **un** precio por (producto, lista). Cuando
> queramos guardar el historial de cambios, agregamos fechas de vigencia. Todavía
> no lo necesitamos. La venta igual **congela** el precio en `venta_detalle`, así
> que las ventas viejas nunca se alteran.

### `inventario`
La **existencia actual** de cada producto (el número rápido de consultar).

| Columna | Tipo | Notas |
|---|---|---|
| producto_id | INT PK, FK → producto.id | 1 fila por producto |
| cantidad | INT NOT NULL DEFAULT 0 | stock actual |
| actualizado_en | DATETIME NOT NULL | |

### `movimiento_inventario`
El **historial** de todo lo que entra y sale (libro mayor de stock).

| Columna | Tipo | Notas |
|---|---|---|
| id | INT PK AI | |
| producto_id | INT FK → producto.id NOT NULL | |
| tipo | ENUM('ingreso','egreso','ajuste') NOT NULL | |
| cantidad | INT NOT NULL | magnitud (siempre positiva) |
| motivo | VARCHAR(150) NULL | 'venta #123', 'anulación #123' |
| venta_id | INT FK → venta.id NULL | de qué venta salió (si aplica) |
| usuario_id | INT FK → usuario.id NOT NULL | quién lo generó |
| creado_en | DATETIME NOT NULL | |

> **Cómo conviven `inventario` y `movimiento_inventario`:** `inventario.cantidad` es
> el número actual (rápido). `movimiento_inventario` es la historia (auditoría). Se
> actualizan **juntos, dentro de la misma transacción**, en el `VentaService`. El
> movimiento explica *por qué* cambió el stock; el inventario dice *cuánto hay hoy*.

### `tipo_pago`
Catálogo de medios de pago. Filas iniciales: **Efectivo**, **Transferencia**.
Agregar tarjetas después = agregar filas, sin tocar código.

| Columna | Tipo | Notas |
|---|---|---|
| id | INT PK AI | |
| nombre | VARCHAR(50) UNIQUE NOT NULL | |
| activo | TINYINT(1) NOT NULL DEFAULT 1 | |

### `venta`
La cabecera de la venta.

| Columna | Tipo | Notas |
|---|---|---|
| id | INT PK AI | |
| numero | VARCHAR(20) UNIQUE NOT NULL | número de venta legible |
| cliente_id | INT FK → cliente.id NOT NULL | |
| usuario_id | INT FK → usuario.id NOT NULL | el vendedor |
| subtotal | DECIMAL(12,2) NOT NULL | suma de líneas antes del descuento total |
| descuento | DECIMAL(12,2) NOT NULL DEFAULT 0 | descuento **sobre el total** |
| total | DECIMAL(12,2) NOT NULL | subtotal − descuento |
| estado | ENUM('registrada','anulada') NOT NULL DEFAULT 'registrada' | |
| creado_en | DATETIME NOT NULL | |

### `venta_detalle`
Cada línea del carrito. Acá se **congela** el precio.

| Columna | Tipo | Notas |
|---|---|---|
| id | INT PK AI | |
| venta_id | INT FK → venta.id NOT NULL | |
| producto_id | INT FK → producto.id NOT NULL | |
| cantidad | INT NOT NULL | > 0 |
| precio_unitario | DECIMAL(12,2) NOT NULL | **foto** del precio al vender |
| descuento_linea | DECIMAL(12,2) NOT NULL DEFAULT 0 | descuento **de esta línea** |
| estado | ENUM('normal','sobre_pedido') NOT NULL DEFAULT 'normal' | |
| subtotal | DECIMAL(12,2) NOT NULL | cantidad × precio_unitario − descuento_linea |

### `pago`
Cómo se pagó. Puede haber varios pagos por venta (pago mixto).

| Columna | Tipo | Notas |
|---|---|---|
| id | INT PK AI | |
| venta_id | INT FK → venta.id NOT NULL | |
| tipo_pago_id | INT FK → tipo_pago.id NOT NULL | |
| monto | DECIMAL(12,2) NOT NULL | |

> **Regla:** la suma de `pago.monto` de una venta debe igualar `venta.total`.
> Esto se valida en el código (PHP), no en la tabla.

### `comprobante`
El ticket. Ya trae los campos de AFIP, **vacíos por ahora**.

| Columna | Tipo | Notas |
|---|---|---|
| id | INT PK AI | |
| venta_id | INT FK → venta.id UNIQUE NOT NULL | 1 comprobante por venta |
| tipo | ENUM('ticket_interno','factura_a','factura_b','factura_c') NOT NULL DEFAULT 'ticket_interno' | |
| numero | VARCHAR(30) NOT NULL | |
| punto_venta | VARCHAR(10) NULL | AFIP (después) |
| cae | VARCHAR(20) NULL | AFIP (después) |
| cae_vencimiento | DATE NULL | AFIP (después) |
| fecha | DATETIME NOT NULL | |

### `venta_anulacion`
Registro de anulaciones (una por venta anulada).

| Columna | Tipo | Notas |
|---|---|---|
| id | INT PK AI | |
| venta_id | INT FK → venta.id UNIQUE NOT NULL | qué venta se anuló |
| usuario_id | INT FK → usuario.id NOT NULL | el admin que anuló |
| motivo | VARCHAR(255) NOT NULL | obligatorio |
| creado_en | DATETIME NOT NULL | |

---

## 3. Relaciones (cardinalidades)

```
rol            1 ──< usuario
usuario        1 ──< venta            (un vendedor hace muchas ventas)
cliente        1 ──< venta
lista_precio   1 ──< cliente
lista_precio   1 ──< precio
producto       1 ──< precio
producto       1 ──1 inventario
producto       1 ──< movimiento_inventario
producto       1 ──< venta_detalle
venta          1 ──< venta_detalle    (una venta, muchas líneas)
venta          1 ──< pago
venta          1 ──1 comprobante
venta          1 ──1 venta_anulacion  (solo si se anuló)
venta          1 ──< movimiento_inventario
tipo_pago      1 ──< pago
```

*(`1 ──< X` = uno a muchos; `1 ──1` = uno a uno.)*

---

## 4. Datos iniciales (semilla) que va a necesitar

Para poder probar una venta, la base arranca con:
- `rol`: admin, vendedor.
- 1 `usuario` admin (para entrar).
- `lista_precio`: Minorista, Mayorista.
- 1 `cliente`: "Consumidor Final" (lista Minorista).
- `tipo_pago`: Efectivo, Transferencia.
- Algunos `producto` + `precio` + `inventario` de prueba.

---

## 5. Lo que se comprueba en el diseño (integridad)

- No se puede crear una venta sin cliente ni sin vendedor (FK NOT NULL).
- No se puede repetir un `codigo_barras` ni un `email` (UNIQUE).
- No se puede tener dos precios para el mismo producto+lista (UNIQUE).
- El dinero es DECIMAL, no FLOAT.
- Borrar un producto usado en ventas: lo **desactivamos** (`activo=0`), no lo
  borramos, para no romper el historial. (Política, se aplica en código.)

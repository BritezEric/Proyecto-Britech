# Módulo: Tienda Online (planificado — se construye DESPUÉS de Ventas)

> Estado: **NOTA DE PLANIFICACIÓN.** No se desarrolla todavía.
> Sirve para no perder el objetivo mientras trabajamos en Ventas.

## Referencia de producto
Modelo a seguir (con **otro diseño** y catálogo propio):
**https://atacadoconnect.com/** — tienda mayorista B2B de electrónica.

Funcionalidades observadas: catálogo por categorías, varias imágenes por producto,
especificaciones, buscador, carrito, favoritos, precios mayoristas, cuentas B2B.

## Funcionalidad → tabla (la base actual ya cubre casi todo)

| Funcionalidad | Tabla existente |
|---|---|
| Categorías | `categoria` |
| Marcas | `marca` |
| Imágenes de producto | `multimedia` |
| Precios mayoristas | `precio`, `lista_precio` |
| Cuentas B2B + aprobación mayorista | `cliente`, `solicitud_mayorista` |
| Pedidos online + estados | `pedido`, `pedido_detalle`, `estado_pedido` |
| Envíos | `envio`, `empresa_envio`, `estado_envio`, `pais`, `provincia`, `localidad` |
| Medios de pago | `pago`, `tipo_pago` |

## Lo que falta agregar (cuando construyamos este módulo)
- **Favoritos / wishlist:** tabla nueva `favorito(cliente_id, producto_id)`.
- **Cantidad mínima mayorista:** campo en `producto` o `lista_precio`.
- **Especificaciones de producto** (opcional): campo descripción al inicio; tabla
  `producto_atributo` solo si se necesitan specs estructuradas.
- **Carrito online:** al inicio puede ser por sesión; tabla `carrito` solo si se
  quieren carritos guardados entre visitas.

## Relación con Ventas
Un **pedido** online confirmado generará una **venta** (misma lógica de descuento
de stock y comprobante que el POS). Por eso primero construimos Ventas bien: la
tienda online se apoya en ese motor.

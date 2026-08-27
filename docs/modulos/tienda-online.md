# Módulo: Tienda Online

> Estado: **EN CONSTRUCCIÓN** (2026-08-21). Ya construido y probado: catálogo,
> carrito, registro/login de cliente, checkout → Pedido, y **acceso mayorista B2B
> con aprobación del admin + toggle minorista/mayorista**. Detalle y estado en
> [entrada-datos.md](entrada-datos.md).
> Lo de abajo es la nota de planificación original (referencia de alcance).
>
> **Ampliación (2026-08-27):** checkout en **2 pasos** con **métodos de pago**
> (Transferencia / Mercado Pago / Tarjeta) y **Moto Express** (envío por barrio).
> Ver [envios-repartidores.md](envios-repartidores.md).

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

> **Tema por modo (2026-08-24):** en la tienda, el **modo minorista usa tema claro**
> y el **mayorista tema oscuro**. Se cambia con el mismo switch Minorista/Mayorista
> (`aplicarTemaModo` pone/saca `data-theme="dark"`, reusa el tema oscuro de
> `tokens.css`). La transición usa la **View Transitions API** (crossfade en el
> compositor: fluido y barato; fallback instantáneo si no está soportada o hay
> `prefers-reduced-motion`). El modo se recuerda en `localStorage` y un script en el
> `<head>` lo aplica antes de pintar para evitar parpadeo. Probado ida y vuelta.

> **Aviso por correo (2026-08-24):** al **aprobar o rechazar** una solicitud
> mayorista (`MayoristaService::resolver`), se le manda un correo al cliente
> avisándole el resultado (con link a la tienda). El envío es tolerante a fallos:
> si el correo no sale, la aprobación/rechazo igual se registra. Probado con
> aprobación y rechazo reales.
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

# Módulo: Pagos y registros de pago

> Estado: **construido**. Cobro en el local (POS) y pago online (transferencia
> manual + Mercado Pago). Detalle de Mercado Pago en `docs/modulos/mercadopago.md`.

## Panorama
Hay **dos circuitos** de pago, con su propio registro:

```
POS (local)     → tabla  pago (venta_id, tipo_pago_id, monto)   [Efectivo / Transferencia]
Tienda (online) → columnas del pedido (estado_pago, metodo_pago, comprobante_url, mp_payment_id)
```

## 1. Pago en el POS
En la venta, el vendedor elige el medio de pago (Efectivo o Transferencia). El
`VentaService` registra la venta y su `pago` (tipo + monto) dentro de una
**transacción**, exige **caja abierta** y liga la venta a esa caja. Modelo en
`schema_ventas.sql` (`tipo_pago`, `pago`).

## 2. Pago online — transferencia manual
Flujo por defecto cuando no hay Mercado Pago configurado:
1. El cliente elige **Transferencia** y ve el alias/CBU (`config_tienda`).
2. Transfiere y **sube el comprobante** → `estado_pago = 'en_revision'` y se avisa al staff.
3. El staff **aprueba o rechaza** desde el panel → `pagado` / `rechazado`.

Estados: `pendiente → en_revision → pagado | rechazado`.

## 3. Pago online — Mercado Pago (Checkout Pro)
Si hay `MERCADOPAGO_ACCESS_TOKEN` en el `.env`, el pedido se cobra online y se
confirma **automáticamente**:
1. El backend crea una **preferencia** con el monto que él mismo calcula (productos
   + envío) y redirige al cliente al checkout de Mercado Pago.
2. Al volver (back_url) o por **webhook**, el backend **re-consulta el pago a MP**
   con su token y recién ahí lo marca `pagado` (guarda `mp_payment_id`), `rechazado`
   o `en_revision`. Es idempotente.
3. Pantallas de resultado para el cliente: acreditado ✓ / pendiente ⏳ / rechazado ✕.

Ver el detalle completo en `docs/modulos/mercadopago.md`.

## Vistas
- **Cliente (tienda)**: elección de método, datos de transferencia, redirección a
  Mercado Pago y pantallas de resultado.
- **Staff (panel)**: en el detalle del pedido, bloque **Pago** con método, estado
  (badge), comprobante o "Cobrado con Mercado Pago · pago #id", y botones
  aprobar/rechazar.

## Seguridad
- El **monto lo fija el backend**; el navegador no puede alterarlo.
- Los pagos de Mercado Pago se **verifican contra el servidor de MP**, nunca se
  confía en datos de la URL o del webhook.
- El comprobante se valida por contenido (imagen o PDF) y tamaño.
- El Access Token es secreto: va en `.env`, nunca en el repositorio ni en el front.

## Archivos
- `database/schema_pagos.sql`, `schema_metodo_pago.sql`, `schema_mercadopago.sql`, `schema_ventas.sql`
- `app/Services/MercadoPagoService.php`, `app/Controllers/PagoController.php`
- `app/Controllers/PedidoController.php` (comprobante, estado de pago)
- `app/Controllers/ConfigController.php` (alias/CBU y flag de Mercado Pago)
- Front: `public/assets/js/tienda.js`, `public/assets/js/admin.js`

> Guía de defensa paso a paso: `docs/exposicion/pagos-eric-paso-a-paso.md`.

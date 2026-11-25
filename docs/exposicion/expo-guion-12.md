# Expo Britech — guión (12/11)

**Presentan:** Eric Britez · Tobías Soto · Franco.
**Objetivo:** mostrar Britech **como un producto terminado**, de pie a cabeza. Cada parte sigue el hilo *problema → se ve funcionando → valor*. Mostramos **acción y efecto** (vendo → baja stock → llega el aviso → aparece en los reportes).

**Hilo conductor:** usamos **un mismo pedido con Moto Express** de punta a punta. Eric lo vende como cliente → lo gestiona como admin y lo sigue en el mapa → Tobías muestra el aviso que llegó → Franco lo muestra en los reportes. Es **una historia**, no features sueltas.

**Duración:** ~18 min + preguntas.

---

## Reparto (orden de la exposición)

1. **Eric** — Apertura.
2. **Eric** — La tienda, como cliente (minorista y mayorista) + comprar con Moto Express.
3. **Eric** — El administrador: cómo llega la venta y el envío en vivo.
4. **Eric** — El POS (venta en el local).
5. **Tobías** — Notificaciones y el bot de Telegram (conectado al cerebro n8n).
6. **Franco** — Reportes, dashboards y salidas (orientado a vender y decidir).
7. **Eric** — Cierre con el asistente de voz.

---

## El guión, paso por paso

### 1. Apertura — Eric (1 min)
- **Dice:** "Britech es un sistema de gestión integral para un comercio de tecnología de Formosa. En un solo lugar junta lo que hoy está disperso o en papel: la tienda online, las ventas del local, el stock, la caja, los envíos con seguimiento en vivo, las notificaciones y los reportes. Se los mostramos funcionando, como lo usaría un negocio de verdad."
- **Gancho:** "Está pensado para cuatro: el dueño, los vendedores, los clientes y los repartidores."

### 2. La tienda, como cliente — Eric (4 min)
- **Entrar a la tienda** y ver el catálogo (como lo ve un cliente desde el celular).
- **Minorista vs mayorista:** mostrar que la tienda tiene **dos modos** — precios minoristas para el público y **mayoristas** para clientes con cuenta. Mostrar el cambio de modo y la **solicitud de cuenta mayorista** (cómo un cliente pide acceso). → *Resuelve: vender al público y a revendedores desde la misma tienda.*
- **Comprar:** agrego un producto al carrito → checkout → elijo **Moto Express** → elijo **barrio** (fija zona y precio) → escribo la dirección y **marco el pin exacto en el mapa** → confirmo el pedido.
- **Dice:** "El cliente compra desde el celular y marca en el mapa a dónde va el pedido. Ese pin es el destino del repartidor." → *Resuelve: vender fuera del local y saber exactamente a dónde entregar.*

### 3. El administrador: la venta y el envío — Eric (4 min) ⭐ *(el momento estrella)*
- **Llega la venta:** en el panel aparece el **pedido nuevo** (y la **campana** avisa). "Apenas el cliente compró, al negocio le entró el pedido."
- **El envío Moto Express:** se **auto-asigna** el cadete disponible. Mostrar el **seguimiento en vivo** en tres pantallas:
  1. **Celu del repartidor:** entra con **su nombre + PIN**, le aparece **"Viaje asignado"** con las dos patas (retirar en el local → entregar en la dirección) y **"Cobrás $X"**. Toca **Aceptar → Ya retiré → Entregué**.
  2. **Celu del cliente:** abre el link (sin instalar nada, sin login) y **ve la moto moverse** por las calles, su dirección y cuánto falta.
  3. **Panel → Mapa de envíos:** todas las motos en reparto en tiempo real.
- **Dice:** "El cliente deja de llamar para preguntar dónde está su pedido, y el negocio ve la calle en tiempo real." → *Resuelve: la peor parte del delivery.* Remarcar: **gratis** (OpenStreetMap, sin Google Maps pago) y **un retiro en el local no genera viaje** (está bien pensado).

### 4. El POS — Eric (2 min)
- **Venta en el local:** agrego productos, cobro. "Al cobrar, **descuenta el stock y suma a la caja**, solo." Mostrar también el **cierre de caja** (apertura, esperado, diferencia). → *Resuelve: vender en el mostrador sin planillas y con la caja controlada.*
- **Pase a Tobías:** "Y todo esto que pasó —la venta, el pedido, el stock— el equipo se entera al toque. Eso lo explica Tobías."

### 5. Notificaciones y Telegram — Tobías (3 min)
- **Por qué y cómo llegan:** cada hecho del sistema (pedido nuevo, stock bajo, caja, reclamos) genera un aviso que le llega **al que corresponde según su rol** (el admin ve todo, el vendedor lo suyo).
- **La campana del panel:** mostrar el aviso que entró con la venta de Eric (en vivo).
- **El bot de Telegram:** los mismos avisos llegan al **Telegram del equipo**, **ordenados por categoría** (🛒 Pedido, 🧾 Venta, ⚠️ Stock, 💰 Caja). Mostrar el **resumen de caja** del día.
- **El asistente del bot:** "El bot además **responde**: le escribís o le mandás un **audio** y te contesta —en simples palabras, está conectado al **cerebro en n8n** (una IA que consulta el sistema)." Mostrar una pregunta por texto o audio ("¿qué productos hay?").
- **Dice:** "Las notificaciones son cómo el equipo se mantiene al día sin estar mirando la pantalla."

### 6. Reportes y salidas — Franco (3 min)
- **Mi panel (dashboard del vendedor):** cada vendedor ve lo suyo — cuánto vendió por día/semana/mes.
- **Reporte por localidades:** en qué **barrios** se vende y se entrega más → dónde conviene empujar.
- **Reportes operativos (admin):** medios de pago, cajas y anulaciones, vendedores, tienda online, reclamos, envíos sin asignar, carga por barrio. **Exportables a CSV/PDF**.
- **Dice:** "Todo orientado a **vender y decidir con números**, no a ojo: qué se vende, dónde, por quién y cómo vienen los envíos." (Mostrar, si se puede, la venta de Eric ya reflejada en un reporte.)

### 7. Cierre con el asistente de voz — Eric (2 min)
- **El POS con voz:** "tocar para hablar" en el POS. Pedirle algo por voz (una consulta o registrar una venta). "No es un mock: es un **agente real** —transcribe lo que digo, lo entiende con IA, consulta la API de Britech y me responde hablado."
- **Cierre del producto (puede cerrar cada uno una frase):**
  - "Britech junta todo lo de un comercio en un sistema: tienda, ventas, stock, caja, envíos en vivo, avisos y reportes."
  - "Integrado —una venta mueve stock, caja, reportes y avisos a la vez."
  - "Con herramientas libres y pensado para Formosa. Una sola herramienta que reemplaza varias."

---

## Antes de empezar (checklist, 15 min antes)
1. **MySQL** prendido (Laragon).
2. **Servidor**: `INICIAR-SERVIDOR.bat` (abre en `127.0.0.1:8123`).
3. **ngrok** (GPS del repartidor): `ngrok http 8123 --url=https://semisweet-uncover-cranberry.ngrok-free.dev`.
4. **Datos cargados**: productos con stock, un repartidor **con PIN**, una caja abierta, un cliente mayorista para mostrar ese modo.
5. **Ubicación del negocio**: panel → Configuración → *Datos de pago y ubicación* → poné el pin en el local.
6. **n8n levantado** (para el bot y la voz): el túnel/Docker de n8n andando.
7. **Pestañas/pantallas listas** (no abrir nada nuevo en vivo):
   - Notebook: tienda, panel admin, POS.
   - Celu 1 (repartidor): `…/repartidor.html` logueado y **en línea**.
   - Celu 2 (cliente): el link de seguimiento.
   - Telegram del equipo abierto.
8. **Plan B** a mano (abajo).

---

## Plan B — si se cae internet o el GPS
Todo en la **notebook, en localhost** (localhost ya es seguro, el GPS no hace falta):
1. Levantá el proyecto local.
2. Simulá la moto: `php tools/sim-moto.php <token_del_repartidor> 60 2` (mueve una moto por Formosa cada 2s).
3. Abrí el **mapa admin** y el **link del cliente** en la misma notebook → se ve moverse igual.
4. Tené el simulador ya tipeado y los links abiertos **antes** de empezar.

---

## Preguntas probables (y respuesta corta)
- **¿Usan Google Maps / cuánto cuesta?** → No, OpenStreetMap: gratis.
- **¿El cliente instala una app?** → No, abre un link.
- **¿Y si el repartidor cierra la pantalla?** → Avisa y la mantiene prendida (Wake Lock); a futuro, un tracker GPS manda al mismo endpoint.
- **¿Se exponen datos del cliente en el link?** → No, solo la moto y el ETA, sin datos personales.
- **¿El bot no se banea?** → Es la API oficial de Meta/Telegram; los avisos internos van por Telegram (no banea bots).
- **¿Quién hizo qué?** → Eric: transacciones, pagos, tienda y envíos. Tobías: notificaciones. Franco: reportes y dashboards (Salidas del Sistema).

---

## Tips para que se vea profesional
- **Ritmo:** una acción → su efecto. No expliquen el código; muestren el resultado.
- **Nada en vivo que pueda fallar:** todo abierto en pestañas de antes.
- **Problema primero, después solución:** "Antes… / Ahora con Britech…".
- **El envío en vivo es el pico:** está en el medio, cuando ya se entendió el resto.
- **El pase entre ustedes cuenta:** "…y acá Tobías les muestra cómo se entera el equipo." Ensayen los pases una vez.
- **Cierren con el valor**, con la voz, no con una pantalla técnica.

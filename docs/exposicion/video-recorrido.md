# Britech — Guion para el video de recorrido

Guía para grabar un video mostrando el proyecto: primero el **pitch** (qué es y qué
resuelve) y después el **recorrido pantalla por pantalla**, con qué decir en cada parte.

---

## 1. El pitch (30–45 segundos, al inicio del video)

**Qué es:** Britech es un **sistema de gestión integral** para un comercio de tecnología
de Formosa. En un solo lugar junta lo que antes estaba disperso o en papel: las **ventas
del local (POS)**, una **tienda online**, el **control de stock**, la **caja**, los
**envíos con seguimiento en vivo**, las **notificaciones** al equipo y los **reportes**.

**A quién apunta:** al **dueño/administrador** (ve y controla todo), a los **vendedores**
(venden y ven lo suyo), a los **clientes** (compran online y siguen su pedido) y a los
**repartidores** (reciben y entregan los viajes).

**Qué problema resuelve:**

| Antes | Con Britech |
|---|---|
| Ventas anotadas a mano, sin control | POS que registra la venta, descuenta stock y arma la caja |
| Stock que no se sabe hasta que falta | Aviso automático de **stock bajo** |
| Sin tienda online | Tienda web con catálogo, carrito y pago |
| El cliente no sabe dónde está su envío | **Seguimiento en vivo** estilo Uber, sin instalar nada |
| El repartidor no sabe qué tiene que hacer | App del cadete: **viaje asignado → aceptar → retirar → entregar** |
| Avisos que no llegan a nadie | **Telegram**: cada aviso al que corresponde, por rol |
| No se sabe cómo cerró el día | **Resumen de caja** diario y semanal automático |
| Reportes a mano | Dashboards y reportes por vendedor, localidad y operación |

**La idea fuerza:** todo **integrado** (una venta mueve stock, caja, reportes y avisos a la
vez), hecho con **herramientas libres** (mapas de OpenStreetMap, sin pagar Google Maps) y
**pensado para Formosa** (barrios, direcciones, entrega en moto).

---

## 2. El recorrido (5–8 minutos)

Orden sugerido. Cada bloque: **qué mostrar** y **qué decir** (siempre cerrando con *qué
resuelve*). Tené datos de prueba cargados antes de grabar.

### Bloque 1 — Login y roles (20 s)
- **Mostrar:** la pantalla de login; entrar como **admin**.
- **Decir:** "El sistema tiene roles: el administrador ve todo; el vendedor, solo lo suyo.
  El cliente entra por la tienda." → *Resuelve: cada uno ve lo que le corresponde.*

### Bloque 2 — POS: venta en el local (60 s)
- **Mostrar:** el POS, agregar productos, cobrar. Mostrar el **asistente de voz** (tocar
  para hablar, pedir algo por voz).
- **Decir:** "Acá se venden los productos en el mostrador. Al cobrar, descuenta el stock y
  suma a la caja automáticamente. Y tiene un asistente de voz: le hablás y te responde o
  registra la venta." → *Resuelve: vender rápido sin planillas, con la mano libre.*

### Bloque 3 — Tienda online: el cliente compra (75 s)
- **Mostrar:** la tienda, buscar un producto, agregarlo al carrito, ir al checkout. Elegir
  **Moto Express**, elegir barrio, escribir la dirección y **fijar el pin en el mapa**.
  Confirmar el pedido.
- **Decir:** "El cliente compra desde el celular. Si pide envío en moto, elige su barrio y
  **marca en el mapa el punto exacto** a dónde va el pedido. Ese pin es el destino del
  repartidor." → *Resuelve: vender fuera del local y saber exactamente a dónde entregar.*

### Bloque 4 — El pedido entra al panel (40 s)
- **Mostrar:** el panel admin, el **pedido nuevo** en la lista; la **notificación** (campana)
  de pedido nuevo y, si corresponde, la de **stock bajo**.
- **Decir:** "Apenas el cliente compra, al negocio le entra el pedido y el aviso. Si un
  producto quedó en su mínimo, salta el aviso de stock bajo." → *Resuelve: no se escapa
  ningún pedido ni te quedás sin stock de sorpresa.*

### Bloque 5 — Envío en vivo, estilo Uber (90 s) ⭐
- **Mostrar (3 pantallas):**
  1. **Celu del repartidor** (`repartidor.html`): aparece **"Viaje asignado"** con las dos
     patas (retirar en el local → entregar en la dirección). Tocar **Aceptar → Ya retiré →
     Entregué**, con el mapa y el ETA.
  2. **Celu del cliente** (link de seguimiento, sin login): ve la **moto moverse** por las
     calles, su dirección y cuánto falta.
  3. **Panel admin → Mapa de envíos:** todas las motos en reparto en tiempo real.
- **Decir:** "El repartidor abre su link y le aparece el viaje como en Uber: acepta, retira
  y entrega. El cliente sigue la moto en vivo con un link, sin instalar nada. Y el negocio
  ve todas las motos en el mapa." → *Resuelve: el cliente deja de llamar para preguntar
  'dónde está mi pedido', y el negocio controla la calle en tiempo real.*
- **Aclarar:** un **retiro en el local no genera viaje** (el cliente lo busca), por eso solo
  los envíos en moto le aparecen al cadete.

### Bloque 6 — Notificaciones por Telegram (60 s)
- **Mostrar:** el bot de Telegram del equipo. Que lleguen avisos con su **categoría**
  (🛒 Pedido, 🧾 Venta, ⚠️ Stock, 💰 Caja). Mostrar el **resumen de caja**. Y mandarle una
  pregunta por **texto o audio** → el bot responde (ej. "¿qué productos hay?").
- **Decir:** "Cada aviso del sistema le llega al que corresponde por Telegram, ya ordenado
  por tipo. Al cierre llega el resumen del día. Y además le podés hablar al bot, por texto
  o audio, y te contesta consultando el sistema." → *Resuelve: el equipo se entera al toque
  y puede consultar sin abrir la compu.*

### Bloque 7 — Caja (40 s)
- **Mostrar:** abrir caja con un monto, el resumen del turno (ventas por medio de pago,
  esperado), cerrar y ver la **diferencia**.
- **Decir:** "El vendedor abre y cierra su caja; el sistema calcula lo esperado y la
  diferencia, y le avisa al admin." → *Resuelve: control de caja sin cuentas a mano.*

### Bloque 8 — Reportes y control (45 s)
- **Mostrar:** Panel → Reportes: **dashboard del vendedor** ("Mi panel"), reporte por
  **localidades**, y los **reportes operativos** (medios de pago, cajas, envíos, reclamos).
- **Decir:** "Para decidir: cuánto vendió cada uno, en qué barrios, por qué medio de pago,
  cómo vienen los envíos y los reclamos." → *Resuelve: ver el negocio con números, no a
  ojo.* (Módulo de Franco.)

### Cierre (20 s)
- **Decir:** "Britech junta todo lo de un comercio en un sistema: venta, stock, tienda,
  caja, envíos en vivo, avisos y reportes. Integrado, con herramientas libres y pensado para
  Formosa." → *Un cierre que remarca el valor: una sola herramienta que reemplaza varias.*

---

## 3. Tips para grabar

- **Cargá datos antes:** productos con stock, un par de pedidos, un repartidor activo, una
  caja abierta. Un sistema vacío no se entiende en video.
- **Para el envío en vivo:** usá **HTTPS (ngrok)** para que el GPS del celu ande; o el
  **simulador** (`php tools/sim-moto.php <token> 60 2`) si grabás sin salir a la calle.
- **No muestres datos sensibles:** tokens, el `.env`, contraseñas reales.
- **Ritmo:** mostrá una acción y su efecto (vendo → baja el stock → llega el aviso). El
  "antes/después" es lo que más vende.
- **Guardá lo mejor para el medio:** el **seguimiento en vivo** es el momento más fuerte,
  ponelo cuando ya se entendió el resto.

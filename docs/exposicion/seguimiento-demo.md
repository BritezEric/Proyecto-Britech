# Demo — Seguimiento de envíos en vivo (expo 12/11)

Guion de 2 minutos + cómo exponer el servidor con HTTPS + plan B.

---

## 0. Por qué necesitamos HTTPS

El GPS del navegador (geolocation) **solo funciona en contexto seguro**: HTTPS con
certificado válido o `localhost`. Por eso abrir un puerto con `http://` pelado **no
alcanza** — el celular del repartidor no entrega la ubicación.

### Para ESTA demo (local + ngrok) — pasos concretos

1. **MySQL** prendido (Laragon → MySQL).
2. **Servir la app** en un puerto limpio (desde la carpeta del proyecto):
   ```bash
   php -S 127.0.0.1:8080 -t public server.php
   ```
3. **Dominio fijo de ngrok** (una sola vez): panel de ngrok → *Domains* →
   **Create Domain** → te da `algo.ngrok-free.dev`.
4. **Exponer** ese puerto con tu dominio fijo:
   ```bash
   ngrok http 8080 --url=https://TU-DOMINIO.ngrok-free.dev
   ```
5. (Opcional) en `.env`: `APP_URL=https://TU-DOMINIO.ngrok-free.dev` — solo hace falta
   para emails y Auth0; el link de seguimiento del admin ya usa el dominio actual solo.
6. Abrí todo en esa URL HTTPS: panel admin, `/repartidor.html?token=…`,
   `/seguimiento.html?t=…`. El GPS ya funciona.

### Otras opciones (referencia)

### Opción rápida para la demo — Cloudflare Tunnel (temporal)
En el server (donde corre el `app` en el puerto 8080):
```bash
cloudflared tunnel --url http://localhost:8080
```
Te da una URL `https://xxxx.trycloudflare.com`. Sirve para la expo (HTTPS listo,
nada que configurar). **Contra:** cambia cada vez que lo reiniciás.

### Opción estable — ngrok con dominio fijo (recomendada)
Una URL fija que no cambia, corriendo como servicio:
```bash
ngrok config add-authtoken TU_TOKEN
ngrok http 8080 --url=https://TU-SUBDOMINIO.ngrok-free.dev
```
El dominio fijo se saca una vez en el panel de ngrok (gratis). No dependés del profe
ni de abrir puertos.

### Opción profesional — dominio propio + Caddy (si el profe colabora)
Si el profe te da **dominio + IP pública** y abre el **443**, poné Caddy delante del
app (certificado Let's Encrypt automático): `https://envios.tudominio`. Es lo más
prolijo, pero depende de que el server tenga IP pública alcanzable (confirmar que no
lo bloquee el firewall del proveedor, como pasa con el 8080).

> **Importante:** cualquiera sea la opción, usá **la misma URL HTTPS** para todo:
> el panel admin, el link del repartidor (`/repartidor.html?token=…`) y el link
> público del cliente (`/seguimiento.html?t=…`). Si mezclás dominios, el GPS o las
> llamadas fallan.

---

## 1. Preparación (10 min antes)

1. Server arriba con HTTPS (una opción de arriba). Anotá la URL base, ej. `https://demo.app`.
2. **Repartidor con token**: Panel → Repartidores → elegí uno → botón **🔗 Link GPS**.
   Copiá `https://demo.app/repartidor.html?token=…`. Abrilo en el **celu del repartidor**.
3. **Un pedido en reparto**:
   - Hacé un pedido de prueba en la tienda con una dirección real de Formosa
     (calle + altura). Se **geocodifica solo** → aparece el 🏁 destino en el mapa.
   - Si el pin no quedó bien: Panel → Gestor de envíos → abrí el pedido →
     **📍 Fijar destino en el mapa** → tocá el punto correcto → Guardar.
   - Asigná el repartidor y poné el envío en **en_camino**.
4. **Link del cliente**: en ese mismo envío, **🔗 Copiar link de seguimiento** →
   es `https://demo.app/seguimiento.html?t=…`. Abrilo en un **segundo celu** (el "cliente").

---

## 2. Guion de 2 minutos

| Tiempo | Pantalla | Qué hago / digo |
|---|---|---|
| 0:00 | **Notebook — panel admin → Mapa de envíos** | "Acá el negocio ve todas las motos en reparto en tiempo real, sobre Formosa." |
| 0:20 | **Celu 1 (repartidor)** | Toco **▶ Ponerme en línea**, acepto el permiso de ubicación. "El repartidor solo abre su link y se pone en línea; su pantalla queda prendida sola." |
| 0:40 | **Notebook (mapa admin)** | La moto 🛵 aparece y se mueve. "Mirá, ya la está siguiendo, con el ETA estimado." |
| 1:00 | **Celu 2 (cliente)** | Abro el link público. "El cliente recibe este link por WhatsApp, sin instalar nada ni iniciar sesión. Ve su moto, su dirección 🏁 y cuánto falta." |
| 1:25 | **Celu 1** | Camino unos pasos (o muevo el celu). "La posición se actualiza cada pocos segundos en los dos lados." |
| 1:45 | **Notebook / celu 2** | "Y cuando marco el envío como entregado, al cliente le aparece ✓ Entregado." Cambio el estado. |
| 2:00 | — | Cierre. |

Puntos a remarcar: **gratis** (OpenStreetMap, sin Google Maps pago), **agnóstico del
hardware** (hoy el celu, mañana un tracker GPS al mismo endpoint), y el link del
cliente **no expone datos personales**, solo la moto y el ETA.

---

## 3. Plan B — si se cae internet

No dependas del GPS de los celus ni de la red externa. Todo en la **notebook, en localhost**:

1. Levantá el proyecto local (`localhost:8080` o Laragon) — `localhost` ya es contexto seguro.
2. Simulá la moto con el simulador incluido (no necesita hardware ni GPS):
   ```bash
   php tools/sim-moto.php <token_del_repartidor> 60 2
   ```
   Mueve una moto por Formosa emitiendo posiciones cada 2s durante 60 pasos.
3. Abrí en la misma notebook: el **mapa admin** y el **link del cliente**
   (`localhost:8080/seguimiento.html?t=…`). Se ve la moto moverse igual.
4. Si tampoco hay destino geocodificado (sin internet Nominatim no resuelve): usá
   **📍 Fijar destino en el mapa** y poné el pin a mano. Listo.

> Tené el simulador ya tipeado en una terminal y los links abiertos en pestañas
> antes de empezar, para no depender de nada en vivo.

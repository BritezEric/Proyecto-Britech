# Módulo: Seguimiento de envíos en tiempo real (estilo Uber)

> Estado: **construido y probado** (2026-10-09). En rama `seguimientoenmapa`.
> Agnóstico del hardware: cualquier emisor que haga POST con token + GPS sirve.

## Problema
El cliente quiere ver **dónde va su pedido** (como en Uber/PedidosYa) y el negocio
quiere ver **todas las motos** en reparto en un mapa. Hay que ubicar la moto en vivo
y estimar cuánto falta (ETA).

## Cómo funciona
```
Emisor (celular del repartidor / TrackingTag)
   │  POST /api/repartidor/posicion  {token, lat, lng}  cada ~4s
   ▼
Backend: guarda la última posición del repartidor + historial
   ▲                                   ▲
   │ GET /api/admin/envios/mapa        │ GET /api/tienda/seguimiento?pedido_id=
Mapa del ADMIN (todas las motos)    Mapa del CLIENTE (su moto + ETA)
```
La actualización en vivo es por **polling** cada 3s (en producción se puede subir a
SSE/WebSocket). El mapa es **Leaflet + OpenStreetMap** (gratis). El ETA se calcula
con la **fórmula de Haversine** (distancia a la dirección de entrega / velocidad
promedio). Si la moto no emite hace más de 30s, se marca **"sin señal"** (moto gris).

## Hardware (agnóstico)
| Opción | Qué es | Costo |
|---|---|---|
| **Celular del repartidor** | `repartidor.html?token=` emite el GPS (PWA) | $0 |
| **TrackingTag ESP32** | ESP32 + GPS (NEO-6M) + SIM800L/SIM7600 con datos | bajo |
| **Tracker GPS comercial** | con SIM, protocolo GT06/H02 | bajo-medio |

Todos mandan al mismo endpoint (`POST /api/repartidor/posicion`). El software no
cambia según el emisor.

## Modelo de datos (`schema_tracking.sql`)
- `repartidor` += `token` (autentica al emisor), `ult_lat`, `ult_lng`, `ult_pos_en`, `en_linea`.
- `repartidor_posicion`: historial de posiciones (rastro del recorrido).
- `envio` += `dest_lat`, `dest_lng` (destino, para distancia y ETA).

## Vistas
- **Repartidor** (`public/repartidor.html?token=…`): página móvil con un botón
  "Ponerme en línea" que emite el GPS; muestra estado, precisión y envíos. El admin
  obtiene este link con el botón **"🔗 Link GPS"** en el detalle del repartidor.
- **Admin** (panel → **Mapa envíos**): todas las motos en curso sobre el mapa de
  Formosa, con ETA, lista lateral y aviso de "sin señal".
- **Cliente** (tienda → *Mis pedidos* → **Seguir en el mapa**): su moto en vivo + ETA.

## API
| Método | Ruta | Quién |
|---|---|---|
| POST | `/api/repartidor/posicion` | emisor (token) — público |
| GET | `/api/admin/envios/mapa` | staff — todas las motos |
| GET | `/api/tienda/seguimiento?pedido_id=` | cliente — su envío |

## Archivos
- `database/schema_tracking.sql`
- `app/Repositories/TrackingRepository.php`, `app/Controllers/TrackingController.php`
- `public/repartidor.html` (emisor), `tools/sim-moto.php` (simulador sin hardware)
- Admin: `public/assets/js/admin-mapa.js` (+ vista en `admin.html`, estilos en `admin.css`)
- Cliente: `public/assets/js/tienda.js` (modal de seguimiento) + estilos en `tienda.css`

## Probar sin hardware
```
php tools/sim-moto.php <token_del_repartidor> 60 2
```
Mueve una moto por Formosa emitiendo posiciones; se ve en el mapa del admin/cliente.

## Pendiente / futuro
- Tiempo real por SSE/WebSocket (hoy polling cada 3s).
- Ruta óptima multi-parada (OSRM) y traza del recorrido en el mapa.
- Integración del TrackingTag por hardware (ESP32) vía HTTP/MQTT.

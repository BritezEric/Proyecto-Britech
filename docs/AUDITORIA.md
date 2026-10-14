# Auditoría completa — Britech

Fecha: octubre de 2026. Alcance: backend PHP (seguridad, datos, acceso), frontend (XSS, performance, accesibilidad), rutas y navegación del sistema (panel admin, POS y tienda).

## Resumen ejecutivo

El proyecto está en **buen estado**. La arquitectura por capas (Front Controller → Router → Controller → Service → Repository → PDO) es consistente y las prácticas de seguridad clave están bien resueltas: consultas parametrizadas, control de acceso por rol, subida de archivos validada, escape de HTML y manejo de errores sin fuga de información.

Los hallazgos son de **endurecimiento (hardening) y performance**, no vulnerabilidades explotables en el uso previsto. No se encontraron bugs funcionales durante la navegación.

## Seguridad — verificado correcto

| Área | Estado | Evidencia |
|------|--------|-----------|
| Inyección SQL | OK | Prepared statements en todos los repositorios; identificadores dinámicos (nombre de tabla, agrupador) salen de listas blancas / constantes, nunca del request. Ej.: `MaestraSimpleRepository` valida la tabla contra `['categoria','marca']`. |
| Control de acceso | OK | El Router exige login; el rol (admin) se valida en los controllers con `Session::esAdmin()` (403 si no). Los ABM de datos maestros heredan el guard desde `MaestraSimpleController`. Las mutaciones son por POST. |
| Subida de archivos | OK | `getimagesize()` + lista blanca de MIME + nombre con `random_bytes(16)` (comprobantes e imágenes de producto). No se confía en la extensión del cliente. |
| XSS | OK | Todo contenido de usuario se renderiza con `esc()` (escapa `& < > " '`). Verificado en el chat de reclamos (cliente y admin) y en las grillas. |
| Autenticación | OK | Rate-limit en el login (staff y tienda), cuenta solo intentos fallidos y corta con 429. Contraseñas con bcrypt. |
| Sesión | OK | Cookie `httponly` + `samesite=Lax` (mitiga CSRF; además no hay GET que muten estado). |
| Manejo de errores | OK | Handler global de excepciones: loguea el detalle, responde 500 genérico; el mensaje real solo con `app.debug`. No filtra stack traces. |

## Seguridad — recomendaciones (hardening)

1. **Cookie `secure`** (media): está comentada. Activarla al pasar a HTTPS en producción (`app/Core/Session.php`).
2. **Headers de seguridad** (media): hoy solo se envía `Content-Type`. Agregar en `public/index.php` / `Response`:
   - `X-Content-Type-Options: nosniff`
   - `X-Frame-Options: DENY` (o `Content-Security-Policy: frame-ancestors 'none'`) contra clickjacking.
   - `Content-Security-Policy` básica (limitar orígenes de script/estilo).
   - `Strict-Transport-Security` cuando haya HTTPS.
3. **SRI en CDNs** (baja): Chart.js y Leaflet se cargan por CDN sin `integrity`. Agregar hash SRI, o auto-hospedarlos.

## Performance

- **Assets sin empaquetar** (baja): el panel carga 9 scripts `admin-*.js` (9 requests) y ~344 KB de JS sin minificar. Para producción: bundle + minify. En desarrollo es cómodo tenerlos separados.
- **Chart.js sin `defer`** (baja): se carga sincrónico antes de `admin.js`. Pasarlo a `defer` o self-host.
- **Imágenes**: el catálogo de la tienda ya usa `loading="lazy"`. Falta en las imágenes del panel admin (listas/tablas).
- **Consultas**: el dashboard usa agregados directos (sin N+1 evidente). Conviene revisar índices sobre `venta.creado_en`, `pedido.creado_en` y claves foráneas usadas en los JOIN de las grillas paginadas (varias ya tienen índices en el schema).

## UI / UX y accesibilidad

- **Consistencia**: alta. Los apartados de tabla comparten el shell (header, toolbar, tabla, paginación). Dashboard y Tablas usan el patrón de tarjetas con chip de ícono. El set de íconos de línea unifica la mayoría del chrome.
- **Fechas verbosas** (baja): en las tablas aparece `2026-10-12 15:08:50`. Un formato corto (`12/10 15:08`) se lee mejor; es un solo lugar (render genérico de columnas).
- **Estados vacíos** (baja): hoy son solo texto. Sumar un ícono tenue + acción los haría más claros y consistentes.
- **Emojis sueltos** (baja): quedan emojis contextuales (marcadores del mapa, estados “Salió 🛵”, chips de pago, campana de notificaciones, menú de cuenta de la tienda). Pasarlos al set de íconos por tandas para coherencia total.
- **Accesibilidad**: los botones de solo-ícono tienen `aria-label` y los SVG decorativos `aria-hidden`; los formularios tienen `<label>`. Base correcta. Pendiente menor: foco visible consistente y `alt` descriptivo en imágenes de producto.

## Bugs / faltantes

- No se detectaron bugs funcionales navegando el sistema.
- La base de desarrollo tenía datos solo de ago/sep; se movieron registros recientes a fechas actuales para que el dashboard muestre actividad (cambio de datos de demo, reversible reimportando el seed).
- Faltantes menores sugeridos: paginación visible en algunos listados largos del dashboard, confirmación al anular/dar de baja, y toasts de éxito uniformes.

## Prioridades sugeridas

1. **Hardening para producción**: cookie `secure`, headers de seguridad, SRI/self-host de CDNs.
2. **Performance de producción**: bundle + minify del JS, `defer` de Chart.js, `lazy` en imágenes del panel.
3. **Pulido de UI**: formato de fechas, estados vacíos con ícono, terminar el pasaje de emojis a íconos.
4. **Robustez**: confirmaciones en acciones destructivas, toasts de éxito uniformes.

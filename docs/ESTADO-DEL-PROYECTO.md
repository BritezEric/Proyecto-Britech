# Estado del Proyecto Britech

> Última actualización: **2026-09-16**
> Este documento resume **dónde estamos**, **qué falta para terminar** (las 5 entregas
> de la materia) y **qué falta para que sea un producto vendible/en producción**.

Britech es una plataforma PHP 8.1+ / MySQL con tres frentes: **POS** (punto de venta),
**tienda online** y **panel de administración**. Arquitectura MVC por capas
(Front Controller → Router → Controller → Service → Repository → PDO), frontend en
HTML/CSS/JS puro consumiendo una API JSON.

---

## 1. Dónde estamos hoy

### Entregas de la materia (cronograma)

| # | Módulo | Entrega | Estado |
|---|--------|---------|--------|
| 1 | 🔐 Seguridad | 12-may-2026 | ✅ **Funcional** (ver salvedad de permisos) |
| 2 | 🗂️ Tablas Maestras | 23-jun-2026 | 🟡 **Parcial** (faltan varias tablas del enunciado) |
| 3 | 📥 Entrada de Datos | 25-ago-2026 | ✅ **Completo** |
| 4 | ⚙️ Procesos | 27-oct-2026 | 🟢 **Casi completo** (Ventas + Compras + Caja + Notificaciones) |
| 5 | 📊 Salidas del Sistema | a definir | 🟡 **Iniciado** (cierre de caja ✓; faltan reportes y auditoría) |

> Extras recientes: **Caja del vendedor** (apertura/arqueo/cierre — cubre el *cierre de caja*
> del Módulo 5), **permisos de vendedor** (accede al panel solo para pedidos/envíos),
> **reclamos de clientes** con seguimiento, y pagos limitados a **transferencia + efectivo**.

### Qué funciona hoy (probado)

- **Seguridad**: login staff (email + contraseña, hash `password_hash`), registro/activación
  de clientes por correo, reset de contraseña, sesiones nativas endurecidas (regenerate_id,
  httponly, SameSite Lax, strict mode), rate limiting en login/registro, roles `admin`/`vendedor`.
  **Login con Google (Auth0)** para clientes, con logout real. Páginas de contraseña con
  ojo mostrar/ocultar + validación en vivo.
- **Tablas Maestras**: ABMs de **Marcas, Categorías, Proveedores, Barrios** (Moto Express),
  con validaciones y confirmaciones.
- **Entrada de Datos**: ABM de **Clientes, Productos, Proveedores** + **stock** (inicial y ajustes),
  con búsquedas y filtros. Imágenes de producto, listas de precio (minorista/mayorista).
- **Procesos**:
  - **Ventas** (POS): venta con scanner, precio y stock calculados en backend, medios de pago,
    envío en el ticket, **descuento automático de stock**, anulación con motivo y **reintegro**,
    todo transaccional + historial de movimientos de inventario.
  - **Compras**: **orden de compra** (manual o desde Reposición) + **recepción de mercadería**
    total o parcial (suma stock automáticamente) + anulación. Historial en `movimiento_inventario`.
  - **Notificaciones**: campana con avisos de **stock bajo** (al vender, con dedupe),
    **mercadería recibida**, pedidos y comprobantes.
  - **Reposición**: detecta faltantes, agrupa por proveedor, arma pedido de WhatsApp y
    guarda historial para no re-avisar.
- **Tienda online**: catálogo con filtros (marca/precio/stock), favoritos, carrito, checkout
  con transferencia + comprobante, Moto Express por barrio, seguimiento de pedido, modo mayorista.
- **Finanzas**: gastos, sueldos (gastos etiquetados al empleado), dashboard con KPIs y accesos rápidos.

---

## 2. Qué falta para *terminar* (las 5 entregas)

### Módulo 2 · Tablas Maestras — completar
El enunciado pide, además de las que ya están: **Provincias, Localidades, Tipos de documento,
Unidades de medida**. Hoy **no existen** como tablas/ABM (el perfil de cliente guarda
provincia/CP como texto libre). Falta:
- [ ] Tablas + ABM de `provincia`, `localidad` (con FK provincia→localidad).
- [ ] Tabla + ABM de `tipo_documento` (DNI, CUIT, …) y usarla en cliente.
- [ ] Tabla + ABM de `unidad_medida` (unidad, kg, litro, …) y asociarla al producto.

### Módulo 4 · Procesos — pulido opcional
- [ ] Orden de compra **imprimible/PDF** (se apoya en el detalle ya existente; reusar Dompdf).
- [ ] En la recepción, opción de **actualizar `producto.costo`** con el costo real recibido.

### Módulo 5 · Salidas del Sistema — construir de cero (la entrega grande que queda)
- [ ] **Reportes gerenciales**: ventas por período / por cliente / por producto / rentabilidad.
- [ ] **Cierre de caja**: resumen de movimientos del día, totales por forma de pago.
- [ ] **Auditoría**: log de acciones de usuarios y operaciones críticas (trazabilidad de cambios).
- [ ] Exportar/imprimir los reportes (Dompdf ya está en el stack; falta CSV/Excel si se pide).

### Módulo 1 · Seguridad — posible ajuste
- [ ] El enunciado menciona *"permisos asignados por perfil"*. Hoy hay 2 roles con control
      binario (`esAdmin`). Si el profe exige **permisos granulares por módulo**, hay que
      agregar una tabla de permisos y chequeos por acción.

---

## 3. Qué falta para que sea un *producto para la venta* (producción)

Esto es lo que separa un TP funcional de un sistema que un negocio real pueda usar.

### Despliegue e infraestructura
- [ ] Servir con **Apache/nginx + PHP-FPM** (no el `php -S` de desarrollo).
- [ ] **HTTPS** con certificado (Let's Encrypt) y **activar la cookie `secure`** en `Session.php`.
- [ ] `APP_ENV=production` y **`APP_DEBUG=false`** (hoy en `true`: filtra detalles de error).
- [ ] **Backups automáticos** de la base + plan de restauración.
- [ ] Variables de entorno / secretos fuera del repo (ya está: `.env` gitignored) y rotación.

### Seguridad (endurecer)
- [ ] Rate limit también en el inicio de login con Google (`/api/tienda/auth0/login`).
- [ ] Revisión de headers de seguridad (CSP, HSTS, X-Frame-Options).
- [ ] Política de contraseñas y bloqueo por intentos (hoy hay rate limit básico).
- [ ] Auditoría (ver Módulo 5) también cumple función de seguridad.

### Comercial / negocio
- [ ] **Pasarela de pago real** (Mercado Pago / tarjeta). Hoy el pago online es transferencia
      + comprobante subido a mano y revisión manual del admin.
- [ ] Facturación / integración AFIP si se factura formalmente.
- [ ] Textos legales: **términos y condiciones, política de privacidad**, tratamiento de datos
      personales (Ley 25.326). Necesario si maneja datos de clientes reales.
- [ ] Emails transaccionales con dominio propio (hoy SMTP de Gmail con App Password).

### Calidad y mantenimiento
- [ ] **Tests automatizados** más allá del smoke actual (al menos de Services críticos:
      ventas, compras, stock).
- [ ] **Zona horaria global** consistente (PHP vs MySQL) — pendiente de sesiones previas.
- [ ] Refactor de **`public/assets/js/tienda.js`** (~1140 líneas) en módulos (catálogo /
      carrito / auth / favoritos), como ya se hizo con `tienda-home.js`.
- [ ] Monitoreo y logs centralizados; página de error amigable.
- [ ] Índices de base de datos revisados para volumen real.

---

## 4. Deuda técnica y riesgos conocidos

- **`tienda.js` monolítico**: funciona, pero cuesta mantener. Split pendiente (bajo riesgo,
  hacerlo con la app corriendo para verificar).
- **Dinero como `float` con `round(2)`**: alcanza a esta escala; si se necesita precisión
  contable exacta, pasar a centavos enteros o `bcmath` (ya documentado en `VentaService`).
- **Auth0 es opcional**: si no se configura el `.env`, el login por email sigue andando.
- **Servidor de desarrollo**: el proyecto se levanta con `php -S` / `INICIAR-SERVIDOR.bat`;
  no es apto para producción.

---

## 5. Próximos pasos sugeridos (priorizados)

1. **Módulo 5 (Salidas)** — es la entrega grande que falta y la más visible en la defensa:
   empezar por **cierre de caja** y **reportes de ventas**, después **auditoría**.
2. **Completar Módulo 2** — tablas maestras faltantes (provincias/localidades/tipos doc/unidades).
   Es rápido y cierra un checkbox del cronograma.
3. **Pulido Módulo 4** — orden de compra en PDF (barato, reusa Dompdf; queda bien en la demo).
4. **Endurecer para producción** — HTTPS + cookie secure + `APP_DEBUG=false` + backups
   (recién cuando se piense en usarlo de verdad).
5. **Deuda técnica** — split de `tienda.js` y fix de zona horaria, cuando haya aire.

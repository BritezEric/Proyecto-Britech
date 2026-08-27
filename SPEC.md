# Britech — Especificación del proyecto (SPEC)

> **Documento maestro de referencia.** Se mantiene actualizado durante todo el
> desarrollo. Si cambia una regla de negocio o una decisión, primero se actualiza
> acá y en el doc del módulo, antes de tocar código importante.
>
> Última actualización: 2026-08-27

---

## 1. Objetivo

Britech es una plataforma web para gestionar y vender productos tecnológicos,
tanto en **venta física (POS)** como en **tienda online mayorista/minorista**.
Debe servir para un emprendimiento real y crecer con el tiempo.

Además, es un **proyecto de aprendizaje**: el código debe ser simple, claro y
entendible. Prioridad: **funciona → seguro → fácil de entender → mantenible → escalable**.

---

## 2. Alcance general

Módulos previstos (ver mapa completo en sección 6):
venta física (POS), tienda online, stock, productos, clientes, vendedores,
pedidos, precios minorista/mayorista, compras/sobre pedido, envíos, tickets/
comprobantes, facturación AFIP, usuarios y permisos, dashboard, documentación y
auditoría.

**Construido y en uso:** POS (ventas), ABM admin (Entrada de Datos), tienda online
(catálogo + carrito + checkout 2 pasos + pedidos), **Envíos + Moto Express +
Repartidores** (con envío también desde el POS), empleados/sueldos y dashboard.
**Próximo:** gateway de pago real (MP/Tarjeta), compras/aprovisionamiento y AFIP.

---

## 3. Actores

| Actor | Descripción |
|---|---|
| **Administrador** | Acceso completo. Gestiona todo, anula ventas, configura. |
| **Vendedor** | Usa el POS y funciones de venta. Sin acceso a lo administrativo sensible. |
| **Cliente** | A quién se le vende. Minorista o mayorista (aprobado). Puede ser "Consumidor Final". |

> Nota: **usuario** (quien se loguea) y **cliente** (entidad comercial) son cosas
> distintas y viven en tablas separadas.

---

## 4. Arquitectura

**PHP puro + MVC propio por capas. Sin framework PHP, sin ORM.** (Para aprender los
mecanismos; migración a Laravel más adelante como aprendizaje.)

**Backend y frontend separados:** el backend expone una **API** que devuelve
**JSON**; el frontend (HTML/CSS/JS) consume esa API con `fetch`. Un solo proyecto.

```
FRONTEND (public/: HTML + CSS + JS)
   │  fetch('/api/...')  →  pide/envía JSON
   ▼
BACKEND (API)
public/index.php   → Front Controller (única entrada)
   │
Router             → qué controlador según la URL
   │
Middleware         → Auth, CSRF, permisos (validados en BACKEND)
   │
Controller         → recibe, valida, DEVUELVE JSON (no HTML)
   │
Service            → lógica de negocio (VentaService…)  ← el corazón
   │
Repository (PDO)   → único lugar con SQL (prepared statements)
   │
MySQL / MariaDB
```

**Responsabilidades:**
- **Controller:** recibe la petición, valida entrada, decide la respuesta. No lleva lógica de negocio.
- **Service:** reglas del negocio (ej: confirmar venta = validar stock + descontar + registrar + comprobante, en una transacción).
- **Repository:** todo el SQL, con PDO + prepared statements.
- **View:** plantillas PHP (HTML).

---

## 5. Stack tecnológico

| Categoría | Herramienta | Estado |
|---|---|---|
| Lenguaje / BD | PHP, MySQL 8.4, PDO | En uso |
| Entorno | Laragon | En uso |
| Dependencias | **Composer** + autoload PSR-4 | A instalar (cimientos) |
| Config | **vlucas/phpdotenv** (`.env`) | A instalar (cimientos) |
| Correo | PHPMailer | Módulo Auth |
| PDF (ticket) | Dompdf | Sub-etapa tickets |
| Facturación | AFIP SDK (a definir) | Final de Ventas |
| Frontend | HTML/CSS/JS vanilla | En uso |
| Comunicación | **AJAX** (`fetch`) back ↔ front | En uso |
| Infraestructura | **Docker** (contenedores PHP + MySQL) | En implementación |
| Códigos de barra | lectura = scanner (sin lib); generación = picqer/php-barcode | Después |

**Opcional/futuro:** framework CSS (arrancamos con CSS propio), Alpine.js,
Chart.js (dashboard), Laravel, PHPUnit, Git.

---

## 6. Mapa de módulos y orden de desarrollo

| Orden | Módulo | Estado |
|---|---|---|
| 0 | Cimientos (Composer, `.env`, PDO, Router) | ✅ hecho |
| 1 | Auth mínimo (login/sesión) | ✅ hecho |
| 2 | Base mínima para vender (producto/cliente/precio/stock) | ✅ hecho |
| 3 | **⭐ Ventas (POS)** | ✅ hecho |
| 4 | Ampliar Productos/Clientes/Stock + ABM genérico | ✅ hecho |
| 5 | Pedidos → Envíos → Tienda online | ✅ hecho |
| 5b | **Moto Express + barrios + Repartidores + envío en POS** | ✅ hecho |
| 6 | Dashboard | ✅ hecho (accesos, KPIs, finanzas) |
| 7 | Compras / aprovisionamiento (sobre pedido) | pendiente |
| 8 | AFIP, seguridad final, testing, optimización | pendiente |

Enfoque: **rebanada vertical** — versión mínima de cada pieza para que una venta
funcione de punta a punta, y luego ampliar. No construir módulos completos a medias.

---

## 7. Decisiones tomadas (registro)

1. Proyecto reescrito limpio en `Proyecto Britech`; BD `britech_v2`.
2. Arquitectura MVC propia por capas; sin framework/ORM.
3. **Venta ≠ Pedido** (entidades separadas).
4. Stock: **pool único** + libro mayor (`movimiento_inventario`).
5. Facturación: **ticket interno primero, AFIP al final**.
6. Anulación de ventas: **en código PHP** (no trigger), solo admin, con motivo + registro.
7. Roles: **un rol por usuario** ahora; RBAC completo con módulo Usuarios.
8. Precio por cliente vía **`lista_precio_id`** (minorista/mayorista).
9. Descuentos: **por línea y sobre el total**.
10. Sin stock **no se vende**, salvo **sobre pedido**.
11. Pagos: efectivo + transferencia (tarjetas después).
12. Dinero en **DECIMAL**, nunca FLOAT. Precio **congelado** en `venta_detalle`.
13. **Backend y frontend separados:** backend = API que devuelve JSON; frontend = HTML/JS que la consume con `fetch`. Un solo proyecto.
14. **AJAX:** el frontend habla con el backend por AJAX (peticiones asíncronas sin recargar la página) usando `fetch`. Ya en uso desde el POS. *(A confirmar si el curso exige `XMLHttpRequest` clásico.)*
15. **Docker:** **en implementación** (lo pide el curso desde ahora). Config lista (`Dockerfile`, `docker-compose.yml`) para correr Britech en contenedores PHP+MySQL; falta instalar Docker Desktop para probarla. No cambia el código, cambia cómo se corre. Ver [docs/docker.md](docs/docker.md).
16. **Metodología 12-Factor:** el proyecto sigue los [12 factores](https://12factor.net/). Ya cumplimos varios (Composer, `.env`, Docker para dev/prod parity). Ver mapeo y acciones en [docs/doce-factores.md](docs/doce-factores.md).
17. **Sistema de diseño unificado:** identidad propia comercial-cálida (verde de marca `#048b56` + acento ámbar `#e18600`, tipografía Hanken Grotesk), **no** look de plantilla/admin, ni ruidoso, ni anticuado. Tokens únicos en [`public/assets/css/tokens.css`](public/assets/css/tokens.css); estrategia y componentes en [DESIGN.md](DESIGN.md); registro/usuarios en [PRODUCT.md](PRODUCT.md). Aplicado a login, POS y ventas. (Guiado con la skill *impeccable*: paleta OKLCH, contraste WCAG AA, motion contenido, principios de emil-design-eng/apple.)
18. **Módulo de Entrada de Datos (panel admin):** ABM completo de las tablas maestras — **Clientes, Productos (+precio +stock inicial), Proveedores, Categorías, Marcas** — con **búsqueda, filtros y paginación** (server-side). Un framework de ABM reutilizable en el frontend (`admin.js` guiado por config por entidad) y un patrón backend por capas (Repository → Service con validaciones → Controller). Baja **lógica** (`activo=0`), no borrado físico. Ver [docs/modulos/entrada-datos.md](docs/modulos/entrada-datos.md).
19. **Tienda online + navegación por rol:** tienda pública con **registro/login de cliente** (el cliente ES un `cliente` con `password_hash`), catálogo con precio por lista, carrito y **checkout que crea un Pedido** (`Venta ≠ Pedido`, no descuenta stock). El admin ve y gestiona pedidos (estado + detalle) en el panel. Navegación por rol: landing `/` (hub) → **admin** al panel, **vendedor** al POS, **cliente** a la tienda. Ver [docs/modulos/entrada-datos.md](docs/modulos/entrada-datos.md).
20. **Envíos: Moto Express por barrio + Repartidores:** medio de envío local **Moto Express** que en el checkout pide un **barrio** (precio fijo, ABM `barrio`) + calle y altura, en vez de la dirección completa. **Repartidores** (ABM, sin login) que el admin **deriva** a cada envío desde la vista **Repartos** (tablero de sin-asignar); su **paga** = suma del precio de los barrios de sus envíos **entregados** en el día. Un **envío** puede colgar de un **pedido** (tienda) **o** de una **venta** (POS) — `envio.venta_id` + `pedido_id` NULL-able. Regla: **no se marca "entregado"** si el pago del pedido no está `pagado` (las ventas del POS ya se cobraron). Utilidades: **ticket imprimible** y **mensaje de WhatsApp** (`wa.me`, sin API) con los envíos a repartir. Ver [docs/modulos/envios-repartidores.md](docs/modulos/envios-repartidores.md).
21. **Métodos de pago en el checkout + envío en el POS:** el checkout de la tienda es de **2 pasos** (entrega → método de pago: Transferencia con comprobante, Mercado Pago, Tarjeta; `pedido.metodo_pago`) — MP/Tarjeta **aún sin gateway real**. El **POS** puede cargar un envío en la venta; su costo **se suma al total** (sube el ticket) y el pago debe cuadrar con productos + envío. Cortes por fecha con `CURDATE()` de MySQL (evita el desfase de zona horaria). Ver [docs/modulos/envios-repartidores.md](docs/modulos/envios-repartidores.md).

### Patrones de diseño (se nombran mientras se aprenden)
No se agregan patrones "porque sí" (eso empeora el código). Se usan donde
resuelven un problema real, y se etiquetan al aparecer:
- **Singleton** → `App\Core\Database::conexion()` (una sola conexión).
- **Front Controller** → `public/index.php` (única entrada).
- **Router** → `App\Core\Router`.
- **Repository** → `App\Repositories\*` (aísla el SQL).
- **Service Layer** → `App\Services\*` (aísla la lógica de negocio; llega en Ventas).
- **MVC** → estructura general.

---

## 8. Modelo de datos

- **Módulo Ventas (14 tablas):** ver [docs/modulos/ventas-modelo-datos.md](docs/modulos/ventas-modelo-datos.md) — implementado en [database/schema_ventas.sql](database/schema_ventas.sql).
- **Envíos / Moto Express:** tablas `barrio` y `repartidor`; `envio` ampliado con `barrio_id`, `repartidor_id`, `venta_id` (y `pedido_id` NULL-able); `empresa_envio.es_moto`; `pedido.metodo_pago`. Ver [docs/modulos/envios-repartidores.md](docs/modulos/envios-repartidores.md) — scripts [`schema_moto_barrios.sql`](database/schema_moto_barrios.sql), [`schema_envio_venta.sql`](database/schema_envio_venta.sql), [`schema_metodo_pago.sql`](database/schema_metodo_pago.sql).
- Diseño normalizado a **3FN**: sin datos duplicados, relaciones por FK, integridad referencial (InnoDB).

---

## 9. Seguridad (línea base, transversal)

- PDO + **prepared statements** siempre (anti SQL injection).
- Contraseñas con **hash** (`password_hash`), nunca en texto.
- Autorización verificada en **backend** (middleware), no solo ocultando botones.
- **CSRF** token en formularios que modifican datos.
- Escapar salida HTML (anti **XSS**).
- Credenciales en **`.env`** (fuera del código y del repositorio vía `.gitignore`).
- Operaciones importantes que tocan datos van dentro de **transacciones**.
- Registro en **auditoría** de acciones sensibles (venta creada, anulada, etc.).

---

## 10. Convenciones

- **Idioma del dominio:** español (`venta`, `cliente`, `producto`), coherente con la BD.
- **Nombres de tabla:** singular, snake_case (`venta_detalle`).
- **Nombres de clase:** PascalCase (`VentaService`, `VentaController`).
- **Un archivo, una clase.**
- Comentarios **solo cuando aportan** (explican el *por qué*, no el *qué* obvio).
- Documentación **viva**: cada módulo se documenta antes y después de construirlo.

---

## 11. Documentación del proyecto

```
SPEC.md                          ← este documento (referencia maestra)
docs/modulos/ventas.md           ← spec del módulo Ventas
docs/modulos/ventas-modelo-datos.md
docs/modulos/tienda-online.md    ← nota de planificación
docs/modulos/entrada-datos.md    ← ABM admin + tienda + dashboard
docs/modulos/envios-repartidores.md  ← Moto Express, barrios, repartidores, Repartos, pago
database/schema_ventas.sql       ← esquema SQL de Ventas
database/schema_moto_barrios.sql · schema_envio_venta.sql · schema_metodo_pago.sql
```

A medida que avancemos se sumarán: docs de otros módulos, registro de decisiones
(ADR) si hace falta, y una bitácora de errores/soluciones.

---

## 12. Método de trabajo

Cada funcionalidad importante sigue el ciclo:
**Entender → Documentar → Diseñar → Programar → Explicar → Probar → Documentar lo hecho → Revisar.**

Reglas: no avanzar sin terminar lo anterior; avanzar despacio y entendiendo; señalar
problemas de diseño antes de programar; evitar sobreingeniería; priorizar seguridad,
simplicidad y mantenibilidad.

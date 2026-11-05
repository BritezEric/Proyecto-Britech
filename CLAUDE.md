# Britech — contexto para Claude

Sistema de gestión para un comercio de tecnología: POS (ventas en el local), tienda online,
panel de administración, envíos con repartidores, cajas, reclamos y reportes.
Proyecto en equipo: **Eric** (transacciones/pagos y casi todo lo base), **Tobi** (notificaciones)
y **Franco** (Módulo 5: Salidas del Sistema — dashboards y reportes). Se habla en español.

## Stack y arquitectura
- PHP 8.3 + MySQL 8.4 (Laragon en `C:\laragon`), Composer (Dompdf, Dotenv, Mercado Pago).
- Backend propio: `routes/api.php` → `app/Controllers` → `app/Services` → `app/Repositories` → PDO.
  `Session::esAdmin()` / `esStaff()` para permisos; rutas con `true` exigen sesión (401).
- Frontend: HTML + JS puro en `public/` (sin frameworks). Panel admin = `public/admin.html`
  + `public/assets/js/admin*.js`. Gráficos con Chart.js 4.4.1 (ya incluido).
- Configuración en `.env` (no se versiona; ver `.env.example`). `DB_NAME` indica la base.

## Cómo levantarlo
- Doble clic en `INICIAR-SERVIDOR.bat`: busca PHP de Laragon, verifica MySQL y abre
  `http://127.0.0.1:8123/login.html` (si el 8123 está ocupado usa el siguiente libre).
- MySQL de Laragon tiene que estar prendido. Base local con datos reales: `britech_original`.
- Base nueva: `mysql -u root < database/instalar.sql` (crea `britech_v2`). Los scripts de
  reportes (`schema_reportes_localidad.sql`, `schema_reportes_operativos.sql`) se aplican aparte.

## Lo que hizo Franco (Módulo 5)
1. **Dashboard del vendedor ("Mi panel")** — `VendedorDashboardController`,
   `VendedorDashboardRepository`, `public/assets/js/admin-vendedor.js`, ruta
   `GET /api/vendedor/dashboard?periodo=dia|semana|mes|ano`. Cada vendedor ve solo lo suyo
   (el id sale de la sesión). Se abre con el botón "Mi panel" del POS o `/admin.html#vendedor`.
   Doc: `docs/modulos/dashboard-vendedor.md`, guía oral: `docs/exposicion/dashboard-vendedor-franco.md`.
2. **Reporte por localidades** — `ReporteLocalidad*`, `admin-reportes.js`,
   `database/schema_reportes_localidad.sql`. Doc: `docs/modulos/reporte-localidades.md`.
3. **Reportes y control operativo (solo admin)** — Panel → Reportes: medios de pago, cajas y
   anulaciones, vendedores, tienda online, reclamos, envíos sin asignar, carga por barrio.
   `ReporteOperativo*`, `admin-reportes-operativos.js`, rutas `/api/admin/reportes/operativo*`
   (datos, opciones, csv, pdf). Script `database/schema_reportes_operativos.sql` (agrega
   `envio.asignado_en`, `pedido.actualizado_en` e índices; idempotente).
   Doc: `docs/modulos/reportes-operativos.md`.

## Reglas del equipo
- No borrar datos ni tocar claves primarias/foráneas; cambios de base solo con scripts
  `database/schema_*.sql` que se puedan volver a ejecutar.
- Consultas siempre preparadas (PDO con `?`); validar filtros en el servidor.
- Reusar estilos y componentes existentes; no agregar librerías sin consultarlo.
- No commitear `.env` ni claves (Groq, ElevenLabs, Mercado Pago).

## Pruebas
- `php tests/smoke.php`
- Reportes (solo contra una base cuyo nombre termine en `_test`, con el servidor levantado):
  `DB_NAME=britech_reportes_test BRITECH_TEST_URL=http://127.0.0.1:8125 php tests/reportes_operativos_test.php`
  y `tests/reporte_localidades_test.php`.

## Repositorios
- Repo del equipo: `https://github.com/BritezEric/Proyecto-Britech` (rama `master`).
- Repo personal de Franco: `https://github.com/jojotfranco5-lgtm/Proyecto-Britech`.

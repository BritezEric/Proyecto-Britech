# Britech

Plataforma de gestión y venta de productos tecnológicos: **POS** (punto de venta),
**tienda online** y **panel de administración** (con finanzas). Proyecto de la materia
*Módulo de Entrada de Datos*.

- **Stack:** PHP 8.1+ · MySQL/MariaDB (InnoDB, utf8mb4) · PDO · Composer · PHPMailer
- **Arquitectura:** MVC por capas → `Front Controller → Router → Controller → Service → Repository → PDO`
- **Front:** HTML + CSS + JS puro (sin frameworks), consumiendo la API JSON.

## Requisitos

- PHP **8.1 o superior** con extensiones `pdo_mysql`, `mbstring`, `openssl`
- MySQL 8 / MariaDB 10.4+
- [Composer](https://getcomposer.org/)
- (Laragon incluye todo lo anterior)

## Instalación

**1. Dependencias**

```bash
composer install
```

**2. Base de datos** — importar los `.sql` **en este orden** (las FKs dependen de él).
El primero crea la base `britech_v2`; el resto hace `USE britech_v2`.

```
1.  database/schema_ventas.sql          (base: rol, usuario, cliente, producto, inventario, venta…)
2.  database/schema_auth.sql            (tokens de verificación/reset de staff)
3.  database/schema_datos_maestros.sql  (categoría, marca, proveedor + FKs de producto)
4.  database/schema_min_mayorista.sql   (columna min_mayorista en producto)
5.  database/schema_imagenes.sql        (imágenes de producto)
6.  database/schema_tienda.sql          (pedidos online)
7.  database/schema_envios.sql          (empresas de envío + envíos)
8.  database/schema_favoritos.sql       (favoritos de clientes)
9.  database/schema_mayorista.sql       (solicitudes mayoristas)
10. database/schema_gastos.sql          (gastos / finanzas)
11. database/schema_bloques.sql         (page builder de la home — independiente)
```

Desde la terminal (uno por uno, o encadenados):

```bash
mysql -u root < database/schema_ventas.sql
mysql -u root britech_v2 < database/schema_auth.sql
mysql -u root britech_v2 < database/schema_datos_maestros.sql
mysql -u root britech_v2 < database/schema_min_mayorista.sql
mysql -u root britech_v2 < database/schema_imagenes.sql
mysql -u root britech_v2 < database/schema_tienda.sql
mysql -u root britech_v2 < database/schema_envios.sql
mysql -u root britech_v2 < database/schema_favoritos.sql
mysql -u root britech_v2 < database/schema_mayorista.sql
mysql -u root britech_v2 < database/schema_gastos.sql
mysql -u root britech_v2 < database/schema_bloques.sql
```

**3. Variables de entorno** — copiar la plantilla y completar:

```bash
cp .env.example .env
```

Editar `.env`: credenciales de la base y, para que salgan los correos, `MAIL_USER` +
`MAIL_PASSWORD` (para Gmail, una **App Password** con 2FA activado). **Nunca** subir el `.env`.

## Levantar el proyecto

Servidor de desarrollo de PHP:

```bash
php -S 127.0.0.1:8123 -t public public/index.php
```

O doble clic en **`INICIAR-SERVIDOR.bat`** (Windows). Luego:

- Tienda: <http://127.0.0.1:8123/tienda.html>
- Panel admin: <http://127.0.0.1:8123/admin.html>
- POS: <http://127.0.0.1:8123/pos.html>

## Credenciales de prueba

| Rol   | Usuario                 | Contraseña  |
|-------|-------------------------|-------------|
| Admin | `admin@britech.local`   | `admin1234` |

(Los clientes de la tienda se registran desde `tienda.html` con verificación por correo.)

## Prueba automatizada

Smoke test de arranque (autoload + config + BD + login admin + reglas de validación):

```bash
php tests/smoke.php
```

## Estructura

```
app/
  Controllers/   Reciben la petición HTTP, validan la sesión, responden JSON.
  Services/      Lógica de negocio y validaciones (precio/stock SIEMPRE en backend).
  Repositories/  Acceso a datos con PDO preparado (nada de SQL concatenado).
  Core/          Router, Request, Response, Session, Database, Mailer, RateLimit…
config/          Configuración central (lee .env / entorno).
database/        Esquemas SQL + datos de semilla.
docs/            Documentación por módulo + guía de defensa del código.
public/          Front (HTML/CSS/JS) + index.php (front controller).
routes/          Definición de rutas de la API.
```

## Equipo

- **Eric** — admin / backend (Services + Repositories)
- **Tobi** — Controllers + Router
- **Franco** — Frontend (tienda)

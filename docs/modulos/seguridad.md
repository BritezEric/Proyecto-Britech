# Seguridad (línea base transversal)

> Estado: **aplicada en todo el sistema**. Complementa a `docs/modulos/autenticacion.md`
> (login, verificación de correo y recuperación de contraseña).

La seguridad no es un módulo aislado: es una línea base que atraviesa todas las capas.

## Autenticación y sesiones
- Contraseñas con **hash** (`password_hash` / `password_verify`), nunca en texto plano.
- **Sesiones nativas endurecidas**: regeneración de ID al iniciar sesión, cookies
  `httponly`, `SameSite=Lax` y modo estricto.
- **Dos identidades separadas**: `usuario` (staff: admin/vendedor) y `cliente`
  (tienda), en tablas y sesiones distintas y **mutuamente excluyentes** (iniciar
  sesión como cliente cierra la de staff y viceversa).
- **Limitación de intentos** (rate limiting) en login y registro (tabla
  `intento_acceso`), para frenar fuerza bruta.
- **Login con Google** mediante Auth0 (opcional) para clientes, con cierre de
  sesión real.

## Autorización
- Verificada **en el backend**, no sólo ocultando botones en el frontend.
- `Session::esAdmin()` (solo admin), `Session::esStaff()` (admin o vendedor) y
  `Session::cliente()` (cliente de la tienda) controlan el acceso en cada endpoint.
- Las rutas se marcan como protegidas en `routes/api.php` (`true` = requiere login).

## Datos y base de datos
- **PDO con sentencias preparadas siempre** → previene inyección SQL. Todo el SQL
  vive en los repositorios; no se concatena SQL con datos del usuario.
- Para ordenamientos dinámicos se usan **listas blancas** de columnas.
- Operaciones que tocan datos importantes (ventas, compras, caja) van dentro de
  **transacciones** (`beginTransaction` / `commit` / `rollBack`).

## Entrada y salida
- **Escape de la salida HTML** en el frontend (función `esc`) → previene XSS.
- Validación de archivos subidos (comprobantes, imágenes) por **contenido y tamaño**.

## Pagos
- El **monto lo calcula el backend**; el navegador no puede alterarlo.
- Los pagos de Mercado Pago se **verifican contra el servidor de MP**; no se confía
  en lo que llega por la URL o el webhook.

## Secretos y configuración
- Credenciales y tokens (base de datos, correo, Mercado Pago, Auth0) viven en
  **`.env`**, fuera del código y **excluido del repositorio** (`.gitignore`).
- `.env.example` documenta las variables sin valores reales.

## Pendiente para producción
- Servir por **HTTPS** y activar la cookie `secure`.
- `APP_DEBUG=false` (hoy en desarrollo expone detalles del error).
- Revisión de cabeceras de seguridad (CSP, HSTS, X-Frame-Options).
- **Auditoría** completa de acciones y operaciones críticas.

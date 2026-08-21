# Módulo: Autenticación

> Estado: **EN DISEÑO** (2026-08-19). Se construye por sub-pasos (B1–B4).

## 1. Objetivo
Que solo usuarios registrados y verificados puedan entrar al sistema, de forma
segura, con recuperación de contraseña. Reemplaza el vendedor fijo (`id=1`) del POS.

## 2. Alcance
- **Login / Logout** con sesión.
- **Sesiones seguras** (recordar quién está conectado).
- **Proteger** el POS y la API (middleware): sin login, no se entra.
- **Verificación de correo** (con PHPMailer): el usuario confirma su email.
- **Recuperación de contraseña** ("olvidé mi contraseña", con PHPMailer).
- El usuario logueado queda como **vendedor** de cada venta.

✅ **Decisión — Registro (Opción A):** el **admin crea** los usuarios (vendedores).
Al crearlos, se les manda un correo para **verificar + definir su contraseña**.
No hay auto-registro de usuarios del sistema. (El registro de *clientes* de la
tienda online es otra cosa, va en ese módulo.)

## 3. Actores
- **Usuario no autenticado:** solo ve login / recuperar contraseña.
- **Admin / Vendedor:** usuarios del sistema (tabla `usuario`, con `rol`).

## 4. Flujos principales

**Login**
```
Ingresa email + contraseña → backend verifica con password_verify()
  → si ok y email verificado → crea sesión → entra al POS
  → si no → mensaje de error (sin decir si falló el email o la clave)
```

**Verificación de correo**
```
Se crea el usuario → se genera un TOKEN → se envía por email un link
  → el usuario hace clic → el backend valida el token → email_verificado = 1
```

**Recuperación de contraseña**
```
"Olvidé mi contraseña" → ingresa email → se genera TOKEN → link por email
  → el usuario abre el link → formulario de nueva contraseña
  → backend valida token (no vencido, no usado) → guarda nuevo hash
```

## 5. Modelo de datos
- `usuario` (ya existe) + nueva columna **`email_verificado`** (0/1).
- `rol` / `usuario_rol` → por ahora `usuario.rol_id` (un rol).
- **`usuario_token`** (nueva): tokens de verificación y de reset.
  - id, usuario_id (FK), tipo (`verificacion` | `reset`), **token_hash**,
    expira_en, usado (0/1), creado_en.
  - Se guarda el **hash** del token (sha256), no el token en texto. El link lleva
    el token real; se busca por su hash. (Si se filtra la base, los tokens no sirven.)

## 6. Seguridad
- Contraseñas con **`password_hash()`** (nunca en texto). Verificación con `password_verify()`.
- Tokens **aleatorios** (`random_bytes`), guardados **hasheados**, con **expiración**
  (verificación ~24 h, reset ~1 h) y de **un solo uso** (`usado`).
- **Sesión**: `session_regenerate_id()` al iniciar sesión (evita *session fixation*).
- Mensajes de error **genéricos** (no revelar si el email existe).
- **CSRF** token en los formularios.
- Credenciales SMTP en **`.env`** (nunca en el código ni en el repo).

## 7. Correo — PHPMailer + SMTP
- Librería **PHPMailer** (vía Composer).
- SMTP de Gmail con **App Password** (requiere 2FA en la cuenta de Google).
- Config en `.env`: `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASSWORD`, `MAIL_FROM`.

## 8. Sub-pasos de construcción
- ✅ **B1 — Núcleo** (2026-08-19): sesión (`App\Core\Session`), login/logout/yo
  (`AuthService`, `AuthController`), middleware de auth en el `Router` (rutas con
  `true` = requieren login), página `login.html`, POS protegido (redirige al login
  si no hay sesión), y la venta usa el **usuario logueado** como vendedor.
  Probado: login OK/erróneo, rutas protegidas (401), logout.
- ✅ **B2 — PHPMailer** (2026-08-19): instalado vía Composer; `App\Core\Mailer`
  envía por SMTP con credenciales del `.env`. Correo de prueba enviado OK.
- ✅ **B3 — Registro + verificación** (2026-08-19): el admin crea usuarios
  (`UsuarioService`/`UsuarioController`, solo admin), se genera token y se manda
  correo; el usuario define su contraseña en `verificar.html`. `TokenRepository`
  (tokens hasheados, expiración, un solo uso). Probado end-to-end.
- ✅ **B4 — Recuperación de contraseña** (2026-08-19): `olvide.html` → correo con
  link → `restablecer.html`. No revela si el email existe. Probado: la clave vieja
  deja de funcionar tras el reset.

# Módulo: Auditoría (Módulo 5 — Salidas del Sistema)

Registra **quién hizo qué, cuándo y qué cambió** en las operaciones importantes de Britech,
y le permite al administrador consultarlo, filtrarlo y exportarlo a PDF o Excel.

## Cómo se registra (siempre desde el backend)
Todo pasa por `app/Services/Auditoria.php`, que escribe con `AuditoriaRepository`:

| Método | Para qué |
|---|---|
| `Auditoria::evento()` | Un hecho: login, alta, baja, anulación, cambio de estado… |
| `Auditoria::campo()` | Un campo que cambió, con **valor anterior y nuevo** |
| `Auditoria::cambios()` | Compara antes/después y guarda una fila por campo cambiado |
| `Auditoria::stock()` | Movimiento de stock: cantidad anterior → nueva y el motivo |

Toma el usuario de la sesión y la IP del pedido. Si el registro falla, **nunca frena la
operación real**. Los campos sensibles (contraseña, token, PIN, clave) se guardan como
`[oculto]`. JavaScript no registra nada: solo lee.

## Qué queda registrado automáticamente
| Módulo | Operaciones | Dónde se engancha |
|---|---|---|
| Sesiones | login, logout, login fallido (con el email probado, nunca la contraseña) | `AuthService` |
| Usuarios | alta, activación de la cuenta, cambio de contraseña, **cambio de rol**, activar/desactivar | `AuthService`, `UsuarioService` |
| Productos | alta, baja y cada campo editado: **precios**, costo, estado, categoría… | `ProductoService`, `PrecioMasivoService` |
| Stock | **toda** entrada, salida o ajuste, con cantidad anterior → nueva y motivo (venta, pedido, compra, anulación, ajuste manual) | `InventarioRepository` (único lugar que toca el stock) |
| Ventas | alta de venta en el POS y **anulación** con su motivo | `VentaService` |
| Pedidos | cambio de estado y de estado de pago | `PedidoController` |
| Caja | apertura, retiros/ingresos y cierre (esperado → contado y diferencia) | `CajaService` |
| Clientes | alta, edición, baja y aprobación/rechazo del acceso mayorista | `ClienteService`, `MayoristaService` |
| Proveedores | alta, edición y baja | `ProveedorService` |
| Configuración | cambios en los datos de transferencia (alias, CBU, titular, banco) | `ConfigController` |

## Pantallas (panel admin)
- **Dinero e informes → Auditoría**: resumen por tipo de operación, filtros por usuario,
  acción, módulo y fechas, buscador, tabla paginada con "antes → después" y botones
  **Exportar PDF** y **Exportar Excel** (respetan los filtros).
- **Personas → Usuarios del sistema**: cambiar el rol (permisos) o desactivar una cuenta.
  No deja que un admin se quite su propio rol ni que el sistema quede sin administradores.

## Seguridad
- Solo el admin consulta o exporta (`403` para vendedores, `401` sin sesión).
- No hay rutas para editar ni borrar registros, y **la base rechaza `UPDATE` y `DELETE`**
  sobre la tabla con dos triggers, aunque se intente desde phpMyAdmin.
- Filtros validados en el servidor (fechas `AAAA-MM-DD`, módulo/acción `[a-z_]`) y
  consultas preparadas.

## API (solo lectura)
| Método | Ruta | Qué |
|---|---|---|
| GET | `/api/admin/auditoria` | listado + resumen + opciones de filtros |
| GET | `/api/admin/auditoria/pdf` | PDF con los filtros (hasta 1000 filas) |
| GET | `/api/admin/auditoria/excel` | Excel `.xlsx` con los filtros (hasta 5000 filas) |
| GET/POST | `/api/admin/usuarios`, `/guardar`, `/baja` | gestión de rol y estado |

Filtros: `usuario_id`, `accion`, `entidad` (módulo), `desde`, `hasta`, `q`.
El Excel lo arma `app/Core/Xlsx.php` (sin librerías ni la extensión zip de PHP).

## Base de datos
`database/schema_auditoria.sql` — re-ejecutable y no destructivo: crea la tabla `auditoria`
si falta, le agrega la columna `ip` si es una versión vieja y crea los triggers de solo lectura.
```
mysql -u root britech_original < database/schema_auditoria.sql
```

## Pruebas
`tests/auditoria_test.php` hace operaciones reales por HTTP (login, venta, anulación, precio,
stock, rol) y verifica lo registrado, filtros, exportes, permisos e inmutabilidad.
Solo sobre una base `*_test` con el servidor levantado sobre esa base:
```
DB_NAME=britech_auditoria_test php -S 127.0.0.1:8125 -t public server.php
DB_NAME=britech_auditoria_test BRITECH_TEST_URL=http://127.0.0.1:8125 php tests/auditoria_test.php
```

---

# Centro de control (ampliación)

Amplía el mismo sistema de auditoría: no hay un segundo registro. Todo sale de la tabla
`auditoria` y de las tablas reales del sistema; la pantalla solo consulta.

## Secciones (Panel → Dinero e informes → Auditoría)
Arriba hay **tarjetas del día**, calculadas en la base: alertas pendientes, anulaciones de hoy,
cajas con diferencia, actividades sospechosas, ajustes manuales de stock y operaciones registradas.
Al tocar una tarjeta se abre la sección con ese filtro puesto.

| Pestaña | De dónde salen los datos | Detalle (panel sin salir de la pantalla) |
|---|---|---|
| Historial general | `auditoria` | registro completo: IP, dispositivo, huella, alertas y enlaces al documento |
| Alertas | `auditoria_alerta` | operación que la originó, persona relacionada, marcar como revisada |
| Ventas y anulaciones | `venta` + `pago` + `venta_anulacion` (POS) y `pedido` (online) | productos, pago, stock, caja, anulación y su historial |
| Cajas y dinero | `caja` + `venta`/`pago` + `venta_anulacion` + `caja_movimiento` | movimientos en orden con saldo anterior/posterior |
| Movimientos de stock | filas `stock` de `auditoria` (anterior → resultante) | historial del producto, más `movimiento_inventario` |
| Usuarios y accesos | filas `sesion` y `usuario` de `auditoria` | quién lo hizo, a quién, rol/estado antes → después, IP y dispositivo |

Cada pestaña tiene sus propios filtros, que se combinan entre sí y los aplica el servidor.
Exportar PDF o Excel usa la pestaña y los filtros elegidos, y cada exportación queda registrada.

## Alertas automáticas (`App\Services\AlertaService`)
Las genera el backend cada vez que se guarda un registro de auditoría. Ninguna sale de la pantalla.

| Situación | Cuándo | Nivel |
|---|---|---|
| Login fallido | 3 / 5 intentos en 15 min (misma IP o misma cuenta) | advertencia / crítica |
| IP bloqueada | se alcanzó el límite de `RateLimit` | crítica |
| Acceso sin permiso (403) | 3 / 10 en 10 min, misma cuenta | advertencia / crítica |
| Precio masivo | 10 / 50 cambios en 10 min, mismo usuario | advertencia / crítica |
| Anulaciones | 3 / 5 ventas anuladas en el día, mismo vendedor | advertencia / crítica |
| Ajustes de stock | 5 / 10 ajustes manuales en el día, mismo usuario | advertencia / crítica |
| Ajuste inusual | ±20 u., o baja de más del 50 % (stock ≥ 10) | advertencia |
| Caja con diferencia | esperado − declarado ≠ 0 (crítica desde $ 10.000) | advertencia / crítica |
| Cambio de rol | a administrador / a otro rol | crítica / advertencia |
| Cuenta | desactivada o alta de un admin / reactivada | advertencia / informativa |
| Acceso nuevo | login desde una IP o un navegador nunca usados por esa cuenta | informativa |

Los umbrales están en `AlertaService::UMBRALES`. Ubicación geográfica: el sistema no tiene datos
de ubicación, así que el aviso se basa solo en la IP y el navegador.

Una alerta no acusa a nadie: describe un comportamiento para que lo revise un administrador.
La `clave` única evita duplicados. Si se cruza un umbral mayor, se crea otra alerta de nivel más alto.
Al revisarla se guarda quién, cuándo y una nota. La alerta no se borra y la revisión no se deshace:
los triggers de la base lo impiden, y la revisión también queda registrada en `auditoria`.

## Registro desde el backend
- **Ventas del POS:** se registra el alta (tipo, cliente, total y medios de pago) y la anulación
  (estado anterior → nuevo, motivo y quién autorizó). Las dos van **dentro de la transacción**:
  si la operación se deshace, el registro también.
- **Pedidos online:**
  - El alta queda registrada dentro de su transacción.
  - Los cambios de estado guardan el estado anterior → nuevo; la cancelación se registra como anulación.
  - Los cambios de pago (revisión manual o Mercado Pago) se registran en `PedidoRepository`,
    así cubren todas las vías.
- **Accesos sin permiso:** el router registra cualquier respuesta 403 de un usuario con sesión,
  sin importar desde qué pantalla o herramienta vino el pedido.
- **Datos sensibles:** nunca se guardan contraseñas ni tokens. El navegador se guarda como User-Agent.
- **Duplicados:** un mismo evento no se registra dos veces dentro del mismo pedido HTTP.

## Integridad (hashes encadenados)
Cada registro nuevo guarda `hash_anterior` y `hash = HMAC-SHA256(hash_anterior + contenido)`,
firmado con `AUDIT_HMAC_KEY` del `.env`. El botón **Verificar integridad** recalcula la cadena
y muestra la huella actual.

**Qué detecta:**
- un registro cuyo contenido fue modificado;
- un registro borrado del medio de la cadena (otra fila apunta a él).

**Qué no detecta (límites reales):**
- **Últimos registros:** que alguien borre los registros más recientes.
- **Clave y acceso a la base:** que alguien que tenga la clave y acceso a la base recalcule toda la cadena.
- **Copia completa:** que se reemplace toda la base por una copia.
- **Registros viejos:** los registros anteriores a esta versión quedan sin hash.

**Cómo protegerse:**
- **Clave:** guardala fuera del servidor y no la cambies, porque los hashes ya guardados se verifican con ella.
- **Huella:** anotá o exportá cada tanto la huella que muestra la verificación.
- **Copias:** hacé copias de seguridad de la base.

Los triggers de solo lectura frenan `UPDATE` y `DELETE`, pero no un `DROP TABLE`
hecho por alguien con permisos de administrador de MySQL.

## Base de datos
`database/schema_auditoria.sql` (re-ejecutable, no borra nada) agrega:
- **En `auditoria`:** las columnas `agente`, `hash_anterior` y `hash`, y los índices
  `ix_hash` e `ix_entidad_accion_fecha`.
- **Tabla nueva `auditoria_alerta`:**
  - **Relaciones:** `auditoria_id` → `auditoria.id`, y `usuario_id` y `revisada_por` → `usuario.id`.
  - **Clave única `clave`:** evita alertas duplicadas.
  - **Triggers:** no se puede borrar, y solo se puede marcar como revisada una vez.

## Lo que no existe en Britech (no se inventó)
- **Devoluciones:** no hay devoluciones como operación propia. Lo más cercano son la anulación
  de una venta y la cancelación de un pedido; las dos reintegran stock y quedan registradas.
- **Cambio de método de pago:** el sistema no lo permite una vez registrada la venta.
- **Permisos:** no hay permisos individuales; los permisos son el rol (admin / vendedor).
- **Depósitos:** no hay depósitos, así que tampoco transferencias entre depósitos.
- **Cerrar sesiones de otro:** no existe la función de que un admin cierre la sesión de otro usuario.
- **Productos dañados:** el ajuste de stock no tiene un motivo "dañado". Aparece como ajuste manual.

## Pruebas del centro de control
`tests/auditoria_centro_test.php` cubre los 10 escenarios con operaciones reales por HTTP:
venta, anulación, cancelación, caja con diferencia, ajuste de stock, cambio de rol, logins
fallidos, revisión de alertas, filtros y exportaciones, inmutabilidad e integridad.
```
DB_NAME=britech_auditoria_test php -S 127.0.0.1:8125 -t public public/index.php
DB_NAME=britech_auditoria_test BRITECH_TEST_URL=http://127.0.0.1:8125 php tests/auditoria_centro_test.php
```
Necesita un admin y un vendedor de prueba con contraseña conocida. Se configuran con
`BRITECH_ADMIN_EMAIL`, `BRITECH_ADMIN_PASS`, `BRITECH_VEND_EMAIL` y `BRITECH_VEND_PASS`.

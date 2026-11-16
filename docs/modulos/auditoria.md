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

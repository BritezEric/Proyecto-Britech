# Endpoints de la API — Britech

> **Fuente de verdad:** [`routes/api.php`](../routes/api.php). Este documento es un
> espejo legible de ese archivo. **Última sincronización: 2026-10-09.**
> Si cambia `routes/api.php`, hay que actualizar esta tabla.

**Auth (columna):**
- `público` — no requiere sesión.
- `cliente` — sesión de la **tienda** (la propiedad se valida dentro del controlador).
- `login` — sesión de **staff** (en `routes` es el 3er parámetro `true`). Algunas son
  solo **admin** (lo chequea el controlador con `Session::esAdmin()`); otras aceptan
  también **vendedor** (`Session::esStaff()`), p. ej. pedidos, notificaciones, caja y reclamos.

Todas las respuestas son JSON (salvo los redirects de Auth0 y el PDF de empleados).

---

## Autenticación (staff) y usuarios
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| POST | `/api/login` | público | `AuthController::login` |
| POST | `/api/logout` | público | `AuthController::logout` |
| GET | `/api/yo` | público | `AuthController::yo` |
| POST | `/api/verificar` | público | `UsuarioController::verificar` |
| POST | `/api/olvide` | público | `AuthController::olvide` |
| POST | `/api/restablecer` | público | `AuthController::restablecer` |
| POST | `/api/usuarios` | login (admin) | `UsuarioController::crear` |

## POS (venta en el local)
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| GET | `/api/productos` | login | `ProductoController::index` |
| GET | `/api/productos/buscar` | login | `ProductoController::buscar` |
| GET | `/api/productos/precios` | login | `ProductoController::precios` |
| GET | `/api/clientes` | login | `ClienteController::index` |
| GET | `/api/tipos-pago` | login | `TipoPagoController::index` |
| POST | `/api/ventas` | login | `VentaController::crear` |
| GET | `/api/ventas` | login | `VentaController::listar` |
| GET | `/api/ventas/ticket` | login | `VentaController::ticket` |
| POST | `/api/ventas/anular` | login | `VentaController::anular` |

## Caja (apertura / movimientos / cierre)
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| GET | `/api/caja/estado` | login (staff) | `CajaController::estado` |
| POST | `/api/caja/abrir` | login (staff) | `CajaController::abrir` |
| POST | `/api/caja/movimiento` | login (staff) | `CajaController::movimiento` |
| POST | `/api/caja/cerrar` | login (staff) | `CajaController::cerrar` |
| GET | `/api/admin/cajas` | login (admin) | `CajaController::adminListar` |
| GET | `/api/admin/cajas/detalle` | login (admin) | `CajaController::adminDetalle` |

## Dashboard y notificaciones (panel)
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| GET | `/api/admin/dashboard` | login (admin) | `DashboardController::resumen` |
| GET | `/api/admin/dashboard/serie` | login (admin) | `DashboardController::serie` |
| GET | `/api/admin/notificaciones` | login (staff) | `NotificacionController::listar` |
| POST | `/api/admin/notificaciones/leer` | login (staff) | `NotificacionController::leer` |
| POST | `/api/admin/notificaciones/leer-todas` | login (staff) | `NotificacionController::leerTodas` |

## Pedidos online (gestión admin)
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| GET | `/api/admin/pedidos` | login (staff) | `PedidoController::adminListar` |
| GET | `/api/admin/pedidos/detalle` | login (staff) | `PedidoController::adminDetalle` |
| POST | `/api/admin/pedidos/estado` | login (staff) | `PedidoController::adminEstado` |
| POST | `/api/admin/pedidos/pago` | login (staff) | `PedidoController::adminEstadoPago` |
| POST | `/api/admin/pedidos/envio` | login (staff) | `PedidoController::adminEnvio` |

## Compras (órdenes + recepción)
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| GET | `/api/admin/compras` | login (admin) | `CompraController::listar` |
| GET | `/api/admin/compras/detalle` | login (admin) | `CompraController::detalle` |
| GET | `/api/admin/compras/nueva` | login (admin) | `CompraController::datosNueva` |
| POST | `/api/admin/compras` | login (admin) | `CompraController::crear` |
| POST | `/api/admin/compras/recibir` | login (admin) | `CompraController::recibir` |
| POST | `/api/admin/compras/anular` | login (admin) | `CompraController::anular` |

## Reposición
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| GET | `/api/admin/reposicion` | login (admin) | `ReposicionController::index` |
| POST | `/api/admin/reposicion` | login (admin) | `ReposicionController::registrar` |

## Tablas maestras y ABMs (admin)
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| GET | `/api/admin/clientes` | login (admin) | `ClienteController::admin` |
| POST | `/api/admin/clientes/guardar` | login (admin) | `ClienteController::guardar` |
| POST | `/api/admin/clientes/baja` | login (admin) | `ClienteController::baja` |
| GET | `/api/admin/productos` | login (admin) | `ProductoController::admin` |
| GET | `/api/admin/productos/detalle` | login (admin) | `ProductoController::detalle` |
| POST | `/api/admin/productos/imagen` | login (admin) | `ProductoController::subirImagen` |
| POST | `/api/admin/productos/guardar` | login (admin) | `ProductoController::guardar` |
| POST | `/api/admin/productos/baja` | login (admin) | `ProductoController::baja` |
| GET | `/api/admin/proveedores` | login (admin) | `ProveedorController::admin` |
| POST | `/api/admin/proveedores/guardar` | login (admin) | `ProveedorController::guardar` |
| POST | `/api/admin/proveedores/baja` | login (admin) | `ProveedorController::baja` |
| GET | `/api/admin/categorias` | login (admin) | `CategoriaController::admin` |
| POST | `/api/admin/categorias/guardar` | login (admin) | `CategoriaController::guardar` |
| POST | `/api/admin/categorias/baja` | login (admin) | `CategoriaController::baja` |
| GET | `/api/admin/marcas` | login (admin) | `MarcaController::admin` |
| POST | `/api/admin/marcas/guardar` | login (admin) | `MarcaController::guardar` |
| POST | `/api/admin/marcas/baja` | login (admin) | `MarcaController::baja` |
| GET | `/api/admin/gastos` | login (admin) | `GastoController::admin` |
| POST | `/api/admin/gastos/guardar` | login (admin) | `GastoController::guardar` |
| POST | `/api/admin/gastos/baja` | login (admin) | `GastoController::baja` |

## Empleados (sueldos + PDF)
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| GET | `/api/admin/empleados` | login (admin) | `EmpleadoController::listar` |
| GET | `/api/admin/empleados/detalle` | login (admin) | `EmpleadoController::detalle` |
| GET | `/api/admin/empleados/pdf` | login (admin) | `EmpleadoController::pdf` (PDF, no JSON) |

## Config de la tienda (datos de transferencia)
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| GET | `/api/admin/config` | login (admin) | `ConfigController::admin` |
| POST | `/api/admin/config/guardar` | login (admin) | `ConfigController::guardar` |

## Envíos: empresas, repartidores, barrios (admin)
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| GET | `/api/admin/empresas-envio` | login (admin) | `EmpresaEnvioController::admin` |
| POST | `/api/admin/empresas-envio/guardar` | login (admin) | `EmpresaEnvioController::guardar` |
| POST | `/api/admin/empresas-envio/baja` | login (admin) | `EmpresaEnvioController::baja` |
| GET | `/api/admin/repartidores` | login (admin) | `RepartidorController::admin` |
| GET | `/api/admin/repartidores/detalle` | login (admin) | `RepartidorController::detalle` |
| POST | `/api/admin/repartidores/guardar` | login (admin) | `RepartidorController::guardar` |
| POST | `/api/admin/repartidores/baja` | login (admin) | `RepartidorController::baja` |
| GET | `/api/admin/envios/sin-asignar` | login (admin) | `RepartidorController::sinAsignar` |
| POST | `/api/admin/envios/derivar` | login (admin) | `RepartidorController::derivar` |
| POST | `/api/admin/envios/estado` | login (admin) | `RepartidorController::estado` |
| GET | `/api/admin/barrios` | login (admin) | `BarrioController::admin` |
| POST | `/api/admin/barrios/guardar` | login (admin) | `BarrioController::guardar` |
| POST | `/api/admin/barrios/baja` | login (admin) | `BarrioController::baja` |

## Seguimiento de envíos en tiempo real
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| POST | `/api/repartidor/posicion` | público (token) | `TrackingController::posicion` |
| GET | `/api/admin/envios/mapa` | login (staff) | `TrackingController::adminMapa` |
| GET | `/api/tienda/seguimiento` | cliente | `TrackingController::seguimiento` |

## Reclamos — gestión (admin)
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| GET | `/api/admin/reclamos` | login (staff) | `ReclamoController::adminListar` |
| GET | `/api/admin/reclamos/detalle` | login (staff) | `ReclamoController::adminDetalle` |
| POST | `/api/admin/reclamos/mensaje` | login (staff) | `ReclamoController::adminMensaje` |
| POST | `/api/admin/reclamos/estado` | login (staff) | `ReclamoController::adminEstado` |

## Home builder + solicitudes mayoristas (admin)
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| GET | `/api/admin/bloques` | login (admin) | `BloqueController::listar` |
| POST | `/api/admin/bloques/guardar` | login (admin) | `BloqueController::guardar` |
| POST | `/api/admin/bloques/estado` | login (admin) | `BloqueController::estado` |
| POST | `/api/admin/bloques/mover` | login (admin) | `BloqueController::mover` |
| POST | `/api/admin/bloques/reordenar` | login (admin) | `BloqueController::reordenar` |
| POST | `/api/admin/bloques/borrar` | login (admin) | `BloqueController::borrar` |
| GET | `/api/admin/bloques/slides` | login (admin) | `BloqueController::slides` |
| POST | `/api/admin/bloques/slide/guardar` | login (admin) | `BloqueController::guardarSlide` |
| POST | `/api/admin/bloques/slide/borrar` | login (admin) | `BloqueController::borrarSlide` |
| GET | `/api/admin/solicitudes` | login (admin) | `MayoristaController::adminListar` |
| POST | `/api/admin/solicitudes/resolver` | login (admin) | `MayoristaController::adminResolver` |

---

## Tienda — auth del cliente
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| POST | `/api/tienda/registro` | público | `TiendaAuthController::registro` |
| POST | `/api/tienda/activar` | público | `TiendaAuthController::activar` |
| POST | `/api/tienda/reenviar` | público | `TiendaAuthController::reenviar` |
| POST | `/api/tienda/olvide` | público | `TiendaAuthController::olvide` |
| POST | `/api/tienda/restablecer` | público | `TiendaAuthController::restablecer` |
| POST | `/api/tienda/login` | público | `TiendaAuthController::login` |
| POST | `/api/tienda/logout` | cliente | `TiendaAuthController::logout` |
| GET | `/api/tienda/auth0/login` | público | `Auth0Controller::login` (redirige) |
| GET | `/api/tienda/auth0/callback` | público | `Auth0Controller::callback` (redirige) |
| GET | `/api/tienda/yo` | cliente | `TiendaAuthController::yo` |
| POST | `/api/tienda/perfil` | cliente | `TiendaAuthController::actualizarPerfil` |
| POST | `/api/tienda/modo` | cliente | `TiendaAuthController::modo` |

## Tienda — catálogo (público)
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| GET | `/api/tienda/catalogo` | público | `CatalogoController::index` |
| GET | `/api/tienda/producto` | público | `CatalogoController::producto` |
| GET | `/api/tienda/categorias` | público | `CatalogoController::categorias` |
| GET | `/api/tienda/envios` | público | `EmpresaEnvioController::opciones` |
| GET | `/api/tienda/barrios` | público | `RepartidorController::barrios` |
| GET | `/api/tienda/home` | público | `HomeController::home` |

## Tienda — pedidos y pago
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| POST | `/api/tienda/pedidos` | cliente | `PedidoController::crear` |
| GET | `/api/tienda/mis-pedidos` | cliente | `PedidoController::mis` |
| POST | `/api/tienda/comprobante` | cliente | `PedidoController::subirComprobante` |
| POST | `/api/tienda/pago/iniciar` | cliente | `PagoController::iniciar` |
| POST | `/api/tienda/pago/confirmar` | cliente | `PagoController::confirmar` |
| POST | `/api/tienda/pago/webhook` | público | `PagoController::webhook` (Mercado Pago) |
| GET | `/api/tienda/pago-info` | público | `ConfigController::pagoInfo` |

## Tienda — reclamos, notificaciones, mayorista, favoritos
| Método | Ruta | Auth | Controlador::acción |
|---|---|---|---|
| POST | `/api/tienda/reclamos` | cliente | `ReclamoController::crear` |
| GET | `/api/tienda/reclamos` | cliente | `ReclamoController::mis` |
| GET | `/api/tienda/reclamos/detalle` | cliente | `ReclamoController::detalle` |
| POST | `/api/tienda/reclamos/mensaje` | cliente | `ReclamoController::mensaje` |
| GET | `/api/tienda/notificaciones` | cliente | `NotificacionController::listarCliente` |
| POST | `/api/tienda/notificaciones/leer` | cliente | `NotificacionController::leerCliente` |
| POST | `/api/tienda/notificaciones/leer-todas` | cliente | `NotificacionController::leerTodasCliente` |
| POST | `/api/tienda/solicitud-mayorista` | cliente | `MayoristaController::solicitar` |
| GET | `/api/tienda/favoritos` | cliente | `FavoritoController::listar` |
| POST | `/api/tienda/favoritos/toggle` | cliente | `FavoritoController::toggle` |

# Documentación de Britech

Índice de toda la documentación del proyecto. La **referencia maestra** es
[`SPEC.md`](../SPEC.md) (raíz); la **guía de instalación** es [`README.md`](../README.md) (raíz).

## 📄 Documentos generales
| Documento | Contenido |
|---|---|
| [ESTADO-DEL-PROYECTO.md](ESTADO-DEL-PROYECTO.md) | Estado por módulo y qué falta para producción |
| [ENDPOINTS.md](ENDPOINTS.md) | Mapa completo de la API (todas las rutas) |
| [MODELO-DE-DATOS.md](MODELO-DE-DATOS.md) | Las 42 tablas por área + decisiones de modelado |
| [infraestructura.md](infraestructura.md) | Infraestructura y despliegue |
| [docker.md](docker.md) | Contenedores PHP + MySQL |
| [doce-factores.md](doce-factores.md) | Metodología 12-Factor |
| [defensa-codigo.md](defensa-codigo.md) | Guía general de defensa del código |
| [presentacion-25.md](presentacion-25.md) | Notas de presentación |
| [Britech-Documentacion.docx](Britech-Documentacion.docx) | Documentación completa del proyecto (Word, para la cátedra) |

## 📦 Módulos (por entrega de la materia)

**Módulo 1 — Seguridad**
- [modulos/seguridad.md](modulos/seguridad.md) — línea base transversal (SQLi, XSS, sesiones, secretos)
- [modulos/autenticacion.md](modulos/autenticacion.md) — login, verificación y recuperación

**Módulo 2 — Tablas Maestras**
- [modulos/tablas-maestras.md](modulos/tablas-maestras.md) — marcas, categorías, proveedores, barrios

**Módulo 3 — Entrada de Datos**
- [modulos/entrada-datos.md](modulos/entrada-datos.md) — ABM de clientes/productos/proveedores + tienda

**Módulo 4 — Procesos**
- [modulos/ventas.md](modulos/ventas.md) — venta en el POS
- [modulos/ventas-modelo-datos.md](modulos/ventas-modelo-datos.md) — modelo de datos de Ventas
- [modulos/compras.md](modulos/compras.md) — órdenes de compra + recepción
- [modulos/caja.md](modulos/caja.md) — apertura, movimientos y cierre/arqueo
- [modulos/reposicion.md](modulos/reposicion.md) — detección de faltantes y pedido a proveedor
- [modulos/notificaciones.md](modulos/notificaciones.md) — avisos por destinatario (staff y cliente) + email

**Módulo 5 — Salidas del Sistema**
- [modulos/dashboard.md](modulos/dashboard.md) — dashboard, KPIs y reportes

## 🛒 Tienda, pagos y posventa
- [modulos/tienda-online.md](modulos/tienda-online.md) — catálogo, carrito, checkout, mayorista, favoritos
- [modulos/pagos.md](modulos/pagos.md) — registros de pago (POS + online)
- [modulos/mercadopago.md](modulos/mercadopago.md) — integración Mercado Pago (Checkout Pro)
- [modulos/reclamos.md](modulos/reclamos.md) — reclamos con seguimiento (chat)
- [modulos/envios-repartidores.md](modulos/envios-repartidores.md) — Moto Express, barrios y repartos
- [modulos/seguimiento.md](modulos/seguimiento.md) — seguimiento de envíos en tiempo real (estilo Uber)
- [modulos/finanzas.md](modulos/finanzas.md) — gastos, sueldos y totales
- [modulos/home-bloques.md](modulos/home-bloques.md) — page builder de la home

## 🎓 Exposición (defensa por integrante)
- [exposicion/README.md](exposicion/README.md) — reparto por integrante
- [exposicion/transacciones-eric.md](exposicion/transacciones-eric.md) — Transacciones (Eric)
- [exposicion/pagos-eric-paso-a-paso.md](exposicion/pagos-eric-paso-a-paso.md) — Pagos paso a paso (Eric)
- [exposicion/salidas-franco.md](exposicion/salidas-franco.md) — Salidas del Sistema (Franco)
- [exposicion/notificaciones-tobi.md](exposicion/notificaciones-tobi.md) — Notificaciones (Tobi)

## 🚀 Propuestas
- [propuestas/Britech-Propuestas-Tecnologicas.docx](propuestas/Britech-Propuestas-Tecnologicas.docx) — propuestas de innovación (Word)

---
> Documentación viva: cada módulo se documenta antes y después de construirlo.
> Al cambiar `routes/api.php`, actualizar también `ENDPOINTS.md`.

# Módulo: Tablas Maestras (Módulo 2)

> Estado: **parcial**. Construidas marcas, categorías, proveedores y barrios.
> Faltan (del enunciado): provincias, localidades, tipos de documento y unidades
> de medida.

## Problema
Las entidades de referencia que usa el resto del sistema (un producto tiene una
marca y una categoría; un envío Moto Express tiene un barrio) deben poder
administrarse: crearlas, editarlas y darlas de baja, con validaciones.

## Qué incluye hoy
ABM (alta, baja y modificación) desde el panel de administración, con **búsqueda,
filtros y paginación** del lado del servidor y **baja lógica** (`activo = 0`, no se
borra físicamente):

| Tabla maestra | Para qué sirve |
|---|---|
| `marca` | Marca del producto (+ logo para el carrusel de la home) |
| `categoria` | Categoría del producto (+ imagen y orden para la home) |
| `proveedor` | Proveedor de compras y de productos |
| `barrio` | Zonas de Moto Express (nombre + costo del envío) |

> Clientes y productos también tienen ABM, pero se documentan como **Entrada de
> Datos** (Módulo 3): ver `docs/modulos/entrada-datos.md`.

## Cómo funciona
- Todas reusan el **framework de ABM** del frontend (`admin.js` guiado por una
  configuración por entidad): la misma lógica de listar/buscar/filtrar/paginar y
  el mismo modal de crear/editar sirven para cada tabla.
- En el backend, el patrón por capas: `Repository` (SQL) → `Service` (validaciones)
  → `Controller` (respuesta JSON). Varias usan `MaestraSimpleController` para los
  ABMs sencillos.

## API (ejemplos)
| Método | Ruta |
|---|---|
| GET / POST | `/api/admin/marcas` · `/guardar` · `/baja` |
| GET / POST | `/api/admin/categorias` · `/guardar` · `/baja` |
| GET / POST | `/api/admin/proveedores` · `/guardar` · `/baja` |
| GET / POST | `/api/admin/barrios` · `/guardar` · `/baja` |

## Archivos
- `database/schema_datos_maestros.sql`, `schema_marca_logo.sql`, `schema_moto_barrios.sql`
- `app/Controllers/{MarcaController, CategoriaController, ProveedorController, BarrioController, MaestraSimpleController}.php`
- Repositorios y servicios correspondientes en `app/Repositories` y `app/Services`.
- Frontend: `public/assets/js/admin.js` (vista "Tablas").

## Pendiente (del enunciado)
- [ ] `provincia` y `localidad` (con FK provincia → localidad), y usarlas en el cliente.
- [ ] `tipo_documento` (DNI, CUIT…) y usarla en el cliente.
- [ ] `unidad_medida` (unidad, kg, litro…) y asociarla al producto.

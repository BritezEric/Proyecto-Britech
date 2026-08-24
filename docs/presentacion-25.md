# Guion de presentación — Módulo de Entrada de Datos (25/08/2026)

> La **consigna** es "Entrada de Datos": ABM de tablas maestras con **búsqueda,
> filtros y paginación**. Eso es el **núcleo** que sí o sí hay que mostrar. Todo
> lo demás (tienda, dashboard, page-builder) es **valor agregado** que suma pero
> no reemplaza al núcleo. Regla de oro: **primero cumplir la consigna, después lucirse.**

---

## 1. Antes de empezar (checklist 10 min antes)

- [ ] Laragon prendido (Apache + MySQL en verde).
- [ ] Abrir en el navegador y que carguen sin error:
  - Login staff: `http://britech.test/login.html` (o la URL que uses)
  - Tienda: `http://britech.test/tienda.html`
  - Panel: `http://britech.test/admin.html`
- [ ] **Hacer 1 venta de prueba en el POS** para que el dashboard muestre
  "ventas de hoy" con número (hoy arranca en 0).
- [ ] Tener las credenciales copiadas en un papel/nota (ver abajo).
- [ ] Cerrar pestañas y apps que no sean del proyecto (que no se cuele nada).
- [ ] Modo claro u oscuro elegido de antemano (probar el toggle igual, queda lindo).
- [ ] Zoom del navegador al 100% y ventana maximizada.

### Credenciales
| Rol | Email | Clave |
|-----|-------|-------|
| Admin | `admin@britech.local` | *(la que definiste)* |
| Vendedor | `britechfsa+vendedor1@gmail.com` | *(la que definiste)* |
| Cliente (verificado) | `carla@shop.com` | *(la que definiste)* |

> Si no recordás las claves, avisá y las reseteo a una conocida (ej. `demo1234`)
> antes de la presentación.

---

## 2. Estructura de la charla (~12–15 min)

Dividida en 3 bloques. **El bloque 2 es la consigna** — es el que más peso tiene.

### Bloque 1 — Contexto y arquitectura (~3 min) · *hablado, poco clic*
- Qué es Britech: sistema de gestión + tienda (POS + e-commerce) para un comercio.
- **Arquitectura en capas** (dibujarla o tenerla en slide):
  `Front Controller → Router → Controller → Service → Repository → PDO/MySQL`
  - *Controller*: recibe el pedido HTTP y responde JSON.
  - *Service*: reglas de negocio y **validaciones** + transacciones.
  - *Repository*: SQL con **consultas preparadas** (anti inyección).
- Backend/Frontend separados: la web pide datos por **fetch/AJAX** y recibe **JSON**.
- Una frase clave para decir: *"Toda validación y todo cálculo de precio se hace
  en el backend; nunca se confía en el navegador."*

### Bloque 2 — Entrada de Datos: el ABM (LA CONSIGNA) (~6 min) · *acá se demuestra todo*
Mostrar en el panel admin, entidad **Productos** (es la más completa):
1. **Listado con paginación** — mostrar el pie de página, cambiar de página.
2. **Búsqueda** — escribir en el buscador y ver el filtrado en vivo (con debounce).
3. **Filtros** — filtrar por categoría / marca.
4. **Alta (crear)** — abrir el modal, cargar un producto:
   - descripción, **precio minorista y mayorista**, categoría, marca, proveedor,
     **stock inicial**, y **subir una imagen** (o pegar con Ctrl+V).
   - *Decir:* "Guardar un producto toca 3 tablas en una **transacción**:
     producto, precio e inventario. Si algo falla, no se guarda nada."
5. **Validaciones** — intentar guardar con un campo mal (ej. precio vacío o
   email inválido en Clientes) y mostrar el mensaje de error del backend.
6. **Editar y baja lógica** — editar el producto recién creado; dar de baja
   (no se borra, se marca inactivo → *decir por qué: no se pierde el historial*).

> Repetir el mismo patrón existe para **Clientes, Proveedores, Categorías y Marcas**.
> No hace falta demostrar las 5; con Productos + una validación de Clientes alcanza.
> *Decir:* "Es el **mismo framework de ABM reutilizable**, configurado por entidad."

### Bloque 3 — Valor agregado (~3–4 min) · *rápido, para lucirse*
Elegir 2–3, no todo (para no pasarse de tiempo):
- **Tienda online**: catálogo, ficha de producto (con oferta y cuotas), carrito.
- **Compra completa**: cliente hace un pedido → cambiar a admin → mostrar el
  pedido y el **seguimiento del envío**.
- **Dashboard**: KPIs, gráfico que rota (semana/mes/año), físicas vs online.
- **Page-builder de la home**: activar/desactivar/reordenar bloques sin tocar código.
- **Modo claro/oscuro**, **ticket PDF**, **verificación de correo**.

### Cierre (~1 min)
- Qué aprendimos (patrones, capas, seguridad).
- Qué queda a futuro (Docker, convertir pedido→venta, etc.).

---

## 3. Reparto del grupo (roles)

Ajustar a cuántos son. Idea para 3–4 personas:

| Rol | Quién | Qué hace |
|-----|-------|----------|
| **Piloto (teclado)** | 1 persona fija | Maneja la compu. **Solo una persona toca** para no marearse. Ensaya los clics. |
| **Narrador arquitectura** | 1 | Explica el Bloque 1 (capas, backend/frontend). |
| **Narrador demo** | 1 | Va relatando el Bloque 2 mientras el piloto hace clic. |
| **Soporte / preguntas** | 1 | Responde dudas técnicas del profe, tiene a mano el código por si piden ver algo. |

> Si son 3: el narrador de arquitectura también hace el soporte de preguntas.
> Si son 2: uno pilota + narra demo, el otro arquitectura + preguntas.

**Consejos:**
- **Ensayar 1 vez completa** el día antes, cronometrando. Es lo que más ayuda.
- Cada uno que sepa explicar **una parte a fondo** (por si preguntan).
- Tener el proyecto **ya abierto y logueado** antes de arrancar (no perder tiempo).
- Si algo falla en vivo: quedarse tranquilo, tener **capturas de respaldo** de las
  pantallas clave por si el WiFi/servidor falla.

---

## 4. Preguntas probables del profe (y respuesta corta)

- **¿Cómo evitan inyección SQL?** → Consultas preparadas (PDO) en todos los repos.
- **¿Dónde validan los datos?** → En los *Services*, en el backend, siempre.
- **¿Qué es la paginación y por qué?** → Traer de a N filas (LIMIT/OFFSET) para no
  cargar toda la tabla; más rápido y escalable.
- **¿Por qué baja lógica y no borrar?** → Para no perder historial ni romper
  relaciones (ventas que apuntan al producto).
- **¿Qué es una transacción?** → Varias operaciones que se confirman juntas o se
  deshacen juntas (todo o nada), ej. producto + precio + stock.
- **¿Por qué separar backend y frontend?** → El backend expone una API JSON; el
  front la consume. Se puede cambiar la interfaz sin tocar la lógica.

---

## 5. Datos cargados hoy (para saber qué mostrar)
- 21 productos activos (18 con imagen, 9 en oferta).
- 13 categorías · 6 marcas · 3 proveedores.
- 13 pedidos (pendientes, preparando, entregado, cancelado) y 9 envíos con estados.
- Clientes verificados listos: `carla@shop.com` (mayorista), `juan.perez@example.com`.

### Pendiente de preparar
- [ ] Hacer 1 venta hoy en el POS (para el KPI "ventas de hoy").
- [ ] Agregar imagen a 3 productos sin foto (o no mostrarlos): *Cargador USB-C 20W*,
      *Notebook 15 (sobre pedido)*, *Samsung Galaxy A15*.
- [ ] (Opcional) Limpiar clientes de prueba feos del listado admin (ej. "Demo Visual").

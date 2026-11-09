# Design System — Britech

> Sistema visual unificado. La **fuente de verdad** es
> [`public/assets/css/tokens.css`](public/assets/css/tokens.css); este documento
> lo explica. Toda pantalla importa `tokens.css` **primero** y después su CSS.
> Estratégico (quién/por qué): ver [PRODUCT.md](PRODUCT.md).

## Concepto
Blanco y negro con morado: minimal, alto contraste, sombras suaves y movimiento
leve. Un solo patrón para POS, tienda, panel, login, legales y repartidor.

## Color (OKLCH)
| Rol | Token | Uso |
|---|---|---|
| Canvas | `--bg` | Fondo de la app |
| Superficie | `--surface`, `--surface-2`, `--surface-sunk` | Tarjetas, hover, inputs |
| Tinta | `--ink`, `--ink-muted` | Texto principal y secundario |
| Línea | `--line`, `--line-strong` | Bordes y divisores |
| **Primario** | `--primary`, `--primary-strong`, `--primary-tint` | Marca: botones, activo, enlaces |
| Acento | `--accent`, `--accent-tint` | Solo ofertas y atención |
| Estados | `--ok`, `--warn`, `--info`, `--danger` (+ `-strong` y `-tint`) | Pagado, pendiente, información, error |
| Escena | `--escena` | Fondo de marca de login y portada |

El estado **nunca depende solo del color**: los badges llevan un punto además del color.

## Tipografía
**Hanken Grotesk** (Google Fonts, `@import` en tokens.css), fallback
`system-ui`. Una sola familia en pesos 400–800; la jerarquía se hace con
**peso + tamaño**, no con muchas fuentes. Números de plata siempre con
`font-variant-numeric: tabular-nums`.

Escala (ratio ~1.25): `--text-xs .75` · `sm .875` · `base 1` · `lg 1.25` ·
`xl 1.625` · `2xl 2.125` rem. Headings/totales con `letter-spacing: -0.02/-0.03em`.

## Forma y profundidad
- Radios: `--r-sm 8` · `md 12` · `lg 16` · `xl 22` · `full 999`.
- Sombras **suaves** con tinte verde-neutro (`--shadow-sm/md/lg`), nunca duras.
- Espaciado en escala de 4pt (`--sp-1..8`).
- Foco visible: halo ámbar (`--ring`) en todo control (accesibilidad).

## Motion
Una sola curva (`--ease-out`) y tres duraciones: `--dur-fast` 150ms, `--dur` 240ms, `--dur-slow` 380ms.
- Pantallas y secciones entran con `aparecer` (fade + 8px). Modales con `fade-in` + `caja-in`.
- Tarjetas clickeables y botones suben 1-3px al pasar el mouse y ganan sombra.
- Con `prefers-reduced-motion` se anulan las entradas.

## Z-index (semántico)
`--z-dropdown 100` < `sticky 200` < `modal-backdrop 300` < `modal 310` <
`toast 400` < `tooltip 500`. Nunca valores mágicos tipo 9999.

## Componentes (dónde viven)
- **tokens.css** — variables, reset, foco, entradas, `.btn-primary`, `.btn-secundario`, `.modal`, `.modal-caja`, toast. Se importa primero. Las pantallas solo ajustan el ancho del modal.
- **pos.css** — POS: barra, paneles, buscador, producto, carrito, resumen,
  botón cobrar, modales (cobro/ticket), pago mixto, ticket térmico, responsive.
- **login.css** — auth (login, usuarios, olvidé, verificar, restablecer): escena
  de marca verde + tarjeta.
- **ventas.html** (`<style>`) — tabla de ventas / anulación, sobre los mismos tokens.

## Reglas al extender
1. Importá `tokens.css` primero; usá **variables**, nunca colores/medidas hardcodeadas.
2. Morado para la marca; `--accent` solo para ofertas. Jerarquía por peso+tamaño+espacio, no por adornos.
3. Estado = color **+** texto/ícono. Plata siempre tabular.
4. Colores de estado siempre con tokens (`--ok`, `--warn`, `--info`, `--danger`), nunca hexadecimales sueltos.

## Pase de pulido 2026-08-23 (skill impeccable, registro "producto")
Criterio: el sistema ya estaba muy pulido (micro-interacciones, modales animados,
foco visible, `prefers-reduced-motion`, sombras multicapa). Se evitó "animar por
animar" (el registro de producto pide que el movimiento **comunique estado**, no
decore, y prohíbe coreografías de carga en UI de tarea). Cambios puntuales, todos
**solo transform/opacity/box-shadow** (baratos para la GPU) y con reduced-motion:
- **Tarjetas de categoría**: `object-fit: cover` en 3:4 → look uniforme y prolijo
  (antes `contain` dejaba bandas feas con imágenes horizontales). + lift/sombra en hover.
- **Entrada escalonada sutil** SOLO en la home de la tienda (`.bloque .carrusel-track > *`,
  vitrina): NO en el catálogo/búsqueda ni en el admin (flujo de tarea → sin coreografía).
- **Zoom sutil de la imagen** del producto al hover (`.prod:hover .prod-thumb img`, scale 1.05).
El admin y el POS se dejaron como estaban (ya cumplían el registro).

## Pase de pulido 2026-08-27 (Moto Express + formularios compactos)
Criterio: densidad y jerarquía tipo marketplace, sin perder legibilidad ni la
identidad. Motion barato (transform/opacity), respeta `prefers-reduced-motion`.
- **Formularios del admin más compactos** (todos los modales con `.campo`):
  labels 11px sentence-case (no MAYÚSCULAS), inputs 13px con relleno sutil, grid de
  2 columnas, gaps ajustados → el form de producto (12 campos) entra en una pantalla
  normal sin scroll. Mejor jerarquía label→input y botones compactos.
- **Checkout tienda (paso pago)**: total en panel arriba (monto grande en morado),
  acción principal **grande full-width** abajo y **"Volver"** como link discreto.
- **Barrios como elección de tarjetas** (tienda) / **botones** (POS) que colapsan al
  elegir, con "cambiar" para volver. Botón **WhatsApp** verde (`wa.me`).
- **Dashboard**: accesos rápidos (Envíos/Repartos/POS) con **badge** de pendientes.

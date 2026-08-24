# Design System — Britech

> Sistema visual unificado. La **fuente de verdad** es
> [`public/assets/css/tokens.css`](public/assets/css/tokens.css); este documento
> lo explica. Toda pantalla importa `tokens.css` **primero** y después su CSS.
> Estratégico (quién/por qué): ver [PRODUCT.md](PRODUCT.md).

## Concepto
Mood: *"mercado de barrio moderno al mediodía"* — verde de feria, calidez ámbar,
luz limpia. Comercial y cálido pero despejado. La calidez la lleva el **acento**
y la **tipografía**, nunca un fondo beige (cliché IA que evitamos).

## Color (OKLCH)
Estrategia: **comprometida** — un verde de marca con significado (confirmar /
plata / fresco) + un ámbar cálido de atención. Nada de color decorativo.

| Rol | Token | Aprox | Uso |
|---|---|---|---|
| Canvas | `--bg` | `#f1f6f3` | Fondo de la app (susurro verde, no beige) |
| Superficie | `--surface` | `#ffffff` | Tarjetas / paneles |
| Superficie 2 | `--surface-2` | gris-verde muy claro | Hover, líneas de carrito |
| Hundido | `--surface-sunk` | | Inputs, tracks |
| Tinta | `--ink` | `#19221d` | Texto principal (16:1) |
| Tinta suave | `--ink-muted` | `#5f6d66` | Texto secundario (5.4:1) |
| Línea | `--line` / `--line-strong` | | Bordes, divisores |
| **Primario** | `--primary` | `#048b56` | Verde marca: CTA, confirmar, activo |
| Primario fuerte | `--primary-strong` | `#007240` | Hover, texto verde (links) |
| Primario tenue | `--primary-tint` | verde muy claro | Fondo de badges "ok" |
| **Acento** | `--accent` | `#e18600` | Ámbar: atención, oferta, foco, punto de marca |
| Peligro | `--danger` | `#d33a3c` | Quitar, error, anulada |

**Semántica del color:** verde = ok/confirmar/plata · ámbar = atención/oferta ·
rojo = quitar/error. El estado **nunca depende solo del color**: los badges de
stock/estado llevan un punto (`::before`) además del color.
Contraste verificado a WCAG AA (cuerpo ≥4.5:1, grande/bold ≥3:1).

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
Intencional y contenido (no ruidoso). Curva `--ease-out` (ease-out-quint), sin
rebote. Duraciones `--dur-fast 120ms` / `--dur 200ms` / `--dur-slow 340ms`.
- Líneas del carrito: entran con `linea-in` (fade + slide corto).
- Modales: backdrop con `blur` + fade, caja con entrada física (scale + translate).
- Botones/rows: transición de color y micro-desplazamiento en hover/active.
- `@media (prefers-reduced-motion: reduce)`: todo se reduce a ~0 (global en tokens.css).

## Z-index (semántico)
`--z-dropdown 100` < `sticky 200` < `modal-backdrop 300` < `modal 310` <
`toast 400` < `tooltip 500`. Nunca valores mágicos tipo 9999.

## Componentes (dónde viven)
- **tokens.css** — variables + reset base + foco + reduced-motion. Se importa primero.
- **pos.css** — POS: barra, paneles, buscador, producto, carrito, resumen,
  botón cobrar, modales (cobro/ticket), pago mixto, ticket térmico, responsive.
- **login.css** — auth (login, usuarios, olvidé, verificar, restablecer): escena
  de marca verde + tarjeta.
- **ventas.html** (`<style>`) — tabla de ventas / anulación, sobre los mismos tokens.

## Reglas al extender
1. Importá `tokens.css` primero; usá **variables**, nunca colores/medidas hardcodeadas.
2. Un solo acento por vista. Jerarquía por peso+tamaño+espacio, no por adornos.
3. Estado = color **+** texto/ícono. Plata siempre tabular.
4. Si parece plantilla de admin, rehacelo. La marca se reconoce por verde-feria + ámbar.

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

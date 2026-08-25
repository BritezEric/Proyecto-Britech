// =====================================================================
// Tienda — componentes reutilizables de la HOME (bloques + carruseles).
// Define helpers compartidos ($, money, esc, inicial) y las funciones de
// render de cada tipo de bloque. tienda.js usa estos componentes.
// (Se carga ANTES que tienda.js; las funciones referencian variables de
//  tienda.js —cliente, favoritos, agregar, abrirFicha…— recién al ejecutarse.)
// =====================================================================

const $ = (id) => document.getElementById(id);
const money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const inicial = (nombre) => nombre.split(' ').slice(0, 2).map((p) => p[0]).join('').toUpperCase();

// ---------- Tarjeta de producto (reutilizable en home, carruseles y grid) ----------
function cardProducto(p) {
    const agotado = Number(p.es_sobre_pedido) !== 1 && Number(p.stock) <= 0;
    const favOn = favoritos.has(Number(p.id));
    const esMay = cliente && cliente.modo === 'mayorista';
    const min = Number(p.min_mayorista) || 1;
    const hayAnt = Number(p.precio_anterior) > Number(p.precio);
    const off = hayAnt ? Math.round((1 - p.precio / p.precio_anterior) * 100) : 0;
    const thumb = p.imagen
        ? `<div class="prod-thumb con-img"><img src="${esc(p.imagen)}" alt="" loading="lazy"></div>`
        : `<div class="prod-thumb">${esc(inicial(p.nombre))}</div>`;
    const stockTxt = Number(p.es_sobre_pedido) === 1 ? 'Sobre pedido' : (agotado ? 'Sin stock' : 'Disponible: ' + Number(p.stock));
    return `<article class="prod" data-id="${p.id}">
        ${hayAnt ? `<span class="oferta-badge">-${off}%</span>` : ''}
        <button class="fav-btn ${favOn ? 'activo' : ''}" data-fav="${p.id}" aria-label="Favorito">${favOn ? '❤️' : '🤍'}</button>
        ${thumb}
        <div class="prod-cat">${esc(p.categoria || 'General')}</div>
        <div class="prod-nombre">${esc(p.nombre)}</div>
        <div class="prod-precios">
            <span class="prod-precio">${money.format(p.precio)}</span>
            ${hayAnt ? `<span class="prod-precio-ant">${money.format(p.precio_anterior)}</span>` : ''}
        </div>
        <div class="prod-stock ${agotado ? 'agotado' : ''}">${stockTxt}${esMay && min > 1 ? ` · mín ${min} u` : ''}</div>
        <button class="prod-add" data-id="${p.id}" data-nombre="${esc(p.nombre)}" data-precio="${p.precio}" data-min="${min}" ${agotado ? 'disabled' : ''}>Agregar</button>
    </article>`;
}

// Tarjeta que NO es producto: banner promocional dentro de un carrusel.
function cardBanner(banner) {
    const cat = (banner.url || '').startsWith('#cat-') ? `data-ir-cat="${esc(banner.url.slice(5))}"` : '';
    return `<a class="prod prod-banner" ${cat} href="${esc(banner.url || '#')}"
               style="background-image:url('${esc(banner.imagen)}')">
        <span class="prod-banner-txt">${esc(banner.titulo || 'Promoción')}</span></a>`;
}

// ---------- Render de la HOME ----------
function renderHome(bloques) {
    const cont = $('home-bloques');
    let html = '';
    for (let i = 0; i < bloques.length; i++) {
        const b = bloques[i];
        // Hero + bloque lateral (video/banner) van lado a lado en desktop.
        if (b.tipo === 'hero' && bloques[i + 1] && (bloques[i + 1].tipo === 'video' || bloques[i + 1].tipo === 'banner')) {
            html += `<div class="home-hero-row">${renderBloque(b)}<div class="home-lateral">${renderBloque(bloques[i + 1])}</div></div>`;
            i++; // ya consumimos el lateral
        } else {
            html += renderBloque(b);
        }
    }
    cont.innerHTML = html;
    // Wiring después de insertar
    cont.querySelectorAll('.hero').forEach(montarHero);
    cont.querySelectorAll('.carrusel').forEach(montarCarrusel);
}

function renderBloque(b) {
    switch (b.tipo) {
        case 'hero':                return bloqueHero(b);
        case 'video':
        case 'banner':              return bloqueBanner(b);
        case 'carrusel_categorias': return bloqueCategorias(b);
        case 'carrusel_marcas':     return bloqueMarcas(b);
        case 'carrusel_productos':  return bloqueProductos(b, false);
        case 'grid_productos':      return bloqueProductos(b, true);
        default:                    return '';
    }
}

function bloqueHero(b) {
    const slides = (b.data.slides || []);
    if (!slides.length) return '';
    const s = slides.map((x, i) => `
        <div class="hero-slide ${i === 0 ? 'activo' : ''}" style="background-image:url('${esc(x.imagen_desktop || '')}')">
            <div class="hero-txt">
                ${x.titulo ? `<h2>${esc(x.titulo)}</h2>` : ''}
                ${x.subtitulo ? `<p>${esc(x.subtitulo)}</p>` : ''}
                ${x.boton_texto ? `<a class="hero-btn" ${(x.url || '').startsWith('#cat-') ? `data-ir-cat="${esc(x.url.slice(5))}"` : ''} href="${esc(x.url || '#')}">${esc(x.boton_texto)}</a>` : ''}
            </div>
        </div>`).join('');
    const dots = slides.map((_, i) => `<button class="hero-dot ${i === 0 ? 'activo' : ''}" data-i="${i}"></button>`).join('');
    return `<div class="hero" data-intervalo="${(b.config && b.config.intervalo_ms) || 5000}">
        <div class="hero-slides">${s}</div>
        ${slides.length > 1 ? `<button class="hero-arrow izq" data-dir="-1">‹</button><button class="hero-arrow der" data-dir="1">›</button><div class="hero-dots">${dots}</div>` : ''}
    </div>`;
}

function bloqueBanner(b) {
    const c = b.config || {};
    const cat = (c.url || '').startsWith('#cat-') ? `data-ir-cat="${esc(c.url.slice(5))}"` : '';
    if (c.video_url) {
        return `<div class="bloque-lateral">
            <video src="${esc(c.video_url)}" autoplay muted loop playsinline poster="${esc(c.imagen || '')}"></video>
            ${c.titulo ? `<div class="lateral-txt"><strong>${esc(c.titulo)}</strong><span>${esc(c.subtitulo || '')}</span></div>` : ''}
        </div>`;
    }
    return `<a class="bloque-lateral banner" ${cat} href="${esc(c.url || '#')}" style="background-image:url('${esc(c.imagen || '')}')">
        ${c.titulo ? `<div class="lateral-txt"><strong>${esc(c.titulo)}</strong><span>${esc(c.subtitulo || '')}</span></div>` : ''}
    </a>`;
}

function bloqueCategorias(b) {
    const cats = (b.data.categorias || []).map((c) => `
        <a class="cat-card" data-ir-cat="${c.id}" href="#cat-${c.id}">
            <div class="cat-img" style="background-image:url('${esc(c.imagen || '')}')"></div>
            <span>${esc(c.nombre)}</span>
        </a>`).join('');
    return `<section class="bloque">
        ${b.titulo ? `<div class="bloque-head"><h2>${esc(b.titulo)}</h2>${flechas()}</div>` : ''}
        <div class="carrusel"><div class="carrusel-track cats">${cats}</div></div>
    </section>`;
}

// Tira de marcas que pasa sola (marquee). Duplicamos los logos para loop continuo.
function bloqueMarcas(b) {
    const marcas = (b.data.marcas || []);
    if (!marcas.length) return '';
    const logo = (m) => `<div class="marca-logo" title="${esc(m.nombre)}">
        <img src="${esc(m.imagen)}" alt="${esc(m.nombre)}" loading="lazy"></div>`;
    const set = `<div class="marcas-set">${marcas.map(logo).join('')}</div>`;
    // Con >=5 marcas anima en loop (dos sets idénticos → empalme sin corte).
    const animar = marcas.length >= 5 ? 'marcas-anima' : '';
    return `<section class="bloque">
        ${b.titulo ? `<div class="bloque-head"><h2>${esc(b.titulo)}</h2></div>` : ''}
        <div class="marcas-strip"><div class="marcas-track ${animar}">${set}${animar ? set : ''}</div></div>
    </section>`;
}

function bloqueProductos(b, esGrid) {
    const cfg = b.config || {};
    let cards = (b.data.productos || []).map(cardProducto);
    const banner = cfg.banner;
    if (banner && banner.imagen) {
        const bc = cardBanner(banner);
        (banner.posicion === 'fin') ? cards.push(bc) : cards.unshift(bc);
    }
    if (cards.length === 0) return '';
    const verTodos = cfg.categoria_id
        ? `<button class="ver-todos" data-ir-cat="${cfg.categoria_id}" data-titulo="${esc(b.titulo || '')}">Ver todos</button>` : '';
    // Siempre una sola fila (carrusel): 6 por fila, el resto rota cada 5s.
    const head = `<div class="bloque-head"><h2>${esc(b.titulo || '')}</h2>
        <div class="bloque-nav">${verTodos}${flechas()}</div></div>`;
    return `<section class="bloque">${head}
        <div class="carrusel"><div class="carrusel-track">${cards.join('')}</div></div></section>`;
}

function flechas() {
    return `<div class="carrusel-nav"><button class="car-arrow" data-dir="-1">‹</button><button class="car-arrow" data-dir="1">›</button></div>`;
}

// ---------- Carrusel: flechas + scroll (touch/swipe es nativo por overflow) ----------
function montarCarrusel(carrusel) {
    const track = carrusel.querySelector('.carrusel-track');
    const nav = carrusel.parentElement.querySelector('.carrusel-nav, .bloque-nav');
    const paso = () => Math.round(track.clientWidth);   // una página = 6 productos
    if (nav) nav.querySelectorAll('.car-arrow').forEach((btn) => btn.addEventListener('click', () => {
        track.scrollBy({ left: Number(btn.dataset.dir) * paso(), behavior: 'smooth' });
    }));

    // Auto-rotación cada 5s, movimiento fluido tipo vaivén (ping-pong): avanza
    // una página hasta el final y luego vuelve suave; nunca un salto brusco.
    if (track.scrollWidth <= track.clientWidth + 8) return;   // no hay overflow → no rota
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    let timer, dir = 1;
    const avanzar = () => {
        const max = track.scrollWidth - track.clientWidth;
        if (dir === 1 && track.scrollLeft >= max - 8) dir = -1;
        else if (dir === -1 && track.scrollLeft <= 8) dir = 1;
        track.scrollBy({ left: dir * paso(), behavior: 'smooth' });
    };
    const arrancar = () => { timer = setInterval(avanzar, 5000); };
    const parar = () => clearInterval(timer);
    carrusel.addEventListener('mouseenter', parar);
    carrusel.addEventListener('mouseleave', arrancar);
    arrancar();
}

// ---------- Hero: autoplay + flechas + dots ----------
function montarHero(hero) {
    const slides = [...hero.querySelectorAll('.hero-slide')];
    const dots = [...hero.querySelectorAll('.hero-dot')];
    if (slides.length <= 1) return;
    let idx = 0, timer;
    const ir = (n) => {
        idx = (n + slides.length) % slides.length;
        slides.forEach((s, i) => s.classList.toggle('activo', i === idx));
        dots.forEach((d, i) => d.classList.toggle('activo', i === idx));
    };
    const auto = () => { clearInterval(timer); timer = setInterval(() => ir(idx + 1), Number(hero.dataset.intervalo) || 5000); };
    hero.querySelectorAll('.hero-arrow').forEach((b) => b.addEventListener('click', () => { ir(idx + Number(b.dataset.dir)); auto(); }));
    dots.forEach((d) => d.addEventListener('click', () => { ir(Number(d.dataset.i)); auto(); }));
    auto();
}

// ---------- Ficha de producto (página completa) ----------
function renderProducto(p, relacionados) {
    const cont = $('producto-cont');
    const imgs = Array.isArray(p.imagenes) && p.imagenes.length ? p.imagenes : [];
    const esMay = cliente && cliente.modo === 'mayorista';
    const min = esMay ? (Number(p.min_mayorista) || 1) : 1;
    const agotado = Number(p.es_sobre_pedido) !== 1 && Number(p.stock) <= 0;
    const hayAnt = Number(p.precio_anterior) > Number(p.precio);
    const off = hayAnt ? Math.round((1 - p.precio / p.precio_anterior) * 100) : 0;
    const favOn = favoritos.has(Number(p.id));
    const cuota = p.precio / 3;

    const galeria = imgs.length
        ? `<div class="pp-main"><img id="pp-img" src="${esc(imgs[0])}" alt=""></div>
           ${imgs.length > 1 ? `<div class="pp-thumbs">${imgs.map((u, i) =>
                `<button class="pp-thumb ${i === 0 ? 'activo' : ''}" data-url="${esc(u)}"><img src="${esc(u)}" alt=""></button>`).join('')}</div>` : ''}`
        : `<div class="pp-main placeholder">${esc(inicial(p.nombre))}</div>`;

    const specs = [
        ['Marca', p.marca], ['Categoría', p.categoria], ['SKU', p.sku],
        ['Código de barras', p.codigo_barras],
        ['Disponibilidad', Number(p.es_sobre_pedido) === 1 ? 'Sobre pedido' : (agotado ? 'Sin stock' : Number(p.stock) + ' u')],
        ['Compra mínima mayorista', (Number(p.min_mayorista) || 1) + ' u'],
    ].filter(([, v]) => v).map(([k, v]) => `<tr><th>${esc(k)}</th><td>${esc(v)}</td></tr>`).join('');

    const rel = (relacionados || []).slice(0, 12).map(cardProducto).join('');

    cont.innerHTML = `
        <nav class="breadcrumb">
            <a data-ir-inicio href="#">Inicio</a> ›
            ${p.categoria ? `<a data-ir-cat="${p.categoria_id}" data-titulo="${esc(p.categoria)}" href="#">${esc(p.categoria)}</a> ›` : ''}
            <span>${esc(p.nombre)}</span>
        </nav>

        <div class="pp">
            <div class="pp-galeria">${galeria}</div>

            <div class="pp-info">
                <div class="pp-eyebrow">${esc(p.marca || 'Producto')}${p.categoria ? ' · ' + esc(p.categoria) : ''}</div>
                <h1 class="pp-nombre">${esc(p.nombre)}</h1>

                <div class="pp-precio-box">
                    ${hayAnt ? `<div class="pp-ant"><s>${money.format(p.precio_anterior)}</s> <span class="pp-off">-${off}%</span></div>` : ''}
                    <div class="pp-precio">${money.format(p.precio)}</div>
                    <div class="pp-cuotas">3 cuotas sin interés de <strong>${money.format(cuota)}</strong></div>
                    <div class="pp-lista">${esMay ? 'Precio mayorista' : 'Precio minorista'}</div>
                </div>

                <div class="pp-stock ${agotado ? 'agotado' : ''}">
                    ${Number(p.es_sobre_pedido) === 1 ? '📦 Disponible sobre pedido' : (agotado ? 'Sin stock' : '✔ En stock (' + Number(p.stock) + ' u)')}
                </div>
                ${esMay && min > 1 ? `<div class="pp-min">Compra mínima mayorista: ${min} u</div>` : ''}

                <div class="pp-compra">
                    <div class="cart-cant">
                        <button id="pp-menos" type="button">−</button>
                        <span id="pp-cant">${min}</span>
                        <button id="pp-mas" type="button">+</button>
                    </div>
                    <button class="btn-primary pp-add" id="pp-add" ${agotado ? 'disabled' : ''}>Agregar al carrito</button>
                    <button class="pp-fav ${favOn ? 'activo' : ''}" id="pp-fav" aria-label="Favorito">${favOn ? '❤️' : '🤍'}</button>
                </div>

                <div class="pp-benes">
                    <div class="pp-bene"><span>🚚</span> Envío a todo el país</div>
                    <div class="pp-bene"><span>🛡️</span> Compra protegida</div>
                    <div class="pp-bene"><span>💳</span> Todos los medios de pago</div>
                </div>
            </div>
        </div>

        <div class="pp-detalle">
            <div class="pp-desc">
                <h2>Descripción</h2>
                <p>${esc(p.descripcion || 'Sin descripción disponible.')}</p>
            </div>
            <div class="pp-specs">
                <h2>Especificaciones</h2>
                <table class="specs-tabla">${specs}</table>
            </div>
        </div>

        ${rel ? `<section class="bloque pp-relacionados">
            <div class="bloque-head"><h2>Productos relacionados</h2>${flechas()}</div>
            <div class="carrusel"><div class="carrusel-track">${rel}</div></div>
        </section>` : ''}`;

    // Galería
    cont.querySelectorAll('.pp-thumb').forEach((t) => t.addEventListener('click', () => {
        $('pp-img').src = t.dataset.url;
        cont.querySelectorAll('.pp-thumb').forEach((x) => x.classList.remove('activo'));
        t.classList.add('activo');
    }));
    // Zoom de la imagen principal (lightbox)
    const ppImg = $('pp-img');
    if (ppImg) { ppImg.style.cursor = 'zoom-in'; ppImg.addEventListener('click', () => abrirLightbox(ppImg.src)); }
    // Cantidad
    let cant = min;
    const pintar = () => { $('pp-cant').textContent = cant; };
    $('pp-menos').addEventListener('click', () => { if (cant > min) { cant--; pintar(); } });
    $('pp-mas').addEventListener('click', () => { cant++; pintar(); });
    // Agregar / favorito
    $('pp-add').addEventListener('click', () => {
        agregar(p.id, p.nombre, p.precio, cant);
        renderCarrito(); abrir('modal-carrito');
    });
    $('pp-fav').addEventListener('click', async () => {
        await toggleFavorito(p.id);
        const on = favoritos.has(Number(p.id));
        $('pp-fav').classList.toggle('activo', on);
        $('pp-fav').textContent = on ? '❤️' : '🤍';
    });
    // Carrusel de relacionados
    cont.querySelectorAll('.carrusel').forEach(montarCarrusel);
}

// Lightbox reutilizable para ampliar imágenes (ficha de producto).
function abrirLightbox(url) {
    let lb = document.getElementById('lightbox');
    if (!lb) {
        lb = document.createElement('div');
        lb.id = 'lightbox';
        lb.className = 'lightbox';
        lb.innerHTML = '<button class="lightbox-x" aria-label="Cerrar">×</button><img alt="">';
        document.body.appendChild(lb);
        lb.addEventListener('click', () => lb.classList.add('oculto'));
    }
    lb.querySelector('img').src = url;
    lb.classList.remove('oculto');
}

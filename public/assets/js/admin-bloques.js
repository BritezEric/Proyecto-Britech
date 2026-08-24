// =====================================================================
// Admin — Inicio de la tienda (page builder). Gestiona los bloques de la home:
// listar, reordenar (↑/↓), activar, crear/editar por tipo, borrar, y editar
// los slides del hero. Usa la API /api/admin/bloques*.
// (Reusa $, esc, api y opcionesDe de admin.js, que se carga antes.)
// =====================================================================

const TIPOS_BLOQUE = [
    ['hero', 'Hero (carrusel principal)'],
    ['banner', 'Banner horizontal'],
    ['video', 'Video / banner lateral'],
    ['carrusel_categorias', 'Carrusel de categorías'],
    ['carrusel_productos', 'Carrusel de productos'],
    ['grid_productos', 'Grid de productos / ofertas'],
];
const NOMBRE_TIPO = Object.fromEntries(TIPOS_BLOQUE);

// Campos de config según el tipo (tipo de campo: text | number | img | cat | select | check)
const CAMPOS_BLOQUE = {
    hero: [{ k: 'intervalo_ms', label: 'Intervalo entre slides (ms)', tipo: 'number', def: 5000 }],
    banner: [
        { k: 'imagen', label: 'Imagen', tipo: 'img' },
        { k: 'titulo', label: 'Título', tipo: 'text' },
        { k: 'subtitulo', label: 'Subtítulo', tipo: 'text' },
        { k: 'url', label: 'Enlace (URL o #cat-ID)', tipo: 'text' },
    ],
    video: [
        { k: 'video_url', label: 'URL del video (mp4)', tipo: 'text' },
        { k: 'imagen', label: 'Imagen (o póster del video)', tipo: 'img' },
        { k: 'titulo', label: 'Título', tipo: 'text' },
        { k: 'subtitulo', label: 'Subtítulo', tipo: 'text' },
        { k: 'url', label: 'Enlace', tipo: 'text' },
    ],
    carrusel_categorias: [],
    carrusel_productos: [
        { k: 'categoria_id', label: 'Categoría', tipo: 'cat' },
        { k: 'limite', label: 'Máx. productos', tipo: 'number', def: 10 },
        { k: 'banner.imagen', label: 'Banner (opcional): imagen', tipo: 'img' },
        { k: 'banner.url', label: 'Banner: enlace', tipo: 'text' },
        { k: 'banner.posicion', label: 'Banner: posición', tipo: 'select', ops: [['inicio', 'Al inicio'], ['fin', 'Al final']] },
    ],
    grid_productos: [
        { k: 'categoria_id', label: 'Categoría (opcional)', tipo: 'cat' },
        { k: 'solo_ofertas', label: 'Mostrar solo ofertas', tipo: 'check' },
        { k: 'limite', label: 'Máx. productos', tipo: 'number', def: 8 },
    ],
};

let bloqueId = 0;      // 0 = nuevo
let bloqueTipo = null;
let slideBloqueId = 0; // hero abierto en el editor de slides
let slideId = 0;       // slide en edición

// ---------- Lista de bloques ----------
async function renderBloques() {
    const cont = $('bloques-lista');
    cont.innerHTML = '<p class="dash-vacio">Cargando…</p>';
    let bloques;
    try { bloques = (await api.get('/api/admin/bloques')).bloques; }
    catch { cont.innerHTML = '<p class="dash-vacio">No se pudo cargar.</p>'; return; }

    if (!bloques.length) { cont.innerHTML = '<p class="dash-vacio">Todavía no hay bloques. Creá el primero.</p>'; return; }

    cont.innerHTML = bloques.map((b, i) => {
        const nombre = b.titulo || NOMBRE_TIPO[b.tipo] || b.tipo;
        const slidesBtn = b.tipo === 'hero' ? `<button class="btn-fila" data-slides="${b.id}">Slides</button>` : '';
        return `<div class="bloque-row ${Number(b.activo) ? '' : 'inactivo'}" draggable="true" data-id="${b.id}">
            <div class="bloque-orden">
                <span class="bloque-drag" title="Arrastrá para reordenar" aria-hidden="true">⠿</span>
                <button class="ord-btn" data-mover="${b.id}" data-dir="arriba" ${i === 0 ? 'disabled' : ''}>▲</button>
                <button class="ord-btn" data-mover="${b.id}" data-dir="abajo" ${i === bloques.length - 1 ? 'disabled' : ''}>▼</button>
            </div>
            <div class="bloque-info">
                <span class="bloque-tipo">${esc(NOMBRE_TIPO[b.tipo] || b.tipo)}</span>
                <strong>${esc(nombre)}</strong>
            </div>
            <span class="badge ${Number(b.activo) ? 'ok' : 'off'}">${Number(b.activo) ? 'Activo' : 'Inactivo'}</span>
            <div class="bloque-acciones">
                ${slidesBtn}
                <button class="btn-fila" data-editar="${b.id}">Editar</button>
                <button class="btn-fila" data-toggle="${b.id}" data-activo="${Number(b.activo) ? 0 : 1}">${Number(b.activo) ? 'Desactivar' : 'Activar'}</button>
                <button class="btn-fila peligro" data-borrar="${b.id}">Borrar</button>
            </div>
        </div>`;
    }).join('');
    // guardamos los bloques para editar sin volver a pedir
    renderBloques._cache = bloques;
}

// ---------- Modal crear/editar ----------
function abrirBloque(modo, b = null) {
    bloqueId = (modo === 'editar' && b) ? b.id : 0;
    bloqueTipo = b ? b.tipo : TIPOS_BLOQUE[0][0];
    $('bloque-title').textContent = modo === 'editar' ? 'Editar bloque' : 'Nuevo bloque';
    $('bloque-error').classList.add('oculto');

    const tipoSelector = modo === 'editar'
        ? `<div class="campo ancho"><label>Tipo</label><input value="${esc(NOMBRE_TIPO[bloqueTipo])}" disabled></div>`
        : `<div class="campo ancho"><label>Tipo de bloque</label>
             <select id="bcfg-tipo">${TIPOS_BLOQUE.map(([v, t]) => `<option value="${v}">${esc(t)}</option>`).join('')}</select></div>`;

    $('bloque-campos').innerHTML = `
        <div class="form-grid">
            ${tipoSelector}
            <div class="campo ancho"><label>Título de sección (opcional)</label><input id="bcfg-titulo" value="${esc(b?.titulo || '')}"></div>
            <div class="campo check"><input type="checkbox" id="bcfg-activo" ${b ? (Number(b.activo) ? 'checked' : '') : 'checked'}><label for="bcfg-activo">Activo</label></div>
        </div>
        <div id="bloque-config"></div>`;

    if (modo !== 'editar') {
        $('bcfg-tipo').addEventListener('change', (e) => { bloqueTipo = e.target.value; renderConfig(b); });
    }
    renderConfig(b);
    $('modal-bloque').classList.remove('oculto');
}

async function renderConfig(b) {
    const cont = $('bloque-config');
    const campos = CAMPOS_BLOQUE[bloqueTipo] || [];
    if (bloqueTipo === 'hero') {
        cont.innerHTML = `<div class="form-grid">${(await Promise.all(campos.map((c) => campoBloque(c, b)))).join('')}</div>
            <p class="dash-vacio" style="text-align:left">Las imágenes y textos del carrusel se cargan en <strong>Slides</strong> (botón en la lista del bloque, después de guardarlo).</p>`;
        return;
    }
    if (bloqueTipo === 'carrusel_categorias') {
        cont.innerHTML = `<p class="dash-vacio" style="text-align:left">Muestra automáticamente las categorías activas que tengan imagen (se cargan en Categorías).</p>`;
        return;
    }
    cont.innerHTML = `<div class="form-grid">${(await Promise.all(campos.map((c) => campoBloque(c, b)))).join('')}</div>`;
    wireImagenes(cont);
}

function getConf(b, k) {
    if (!b || !b.config) return undefined;
    return k.split('.').reduce((o, p) => (o == null ? undefined : o[p]), b.config);
}

async function campoBloque(c, b) {
    const id = 'bcfg_' + c.k.replace('.', '_');
    let val = getConf(b, c.k);
    if (val === undefined) val = c.def ?? '';
    if (c.tipo === 'check') {
        return `<div class="campo check"><input type="checkbox" id="${id}" ${val ? 'checked' : ''}><label for="${id}">${esc(c.label)}</label></div>`;
    }
    if (c.tipo === 'cat') {
        const ops = await opcionesDe('categorias');
        return `<div class="campo"><label>${esc(c.label)}</label><select id="${id}">
            <option value="">(elegir)</option>
            ${ops.map((o) => `<option value="${o.v}" ${String(o.v) === String(val) ? 'selected' : ''}>${esc(o.t)}</option>`).join('')}
        </select></div>`;
    }
    if (c.tipo === 'select') {
        return `<div class="campo"><label>${esc(c.label)}</label><select id="${id}">
            <option value="">(ninguno)</option>
            ${c.ops.map(([v, t]) => `<option value="${v}" ${v === val ? 'selected' : ''}>${esc(t)}</option>`).join('')}
        </select></div>`;
    }
    if (c.tipo === 'img') {
        return `<div class="campo ancho"><label>${esc(c.label)}</label>
            <div class="img-campo">
                <input type="text" id="${id}" value="${esc(val)}" placeholder="URL o subí un archivo">
                <button type="button" class="btn-secundario btn-subir" data-target="${id}">Subir</button>
            </div></div>`;
    }
    const tipo = c.tipo === 'number' ? 'number' : 'text';
    return `<div class="campo"><label>${esc(c.label)}</label><input type="${tipo}" id="${id}" value="${esc(val)}"></div>`;
}

// Botón "Subir" en campos de imagen (reusa el endpoint de imágenes de producto)
function wireImagenes(cont) {
    cont.querySelectorAll('.btn-subir').forEach((btn) => btn.addEventListener('click', () => {
        const input = document.createElement('input');
        input.type = 'file'; input.accept = 'image/*';
        input.onchange = async () => {
            if (!input.files[0]) return;
            const fd = new FormData(); fd.append('imagen', input.files[0]);
            btn.textContent = 'Subiendo…'; btn.disabled = true;
            try {
                const r = await fetch('/api/admin/productos/imagen', { method: 'POST', body: fd }).then((x) => x.json());
                if (r.ok) $(btn.dataset.target).value = r.url; else alert(r.error);
            } catch (e) { alert('No se pudo subir.'); }
            btn.textContent = 'Subir'; btn.disabled = false;
        };
        input.click();
    }));
}

function leerConfig() {
    const cfg = {};
    for (const c of (CAMPOS_BLOQUE[bloqueTipo] || [])) {
        const el = $('bcfg_' + c.k.replace('.', '_'));
        if (!el) continue;
        let v = c.tipo === 'check' ? el.checked : el.value.trim();
        if (c.tipo === 'number') v = Number(el.value) || c.def || 0;
        if (v === '' || v === false) continue;   // no guardamos vacíos
        // set anidado (banner.imagen)
        const partes = c.k.split('.');
        if (partes.length === 2) { (cfg[partes[0]] ??= {})[partes[1]] = v; }
        else cfg[c.k] = v;
    }
    if (cfg.banner && !cfg.banner.imagen) delete cfg.banner;   // banner sin imagen = no hay banner
    return cfg;
}

async function guardarBloque() {
    const err = $('bloque-error'); err.classList.add('oculto');
    const btn = $('bloque-guardar'); btn.disabled = true;
    try {
        const payload = {
            id: bloqueId || undefined,
            tipo: bloqueTipo,
            titulo: $('bcfg-titulo').value,
            activo: $('bcfg-activo').checked ? 1 : 0,
            config: leerConfig(),
        };
        await api.post('/api/admin/bloques/guardar', payload);
        $('modal-bloque').classList.add('oculto');
        await renderBloques();
    } catch (e) { err.textContent = e.message; err.classList.remove('oculto'); }
    finally { btn.disabled = false; }
}

// ---------- Slides del hero ----------
async function abrirSlides(id) {
    slideBloqueId = id; slideId = 0;
    $('modal-slides').classList.remove('oculto');
    await cargarSlides();
    resetSlideForm();
}

async function cargarSlides() {
    const r = await api.get('/api/admin/bloques/slides?bloque_id=' + slideBloqueId);
    const cont = $('slides-lista');
    cont.innerHTML = r.slides.length === 0
        ? '<p class="dash-vacio">Sin slides todavía.</p>'
        : r.slides.map((s) => `<div class="slide-row">
            <div class="slide-thumb" style="background-image:url('${esc(s.imagen_desktop || '')}')"></div>
            <div class="slide-info"><strong>${esc(s.titulo || '(sin título)')}</strong><span>${esc(s.subtitulo || '')}</span></div>
            <span class="badge ${Number(s.activo) ? 'ok' : 'off'}">${Number(s.activo) ? 'on' : 'off'}</span>
            <button class="btn-fila" data-eslide="${s.id}">Editar</button>
            <button class="btn-fila peligro" data-bslide="${s.id}">Borrar</button>
        </div>`).join('');
    cont._slides = r.slides;
}

function resetSlideForm(s = null) {
    slideId = s ? s.id : 0;
    const f = (id, v = '') => `value="${esc(s ? (s[id] ?? '') : v)}"`;
    $('slide-form').innerHTML = `
        <h4 class="detalle-sub">${s ? 'Editar slide' : 'Nueva slide'}</h4>
        <div class="form-grid">
            <div class="campo ancho"><label>Imagen desktop *</label>
                <div class="img-campo"><input type="text" id="sl_imagen_desktop" ${f('imagen_desktop')} placeholder="URL o subir">
                <button type="button" class="btn-secundario btn-subir" data-target="sl_imagen_desktop">Subir</button></div></div>
            <div class="campo ancho"><label>Imagen mobile (opcional)</label>
                <div class="img-campo"><input type="text" id="sl_imagen_mobile" ${f('imagen_mobile')} placeholder="URL o subir">
                <button type="button" class="btn-secundario btn-subir" data-target="sl_imagen_mobile">Subir</button></div></div>
            <div class="campo"><label>Título</label><input id="sl_titulo" ${f('titulo')}></div>
            <div class="campo"><label>Subtítulo</label><input id="sl_subtitulo" ${f('subtitulo')}></div>
            <div class="campo"><label>Texto del botón</label><input id="sl_boton_texto" ${f('boton_texto')}></div>
            <div class="campo"><label>Enlace (URL o #cat-ID)</label><input id="sl_url" ${f('url')}></div>
            <div class="campo"><label>Desde (opcional)</label><input type="date" id="sl_desde" ${f('desde')}></div>
            <div class="campo"><label>Hasta (opcional)</label><input type="date" id="sl_hasta" ${f('hasta')}></div>
            <div class="campo check"><input type="checkbox" id="sl_activo" ${!s || Number(s.activo) ? 'checked' : ''}><label for="sl_activo">Activa</label></div>
        </div>`;
    wireImagenes($('slide-form'));
}

async function guardarSlide() {
    const val = (id) => $(id).value.trim();
    try {
        await api.post('/api/admin/bloques/slide/guardar', {
            id: slideId || undefined, bloque_id: slideBloqueId,
            imagen_desktop: val('sl_imagen_desktop'), imagen_mobile: val('sl_imagen_mobile'),
            titulo: val('sl_titulo'), subtitulo: val('sl_subtitulo'), boton_texto: val('sl_boton_texto'),
            url: val('sl_url'), desde: val('sl_desde'), hasta: val('sl_hasta'),
            activo: $('sl_activo').checked ? 1 : 0,
        });
        await cargarSlides();
        resetSlideForm();
    } catch (e) { alert(e.message); }
}

// Dada la posición Y del mouse, ¿antes de qué fila hay que insertar la arrastrada?
function filaDespuesDe(cont, y) {
    const filas = [...cont.querySelectorAll('.bloque-row:not(.arrastrando)')];
    let mejor = { offset: -Infinity, el: null };
    for (const fila of filas) {
        const caja = fila.getBoundingClientRect();
        const offset = y - caja.top - caja.height / 2;   // negativo = el mouse está por encima del centro
        if (offset < 0 && offset > mejor.offset) mejor = { offset, el: fila };
    }
    return mejor.el;
}

// ---------- Wiring ----------
(function wireBloques() {
    $('btn-nuevo-bloque').addEventListener('click', () => abrirBloque('nuevo'));
    $('bloque-cancelar').addEventListener('click', () => $('modal-bloque').classList.add('oculto'));
    $('bloque-guardar').addEventListener('click', guardarBloque);
    $('modal-bloque').addEventListener('click', (e) => { if (e.target === $('modal-bloque')) $('modal-bloque').classList.add('oculto'); });
    $('slides-cerrar').addEventListener('click', () => { $('modal-slides').classList.add('oculto'); renderBloques(); });
    $('slide-guardar').addEventListener('click', guardarSlide);

    $('bloques-lista').addEventListener('click', async (e) => {
        const t = e.target.closest('button'); if (!t) return;
        const d = t.dataset;
        if (d.mover)  { await api.post('/api/admin/bloques/mover', { id: Number(d.mover), dir: d.dir }); return renderBloques(); }
        if (d.toggle) { await api.post('/api/admin/bloques/estado', { id: Number(d.toggle), activo: Number(d.activo) }); return renderBloques(); }
        if (d.borrar) { if (confirm('¿Borrar este bloque?')) { await api.post('/api/admin/bloques/borrar', { id: Number(d.borrar) }); renderBloques(); } return; }
        if (d.editar) { const b = (renderBloques._cache || []).find((x) => String(x.id) === d.editar); return abrirBloque('editar', b); }
        if (d.slides) return abrirSlides(Number(d.slides));
    });

    // --- Reordenar arrastrando (drag & drop) ---
    const lista = $('bloques-lista');
    let dragEl = null;
    lista.addEventListener('dragstart', (e) => {
        const row = e.target.closest('.bloque-row');
        if (!row) return;
        dragEl = row;
        e.dataTransfer.effectAllowed = 'move';
        // pequeño delay para que el estilo "fantasma" no tape el arrastre
        setTimeout(() => row.classList.add('arrastrando'), 0);
    });
    lista.addEventListener('dragover', (e) => {
        if (!dragEl) return;
        e.preventDefault();
        const despues = filaDespuesDe(lista, e.clientY);
        if (despues == null) lista.appendChild(dragEl);
        else lista.insertBefore(dragEl, despues);
    });
    lista.addEventListener('drop', (e) => e.preventDefault());
    lista.addEventListener('dragend', async () => {
        if (!dragEl) return;
        dragEl.classList.remove('arrastrando');
        dragEl = null;
        const ids = [...lista.querySelectorAll('.bloque-row')].map((r) => Number(r.dataset.id));
        try { await api.post('/api/admin/bloques/reordenar', { ids }); }
        catch { alert('No se pudo guardar el nuevo orden.'); }
        renderBloques();   // refresca los botones ▲/▼ (bordes) según el orden nuevo
    });

    $('slides-lista').addEventListener('click', async (e) => {
        const t = e.target.closest('button'); if (!t) return;
        if (t.dataset.eslide) { const s = ($('slides-lista')._slides || []).find((x) => String(x.id) === t.dataset.eslide); return resetSlideForm(s); }
        if (t.dataset.bslide && confirm('¿Borrar la slide?')) { await api.post('/api/admin/bloques/slide/borrar', { id: Number(t.dataset.bslide) }); await cargarSlides(); resetSlideForm(); }
    });
})();

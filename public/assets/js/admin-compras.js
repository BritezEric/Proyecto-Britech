/* ============================================================
 * Compras — órdenes de compra a proveedores + recepción de mercadería.
 * Mini-pantalla dentro de #compras-cont con tres vistas: lista, nueva orden
 * y detalle/recepción. Reusa los globals de admin.js ($, api, money, esc, toast).
 * ========================================================== */

const COMPRA_EST = {
    enviada:  { txt: 'Enviada',  cls: 'env' },
    parcial:  { txt: 'Parcial',  cls: 'par' },
    recibida: { txt: 'Recibida', cls: 'rec' },
    anulada:  { txt: 'Anulada',  cls: 'anu' },
};

let compraFiltro = '';        // filtro por estado ('' = todas)
let compraNueva = null;       // { proveedores, prov, items:[{id,nombre,sku,cantidad,costo}] }

async function renderCompras() {
    const cont = $('compras-cont');
    cont.innerHTML = '<p class="td-mute">Cargando…</p>';
    let r;
    try { r = await api.get('/api/admin/compras' + (compraFiltro ? '?estado=' + compraFiltro : '')); }
    catch { cont.innerHTML = '<p class="tabla-vacia">No se pudo cargar las compras.</p>'; return; }
    pintarListaCompras(r.data || []);   // Paginacion::respuesta devuelve las filas en `data`
}

function pintarListaCompras(rows) {
    const cont = $('compras-cont');
    const chip = (val, txt) =>
        `<button class="compra-fchip ${compraFiltro === val ? 'on' : ''}" data-filtro="${val}">${txt}</button>`;
    const filtros = `<div class="compra-filtros">
        ${chip('', 'Todas')}${chip('enviada', 'Enviadas')}${chip('parcial', 'Parciales')}
        ${chip('recibida', 'Recibidas')}${chip('anulada', 'Anuladas')}
    </div>`;

    const filas = rows.length ? rows.map((o) => {
        const e = COMPRA_EST[o.estado] || { txt: o.estado, cls: 'env' };
        return `<button class="compra-fila" data-orden="${o.id}">
            <span class="compra-num">${esc(o.numero || ('#' + o.id))}</span>
            <span class="compra-prov">${esc(o.proveedor || '(sin proveedor)')}</span>
            <span class="compra-lineas">${o.lineas} ítem(s)</span>
            <span class="compra-total">${money.format(o.total_estimado)}</span>
            <span class="compra-est est-${e.cls}">${e.txt}</span>
        </button>`;
    }).join('') : '<p class="tabla-vacia">No hay órdenes con este filtro.</p>';

    cont.innerHTML = filtros + `<div class="compra-lista">${filas}</div>`;
    cont.querySelectorAll('[data-filtro]').forEach((b) => b.addEventListener('click', () => {
        compraFiltro = b.dataset.filtro; renderCompras();
    }));
    cont.querySelectorAll('[data-orden]').forEach((b) => b.addEventListener('click', () =>
        abrirDetalleCompra(Number(b.dataset.orden))));
}

// ---- Detalle + recepción de una orden ----
async function abrirDetalleCompra(id) {
    const cont = $('compras-cont');
    cont.innerHTML = '<p class="td-mute">Cargando…</p>';
    let r;
    try { r = await api.get('/api/admin/compras/detalle?id=' + id); }
    catch { cont.innerHTML = '<p class="tabla-vacia">No se pudo cargar la orden.</p>'; return; }

    const o = r.orden, items = r.items || [];
    const e = COMPRA_EST[o.estado] || { txt: o.estado, cls: 'env' };
    const abierta = o.estado === 'enviada' || o.estado === 'parcial';

    const filas = items.map((it) => {
        const pend = Number(it.cantidad) - Number(it.cantidad_recibida);
        const inputRec = abierta && pend > 0
            ? `<input type="number" min="0" max="${pend}" value="${pend}" data-rec="${it.id}" class="compra-rec-inp">`
            : '<span class="td-mute">—</span>';
        return `<div class="compra-dfila">
            <span class="compra-nom">${esc(it.nombre)}${it.sku ? `<small>${esc(it.sku)}</small>` : ''}</span>
            <span>${it.cantidad}</span>
            <span>${it.cantidad_recibida}</span>
            <span>${money.format(it.costo_unitario)}</span>
            <span class="ta-r">${inputRec}</span>
        </div>`;
    }).join('');

    const acciones = abierta ? `<div class="compra-acc">
        <button class="btn-primary" id="compra-recibir">Registrar recepción</button>
        <button class="btn-ghost" id="compra-anular">Anular orden</button>
    </div>` : '';

    cont.innerHTML = `
        <button class="volver-link" id="compra-volver">‹ Compras</button>
        <div class="compra-detalle">
            <div class="compra-dhead">
                <div>
                    <h2>${esc(o.numero || ('#' + o.id))}</h2>
                    <p class="sub">${esc(o.proveedor || '(sin proveedor)')}${o.proveedor_tel ? ' · ' + esc(o.proveedor_tel) : ''}</p>
                </div>
                <span class="compra-est est-${e.cls} grande">${e.txt}</span>
            </div>
            ${o.observacion ? `<p class="compra-obs">${esc(o.observacion)}</p>` : ''}
            <div class="compra-dtabla">
                <div class="compra-dhrow"><span>Producto</span><span>Pedido</span><span>Recibido</span><span>Costo</span><span class="ta-r">Recibir ahora</span></div>
                ${filas}
            </div>
            <div class="compra-dfoot"><span>Total estimado</span><b>${money.format(o.total_estimado)}</b></div>
            ${acciones}
        </div>`;

    $('compra-volver').addEventListener('click', renderCompras);
    if (abierta) {
        $('compra-recibir').addEventListener('click', () => registrarRecepcion(id));
        $('compra-anular').addEventListener('click', () => anularOrden(id, o.numero));
    }
}

async function registrarRecepcion(id) {
    const recepciones = [...document.querySelectorAll('[data-rec]')]
        .map((inp) => ({ detalle_id: Number(inp.dataset.rec), cantidad: parseInt(inp.value, 10) || 0 }))
        .filter((x) => x.cantidad > 0);
    if (!recepciones.length) { toast('⚠ Poné al menos una cantidad a recibir'); return; }
    try {
        const r = await api.post('/api/admin/compras/recibir', { orden_id: id, recepciones });
        toast(r.orden.estado === 'recibida' ? '✓ Mercadería recibida' : '✓ Recepción parcial registrada');
        abrirDetalleCompra(id);
    } catch (err) { toast('⚠ ' + err.message); }
}

async function anularOrden(id, numero) {
    if (!confirm(`¿Anular la orden ${numero}? El stock ya recibido no se revierte.`)) return;
    try {
        await api.post('/api/admin/compras/anular', { orden_id: id });
        toast('✓ Orden anulada');
        renderCompras();
    } catch (err) { toast('⚠ ' + err.message); }
}

// ---- Nueva orden (alta manual) ----
async function abrirNuevaCompra() {
    const cont = $('compras-cont');
    cont.innerHTML = '<p class="td-mute">Cargando…</p>';
    let r;
    try { r = await api.get('/api/admin/compras/nueva'); }
    catch { cont.innerHTML = '<p class="tabla-vacia">No se pudo abrir el alta.</p>'; return; }
    compraNueva = { proveedores: r.proveedores || [], prov: '', items: [] };
    pintarNuevaCompra();
}

function pintarNuevaCompra() {
    const cont = $('compras-cont');
    const c = compraNueva;
    const opts = ['<option value="">Elegí un proveedor…</option>']
        .concat(c.proveedores.map((p) => `<option value="${p.id}" ${String(p.id) === c.prov ? 'selected' : ''}>${esc(p.nombre)}</option>`))
        .join('');

    const filas = c.items.length ? c.items.map((it, i) => `
        <div class="compra-dfila">
            <span class="compra-nom">${esc(it.nombre)}${it.sku ? `<small>${esc(it.sku)}</small>` : ''}</span>
            <span><input type="number" min="1" value="${it.cantidad}" data-ncant="${i}" class="compra-rec-inp"></span>
            <span><input type="number" min="0" step="0.01" value="${it.costo}" data-ncosto="${i}" class="compra-rec-inp ancho"></span>
            <span class="ta-r">${money.format(it.cantidad * it.costo)}</span>
            <span class="ta-r"><button class="compra-quitar" data-quitar="${i}" title="Quitar">✕</button></span>
        </div>`).join('') : '<p class="td-mute compra-vacio-l">Buscá y agregá productos a la orden.</p>';

    const total = c.items.reduce((s, it) => s + it.cantidad * it.costo, 0);

    cont.innerHTML = `
        <button class="volver-link" id="compra-volver">‹ Compras</button>
        <div class="compra-detalle">
            <div class="compra-nueva-top">
                <label>Proveedor <select id="compra-prov" class="compra-select">${opts}</select></label>
                <label class="compra-buscar-w">Agregar producto
                    <input type="text" id="compra-buscar" class="compra-select" placeholder="Nombre o SKU…" autocomplete="off">
                    <div id="compra-sugerencias" class="compra-sug oculto"></div>
                </label>
            </div>
            <div class="compra-dtabla">
                <div class="compra-dhrow"><span>Producto</span><span>Cantidad</span><span>Costo unit.</span><span class="ta-r">Subtotal</span><span></span></div>
                ${filas}
            </div>
            <div class="compra-dfoot"><span>Total estimado</span><b>${money.format(total)}</b></div>
            <div class="compra-acc">
                <button class="btn-primary" id="compra-crear" ${c.prov && c.items.length ? '' : 'disabled'}>Crear orden</button>
            </div>
        </div>
        <label class="compra-obs-w">Observación (opcional)
            <input type="text" id="compra-obs" class="compra-select" maxlength="250" placeholder="Ej: entrega urgente">
        </label>`;

    $('compra-volver').addEventListener('click', renderCompras);
    $('compra-prov').addEventListener('change', (e) => { c.prov = e.target.value; pintarNuevaCompra(); });
    c.items.forEach((it, i) => {
        cont.querySelector(`[data-ncant="${i}"]`).addEventListener('input', (e) => { it.cantidad = Math.max(1, parseInt(e.target.value, 10) || 1); pintarNuevaCompra(); });
        cont.querySelector(`[data-ncosto="${i}"]`).addEventListener('input', (e) => { it.costo = Math.max(0, parseFloat(e.target.value) || 0); pintarNuevaCompra(); });
    });
    cont.querySelectorAll('[data-quitar]').forEach((b) => b.addEventListener('click', () => {
        c.items.splice(Number(b.dataset.quitar), 1); pintarNuevaCompra();
    }));
    const crear = $('compra-crear');
    if (crear) crear.addEventListener('click', crearOrdenCompra);
    wireBuscadorCompra();
}

let compraBuscarTimer;
function wireBuscadorCompra() {
    const inp = $('compra-buscar'); const sug = $('compra-sugerencias');
    if (!inp) return;
    inp.addEventListener('input', () => {
        clearTimeout(compraBuscarTimer);
        const q = inp.value.trim();
        if (q.length < 2) { sug.classList.add('oculto'); return; }
        compraBuscarTimer = setTimeout(async () => {
            let r;
            try { r = await api.get('/api/productos/buscar?q=' + encodeURIComponent(q)); }
            catch { return; }
            const lista = r.datos || [];   // ProductoController::buscar responde { datos: [...] }
            sug.innerHTML = lista.length
                ? lista.slice(0, 8).map((p) => `<button class="compra-sug-it" data-add='${esc(JSON.stringify({ id: p.id, nombre: p.nombre, sku: p.sku || '', costo: Number(p.costo) || 0 }))}'>${esc(p.nombre)}${p.sku ? ` · ${esc(p.sku)}` : ''}</button>`).join('')
                : '<p class="compra-sug-vacio">Sin resultados</p>';
            sug.classList.remove('oculto');
            sug.querySelectorAll('[data-add]').forEach((b) => b.addEventListener('click', () => {
                agregarLineaCompra(JSON.parse(b.dataset.add));
                inp.value = ''; sug.classList.add('oculto');
            }));
        }, 250);
    });
}

function agregarLineaCompra(p) {
    const c = compraNueva;
    if (c.items.some((it) => it.id === p.id)) { toast('Ya está en la orden'); return; }
    c.items.push({ id: p.id, nombre: p.nombre, sku: p.sku, cantidad: 1, costo: p.costo });
    pintarNuevaCompra();
}

async function crearOrdenCompra() {
    const c = compraNueva;
    if (!c.prov || !c.items.length) return;
    const obs = ($('compra-obs') || {}).value || '';
    try {
        const r = await api.post('/api/admin/compras', {
            proveedor_id: Number(c.prov),
            observacion: obs,
            items: c.items.map((it) => ({ producto_id: it.id, cantidad: it.cantidad, costo_unitario: it.costo })),
        });
        toast('✓ Orden ' + r.orden.numero + ' creada');
        compraNueva = null;
        renderCompras();
    } catch (err) { toast('⚠ ' + err.message); }
}

// Botones del header de la vista (fuera de #compras-cont, estáticos en admin.html).
(function () {
    const b = document.getElementById('compras-nueva');
    if (b) b.addEventListener('click', abrirNuevaCompra);
    const volver = document.querySelector('#vista-compras [data-ir-inicio]');
    if (volver) volver.addEventListener('click', () => seleccionar('inicio'));
})();

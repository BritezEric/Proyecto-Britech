/* ============================================================
 * Reclamos (staff) — gestión de reclamos de clientes.
 * Lista con filtro por estado, detalle con hilo de mensajes, respuesta y
 * cambio de estado. Reusa los globals de admin.js ($, api, esc).
 * ========================================================== */

const RECLAMO_EST = {
    abierto:     { txt: 'Abierto',      cls: 'env' },
    en_revision: { txt: 'En revisión',  cls: 'par' },
    resuelto:    { txt: 'Resuelto',     cls: 'rec' },
    rechazado:   { txt: 'Rechazado',    cls: 'anu' },
};

let reclamoFiltro = '';

async function renderReclamos() {
    const cont = $('reclamos-cont');
    cont.innerHTML = '<p class="td-mute">Cargando…</p>';
    let r;
    try { r = await api.get('/api/admin/reclamos' + (reclamoFiltro ? '?estado=' + reclamoFiltro : '')); }
    catch { cont.innerHTML = '<p class="tabla-vacia">No se pudo cargar los reclamos.</p>'; return; }

    const chip = (val, txt) => `<button class="compra-fchip ${reclamoFiltro === val ? 'on' : ''}" data-filtro="${val}">${txt}</button>`;
    const filtros = `<div class="compra-filtros">
        ${chip('', 'Todos')}${chip('abierto', 'Abiertos')}${chip('en_revision', 'En revisión')}
        ${chip('resuelto', 'Resueltos')}${chip('rechazado', 'Rechazados')}
    </div>`;

    const rows = r.data || [];
    const filas = rows.length ? rows.map((x) => {
        const e = RECLAMO_EST[x.estado] || { txt: x.estado, cls: 'env' };
        return `<button class="compra-fila reclamo-fila" data-reclamo="${x.id}">
            <span class="compra-num">${esc(x.numero || ('#' + x.id))}</span>
            <span class="compra-prov">${esc(x.cliente)}</span>
            <span class="reclamo-asunto">${esc(x.asunto)}</span>
            <span class="compra-lineas">${esc(x.pedido_numero)}</span>
            <span class="compra-est est-${e.cls}">${e.txt}</span>
        </button>`;
    }).join('') : '<p class="tabla-vacia">No hay reclamos con este filtro.</p>';

    cont.innerHTML = filtros + `<div class="compra-lista">${filas}</div>`;
    cont.querySelectorAll('[data-filtro]').forEach((b) => b.addEventListener('click', () => { reclamoFiltro = b.dataset.filtro; renderReclamos(); }));
    cont.querySelectorAll('[data-reclamo]').forEach((b) => b.addEventListener('click', () => abrirDetalleReclamo(Number(b.dataset.reclamo))));
}

async function abrirDetalleReclamo(id) {
    const cont = $('reclamos-cont');
    cont.innerHTML = '<p class="td-mute">Cargando…</p>';
    let r;
    try { r = await api.get('/api/admin/reclamos/detalle?id=' + id); }
    catch { cont.innerHTML = '<p class="tabla-vacia">No se pudo cargar el reclamo.</p>'; return; }

    const x = r.reclamo;
    const opts = Object.entries(RECLAMO_EST).map(([k, v]) =>
        `<option value="${k}" ${x.estado === k ? 'selected' : ''}>${v.txt}</option>`).join('');

    cont.innerHTML = `
        <button class="volver-link" id="reclamo-volver">‹ Reclamos</button>
        <div class="compra-detalle">
            <div class="compra-dhead">
                <div>
                    <h2>${esc(x.numero || ('#' + x.id))} · ${esc(x.asunto)}</h2>
                    <p class="sub">${esc(x.cliente)} · pedido ${esc(x.pedido_numero)}</p>
                </div>
                <select id="reclamo-estado" class="compra-select">${opts}</select>
            </div>
            <div id="reclamo-hilo" class="reclamo-hilo">${pintarHilo(r.mensajes)}</div>
            <div class="reclamo-responder">
                <textarea id="reclamo-msg" rows="2" placeholder="Escribí una respuesta al cliente…"></textarea>
                <button class="btn-primary" id="reclamo-enviar">Responder</button>
            </div>
        </div>`;

    $('reclamo-volver').addEventListener('click', renderReclamos);
    $('reclamo-estado').addEventListener('change', async (e) => {
        try { await api.post('/api/admin/reclamos/estado', { reclamo_id: id, estado: e.target.value }); toast('✓ Estado actualizado'); }
        catch (ex) { toast('⚠ ' + ex.message); }
    });
    $('reclamo-enviar').addEventListener('click', async () => {
        const msg = $('reclamo-msg').value.trim();
        if (!msg) return;
        try {
            await api.post('/api/admin/reclamos/mensaje', { reclamo_id: id, mensaje: msg });
            abrirDetalleReclamo(id);   // recarga el hilo
        } catch (ex) { toast('⚠ ' + ex.message); }
    });
}

function pintarHilo(mensajes) {
    if (!mensajes || !mensajes.length) return '<p class="td-mute">Sin mensajes.</p>';
    return mensajes.map((m) => {
        const staff = m.autor === 'staff';
        const quien = staff ? (m.usuario ? esc(m.usuario) : 'Staff') : 'Cliente';
        const fecha = new Date(String(m.creado_en).replace(' ', 'T')).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
        return `<div class="reclamo-msg ${staff ? 'staff' : 'cliente'}">
            <div class="reclamo-msg-head">${quien} · ${fecha}</div>
            <div class="reclamo-msg-txt">${esc(m.mensaje)}</div>
        </div>`;
    }).join('');
}

// Botón "‹ Panel" del header (estático en admin.html).
(function () {
    const volver = document.querySelector('#vista-reclamos [data-ir-inicio]');
    if (volver) volver.addEventListener('click', () => seleccionar('inicio'));
})();

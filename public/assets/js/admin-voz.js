// ===== Auditoría del asistente de voz (panel admin) =====
// Lista las interacciones (fecha, usuario, qué pidió, qué respondió). Al hacer
// click en una fila se abre el detalle completo de esa conversación.

let _vozFilas = [];

function _vozEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}
function _vozFecha(s) {
    try { return new Date(String(s).replace(' ', 'T')).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); }
    catch (e) { return s; }
}
function _vozFechaLarga(s) {
    try { return new Date(String(s).replace(' ', 'T')).toLocaleString('es-AR', { dateStyle: 'long', timeStyle: 'short' }); }
    catch (e) { return s; }
}

// Modal (se crea una sola vez). Usa los tokens del design system.
function _vozModal() {
    let m = document.getElementById('voz-modal');
    if (m) return m;
    const st = document.createElement('style');
    st.textContent = `
        #voz-modal{position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:1rem;z-index:320}
        #voz-modal .vm-card{background:var(--surface);border:1px solid var(--line);border-radius:var(--r-lg);box-shadow:var(--shadow-lg);width:100%;max-width:520px;max-height:85vh;overflow:auto}
        #voz-modal .vm-head{display:flex;align-items:center;justify-content:space-between;padding:var(--sp-4) var(--sp-4) var(--sp-3);border-bottom:1px solid var(--line);position:sticky;top:0;background:var(--surface)}
        #voz-modal .vm-head h2{margin:0;font-size:var(--text-lg);font-weight:800}
        #voz-modal .vm-x{border:none;background:none;cursor:pointer;font-size:1.1rem;color:var(--ink-muted);padding:2px 8px;border-radius:var(--r-sm);line-height:1}
        #voz-modal .vm-x:hover{background:var(--surface-2);color:var(--ink)}
        #voz-modal .vm-body{padding:var(--sp-4);display:flex;flex-direction:column;gap:var(--sp-4)}
        #voz-modal .vm-meta{font-size:var(--text-sm);color:var(--ink-muted)}
        #voz-modal .vm-meta b{color:var(--ink)}
        #voz-modal .vm-turno .lbl{font-size:var(--text-xs);font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-muted);margin-bottom:4px}
        #voz-modal .vm-bubble{background:var(--surface-2);border:1px solid var(--line);border-radius:var(--r-md);padding:var(--sp-3) var(--sp-4);font-size:var(--text-sm);line-height:1.5;white-space:pre-wrap}
        #voz-modal .vm-bubble.resp{background:var(--primary-tint);border-color:transparent;color:var(--primary-strong)}`;
    document.head.appendChild(st);
    m = document.createElement('div');
    m.id = 'voz-modal';
    m.className = 'oculto';
    m.innerHTML = `
        <div class="vm-card" role="dialog" aria-modal="true">
            <div class="vm-head"><h2>Interacción</h2><button class="vm-x" aria-label="Cerrar">✕</button></div>
            <div class="vm-body"></div>
        </div>`;
    document.body.appendChild(m);
    const cerrar = () => m.classList.add('oculto');
    m.querySelector('.vm-x').addEventListener('click', cerrar);
    m.addEventListener('click', (e) => { if (e.target === m) cerrar(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrar(); });
    return m;
}

function abrirDetalleVoz(idx) {
    const f = _vozFilas[idx];
    if (!f) return;
    const m = _vozModal();
    m.querySelector('.vm-body').innerHTML = `
        <div class="vm-meta"><b>${_vozEsc(f.usuario)}</b> · ${_vozEsc(_vozFechaLarga(f.creado_en))} · <b>${_vozEsc(f.origen || 'pos')}</b></div>
        <div class="vm-turno"><div class="lbl">Dijo</div><div class="vm-bubble">${_vozEsc(f.texto)}</div></div>
        <div class="vm-turno"><div class="lbl">Respondió el asistente</div><div class="vm-bubble resp">${_vozEsc(f.respuesta)}</div></div>`;
    m.classList.remove('oculto');
}

async function renderVoz() {
    const cont = document.getElementById('voz-log-cont');
    const btn = document.getElementById('voz-refrescar');
    if (btn) btn.onclick = renderVoz;

    cont.innerHTML = '<p class="td-mute" style="padding:1rem">Cargando…</p>';

    let r;
    try { r = await api.get('/api/admin/voz/log'); }
    catch (e) { cont.innerHTML = '<div class="tabla-caja"><p class="tabla-vacia">No se pudo cargar la auditoría.</p></div>'; return; }

    _vozFilas = (r && r.data) || [];
    if (!_vozFilas.length) {
        cont.innerHTML = '<div class="tabla-caja"><p class="tabla-vacia">Todavía no hay interacciones con el asistente.</p></div>';
        return;
    }

    const rows = _vozFilas.map((f, i) => `
        <tr class="fila-link" data-idx="${i}">
            <td class="td-mute tabular" style="white-space:nowrap">${_vozFecha(f.creado_en)}</td>
            <td>${_vozEsc(f.usuario)}</td>
            <td>${_vozEsc(f.texto)}</td>
            <td class="td-mute">${_vozEsc(f.respuesta)}</td>
        </tr>`).join('');

    cont.innerHTML = `
        <div class="tabla-caja">
            <table>
                <thead><tr><th>Fecha</th><th>Usuario</th><th>Dijo</th><th>Respondió</th></tr></thead>
                <tbody id="voz-log-tbody">${rows}</tbody>
            </table>
        </div>`;

    document.getElementById('voz-log-tbody').addEventListener('click', (e) => {
        const tr = e.target.closest('tr[data-idx]');
        if (tr) abrirDetalleVoz(Number(tr.dataset.idx));
    });
}

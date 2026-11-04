// Lista de ventas + anulación (solo admin).

const tbody = document.getElementById('tbody');
const msg   = document.getElementById('msg');

function aviso(texto, tipo) {
    msg.textContent = texto;
    msg.className = 'msg ' + tipo;
}

const money = (n) => '$ ' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2 });

function fila(v) {
    const tr = document.createElement('tr');
    tr.className = 'clickable';
    tr.dataset.id = v.id;
    tr.dataset.num = v.numero;
    const boton = v.estado === 'registrada'
        ? `<button class="btn-anular" data-id="${v.id}" data-num="${v.numero}">Anular</button>`
        : '';
    tr.innerHTML = `
        <td class="num-venta">${v.numero}</td>
        <td>${v.cliente}</td>
        <td>${v.vendedor}</td>
        <td class="num">${money(v.total)}</td>
        <td class="fecha">${v.creado_en}</td>
        <td><span class="estado ${v.estado}">${v.estado}</span></td>
        <td>${boton}</td>`;
    return tr;
}

async function cargar() {
    const { ventas } = await api.get('/api/ventas');
    tbody.innerHTML = '';
    if (ventas.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7">No hay ventas todavía.</td></tr>';
        return;
    }
    ventas.forEach((v) => tbody.appendChild(fila(v)));
}

// ----- Detalle de la venta (modal al hacer click en la fila) -----
const modalDet = document.getElementById('modal-det');
const detCont  = document.getElementById('det-cont');
const detTit   = document.getElementById('det-titulo');

function cerrarDet() { modalDet.classList.add('oculto'); }
document.getElementById('det-cerrar').addEventListener('click', cerrarDet);
modalDet.addEventListener('click', (e) => { if (e.target === modalDet) cerrarDet(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrarDet(); });

async function verDetalle(id, num) {
    detTit.textContent = 'Venta ' + num;
    detCont.innerHTML = '<p class="td-mute">Cargando…</p>';
    modalDet.classList.remove('oculto');
    let d;
    try { d = await api.get('/api/ventas/detalle?id=' + id); }
    catch (err) { detCont.innerHTML = '<p class="msg error">No se pudo cargar el detalle.</p>'; return; }

    const v = d.venta || {};
    const items = (d.items || []).map((it) =>
        `<tr><td>${it.cantidad} × ${esc(it.nombre)}</td><td class="r">${money(it.subtotal)}</td></tr>`).join('');
    const pagos = (d.pagos || []).map((p) =>
        `<div class="row"><span>${esc(p.nombre)}</span><span>${money(p.monto)}</span></div>`).join('');
    const desc = Number(v.descuento) > 0 ? `<div class="row"><span>Descuento</span><span>- ${money(v.descuento)}</span></div>` : '';
    const envio = Math.round((Number(v.total) - (Number(v.subtotal) - Number(v.descuento))) * 100) / 100;
    const envioRow = envio > 0.001 ? `<div class="row"><span>Envío</span><span>${money(envio)}</span></div>` : '';

    detCont.innerHTML = `
        <div class="det-meta">
            <span>Estado <b>${esc(v.estado)}</b></span>
            <span>Cliente <b>${esc(v.cliente)}</b></span>
            <span>Vendedor <b>${esc(v.vendedor)}</b></span>
            <span>Fecha <b>${esc(v.creado_en)}</b></span>
        </div>
        <table class="det-items"><tbody>${items || '<tr><td>Sin ítems</td></tr>'}</tbody></table>
        <div class="det-sum">
            <div class="row"><span>Subtotal</span><span>${money(v.subtotal)}</span></div>
            ${desc}${envioRow}
            <div class="row total"><span>Total</span><span>${money(v.total)}</span></div>
            ${pagos ? '<div style="margin-top:var(--sp-3);font-weight:700;color:var(--ink)">Pagos</div>' + pagos : ''}
        </div>`;
}

function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); }

tbody.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btn-anular');
    if (!btn) {
        const tr = e.target.closest('tr[data-id]');
        if (tr) verDetalle(tr.dataset.id, tr.dataset.num);
        return;
    }

    const motivo = prompt(`Anular venta ${btn.dataset.num}. Motivo (obligatorio):`);
    if (motivo === null) return;              // canceló
    if (motivo.trim() === '') { aviso('El motivo es obligatorio.', 'error'); return; }

    btn.disabled = true;
    try {
        await api.post('/api/ventas/anular', { venta_id: Number(btn.dataset.id), motivo });
        aviso(`Venta ${btn.dataset.num} anulada. Stock reintegrado.`, 'ok');
        await cargar();
    } catch (err) {
        aviso(err.message, 'error');
        btn.disabled = false;
    }
});

// Protección: solo admin.
(async function () {
    let sesion;
    try { sesion = await api.get('/api/yo'); }
    catch { window.location.href = '/login.html'; return; }
    if (sesion.usuario.rol !== 'admin') {
        alert('Solo el administrador puede ver/anular ventas.');
        window.location.href = '/pos.html';
        return;
    }
    await cargar();
})();

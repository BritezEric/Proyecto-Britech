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

tbody.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btn-anular');
    if (!btn) return;

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

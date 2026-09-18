/* ============================================================
 * Cajas (admin) — supervisión de aperturas/cierres del vendedor.
 * Lista el historial y el detalle (resumen + movimientos) de cada caja.
 * Reusa los globals de admin.js ($, api, money, esc).
 * ========================================================== */

async function renderCajas() {
    const cont = $('cajas-cont');
    cont.innerHTML = '<p class="td-mute">Cargando…</p>';
    let r;
    try { r = await api.get('/api/admin/cajas'); }
    catch { cont.innerHTML = '<p class="tabla-vacia">No se pudo cargar las cajas.</p>'; return; }

    const rows = r.data || [];
    if (!rows.length) { cont.innerHTML = '<p class="tabla-vacia">Todavía no hay cajas registradas.</p>'; return; }

    const filas = rows.map((c) => {
        const abierta = c.estado === 'abierta';
        const dif = c.diferencia == null ? null : Number(c.diferencia);
        const difTxt = dif == null ? '—'
            : (dif === 0 ? '✔ OK' : (dif > 0 ? 'Sobra ' + money.format(dif) : 'Falta ' + money.format(-dif)));
        const difCls = dif == null ? '' : (dif === 0 ? 'ok' : 'mal');
        return `<button class="compra-fila caja-fila" data-caja="${c.id}">
            <span class="compra-num">#${c.id}</span>
            <span class="compra-prov">${esc(c.vendedor)}</span>
            <span class="compra-lineas">${new Date(String(c.abierta_en).replace(' ','T')).toLocaleDateString('es-AR')}</span>
            <span class="compra-total">${money.format(c.monto_apertura)}</span>
            <span class="caja-dif-cell ${difCls}">${difTxt}</span>
            <span class="compra-est est-${abierta ? 'env' : 'rec'}">${abierta ? 'Abierta' : 'Cerrada'}</span>
        </button>`;
    }).join('');

    cont.innerHTML = `<div class="compra-lista">${filas}</div>`;
    cont.querySelectorAll('[data-caja]').forEach((b) => b.addEventListener('click', () => abrirDetalleCaja(Number(b.dataset.caja))));
}

async function abrirDetalleCaja(id) {
    const cont = $('cajas-cont');
    cont.innerHTML = '<p class="td-mute">Cargando…</p>';
    let r;
    try { r = await api.get('/api/admin/cajas/detalle?id=' + id); }
    catch { cont.innerHTML = '<p class="tabla-vacia">No se pudo cargar la caja.</p>'; return; }

    const c = r.caja, res = r.resumen, movs = r.movimientos || [];
    const esperado = Number(c.monto_apertura) + Number(res.efectivo) - Number(res.retiros) + Number(res.ingresos);
    const dif = c.diferencia == null ? null : Number(c.diferencia);

    const movRows = movs.length
        ? movs.map((m) => `<div class="compra-dfila">
              <span>${m.tipo === 'retiro' ? '➖ Retiro' : '➕ Ingreso'}</span>
              <span>${money.format(m.monto)}</span>
              <span class="td-mute">${esc(m.motivo || '')}</span>
           </div>`).join('')
        : '<p class="td-mute" style="padding:8px 0">Sin movimientos.</p>';

    cont.innerHTML = `
        <button class="volver-link" id="caja-volver">‹ Cajas</button>
        <div class="compra-detalle">
            <div class="compra-dhead">
                <div><h2>Caja #${c.id}</h2><p class="sub">${esc(c.vendedor)}</p></div>
                <span class="compra-est est-${c.estado === 'abierta' ? 'env' : 'rec'} grande">${c.estado === 'abierta' ? 'Abierta' : 'Cerrada'}</span>
            </div>
            <div class="caja-grid">
                <div><span>Apertura</span><strong>${money.format(c.monto_apertura)}</strong></div>
                <div><span>Ventas efectivo</span><strong>${money.format(res.efectivo)}</strong></div>
                <div><span>Ventas transferencia</span><strong>${money.format(res.transferencia)}</strong></div>
                <div><span>Retiros</span><strong>−${money.format(res.retiros)}</strong></div>
                <div><span>Ingresos</span><strong>${money.format(res.ingresos)}</strong></div>
                <div><span>Ventas</span><strong>${res.ventas}</strong></div>
                <div class="caja-esperado"><span>Efectivo esperado</span><strong>${money.format(esperado)}</strong></div>
                ${c.estado === 'cerrada' ? `
                    <div><span>Contado</span><strong>${money.format(c.monto_contado)}</strong></div>
                    <div class="${dif === 0 ? '' : 'caja-dif-cell mal'}"><span>Diferencia</span><strong>${dif === 0 ? '✔ OK' : money.format(dif)}</strong></div>` : ''}
            </div>
            ${c.observacion ? `<p class="compra-obs">${esc(c.observacion)}</p>` : ''}
            <div class="compra-dtabla">
                <div class="compra-dhrow"><span>Movimiento</span><span>Monto</span><span>Motivo</span></div>
                ${movRows}
            </div>
        </div>`;
    $('caja-volver').addEventListener('click', renderCajas);
}

// Botón "‹ Panel" del header (estático en admin.html).
(function () {
    const volver = document.querySelector('#vista-cajas [data-ir-inicio]');
    if (volver) volver.addEventListener('click', () => seleccionar('inicio'));
})();

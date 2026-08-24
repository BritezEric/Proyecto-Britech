// ============================================================
// Gestor de empleados: rendimiento en ventas + pago de sueldos + PDF.
// Reusa helpers globales de admin.js ($, api, esc, money, toast).
// ============================================================

async function renderEmpleados() {
    const cont = $('empleados-lista');
    cont.innerHTML = '<p class="td-mute">Cargando…</p>';
    const r = await api.get('/api/admin/empleados');
    if (!r.empleados.length) {
        cont.innerHTML = '<p class="tabla-vacia">Todavía no hay empleados. Se crean como usuarios del sistema.</p>';
        return;
    }
    cont.innerHTML = r.empleados.map(empCard).join('');
    cont.querySelectorAll('[data-emp]').forEach((el) =>
        el.addEventListener('click', () => abrirEmpleado(Number(el.dataset.emp))));
}

function empCard(e) {
    const inicial = (e.nombre || '?').trim().charAt(0).toUpperCase();
    const rolBadge = `<span class="emp-rol rol-${esc(e.rol)}">${esc(e.rol)}</span>`;
    const inactivo = Number(e.activo) ? '' : '<span class="emp-rol rol-off">inactivo</span>';
    const pago = Number(e.pagado_mes) > 0
        ? `<span class="emp-pago ok">✔ sueldo pago</span>`
        : '';
    return `<button class="emp-card" data-emp="${e.id}">
        <div class="emp-top">
            <span class="emp-avatar">${esc(inicial)}</span>
            <div class="emp-id">
                <div class="emp-nombre">${esc(e.nombre)}</div>
                <div class="emp-badges">${rolBadge}${inactivo}</div>
            </div>
        </div>
        <div class="emp-metricas">
            <div><span class="emp-num">${Number(e.ventas_mes)}</span><span class="emp-lbl">ventas (mes)</span></div>
            <div><span class="emp-num">${money.format(e.monto_mes)}</span><span class="emp-lbl">facturado</span></div>
        </div>
        ${pago}
    </button>`;
}

async function abrirEmpleado(id, periodo) {
    const modal = $('modal-empleado');
    $('emp-title').textContent = 'Cargando…';
    $('emp-detalle').innerHTML = '<p class="td-mute">Cargando…</p>';
    modal.classList.remove('oculto');
    const qs = periodo ? `&periodo=${encodeURIComponent(periodo)}` : '';
    const r = await api.get(`/api/admin/empleados/detalle?id=${id}${qs}`);
    const e = r.empleado, rd = r.rendimiento, per = r.periodo, pago = r.pago_periodo;

    $('emp-title').textContent = e.nombre;

    // Mini-gráfico: facturado por mes (últimos meses).
    const max = Math.max(1, ...r.serie.map((s) => Number(s.monto)));
    const barras = r.serie.length
        ? r.serie.map((s) => {
            const alto = Math.round((Number(s.monto) / max) * 100);
            const etq = s.mes.slice(5) + '/' + s.mes.slice(2, 4);
            return `<div class="emp-bar" title="${etq}: ${money.format(s.monto)}">
                <div class="emp-bar-fill" style="height:${alto}%"></div><span>${etq}</span></div>`;
        }).join('')
        : '<p class="td-mute">Sin ventas en el período.</p>';

    const pagoBloque = pago
        ? `<p class="emp-pago ok" style="margin:0">✔ Sueldo de ${esc(per)} pagado el ${esc(pago.fecha)} — ${money.format(pago.monto)}</p>`
        : '';

    const listaPagos = r.pagos.length
        ? r.pagos.map((p) => `<div class="pedido-linea">
              <span>${esc(p.periodo || p.fecha)}${p.observacion ? ' · ' + esc(p.observacion) : ''}</span>
              <span class="tabular">${money.format(p.monto)}</span></div>`).join('')
        : '<p class="td-mute">Sin pagos registrados.</p>';

    $('emp-detalle').innerHTML = `
        <div class="emp-detalle-top">
            <span class="emp-rol rol-${esc(e.rol)}">${esc(e.rol)}</span>
            <span class="td-mute">${esc(e.email)}</span>
            <label class="emp-mes">Mes
                <input type="month" id="emp-periodo" value="${esc(per)}">
            </label>
        </div>

        <div class="emp-kpis">
            <div class="emp-kpi"><span class="emp-num">${Number(rd.ventas)}</span><span class="emp-lbl">Ventas</span></div>
            <div class="emp-kpi"><span class="emp-num">${Number(rd.unidades)}</span><span class="emp-lbl">Unidades</span></div>
            <div class="emp-kpi"><span class="emp-num">${money.format(rd.monto)}</span><span class="emp-lbl">Facturado</span></div>
            <div class="emp-kpi"><span class="emp-num">${money.format(rd.ticket)}</span><span class="emp-lbl">Ticket prom.</span></div>
        </div>

        <h4 class="detalle-sub">Facturación por mes</h4>
        <div class="emp-chart">${barras}</div>

        <h4 class="detalle-sub">Sueldo</h4>
        ${pagoBloque}
        <p class="emp-hint">Los sueldos se cargan desde <strong>Gastos</strong>: creá un gasto, elegí a este empleado y el mes. El estado y el historial se reflejan acá.</p>

        <h4 class="detalle-sub">Historial de pagos</h4>
        ${listaPagos}

        <a class="envio-seg" href="/api/admin/empleados/pdf?id=${id}&periodo=${encodeURIComponent(per)}" target="_blank" rel="noopener">📄 Descargar PDF del desempeño</a>
    `;

    // Cambiar de mes → recargar el detalle con ese período.
    $('emp-periodo').addEventListener('change', (ev) => abrirEmpleado(id, ev.target.value));
}

document.addEventListener('DOMContentLoaded', () => {
    const cerrar = $('emp-cerrar');
    if (cerrar) cerrar.addEventListener('click', () => $('modal-empleado').classList.add('oculto'));
    const modal = $('modal-empleado');
    if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.add('oculto'); });
});

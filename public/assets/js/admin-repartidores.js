// ============================================================
// Repartos + apartado de cada repartidor.
//  - renderRepartos(): tablero para DERIVAR envíos sin asignar y abrir el
//    detalle de cada repartidor.
//  - abrirRepartidor(): envíos ACTIVOS (a repartir) con producto comprado,
//    marcar salió/entregado, ticket imprimible, y la paga del día por barrio.
// Reusa helpers globales de admin.js ($, api, esc, money, toast).
// ============================================================

let repDetalle = null;   // último detalle cargado (para imprimir)

const REP_ESTADO = {
    pendiente: 'pendiente', despachado: 'despachado',
    en_camino: 'salió 🛵', entregado: 'entregado ✓', cancelado: 'cancelado',
};

// Línea de un envío (datos comunes: cliente, dirección, productos).
function envioDatos(e) {
    return `<div class="rep-envio-datos">
        <div>👤 Recibe: ${esc(quienRecibe(e))}${e.telefono ? ' · ' + esc(e.telefono) : ''} ${e.origen === 'venta' ? '<span class="chip">POS</span>' : ''}</div>
        <div>📍 ${esc(e.barrio)} — ${esc(e.direccion || '')} ${esc(e.altura || '')}${e.referencia ? ' (' + esc(e.referencia) + ')' : ''}</div>
        <div class="td-mute">📦 ${esc(e.productos || '—')}</div>
    </div>`;
}

// ---------- Tablero de repartos ----------
async function renderRepartos() {
    $('repartos-sin-asignar').innerHTML = '<p class="td-mute">Cargando…</p>';
    $('repartos-repartidores').innerHTML = '';
    const [sa, reps] = await Promise.all([
        api.get('/api/admin/envios/sin-asignar'),
        api.get('/api/admin/repartidores?per_page=100'),
    ]);
    const activos = reps.data.filter((r) => Number(r.activo));
    const opts = activos.map((r) => `<option value="${r.id}">${esc(r.nombre)}</option>`).join('');

    document.querySelectorAll('#vista-repartos .detalle-sub').forEach((h) => {
        if (/sin asignar/i.test(h.textContent)) h.dataset.n = sa.envios.length;
    });

    $('repartos-sin-asignar').innerHTML = sa.envios.length
        ? sa.envios.map((e) => `
            <div class="rep-envio">
                <div class="rep-envio-top">
                    <strong>${esc(e.numero)}</strong>
                    <span class="badge neutral">${esc(REP_ESTADO[e.estado] || e.estado)}</span>
                    <span class="tabular">${money.format(e.envio_costo)}</span>
                </div>
                ${envioDatos(e)}
                <div class="rep-envio-acc">
                    <select data-derivar="${e.envio_id}">
                        <option value="">Derivar a…</option>${opts}
                    </select>
                </div>
            </div>`).join('')
        : '<p class="td-mute">No hay envíos pendientes de asignar. 👍</p>';

    $('repartos-repartidores').innerHTML = activos.length
        ? activos.map((r) => `
            <button class="emp-card" data-rep="${r.id}">
                <div class="emp-top">
                    <span class="emp-avatar">${esc((r.nombre || '?')[0].toUpperCase())}</span>
                    <div class="emp-id">
                        <div class="emp-nombre">${esc(r.nombre)}</div>
                        <div class="emp-badges">${Number(r.activos) > 0 ? `<span class="chip chip-alerta">${Number(r.activos)} a repartir</span>` : '<span class="chip">al día</span>'}</div>
                    </div>
                </div>
                <div class="emp-metricas">
                    <div><span class="emp-num">${Number(r.envios_hoy)}</span><span class="emp-lbl">entregados hoy</span></div>
                    <div><span class="emp-num">${money.format(r.pago_hoy)}</span><span class="emp-lbl">a pagar hoy</span></div>
                </div>
            </button>`).join('')
        : '<p class="td-mute">No hay repartidores activos. Creá uno en “Repartidores”.</p>';

    $('repartos-sin-asignar').querySelectorAll('[data-derivar]').forEach((sel) => sel.addEventListener('change', async () => {
        if (!sel.value) return;
        try {
            await api.post('/api/admin/envios/derivar', { envio_id: Number(sel.dataset.derivar), repartidor_id: Number(sel.value) });
            toast('✓ Envío derivado');
            renderRepartos();
        } catch (e) { toast('⚠ ' + e.message); }
    }));
    $('repartos-repartidores').querySelectorAll('[data-rep]').forEach((b) =>
        b.addEventListener('click', () => abrirRepartidor(Number(b.dataset.rep))));
}

// ---------- Detalle de un repartidor ----------
async function abrirRepartidor(id, fecha) {
    const modal = $('modal-repartidor');
    $('rep-title').textContent = 'Cargando…';
    $('rep-detalle').innerHTML = '<p class="td-mute">Cargando…</p>';
    modal.classList.remove('oculto');

    const qs = fecha ? `&fecha=${encodeURIComponent(fecha)}` : '';
    const r = await api.get(`/api/admin/repartidores/detalle?id=${id}${qs}`);
    repDetalle = r;
    const rep = r.repartidor;
    $('rep-title').textContent = rep.nombre;

    // Envíos ACTIVOS (a repartir): producto, dirección, estado + acciones.
    const activos = r.activos.length
        ? r.activos.map((e) => `
            <div class="rep-envio">
                <div class="rep-envio-top">
                    <strong>${esc(e.numero)}</strong>
                    <span class="badge neutral">${esc(REP_ESTADO[e.estado] || e.estado)}</span>
                    <span class="tabular">${money.format(e.envio_costo)}</span>
                </div>
                ${envioDatos(e)}
                <div class="rep-envio-acc">
                    <button class="btn-mini" data-envio="${e.envio_id}" data-estado="en_camino" ${e.estado === 'en_camino' ? 'disabled' : ''}>🛵 Marcar salida</button>
                    <button class="btn-mini" data-envio="${e.envio_id}" data-estado="entregado">✓ Entregado</button>
                </div>
            </div>`).join('')
        : '<p class="td-mute">Sin envíos activos para repartir.</p>';

    // Paga del día por barrio (solo entregados).
    const filas = r.por_barrio.length
        ? r.por_barrio.map((b) => `<div class="pedido-linea">
              <span>${esc(b.barrio)} · ${Number(b.cantidad)} × ${money.format(b.costo)}</span>
              <span class="tabular">${money.format(b.subtotal)}</span></div>`).join('')
        : '<p class="td-mute">Todavía nada entregado en esta fecha.</p>';

    // Mini-gráfico de paga por día.
    const max = Math.max(1, ...r.serie.map((s) => Number(s.total)));
    const barras = r.serie.length
        ? r.serie.map((s) => {
            const alto = Math.round((Number(s.total) / max) * 100);
            const etq = s.dia.slice(8) + '/' + s.dia.slice(5, 7);
            return `<div class="emp-bar" title="${etq}: ${money.format(s.total)} (${Number(s.envios)} env.)">
                <div class="emp-bar-fill" style="height:${alto}%"></div><span>${etq}</span></div>`;
        }).join('')
        : '<p class="td-mute">Sin entregas en los últimos días.</p>';

    $('rep-detalle').innerHTML = `
        <div class="emp-detalle-top">
            ${rep.telefono ? `<span class="td-mute">📞 ${esc(rep.telefono)}</span>` : ''}
            <a class="btn-wa" href="${esc(linkWhatsapp(r))}" target="_blank" rel="noopener">📲 Enviar por WhatsApp</a>
            <button class="btn-primary" id="rep-ticket">🎫 Ticket de reparto</button>
        </div>

        <div class="emp-kpis">
            <div class="emp-kpi"><span class="emp-num">${r.activos.length}</span><span class="emp-lbl">A repartir</span></div>
            <div class="emp-kpi"><span class="emp-num">${Number(r.envios)}</span><span class="emp-lbl">Entregados (día)</span></div>
            <div class="emp-kpi"><span class="emp-num">${money.format(r.total)}</span><span class="emp-lbl">A pagar (día)</span></div>
        </div>

        <h4 class="detalle-sub">Envíos a repartir</h4>
        <div class="rep-envios">${activos}</div>

        <h4 class="detalle-sub">Paga del día
            <input type="date" id="rep-fecha" value="${esc(r.fecha)}" class="rep-fecha-inp">
        </h4>
        ${filas}
        <div class="pedido-linea total"><strong>Total a pagar</strong><strong class="tabular">${money.format(r.total)}</strong></div>
        <div class="emp-chart">${barras}</div>
    `;

    $('rep-fecha').addEventListener('change', (ev) => abrirRepartidor(id, ev.target.value));
    $('rep-ticket').addEventListener('click', () => imprimirTicket(r));

    $('rep-detalle').querySelectorAll('[data-envio]').forEach((b) => b.addEventListener('click', async () => {
        b.disabled = true;
        try {
            await api.post('/api/admin/envios/estado', { envio_id: Number(b.dataset.envio), estado: b.dataset.estado });
            toast(b.dataset.estado === 'entregado' ? '✓ Entregado' : '🛵 Marcado como salido');
            abrirRepartidor(id, $('rep-fecha').value);
        } catch (ex) { toast('⚠ ' + ex.message); b.disabled = false; }
    }));
}

// Normaliza un teléfono argentino a formato WhatsApp (54 9 + área + número, solo dígitos).
function telWhatsapp(tel) {
    let d = String(tel || '').replace(/\D/g, '');
    if (!d) return '';
    d = d.replace(/^0/, '');           // saca 0 inicial (interurbano)
    d = d.replace(/^15/, '');          // saca 15 inicial (celular viejo)
    if (d.startsWith('54')) return d.startsWith('549') ? d : '549' + d.slice(2);
    if (d.startsWith('9'))  return '54' + d;
    return '549' + d;                  // celular argentino
}

// Quién recibe la entrega (lo que le importa al repartidor): destinatario del envío;
// si no hay, el nombre del cliente.
function quienRecibe(e) { return (e.destinatario && e.destinatario.trim()) || e.cliente || 'A confirmar'; }

// Arma el link wa.me con el mensaje pre-cargado de los envíos a repartir.
// Conciso y directo: dónde, quién recibe (+ tel) y qué lleva. Sin API: abre
// WhatsApp con el texto listo y el admin solo toca "enviar".
function mensajeWhatsapp(r) {
    const hoy = new Date().toLocaleDateString('es-AR');
    const lineas = [`*Reparto — ${r.repartidor.nombre}* · ${hoy}`, ''];
    if (!r.activos.length) {
        lineas.push('No hay envíos pendientes por ahora.');
        return lineas.join('\n');
    }
    r.activos.forEach((e, i) => {
        const dir = `${e.direccion || ''} ${e.altura || ''}`.trim();
        lineas.push(`*${i + 1}) ${e.barrio}*`);
        lineas.push(`Dirección: ${dir}${e.referencia ? ' — ' + e.referencia : ''}`);
        lineas.push(`A quién entregar: ${quienRecibe(e)}${e.telefono ? ' · ' + e.telefono : ''}`);
        if (e.productos) lineas.push(`Producto: ${e.productos}`);
        lineas.push('');
    });
    lineas.push(`*Total: ${r.activos.length} entrega(s)*`);
    return lineas.join('\n');
}

function linkWhatsapp(r) {
    const tel = telWhatsapp(r.repartidor.telefono);
    const texto = encodeURIComponent(mensajeWhatsapp(r));
    return tel ? `https://wa.me/${tel}?text=${texto}` : `https://wa.me/?text=${texto}`;
}

// Ticket imprimible: conciso — dónde, quién recibe (+ tel) y qué lleva.
function imprimirTicket(r) {
    const filas = r.activos.map((e, i) => `
        <tr>
            <td class="n">${i + 1}</td>
            <td><strong>${esc(e.barrio)}</strong><br><small>${esc(e.direccion || '')} ${esc(e.altura || '')}${e.referencia ? ' — ' + esc(e.referencia) : ''}</small></td>
            <td>${esc(quienRecibe(e))}${e.telefono ? '<br><small>' + esc(e.telefono) + '</small>' : ''}</td>
            <td>${esc(e.productos || '—')}</td>
        </tr>`).join('');
    const html = `<!doctype html><html><head><meta charset="utf-8"><title>Reparto ${esc(r.repartidor.nombre)}</title>
        <style>
            body { font-family: system-ui, sans-serif; margin: 24px; color: #111; }
            h1 { font-size: 18px; margin: 0 0 4px; } h2 { font-size: 13px; font-weight: normal; color: #555; margin: 0 0 16px; }
            table { width: 100%; border-collapse: collapse; font-size: 13px; }
            th, td { border: 1px solid #ccc; padding: 7px 9px; text-align: left; vertical-align: top; }
            th { background: #f2f2f2; } small { color: #666; } .n { text-align: center; width: 28px; font-weight: bold; }
        </style></head><body>
        <h1>Reparto — ${esc(r.repartidor.nombre)}</h1>
        <h2>${new Date().toLocaleString('es-AR')} · ${r.activos.length} entrega(s)</h2>
        <table>
            <thead><tr><th class="n">#</th><th>Barrio / Dirección</th><th>Recibe</th><th>Productos</th></tr></thead>
            <tbody>${filas || '<tr><td colspan="4">Sin envíos para repartir.</td></tr>'}</tbody>
        </table>
        </body></html>`;
    const w = window.open('', '_blank');
    if (!w) { toast('Permití las ventanas emergentes para imprimir.'); return; }
    w.document.write(html); w.document.close(); w.focus(); w.print();
}

document.addEventListener('DOMContentLoaded', () => {
    const cerrar = $('rep-cerrar');
    if (cerrar) cerrar.addEventListener('click', () => $('modal-repartidor').classList.add('oculto'));
    const modal = $('modal-repartidor');
    if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.add('oculto'); });
    // Botón "‹ Panel" del header de Repartos → vuelve al dashboard.
    const volver = document.querySelector('#vista-repartos [data-ir-inicio]');
    if (volver) volver.addEventListener('click', () => seleccionar('inicio'));
});

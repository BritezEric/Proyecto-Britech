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
let repId = null;   // repartidor abierto (para recargar por fecha)

async function abrirRepartidor(id, fecha) {
    repId = id;
    $('rep-title').textContent = 'Repartidor';
    $('rep-detalle').innerHTML = '<div class="rep-cargando"><span class="rep-spin"></span></div>';
    $('modal-repartidor').classList.remove('oculto');
    await cargarRepartidor(id, fecha);
}

// Trae y pinta el detalle. Sin placeholder "Cargando…" (evita el parpadeo al
// cambiar de fecha); el innerHTML se reemplaza de una.
async function cargarRepartidor(id, fecha) {
    const qs = fecha ? `&fecha=${encodeURIComponent(fecha)}` : '';
    let r;
    try { r = await api.get(`/api/admin/repartidores/detalle?id=${id}${qs}`); }
    catch { $('rep-detalle').innerHTML = '<p class="td-mute">No se pudo cargar el detalle.</p>'; return; }
    repDetalle = r;
    pintarRepartidor(r);
}

function tarjetaEnvio(e) {
    return `<div class="rep-envio" data-envio="${e.envio_id}">
        <div class="rep-envio-top">
            <span class="rep-badge">${esc(REP_ESTADO[e.estado] || e.estado)}</span>
            <span class="rep-envio-barrio">${esc(e.barrio)}</span>
            ${e.origen === 'venta' ? '<span class="chip">POS</span>' : ''}
        </div>
        <div class="rep-envio-datos">
            <div><b>Dirección:</b> ${esc(e.direccion || '')} ${esc(e.altura || '')}${e.referencia ? ' — ' + esc(e.referencia) : ''}</div>
            <div><b>Entregar a:</b> ${esc(quienRecibe(e))}${e.telefono ? ' · ' + esc(e.telefono) : ''}</div>
            <div class="td-mute">${esc(e.productos || '—')}</div>
        </div>
        <div class="rep-envio-acc">
            <button class="btn-mini ghost" data-accion="salida" ${e.estado === 'en_camino' ? 'disabled' : ''}>Salió 🛵</button>
            <button class="btn-mini" data-accion="entregado">Entregado ✓</button>
        </div>
    </div>`;
}

function pintarRepartidor(r) {
    $('rep-title').textContent = r.repartidor.nombre;
    const activos = r.activos.length
        ? r.activos.map(tarjetaEnvio).join('')
        : '<p class="rep-vacio">✓ Nada pendiente por repartir.</p>';

    $('rep-detalle').innerHTML = `
        <div class="rep-head">
            ${r.repartidor.telefono ? `<span class="rep-tel">📞 ${esc(r.repartidor.telefono)}</span>` : '<span></span>'}
            <div class="rep-head-acc">
                <a class="btn-wa" href="${esc(linkWhatsapp(r))}" target="_blank" rel="noopener">WhatsApp</a>
                <button class="btn-secundario btn-sm" id="rep-ticket">🎫 Ticket</button>
            </div>
        </div>

        <div class="rep-kpis">
            <div class="rep-kpi"><span class="rep-num" id="k-repartir">${r.activos.length}</span><span class="rep-lbl">A repartir</span></div>
            <div class="rep-kpi"><span class="rep-num" id="k-entreg">${Number(r.envios)}</span><span class="rep-lbl">Entregados</span></div>
            <div class="rep-kpi"><span class="rep-num" id="k-pagar">${money.format(r.total)}</span><span class="rep-lbl">A pagar</span></div>
        </div>

        <div class="rep-seccion-h">Envíos a repartir</div>
        <div class="rep-envios" id="rep-lista">${activos}</div>

        <details class="rep-paga">
            <summary>Paga del día <input type="date" id="rep-fecha" value="${esc(r.fecha)}"></summary>
            <div id="rep-paga-body">${pagaBody(r)}</div>
        </details>
    `;

    $('rep-ticket').addEventListener('click', () => imprimirTicket(repDetalle));
    const fInp = $('rep-fecha');
    fInp.addEventListener('change', (ev) => { ev.stopPropagation(); cargarRepartidor(repId, ev.target.value); });
    fInp.addEventListener('click', (ev) => ev.stopPropagation());   // no togglea el <details>

    $('rep-lista').addEventListener('click', (ev) => {
        const btn = ev.target.closest('[data-accion]');
        if (!btn) return;
        const card = btn.closest('.rep-envio');
        const env = repDetalle.activos.find((a) => String(a.envio_id) === String(card.dataset.envio));
        if (!env) return;
        (btn.dataset.accion === 'entregado') ? marcarEntregado(env, card, btn) : marcarSalida(env, card, btn);
    });
}

function pagaBody(r) {
    const filas = r.por_barrio.length
        ? r.por_barrio.map((b) => `<div class="pedido-linea">
              <span>${esc(b.barrio)} · ${Number(b.cantidad)} × ${money.format(b.costo)}</span>
              <span class="tabular">${money.format(b.subtotal)}</span></div>`).join('')
        : '<p class="td-mute">Todavía nada entregado en esta fecha.</p>';
    return filas + `<div class="pedido-linea total"><strong>Total</strong><strong class="tabular">${money.format(r.total)}</strong></div>`;
}

// Actualiza KPIs + paga en el sitio (sin re-render de todo el modal).
function refrescarResumenRep() {
    const r = repDetalle;
    $('k-repartir').textContent = r.activos.length;
    $('k-entreg').textContent = Number(r.envios);
    $('k-pagar').textContent = money.format(r.total);
    $('rep-paga-body').innerHTML = pagaBody(r);
    if (!r.activos.length && !$('rep-lista').querySelector('.rep-envio'))
        $('rep-lista').innerHTML = '<p class="rep-vacio">✓ Nada pendiente por repartir.</p>';
}

// Marcar ENTREGADO: valida en el server; si OK, la tarjeta se va y se actualizan los números.
async function marcarEntregado(env, card, btn) {
    btn.disabled = true;
    try {
        await api.post('/api/admin/envios/estado', { envio_id: env.envio_id, estado: 'entregado' });
    } catch (ex) { toast('⚠ ' + ex.message); btn.disabled = false; return; }

    card.classList.add('rep-envio-out');
    setTimeout(() => card.remove(), 240);

    repDetalle.activos = repDetalle.activos.filter((a) => a.envio_id !== env.envio_id);
    repDetalle.envios = Number(repDetalle.envios) + 1;
    const c = Number(env.envio_costo) || 0;
    repDetalle.total = Number(repDetalle.total) + c;
    const b = repDetalle.por_barrio.find((x) => x.barrio === env.barrio);
    if (b) { b.cantidad = Number(b.cantidad) + 1; b.subtotal = Number(b.subtotal) + c; }
    else repDetalle.por_barrio.push({ barrio: env.barrio, costo: env.envio_costo, cantidad: 1, subtotal: c });
    refrescarResumenRep();
    toast('✓ Entregado');
}

// Marcar SALIDA (en camino): actualiza el estado en la tarjeta, sin sacarla.
async function marcarSalida(env, card, btn) {
    btn.disabled = true;
    try {
        await api.post('/api/admin/envios/estado', { envio_id: env.envio_id, estado: 'en_camino' });
    } catch (ex) { toast('⚠ ' + ex.message); btn.disabled = false; return; }
    env.estado = 'en_camino';
    card.querySelector('.rep-badge').textContent = REP_ESTADO['en_camino'];
    toast('🛵 En camino');
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

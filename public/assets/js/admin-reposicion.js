/* ============================================================
 * Reposición — pedido de compra a proveedores por stock bajo.
 * Lista los productos en o por debajo de su stock mínimo, agrupados por
 * proveedor. El admin ajusta cantidades, puede cambiar de proveedor, y
 * envía la solicitud por WhatsApp (no se persiste: se lee y se acepta).
 * Reusa telWhatsapp() de admin-repartidores.js y los globals de admin.js.
 * ========================================================== */

let repoItems = [];   // [{id, nombre, sku, stock, stock_minimo, costo, proveedor_id, ...}] + {qty, provSel}
let repoProv  = [];   // [{id, nombre, telefono}] proveedores activos

async function renderReposicion() {
    const cont = $('reposicion-cont');
    cont.innerHTML = '<p class="td-mute">Cargando…</p>';
    let r;
    try { r = await api.get('/api/admin/reposicion'); }
    catch { cont.innerHTML = '<p class="tabla-vacia">No se pudo cargar la reposición.</p>'; return; }

    repoProv = r.proveedores || [];
    repoItems = (r.items || []).map((it) => ({
        ...it,
        qty: Number(it.sugerido) || 1,
        provSel: it.proveedor_id ? String(it.proveedor_id) : '',   // proveedor elegido (editable)
    }));

    if (!repoItems.length) {
        cont.innerHTML = `<div class="repo-vacio">
            <span class="repo-vacio-ic">✔</span>
            <p>No hay productos por reponer. Todo el stock está por encima del mínimo.</p>
        </div>`;
        return;
    }
    pintarReposicion();
}

// Agrupa los ítems por el proveedor elegido (provSel) y pinta una tarjeta por grupo.
function pintarReposicion() {
    const cont = $('reposicion-cont');
    const grupos = new Map();   // provSel -> [items]
    for (const it of repoItems) {
        if (!grupos.has(it.provSel)) grupos.set(it.provSel, []);
        grupos.get(it.provSel).push(it);
    }

    // Grupos con proveedor primero, "sin proveedor" al final.
    const claves = [...grupos.keys()].sort((a, b) => (a === '' ? 1 : b === '' ? -1 : 0));
    cont.innerHTML = claves.map((clave) => tarjetaGrupo(clave, grupos.get(clave))).join('');

    // Cambio de cantidad: actualiza el estado y el total del grupo sin re-render.
    cont.querySelectorAll('input[data-qty]').forEach((inp) => inp.addEventListener('input', () => {
        const it = repoItems.find((x) => x.id === Number(inp.dataset.qty));
        it.qty = Math.max(0, parseInt(inp.value, 10) || 0);
        actualizarTotalGrupo(it.provSel);
    }));
    // Cambio de proveedor: re-agrupa.
    cont.querySelectorAll('select[data-prov]').forEach((sel) => sel.addEventListener('change', () => {
        const it = repoItems.find((x) => x.id === Number(sel.dataset.prov));
        it.provSel = sel.value;
        pintarReposicion();
    }));
    // Enviar pedido por WhatsApp.
    cont.querySelectorAll('button[data-enviar]').forEach((btn) => btn.addEventListener('click', () =>
        enviarPedidoProveedor(btn.dataset.enviar)));
}

function proveedorDe(clave) { return repoProv.find((p) => String(p.id) === String(clave)) || null; }
function totalGrupo(items) { return items.reduce((s, it) => s + it.qty * (Number(it.costo) || 0), 0); }

function tarjetaGrupo(clave, items) {
    const prov = proveedorDe(clave);
    const sinProv = !prov;
    const total = totalGrupo(items);
    const filas = items.map((it) => filaProducto(it)).join('');
    const cabecera = sinProv
        ? `<div class="repo-prov sin"><span class="repo-prov-nom">⚠ Sin proveedor asignado</span>
             <span class="repo-prov-sub">Elegí un proveedor en cada producto para poder enviar el pedido.</span></div>`
        : `<div class="repo-prov"><span class="repo-prov-nom">🏭 ${esc(prov.nombre)}</span>
             <span class="repo-prov-sub">${prov.telefono ? '📞 ' + esc(prov.telefono) : 'Sin teléfono cargado'}</span></div>`;

    const btnEnviar = (!sinProv && prov.telefono)
        ? `<button class="btn-primary repo-enviar" data-enviar="${clave}">Enviar pedido por WhatsApp</button>`
        : `<button class="btn-primary repo-enviar" disabled title="${sinProv ? 'Asigná un proveedor' : 'El proveedor no tiene teléfono'}">Enviar pedido por WhatsApp</button>`;

    return `<div class="repo-grupo">
        ${cabecera}
        <div class="repo-tabla">
            <div class="repo-head">
                <span>Producto</span><span>Stock</span><span>Costo</span><span>Pedir</span><span>Proveedor</span><span class="ta-r">Subtotal</span>
            </div>
            ${filas}
        </div>
        <div class="repo-foot">
            <span class="repo-total-lbl">Total estimado del pedido</span>
            <span class="repo-total" data-total="${clave}">${money.format(total)}</span>
            ${btnEnviar}
        </div>
    </div>`;
}

function filaProducto(it) {
    const opts = ['<option value="">(sin proveedor)</option>']
        .concat(repoProv.map((p) => `<option value="${p.id}" ${String(p.id) === it.provSel ? 'selected' : ''}>${esc(p.nombre)}</option>`))
        .join('');
    const sub = it.qty * (Number(it.costo) || 0);
    return `<div class="repo-fila">
        <span class="repo-nom">${esc(it.nombre)}${it.sku ? `<small>${esc(it.sku)}</small>` : ''}</span>
        <span class="repo-stock"><b class="${it.stock <= 0 ? 'sin' : 'bajo'}">${it.stock}</b> / ${it.stock_minimo}</span>
        <span>${it.costo === null ? '—' : money.format(it.costo)}</span>
        <span><input type="number" min="0" data-qty="${it.id}" value="${it.qty}" class="repo-qty"></span>
        <span><select data-prov="${it.id}" class="repo-select">${opts}</select></span>
        <span class="ta-r repo-sub" data-sub="${it.id}">${money.format(sub)}</span>
    </div>`;
}

// Recalcula subtotales de línea y el total del grupo tras cambiar una cantidad.
function actualizarTotalGrupo(clave) {
    const items = repoItems.filter((it) => it.provSel === clave);
    items.forEach((it) => {
        const cel = document.querySelector(`[data-sub="${it.id}"]`);
        if (cel) cel.textContent = money.format(it.qty * (Number(it.costo) || 0));
    });
    const tot = document.querySelector(`[data-total="${clave}"]`);
    if (tot) tot.textContent = money.format(totalGrupo(items));
}

// Mensaje de pedido para el proveedor: conciso, con etiquetas de texto (no emojis).
function mensajePedido(prov, items) {
    const hoy = new Date().toLocaleDateString('es-AR');
    const lineas = [`*Pedido de reposición* · ${hoy}`, `Proveedor: ${prov.nombre}`, ''];
    items.filter((it) => it.qty > 0).forEach((it, i) => {
        lineas.push(`${i + 1}) ${it.nombre}${it.sku ? ' (' + it.sku + ')' : ''} — Cantidad: ${it.qty}`);
    });
    lineas.push('', `Total: ${items.filter((it) => it.qty > 0).length} producto(s)`);
    return lineas.join('\n');
}

function enviarPedidoProveedor(clave) {
    const prov = proveedorDe(clave);
    if (!prov) return;
    const items = repoItems.filter((it) => it.provSel === clave && it.qty > 0);
    if (!items.length) { toast('⚠ Poné una cantidad mayor a 0'); return; }
    const tel = telWhatsapp(prov.telefono);
    const texto = encodeURIComponent(mensajePedido(prov, items));
    window.open(tel ? `https://wa.me/${tel}?text=${texto}` : `https://wa.me/?text=${texto}`, '_blank');
}

// ===== Auditoría: quién hizo qué, cuándo y qué cambió (solo admin, solo lectura) =====
// Depende de los globales de admin.js: $, api, esc, toast.
// La pantalla solo LEE: no hay botones ni rutas para editar o borrar registros.

const AU = { page: 1, iniciado: false, modulos: {}, acciones: {}, tipoDe: {} };

// Tarjetas del resumen: clave del backend → etiqueta y tipo (color).
const AU_TARJETAS = [
    ['total', 'Registros', ''], ['alta', 'Altas', 'alta'], ['cambio', 'Modificaciones', 'cambio'],
    ['baja', 'Bajas y anulaciones', 'baja'], ['stock', 'Movimientos de stock', 'stock'],
    ['login_fallido', 'Logins fallidos', 'baja'], ['usuarios', 'Usuarios', ''],
];

function auFiltros() {
    const f = {
        q: $('au-q').value.trim(), usuario_id: $('au-usuario').value, accion: $('au-accion').value,
        entidad: $('au-modulo').value, desde: $('au-desde').value, hasta: $('au-hasta').value,
    };
    return Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''));
}

function renderAuditoria() {
    if (!AU.iniciado) {
        AU.iniciado = true;
        let deb;
        $('au-q').addEventListener('input', () => { clearTimeout(deb); deb = setTimeout(() => { AU.page = 1; cargarAuditoria(); }, 300); });
        ['au-usuario', 'au-accion', 'au-modulo', 'au-desde', 'au-hasta'].forEach((id) =>
            $(id).addEventListener('change', () => { AU.page = 1; cargarAuditoria(); }));
        $('au-limpiar').addEventListener('click', () => {
            ['au-q', 'au-usuario', 'au-accion', 'au-modulo', 'au-desde', 'au-hasta'].forEach((id) => { $(id).value = ''; });
            AU.page = 1; cargarAuditoria();
        });
        $('au-pdf').addEventListener('click', () => exportarAuditoria('pdf'));
        $('au-excel').addEventListener('click', () => exportarAuditoria('excel'));
    }
    AU.page = 1;
    cargarAuditoria();
}

async function cargarAuditoria() {
    const err = $('au-error');
    err.classList.add('oculto');
    const params = new URLSearchParams({ ...auFiltros(), page: AU.page, per_page: 20 });
    let r;
    try { r = await api.get('/api/admin/auditoria?' + params); }
    catch (e) { err.textContent = e.message; err.classList.remove('oculto'); return; }

    AU.modulos = r.modulos || {};
    AU.acciones = r.acciones || {};
    AU.tipoDe = {};
    Object.entries(r.tipos || {}).forEach(([tipo, lista]) => lista.forEach((a) => { AU.tipoDe[a] = tipo; }));
    auOpciones(r.opciones || {});
    auResumen(r.resumen || {});
    auTabla(r.data || []);
    auPaginacion(r);
}

// Llena los selects con lo que existe en el historial (sin perder lo elegido).
function auOpciones(op) {
    const llenar = (id, primera, items) => {
        const sel = $(id), actual = sel.value;
        sel.innerHTML = `<option value="">${primera}</option>` + items.map(([v, t]) => `<option value="${esc(v)}">${esc(t)}</option>`).join('');
        sel.value = items.some(([v]) => String(v) === actual) ? actual : '';
    };
    llenar('au-usuario', 'Todos los usuarios', (op.usuarios || []).map((u) => [u.id, u.nombre]));
    llenar('au-accion', 'Todas las acciones', (op.acciones || []).map((a) => [a, AU.acciones[a] || a]));
    llenar('au-modulo', 'Todos los módulos', (op.entidades || []).map((m) => [m, AU.modulos[m] || m]));
}

function auResumen(res) {
    $('au-resumen').innerHTML = AU_TARJETAS.map(([k, label, tipo]) => `
        <div class="card kpi au-kpi ${tipo ? 'au-' + tipo : ''}">
            <div class="card-label">${label}</div>
            <div class="card-num">${Number(res[k] || 0).toLocaleString('es-AR')}</div>
        </div>`).join('');
}

function auFecha(s) {
    const [d, h] = String(s).split(' ');
    const [y, m, dd] = (d || '').split('-');
    return `<span class="au-dia">${dd}/${m}/${y}</span> <span class="td-mute">${(h || '').slice(0, 5)}</span>`;
}

function auTabla(filas) {
    $('au-vacio').classList.toggle('oculto', filas.length > 0);
    $('au-tbody').innerHTML = filas.map((a) => {
        const tipo = AU.tipoDe[a.accion] || 'otro';
        const fallido = a.accion === 'login_fallido' ? ' au-tag-alerta' : '';
        const registro = a.entidad_id === null ? '<span class="td-mute">—</span>'
            : `#${esc(a.entidad_id)}${a.referencia ? ` <span class="td-mute">· ${esc(a.referencia)}</span>` : ''}`;
        const desc = [a.campo ? `<strong>${esc(a.campo)}</strong>` : '', a.detalle ? esc(a.detalle) : ''].filter(Boolean).join(' · ')
            || '<span class="td-mute">—</span>';
        const cambio = (a.valor_anterior === null && a.valor_nuevo === null) ? '<span class="td-mute">—</span>'
            : `<span class="au-antes">${a.valor_anterior === null ? '—' : esc(a.valor_anterior)}</span>
               <span class="au-flecha">→</span>
               <span class="au-despues">${a.valor_nuevo === null ? '—' : esc(a.valor_nuevo)}</span>`;
        return `<tr>
            <td class="au-nowrap">${auFecha(a.creado_en)}</td>
            <td>${a.usuario ? esc(a.usuario) : '<span class="td-mute">Sistema</span>'}</td>
            <td><span class="au-tag au-${tipo}${fallido}">${esc(AU.acciones[a.accion] || a.accion)}</span></td>
            <td>${esc(AU.modulos[a.entidad] || a.entidad)}</td>
            <td class="au-nowrap">${registro}</td>
            <td class="au-desc">${desc}</td>
            <td class="au-cambio">${cambio}</td>
        </tr>`;
    }).join('');
}

function auPaginacion(r) {
    const cont = $('au-paginacion');
    const desde = r.total === 0 ? 0 : (r.page - 1) * r.per_page + 1;
    const hasta = Math.min(r.page * r.per_page, r.total);
    cont.innerHTML = `<span class="pag-info">${desde}–${hasta} de ${Number(r.total).toLocaleString('es-AR')}</span>
        <div class="pag-botones">
            <button class="pag-btn" data-p="${r.page - 1}" ${r.page <= 1 ? 'disabled' : ''}>‹</button>
            <span class="pag-info">Página ${r.page} de ${r.total_pages}</span>
            <button class="pag-btn" data-p="${r.page + 1}" ${r.page >= r.total_pages ? 'disabled' : ''}>›</button>
        </div>`;
    cont.querySelectorAll('.pag-btn').forEach((b) => b.addEventListener('click', () => {
        AU.page = Number(b.dataset.p); cargarAuditoria();
        document.querySelector('.content').scrollTo({ top: 0, behavior: 'smooth' });
    }));
}

// Descarga el PDF/Excel con los filtros actuales. Si el servidor responde un error, se muestra.
async function exportarAuditoria(formato) {
    const btn = $(formato === 'pdf' ? 'au-pdf' : 'au-excel');
    const txt = btn.textContent;
    btn.disabled = true; btn.textContent = 'Generando…';
    try {
        const resp = await fetch(`/api/admin/auditoria/${formato}?` + new URLSearchParams(auFiltros()), { credentials: 'same-origin' });
        const tipo = resp.headers.get('Content-Type') || '';
        if (!resp.ok || tipo.includes('application/json')) {
            let msg = 'No se pudo exportar.';
            try { msg = (await resp.json()).error || msg; } catch {}
            throw new Error(msg);
        }
        const nombre = (resp.headers.get('Content-Disposition') || '').match(/filename="?([^"]+)"?/)?.[1]
            || `auditoria.${formato === 'pdf' ? 'pdf' : 'xlsx'}`;
        const url = URL.createObjectURL(await resp.blob());
        const a = document.createElement('a');
        a.href = url; a.download = nombre; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 2000);
        toast('✓ Descargado ' + nombre);
    } catch (e) {
        toast('⚠ ' + e.message);
    } finally {
        btn.disabled = false; btn.textContent = txt;
    }
}

/* ============================================================
 * Reportes — menú de pestañas + Reportes y Control Operativo (solo admin):
 * medios de pago, cajas y anulaciones, vendedores, tienda online, reclamos,
 * envíos sin asignar y carga por barrio.
 *
 * Todos los cálculos los hace el backend (/api/admin/reportes/operativo) y los
 * siete reportes llegan con la misma forma (kpis / grafico / tablas / notas),
 * así que una sola pantalla genérica los pinta. Acá solo hay filtros, búsqueda,
 * orden y paginación de las tablas ya calculadas, y accesos a las pantallas que
 * ya existen (detalle de pedido, reclamos, repartos, cajas): este módulo no
 * modifica datos.
 * Reusa los globals de admin.js y admin-reportes.js ($, api, money, esc, toast,
 * chartTema, montoCorto, cssVar, seleccionar, verPedido, abrirDetalleReclamo,
 * abrirDetalleCaja, renderReporteLocalidades, rlDescargar, rlPaleta, rlFecha, rlIso, rlDmy).
 * ========================================================== */

const RO_API = '/api/admin/reportes/operativo';
const RO = {
    actual: 'localidades', opciones: null, iniciado: false, datos: null, chart: null,
    filtros: {},   // filtros elegidos por reporte (se conservan al cambiar de pestaña)
    tablas: {},    // estado de cada tabla: { q, orden, dir, page, barrio }
};
const RO_POR_PAGINA = 15;
const roNum = new Intl.NumberFormat('es-AR');

/** Qué muestra cada reporte: subtítulo y filtros propios (además del período). */
const RO_DEF = {
    pagos: {
        sub: 'Cuánto dinero ingresó y por qué medio. Solo suma lo acreditado: pendientes, rechazados y anulados se listan aparte.',
        filtros: [
            { k: 'canal', t: 'Canal', tipo: 'select', ops: () => [{ v: 'local', t: 'Local (POS)' }, { v: 'online', t: 'Tienda online' }] },
            { k: 'medio', t: 'Medio de pago', tipo: 'select', ops: (o) => o.medios.map((m) => ({ v: m.k, t: m.t })) },
            { k: 'estado', t: 'Estado del pago', tipo: 'select', ops: () => [
                { v: 'acreditado', t: 'Acreditado' }, { v: 'pendiente', t: 'Pendiente' }, { v: 'en_revision', t: 'En revisión' },
                { v: 'rechazado', t: 'Rechazado' }, { v: 'anulado', t: 'Anulado' }] },
            { k: 'vendedor_id', t: 'Vendedor', tipo: 'select', ops: (o) => o.usuarios.map((u) => ({ v: u.id, t: u.nombre })) },
        ],
    },
    cajas: {
        sub: 'Arqueo de cada caja (esperado vs. declarado) y ventas anuladas. Diferencia = declarado − esperado.',
        filtros: [
            { k: 'usuario_id', t: 'Cajero', tipo: 'select', ops: (o) => o.usuarios.map((u) => ({ v: u.id, t: u.nombre })) },
            { k: 'caja_id', t: 'N.º de caja', tipo: 'number', min: 1 },
        ],
    },
    vendedores: {
        sub: 'Ventas del POS por vendedor: cantidad, total, ticket promedio y anulaciones. Tocá un vendedor para ver sus operaciones.',
        filtros: [
            { k: 'vendedor_id', t: 'Vendedor', tipo: 'select', ops: (o) => o.usuarios.map((u) => ({ v: u.id, t: u.nombre })) },
        ],
    },
    pedidos: {
        sub: 'Estado de los pedidos de la tienda: qué falta cobrar, preparar o entregar. Lo que requiere acción aparece primero.',
        filtros: [
            { k: 'etapa', t: 'Estado del pedido', tipo: 'select', ops: (o) => o.etapas.map((e) => ({ v: e.k, t: e.t })) },
            { k: 'estado_pago', t: 'Estado del pago', tipo: 'select', ops: (o) => o.estados_pago.map((e) => ({ v: e.k, t: e.t })) },
            { k: 'cliente', t: 'Cliente', tipo: 'text', ph: 'Nombre del cliente' },
            { k: 'horas_alerta', t: 'Alerta sin actualizar (horas)', tipo: 'number', min: 1, max: 720, def: '24' },
            { k: 'incluir_activos', t: 'Incluir pedidos activos de días anteriores', tipo: 'check', def: '1' },
        ],
    },
    reclamos: {
        sub: 'Reclamos abiertos y sin responder. Abrí uno para leer el historial y responderle al cliente.',
        filtros: [
            { k: 'estado', t: 'Estado', tipo: 'select', ops: (o) => [{ v: 'sin_responder', t: 'Sin responder' }, ...o.estados_reclamo.map((e) => ({ v: e.k, t: e.t }))] },
            { k: 'cliente', t: 'Cliente', tipo: 'text', ph: 'Nombre del cliente' },
            { k: 'motivo', t: 'Motivo contiene', tipo: 'text', ph: 'Ej: demora' },
            { k: 'horas_alerta', t: 'Alerta abiertos (horas)', tipo: 'number', min: 1, max: 720, def: '48' },
            { k: 'incluir_activos', t: 'Incluir reclamos activos de días anteriores', tipo: 'check', def: '1' },
        ],
    },
    envios: {
        sub: 'Envíos de Moto Express que esperan repartidor y cuánto tiempo llevan esperando.',
        filtros: [
            { k: 'barrio_id', t: 'Barrio', tipo: 'select', ops: (o) => o.barrios.map((b) => ({ v: b.id, t: b.nombre })) },
            { k: 'estado', t: 'Estado del envío', tipo: 'select', ops: (o) => o.estados_envio.map((e) => ({ v: e.k, t: e.t })) },
            { k: 'repartidor_id', t: 'Repartidor', tipo: 'select', ops: (o) => [{ v: 'sin', t: 'Sin asignar' }, ...o.repartidores.map((r) => ({ v: r.id, t: r.nombre }))] },
            { k: 'espera_min', t: 'Espera mínima (minutos)', tipo: 'number', min: 0 },
            { k: 'incluir_activos', t: 'Incluir sin asignar de días anteriores', tipo: 'check', def: '1' },
        ],
    },
    carga: {
        sub: 'Envíos de Moto Express por barrio para organizar repartidores y motos. Tocá un barrio para ver sus envíos.',
        filtros: [
            { k: 'barrio_id', t: 'Barrio', tipo: 'select', ops: (o) => o.barrios.map((b) => ({ v: b.id, t: b.nombre })) },
            { k: 'estado', t: 'Estado', tipo: 'select', ops: (o) => o.estados_carga.map((e) => ({ v: e.k, t: e.t })) },
            { k: 'repartidor_id', t: 'Repartidor', tipo: 'select', ops: (o) => [{ v: 'sin', t: 'Sin asignar' }, ...o.repartidores.map((r) => ({ v: r.id, t: r.nombre }))] },
        ],
    },
};

// ---------- Pestañas (entrada desde seleccionar('reportes')) ----------
async function renderReportes(rep) {
    if (!ES_ADMIN) rep = 'localidades';   // los operativos son solo del admin (el backend igual devuelve 403)
    RO.actual = rep || RO.actual || 'localidades';
    if (!RO.iniciado) {
        $('rep-tabs').querySelectorAll('[data-rep]').forEach((b) => b.addEventListener('click', () => renderReportes(b.dataset.rep)));
        // Al cambiar el tema claro/oscuro, el gráfico toma los colores nuevos.
        const previo = window.redibujarDash;
        window.redibujarDash = () => {
            if (previo) previo();
            if (!$('vista-reportes').classList.contains('oculto') && RO.actual !== 'localidades') roDibujarChart();
        };
        RO.iniciado = true;
    }
    $('rep-tabs').querySelectorAll('[data-rep]').forEach((b) => {
        const on = b.dataset.rep === RO.actual;
        b.classList.toggle('activo', on);
        b.setAttribute('aria-current', on ? 'page' : 'false');
    });
    $('rep-tabs').classList.toggle('oculto', !ES_ADMIN);   // el vendedor solo tiene Localidades
    const esLoc = RO.actual === 'localidades';
    $('rep-localidades').classList.toggle('oculto', !esLoc);
    $('rep-operativo').classList.toggle('oculto', esLoc);
    if (esLoc) return renderReporteLocalidades();
    return roMostrar(RO.actual);
}

// ---------- Armado de la pantalla de un reporte ----------
async function roMostrar(rep) {
    const cont = $('rep-operativo');
    if (!RO.opciones) {
        cont.innerHTML = '<p class="td-mute">Cargando…</p>';
        try { RO.opciones = await api.get(RO_API + '/opciones'); }
        catch (e) { cont.innerHTML = `<div class="rl-error" role="alert">No se pudo abrir el reporte: ${esc(e.message)}</div>`; return; }
    }
    const def = RO_DEF[rep], op = RO.opciones;
    RO.filtros[rep] ??= { desde: op.hoy, hasta: op.hoy };
    const f = RO.filtros[rep];

    const campo = (x) => {
        const id = 'ro-f-' + x.k, val = f[x.k] ?? x.def ?? '';
        if (x.tipo === 'select') {
            const ops = [{ v: '', t: 'Todos' }, ...x.ops(op)];
            return `<div class="campo"><label for="${id}">${esc(x.t)}</label><select id="${id}" name="${x.k}">${ops.map((o) =>
                `<option value="${esc(o.v)}" ${String(o.v) === String(val) ? 'selected' : ''}>${esc(o.t)}</option>`).join('')}</select></div>`;
        }
        if (x.tipo === 'check') {
            return `<div class="campo ro-check"><label><input type="checkbox" id="${id}" name="${x.k}" ${val === '1' ? 'checked' : ''}> ${esc(x.t)}</label></div>`;
        }
        const extra = x.tipo === 'number' ? ` min="${x.min ?? 0}"${x.max ? ` max="${x.max}"` : ''} step="1"` : ` maxlength="80" placeholder="${esc(x.ph || '')}"`;
        return `<div class="campo"><label for="${id}">${esc(x.t)}</label><input type="${x.tipo}" id="${id}" name="${x.k}" value="${esc(val)}"${extra}></div>`;
    };

    cont.innerHTML = `
        <header class="content-top">
            <div>
                <button class="volver-link" id="ro-volver">‹ Panel</button>
                <h1>${esc(op.reportes[rep])}</h1>
                <p class="sub">${esc(def.sub)}</p>
            </div>
            <div class="content-top-acciones">
                <button id="ro-actualizar" class="btn-secundario" title="Volver a consultar con los mismos filtros">↻ Actualizar</button>
                <select id="ro-csv-tabla" class="rl-select oculto" aria-label="Qué tabla exportar en CSV"></select>
                <button id="ro-csv" class="btn-secundario">Exportar CSV</button>
                <button id="ro-pdf" class="btn-primary">Exportar PDF</button>
            </div>
        </header>
        <form id="ro-filtros" class="rl-filtros" autocomplete="off">
            <div class="rl-rapidos">
                <button type="button" data-rango="hoy">Hoy</button>
                <button type="button" data-rango="ayer">Ayer</button>
                <button type="button" data-rango="7">Últimos 7 días</button>
                <button type="button" data-rango="mes">Este mes</button>
            </div>
            <div class="rl-grid">
                <div class="campo"><label for="ro-f-desde">Desde</label><input type="date" id="ro-f-desde" name="desde" value="${esc(f.desde)}" required></div>
                <div class="campo"><label for="ro-f-hasta">Hasta</label><input type="date" id="ro-f-hasta" name="hasta" value="${esc(f.hasta)}" required></div>
                ${def.filtros.map(campo).join('')}
            </div>
            <div class="rl-acciones">
                <button type="button" id="ro-limpiar" class="btn-secundario">Limpiar</button>
                <button type="submit" class="btn-primary">Aplicar filtros</button>
            </div>
        </form>
        <div id="ro-error" class="rl-error oculto" role="alert"></div>
        <div id="ro-cont" aria-live="polite"></div>`;

    $('ro-volver').addEventListener('click', () => seleccionar('inicio'));
    $('ro-filtros').addEventListener('submit', (ev) => { ev.preventDefault(); roLeerFiltros(rep); roCargar(rep, true); });
    $('ro-limpiar').addEventListener('click', () => { RO.filtros[rep] = { desde: op.hoy, hasta: op.hoy }; RO.tablas = {}; roMostrar(rep); });
    $('ro-actualizar').addEventListener('click', () => roCargar(rep, false));
    $('ro-filtros').querySelectorAll('[data-rango]').forEach((b) => b.addEventListener('click', () => {
        const hoy = rlFecha(op.hoy), d = new Date(hoy);
        let desde = hoy, hasta = hoy;
        if (b.dataset.rango === 'ayer') { d.setDate(d.getDate() - 1); desde = hasta = d; }
        if (b.dataset.rango === '7') { d.setDate(d.getDate() - 6); desde = d; }
        if (b.dataset.rango === 'mes') { desde = new Date(hoy.getFullYear(), hoy.getMonth(), 1); }
        $('ro-f-desde').value = rlIso(desde); $('ro-f-hasta').value = rlIso(hasta);
        roLeerFiltros(rep); roCargar(rep, true);
    }));
    $('ro-pdf').addEventListener('click', () => rlDescargar(RO_API + '/pdf?' + roParams(rep), $('ro-pdf'), 'PDF'));
    $('ro-csv').addEventListener('click', () =>
        rlDescargar(RO_API + '/csv?' + roParams(rep, { tabla: $('ro-csv-tabla').value }), $('ro-csv'), 'CSV'));
    await roCargar(rep, false);
}

function roLeerFiltros(rep) {
    const f = { desde: $('ro-f-desde').value, hasta: $('ro-f-hasta').value };
    RO_DEF[rep].filtros.forEach((x) => {
        const el = $('ro-f-' + x.k);
        f[x.k] = x.tipo === 'check' ? (el.checked ? '1' : '0') : el.value.trim();
    });
    RO.filtros[rep] = f;
}

function roParams(rep, extra = {}) {
    const p = new URLSearchParams({ reporte: rep });
    Object.entries({ ...RO.filtros[rep], ...extra }).forEach(([k, v]) => { if (v !== '' && v != null) p.set(k, v); });
    return p.toString();
}

async function roCargar(rep, reiniciarTablas) {
    const cont = $('ro-cont'), err = $('ro-error');
    if (!cont) return;
    cont.classList.add('rl-cargando');
    if (reiniciarTablas) RO.tablas = {};
    try {
        const d = await api.get(RO_API + '?' + roParams(rep));
        if (RO.actual !== rep) return;   // el usuario ya cambió de pestaña
        err.classList.add('oculto');
        RO.datos = d;
        roPintar(d);
    } catch (e) {
        err.textContent = 'No se pudo cargar el reporte: ' + e.message;
        err.classList.remove('oculto');
    } finally {
        cont.classList.remove('rl-cargando');
    }
}

// ---------- Pintado ----------
function roPintar(d) {
    const cont = $('ro-cont');
    $('ro-csv-tabla').innerHTML = d.tablas.map((t, i) => `<option value="${i}">CSV · ${esc(t.titulo)}</option>`).join('');
    $('ro-csv-tabla').classList.toggle('oculto', d.tablas.length < 2);

    const periodo = d.periodo.desde === d.periodo.hasta ? rlDmy(d.periodo.desde) : `${rlDmy(d.periodo.desde)} al ${rlDmy(d.periodo.hasta)}`;
    const hora = d.generado.slice(11, 16);
    cont.innerHTML = `
        <p class="rl-periodo">Período: ${esc(periodo)} · Actualizado a las ${esc(hora)}</p>
        <div class="cards rl-kpis">${d.kpis.map(roKpi).join('')}</div>
        <div class="chart-card">
            <p class="chart-titulo">${esc(d.grafico.titulo)}</p>
            <div class="chart-box"><canvas id="ro-chart" role="img" aria-label="${esc(d.grafico.titulo)}"></canvas></div>
            <p id="ro-chart-vacio" class="dash-vacio oculto">Sin datos para graficar con estos filtros.</p>
        </div>
        ${d.tablas.map((t, i) => `<section class="ro-tabla" id="ro-tabla-${i}"></section>`).join('')}
        <details class="rl-notas"><summary>Cómo se calcula este reporte</summary>
            <ul>${d.notas.map((n) => `<li>${esc(n)}</li>`).join('')}</ul></details>`;
    roDibujarChart();
    d.tablas.forEach((t, i) => roPintarTabla(i));
}

function roValor(v, tipo) {
    if (v === null || v === undefined || v === '') return '—';
    switch (tipo) {
        case 'money': return money.format(v);
        case 'int': return roNum.format(v);
        case 'pct': return String(Number(v).toFixed(1)).replace('.', ',') + '%';
        case 'min': return roDuracion(v);
        case 'fecha': { const [f, h] = String(v).split(' '); return `${rlDmy(f)} ${(h || '').slice(0, 5)}`; }
        default: return String(v);
    }
}

function roDuracion(min) {
    min = Number(min);
    if (min < 60) return `${min} min`;
    if (min < 1440) return `${Math.floor(min / 60)} h ${min % 60} min`;
    return `${Math.floor(min / 1440)} d ${Math.floor((min % 1440) / 60)} h`;
}

function roKpi(k) {
    const cls = { danger: 'rl-alerta', warn: 'ro-warn', ok: 'ro-ok' }[k.tono] || '';
    return `<div class="card kpi rl-kpi ${cls}">
        <div class="card-label">${esc(k.label)}</div>
        <div class="card-num">${esc(roValor(k.valor, k.tipo))}</div>
        ${k.nota ? `<div class="rl-kpi-foot">${esc(k.nota)}</div>` : ''}
    </div>`;
}

function roDibujarChart() {
    const d = RO.datos;
    if (!d || typeof Chart === 'undefined' || !$('ro-chart')) return;
    if (RO.chart) { RO.chart.destroy(); RO.chart = null; }
    const g = d.grafico, t = chartTema(), pal = rlPaleta();
    const vacio = !g.labels.length || g.series.every((s) => s.data.every((x) => !Number(x)));
    $('ro-chart-vacio').classList.toggle('oculto', !vacio);
    $('ro-chart').classList.toggle('oculto', vacio);
    if (vacio) return;

    const dinero = g.formato === 'money';
    const fmt = (v) => (dinero ? money.format(v) : roNum.format(v));
    const tooltip = { callbacks: { label: (c) => `${c.dataset.label || c.label}: ${fmt(c.raw)}` } };
    let cfg;
    if (g.tipo === 'doughnut') {
        const total = g.series[0].data.reduce((s, x) => s + Number(x), 0) || 1;
        cfg = { type: 'doughnut', data: { labels: g.labels, datasets: [{ data: g.series[0].data, backgroundColor: pal,
                borderColor: cssVar('--surface') || '#fff', borderWidth: 2 }] },
            options: { cutout: '58%', plugins: { tooltip: { callbacks: {
                label: (c) => `${c.label}: ${fmt(c.raw)} (${(c.raw / total * 100).toFixed(1).replace('.', ',')}%)` } } } } };
    } else {
        const horizontal = !!g.horizontal, apilado = !!g.apilado;
        const tonos = { pendiente: '#f59e0b', asignado: '#0ea5e9', en_reparto: t.primary, entregado: '#10b981', cancelado: t.muted };
        const val = { stacked: apilado, grid: { color: t.line }, ticks: { color: t.muted, precision: 0, callback: dinero ? (x) => montoCorto(x) : undefined } };
        const cat = { stacked: apilado, grid: { display: false }, ticks: { color: t.muted } };
        cfg = { type: 'bar',
            data: { labels: g.labels, datasets: g.series.map((s, i) => ({ label: s.label, data: s.data,
                backgroundColor: tonos[s.k] || pal[i % pal.length], borderRadius: 4, maxBarThickness: 34 })) },
            options: { indexAxis: horizontal ? 'y' : 'x', scales: horizontal ? { x: val, y: cat } : { x: cat, y: val },
                plugins: { legend: { display: g.series.length > 1, labels: { color: t.ink } }, tooltip } } };
    }
    cfg.options.responsive = true;
    cfg.options.maintainAspectRatio = false;
    RO.chart = new Chart($('ro-chart'), cfg);
}

// ---------- Tablas: búsqueda, orden, paginación y acciones (sobre las filas ya calculadas) ----------
function roPintarTabla(i) {
    const d = RO.datos, t = d.tablas[i], rep = d.reporte;
    const st = RO.tablas[i] ??= { q: '', orden: null, dir: 'asc', page: 1, barrio: null };
    const sec = $('ro-tabla-' + i);
    const acciones = roAcciones(rep, t.id);
    const cols = acciones ? [...t.columnas, { k: '_acc', t: 'Ir a', tipo: 'acc' }] : t.columnas;

    // Filtro por barrio (detalle del reporte de carga) + búsqueda de texto en todas las columnas.
    let filas = t.filas.filter((f) => st.barrio === null || f.barrio_id === st.barrio);
    const q = st.q.toLowerCase();
    if (q) filas = filas.filter((f) => t.columnas.some((c) => String(roValor(f[c.k], c.tipo)).toLowerCase().includes(q)));
    if (st.orden) {
        const c = t.columnas.find((x) => x.k === st.orden);
        const num = ['money', 'int', 'pct', 'min'].includes(c.tipo);
        filas = [...filas].sort((a, b) => {
            const va = a[c.k], vb = b[c.k];
            if (va == null && vb == null) return 0;
            if (va == null) return 1;
            if (vb == null) return -1;
            const r = num ? Number(va) - Number(vb) : String(va).localeCompare(String(vb), 'es', { numeric: true });
            return st.dir === 'asc' ? r : -r;
        });
    }
    const totalPag = Math.max(1, Math.ceil(filas.length / RO_POR_PAGINA));
    st.page = Math.min(st.page, totalPag);
    const pagina = filas.slice((st.page - 1) * RO_POR_PAGINA, st.page * RO_POR_PAGINA);

    const titulo = st.barrio !== null
        ? `${t.titulo}: ${esc((t.filas.find((f) => f.barrio_id === st.barrio) || {}).barrio || '')} <button type="button" class="volver-link ro-quitar" data-quitar>✕ ver todos</button>`
        : esc(t.titulo);
    const th = cols.map((c) => {
        const num = ['money', 'int', 'pct', 'min'].includes(c.tipo) ? 'num' : '';
        if (c.tipo === 'acc') return `<th>${esc(c.t)}</th>`;
        const activo = st.orden === c.k, flecha = activo ? (st.dir === 'asc' ? ' ▲' : ' ▼') : '';
        return `<th class="${num}" aria-sort="${activo ? (st.dir === 'asc' ? 'ascending' : 'descending') : 'none'}">
            <button type="button" class="rl-orden${activo ? ' activo' : ''}" data-orden="${c.k}">${esc(c.t)}${flecha}</button></th>`;
    }).join('');
    const td = (f, c) => {
        if (c.tipo === 'acc') return `<td class="ro-acc">${acciones(f)}</td>`;
        if (c.tipo === 'badge') return `<td><span class="badge ${esc(f[c.k + '_tono'] || 'neutral')}">${esc(f[c.k] ?? '—')}</span></td>`;
        const num = ['money', 'int', 'pct', 'min'].includes(c.tipo);
        let txt = esc(roValor(f[c.k], c.tipo));
        if (c.k === 'espera' && f.espera_txt) txt += `<div class="rl-sub">${esc(f.espera_txt)}</div>`;
        const rojo = c.k === 'diferencia' && f.diferencia < 0 ? ' rl-rojo' : '';
        return `<td class="${num ? 'num' : ''}${rojo}">${txt}</td>`;
    };

    sec.innerHTML = `
        <h2 class="dash-h">${titulo}</h2>
        <div class="toolbar">
            <div class="search-wrap"><input class="search" type="search" data-buscar placeholder="Buscar en esta tabla…" aria-label="Buscar en ${esc(t.titulo)}" value="${esc(st.q)}"></div>
        </div>
        ${t.recortada ? `<p class="rl-nota">Se muestran las primeras ${roNum.format(t.filas.length)} filas de ${roNum.format(t.total)}. Acotá el período o exportá el CSV para ver todo.</p>` : ''}
        <div class="tabla-caja rl-tabla-scroll">
            <table class="rl-tabla ro-tabla-datos">
                <thead><tr>${th}</tr></thead>
                <tbody>${pagina.map((f) => `<tr class="${f.alerta ? 'ro-fila-alerta' : ''}">${cols.map((c) => td(f, c)).join('')}</tr>`).join('')}</tbody>
            </table>
            ${pagina.length ? '' : `<div class="tabla-vacia">${q || st.barrio !== null ? 'Sin resultados para la búsqueda.' : 'Sin registros para estos filtros.'}</div>`}
        </div>
        <div class="paginacion">${roPaginacion(st, filas.length, totalPag)}</div>`;

    const buscar = sec.querySelector('[data-buscar]');
    let tmo = null;
    buscar.addEventListener('input', () => {
        clearTimeout(tmo);
        tmo = setTimeout(() => {
            st.q = buscar.value.trim(); st.page = 1; roPintarTabla(i);
            const nuevo = $('ro-tabla-' + i).querySelector('[data-buscar]');
            nuevo.focus(); nuevo.setSelectionRange(nuevo.value.length, nuevo.value.length);
        }, 250);
    });
    sec.querySelectorAll('[data-orden]').forEach((b) => b.addEventListener('click', () => {
        const k = b.dataset.orden;
        st.dir = st.orden === k ? (st.dir === 'asc' ? 'desc' : 'asc') : 'asc';
        st.orden = k; st.page = 1; roPintarTabla(i);
    }));
    sec.querySelectorAll('[data-p]').forEach((b) => b.addEventListener('click', () => { st.page = Number(b.dataset.p); roPintarTabla(i); }));
    const quitar = sec.querySelector('[data-quitar]');
    if (quitar) quitar.addEventListener('click', () => { st.barrio = null; st.page = 1; roPintarTabla(i); });
    sec.querySelectorAll('[data-accion]').forEach((b) => b.addEventListener('click', () => roEjecutar(b.dataset.accion, b.dataset)));
}

function roPaginacion(st, total, totalPag) {
    if (!total) return '';
    const desde = (st.page - 1) * RO_POR_PAGINA + 1, hasta = Math.min(total, st.page * RO_POR_PAGINA);
    const ini = Math.max(1, Math.min(st.page - 2, totalPag - 4)), fin = Math.min(totalPag, ini + 4);
    let b = `<button class="pag-btn" data-p="${st.page - 1}" ${st.page <= 1 ? 'disabled' : ''} aria-label="Página anterior">‹</button>`;
    for (let p = ini; p <= fin; p++) b += `<button class="pag-btn ${p === st.page ? 'activo' : ''}" data-p="${p}">${p}</button>`;
    b += `<button class="pag-btn" data-p="${st.page + 1}" ${st.page >= totalPag ? 'disabled' : ''} aria-label="Página siguiente">›</button>`;
    return `<span class="pag-info">${desde}–${hasta} de ${roNum.format(total)}</span><div class="pag-botones">${b}</div>`;
}

/**
 * Botones de cada fila: llevan a pantallas que YA existen (no cambian nada
 * desde el reporte). Devuelve null si la tabla no tiene acciones.
 */
function roAcciones(rep, tabla) {
    const btn = (accion, txt, datos) => `<button type="button" class="btn-secundario btn-chico" data-accion="${accion}" ${
        Object.entries(datos).map(([k, v]) => `data-${k}="${esc(v)}"`).join(' ')}>${txt}</button>`;
    if (rep === 'pagos' && tabla === 'movimientos') {
        return (f) => f.canal_k === 'online' ? btn('pedido', 'Ver pedido', { id: f.op_id, numero: f.numero }) : '';
    }
    if (rep === 'cajas' && tabla === 'cajas') return (f) => btn('caja', 'Ver caja', { id: f.id });
    if (rep === 'vendedores' && tabla === 'vendedores') return (f) => btn('vendedor', 'Ver operaciones', { id: f.id });
    if (rep === 'pedidos') {
        return (f) => btn('pedido', 'Ver / gestionar', { id: f.id, numero: f.numero });
    }
    if (rep === 'reclamos') return (f) => btn('reclamo', f.respuesta === 'Sin responder' ? 'Responder' : 'Ver', { id: f.id });
    if (rep === 'envios') {
        return (f) => (f.sin_asignar ? btn('repartos', 'Asignar', {}) : '')
            + (f.pedido_id ? ' ' + btn('pedido', 'Ver pedido', { id: f.pedido_id, numero: f.numero }) : '');
    }
    if (rep === 'carga' && tabla === 'barrios') return (f) => btn('barrio', 'Ver envíos', { id: f.id });
    if (rep === 'carga' && tabla === 'detalle') {
        return (f) => (f.estado_k === 'pendiente' ? btn('repartos', 'Asignar', {}) : '')
            + (f.pedido_id ? ' ' + btn('pedido', 'Ver pedido', { id: f.pedido_id, numero: f.numero }) : '');
    }
    return null;
}

function roEjecutar(accion, ds) {
    if (accion === 'pedido') return verPedido(Number(ds.id), ds.numero);   // modal existente: estados, pago y envío
    if (accion === 'reclamo') { seleccionar('reclamos').then(() => abrirDetalleReclamo(Number(ds.id))); return; }
    if (accion === 'caja') { seleccionar('cajas').then(() => abrirDetalleCaja(Number(ds.id))); return; }
    if (accion === 'repartos') return seleccionar('repartos');               // tablero con "Derivar a…"
    if (accion === 'vendedor') {
        RO.filtros.vendedores = { ...RO.filtros.vendedores, vendedor_id: ds.id };
        RO.tablas = {};
        return roMostrar('vendedores');
    }
    if (accion === 'barrio') {
        const st = RO.tablas[1] ??= { q: '', orden: null, dir: 'asc', page: 1, barrio: null };
        st.barrio = Number(ds.id); st.page = 1; st.q = '';
        roPintarTabla(1);
        $('ro-tabla-1').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

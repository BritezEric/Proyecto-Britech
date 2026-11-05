/* ============================================================
 * Reporte de Localidades — ventas, pedidos y entregas por zona.
 * Todos los cálculos los hace el backend (/api/admin/reportes/localidades/*):
 * acá solo se arman los filtros, se pinta y se descargan los exports.
 * Reusa los globals de admin.js ($, api, money, esc, toast, chartTema, montoCorto, ES_ADMIN).
 * ========================================================== */

const RL_API = '/api/admin/reportes/localidades';
const RL = {
    iniciado: false, opciones: null, datos: null, hoy: null, esAdmin: false,
    page: 1, perPage: 10, orden: 'importe', dir: 'desc', q: '',
    chart: null, vista: 'importe', prodOrden: 'unidades', pedido: 0,
};

const RL_TIPO = { localidad: 'Localidad', barrio: 'Barrio · Moto Express', local: 'Retiro / mostrador', sin: 'Sin localidad' };
const RL_ESTADO_PEDIDO = { pendiente: 'Pendiente', preparando: 'Preparando', entregado: 'Entregado', cancelado: 'Cancelado' };
const RL_ESTADO_ENVIO = { pendiente: 'Pendiente', despachado: 'Despachado', en_camino: 'En camino', entregado: 'Entregado', cancelado: 'Cancelado' };
const RL_VISTAS = [
    { k: 'importe',     t: 'Importe por zona' },
    { k: 'operaciones', t: 'Pedidos y ventas' },
    { k: 'distribucion', t: 'Distribución' },
    { k: 'canal',       t: 'Online vs POS' },
    { k: 'evolucion',   t: 'Evolución' },
];
const rlNum = new Intl.NumberFormat('es-AR');

async function renderReporteLocalidades() {
    if (!RL.iniciado) {
        try { await rlIniciar(); }
        catch (e) { rlError('No se pudo abrir el reporte: ' + e.message); return; }
    }
    await rlCargarTodo();
}

// ---------- Inicio: opciones de filtros + eventos ----------
async function rlIniciar() {
    const op = await api.get(RL_API + '/opciones?base=entrega');
    RL.opciones = op; RL.hoy = op.hoy; RL.esAdmin = !!op.es_admin;
    rlPonerRango('mes');
    rlLlenarSelect('rl-estado-pedido', [{ v: '', t: 'Todos' }, ...op.estados_pedido.map((e) => ({ v: e, t: RL_ESTADO_PEDIDO[e] || e }))]);
    rlLlenarSelect('rl-estado-envio', [{ v: '', t: 'Todos' }, ...op.estados_envio.map((e) => ({ v: e, t: RL_ESTADO_ENVIO[e] || e }))]);
    rlLlenarSelect('rl-empresa', [{ v: '', t: 'Todos' }, ...op.empresas]);
    rlLlenarSelect('rl-producto', [{ v: '', t: 'Todos' }, ...op.productos]);
    rlLlenarZonas(op.zonas);

    $('rl-alcance').textContent = RL.esAdmin ? ''
        : 'Vista de vendedor: tus ventas del POS + todos los pedidos online. Cobros, costos de envío y repartidores son solo para el administrador.';

    $('rl-filtros').addEventListener('submit', (ev) => { ev.preventDefault(); RL.page = 1; rlCargarTodo(); });
    $('rl-limpiar').addEventListener('click', () => {
        $('rl-filtros').reset(); rlPonerRango('mes'); rlToggleComp(); RL.page = 1; RL.q = ''; $('rl-q').value = '';
        rlCambiarBase().then(rlCargarTodo);
    });
    $('rl-rapidos').querySelectorAll('[data-rango]').forEach((b) => b.addEventListener('click', () => {
        rlPonerRango(b.dataset.rango); RL.page = 1; rlCargarTodo();
    }));
    $('rl-comparar').addEventListener('change', rlToggleComp);
    $('rl-base').addEventListener('change', () => rlCambiarBase());

    let t = null;
    $('rl-q').addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => { RL.q = $('rl-q').value.trim(); RL.page = 1; rlCargarZonas(); }, 300);
    });

    $('rl-chart-switch').innerHTML = RL_VISTAS.map((v) =>
        `<button type="button" data-k="${v.k}" class="${v.k === RL.vista ? 'activo' : ''}">${v.t}</button>`).join('');
    $('rl-chart-switch').querySelectorAll('button').forEach((b) => b.addEventListener('click', () => {
        RL.vista = b.dataset.k;
        $('rl-chart-switch').querySelectorAll('button').forEach((x) => x.classList.toggle('activo', x === b));
        rlDibujarChart();
    }));

    $('rl-prod-zona').addEventListener('change', rlCargarProductos);
    $('rl-prod-orden').querySelectorAll('button').forEach((b) => b.addEventListener('click', () => {
        RL.prodOrden = b.dataset.o;
        $('rl-prod-orden').querySelectorAll('button').forEach((x) => x.classList.toggle('activo', x === b));
        rlCargarProductos();
    }));
    $('rl-comparar-zonas').addEventListener('click', rlCompararZonas);

    $('rl-pdf').addEventListener('click', () => rlDescargar(RL_API + '/pdf?' + rlParams(), $('rl-pdf'), 'PDF'));
    $('rl-csv').addEventListener('click', () =>
        rlDescargar(RL_API + '/csv?' + rlParams({ seccion: $('rl-csv-seccion').value, q: RL.q, orden: RL.orden, dir: RL.dir }), $('rl-csv'), 'CSV'));

    const volver = document.querySelector('#vista-reportes [data-ir-inicio]');
    if (volver) volver.addEventListener('click', () => seleccionar('inicio'));

    // Al cambiar el tema claro/oscuro, el gráfico toma los colores nuevos.
    const previo = window.redibujarDash;
    window.redibujarDash = () => {
        if (previo) previo();
        if (!$('vista-reportes').classList.contains('oculto')) rlDibujarChart();
    };
    RL.iniciado = true;
}

function rlLlenarSelect(id, ops, valor) {
    const sel = $(id);
    const actual = valor !== undefined ? valor : sel.value;
    sel.innerHTML = ops.map((o) => `<option value="${esc(o.v)}">${esc(o.t)}</option>`).join('');
    if ([...sel.options].some((o) => o.value === String(actual))) sel.value = actual;
}

/** Zonas agrupadas (localidades / barrios / otros) en los 4 selects de zona. */
function rlLlenarZonas(zonas) {
    const grupo = (tipo, titulo) => {
        const items = zonas.filter((z) => z.tipo === tipo);
        return items.length ? `<optgroup label="${esc(titulo)}">${items.map((z) =>
            `<option value="${esc(z.v)}">${esc(z.t)}</option>`).join('')}</optgroup>` : '';
    };
    const cuerpo = grupo('localidad', 'Localidades') + grupo('barrio', 'Barrios (Moto Express)')
        + zonas.filter((z) => z.tipo === 'local' || z.tipo === 'sin').map((z) => `<option value="${esc(z.v)}">${esc(z.t)}</option>`).join('');
    const poner = (id, primera) => {
        const sel = $(id), previo = sel.value;
        sel.innerHTML = `<option value="">${esc(primera)}</option>` + cuerpo;
        if ([...sel.options].some((o) => o.value === previo)) sel.value = previo;
    };
    poner('rl-zona', 'Todas');
    poner('rl-prod-zona', 'Todas las zonas');
    poner('rl-zona-a', 'Elegí una zona');
    poner('rl-zona-b', 'Elegí otra zona');
}

async function rlCambiarBase() {
    try {
        const op = await api.get(RL_API + '/opciones?base=' + encodeURIComponent($('rl-base').value));
        RL.opciones.zonas = op.zonas;
        rlLlenarZonas(op.zonas);
    } catch (e) { toast('⚠ ' + e.message); }
}

function rlToggleComp() {
    const personalizado = $('rl-comparar').value === 'personalizado';
    document.querySelectorAll('.rl-comp').forEach((el) => el.classList.toggle('oculto', !personalizado));
}

// ---------- Fechas (siempre a partir del "hoy" del servidor) ----------
function rlFecha(s) { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); }
function rlIso(d) { return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; }
function rlDmy(s) { const [y, m, d] = s.split('-'); return `${d}/${m}/${y}`; }

function rlPonerRango(r) {
    const hoy = rlFecha(RL.hoy);
    let desde = new Date(hoy.getFullYear(), hoy.getMonth(), 1), hasta = hoy;
    if (r === 'mes_ant') { desde = new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1); hasta = new Date(hoy.getFullYear(), hoy.getMonth(), 0); }
    if (r === '7' || r === '30') { desde = new Date(hoy); desde.setDate(hoy.getDate() - (Number(r) - 1)); }
    if (r === 'anio') { desde = new Date(hoy.getFullYear(), 0, 1); }
    $('rl-desde').value = rlIso(desde);
    $('rl-hasta').value = rlIso(hasta);
}

/** Query string con los filtros del formulario (+ extras). Los vacíos no viajan. */
function rlParams(extra = {}) {
    const p = new URLSearchParams();
    new FormData($('rl-filtros')).forEach((v, k) => {
        if ((k === 'comp_desde' || k === 'comp_hasta') && $('rl-comparar').value !== 'personalizado') return;
        if (String(v).trim() !== '') p.set(k, String(v).trim());
    });
    Object.entries(extra).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') p.set(k, v); });
    return p.toString();
}

// ---------- Carga ----------
async function rlCargarTodo() {
    const n = ++RL.pedido;
    rlError(null);
    $('rl-cont').classList.add('rl-cargando');
    $('rl-kpis').innerHTML = '<p class="td-mute">Cargando indicadores…</p>';
    try {
        const [res] = await Promise.all([api.get(RL_API + '/resumen?' + rlParams()), rlCargarZonas()]);
        if (n !== RL.pedido) return;   // llegó una respuesta vieja: se ignora
        RL.datos = res;
        rlPintarPeriodo(res);
        rlPintarKpis(res.indicadores);
        rlDibujarChart();
        rlPintarLogistica(res.logistica);
        // Productos: si hay una zona filtrada, el panel la toma.
        $('rl-prod-zona').value = $('rl-zona').value;
        if ($('rl-prod-zona').value === '' && RL.prodOrden === 'unidades') rlPintarProductos(res.productos);
        else rlCargarProductos();
    } catch (e) {
        if (n !== RL.pedido) return;
        rlError(e.message);
        $('rl-kpis').innerHTML = '';
    } finally {
        if (n === RL.pedido) $('rl-cont').classList.remove('rl-cargando');
    }
}

function rlError(msg) {
    const el = $('rl-error');
    el.classList.toggle('oculto', !msg);
    el.textContent = msg ? '⚠ ' + msg : '';
}

function rlPintarPeriodo(res) {
    let txt = `Período: ${rlDmy(res.periodo.desde)} al ${rlDmy(res.periodo.hasta)}`;
    if (res.comparacion) txt += ` · comparado con ${res.comparacion.texto.toLowerCase()} (${rlDmy(res.comparacion.desde)} al ${rlDmy(res.comparacion.hasta)})`;
    $('rl-periodo').textContent = txt;
}

// ---------- Indicadores ----------
function rlVar(v) {
    if (!v) return '';
    if (v.pct === null) return `<div class="rl-var">${v.anterior == 0 && v.abs == 0 ? 'Sin movimiento en ambos períodos' : 'Sin datos en el período anterior'}</div>`;
    const sube = v.pct >= 0;
    return `<div class="rl-var ${sube ? 'up' : 'down'}">${sube ? '▲ +' : '▼ '}${String(v.pct).replace('.', ',')}% vs. anterior</div>`;
}

function rlPintarKpis(ind) {
    const a = ind.actual, v = ind.variacion || {};
    const card = (label, valor, foot, ayuda, varKey, cls = '') => `
        <div class="card kpi rl-kpi ${cls}" title="${esc(ayuda)}">
            <div class="card-label">${esc(label)}</div>
            <div class="card-num">${valor}</div>
            <div class="rl-kpi-foot">${foot}</div>
            ${varKey ? rlVar(v[varKey]) : ''}
        </div>`;
    const dias = Number($('rl-demora').value) || 3;
    $('rl-kpis').innerHTML =
        card('Operaciones de venta', rlNum.format(a.operaciones), `POS ${rlNum.format(a.ventas_pos)} · Online ${rlNum.format(a.pedidos_online)}`,
            'Ventas del POS registradas + pedidos online no cancelados del período. Un pedido con varios productos cuenta una vez.', 'operaciones')
      + card('Importe vendido', money.format(a.importe), 'Productos, sin costo de envío',
            'Suma de productos vendidos (ventas registradas + pedidos no cancelados). No incluye envíos ni anuladas.', 'importe')
      + card('Pedidos online', rlNum.format(a.pedidos_online), `Cancelados aparte: ${rlNum.format(a.pedidos_cancelados)}`,
            'Pedidos de la tienda no cancelados (cualquier estado de pago).', 'pedidos_online')
      + card('Zonas con actividad', rlNum.format(a.zonas_activas), 'Localidades y barrios con ventas',
            'Cantidad de localidades o barrios distintos con al menos una operación. No cuenta "Retiro / mostrador" ni "Sin localidad".', 'zonas_activas')
      + card('Envíos a domicilio', rlNum.format(a.envios), `Entregados: ${rlNum.format(a.envios_entregados)}`,
            'Envíos no cancelados de las operaciones filtradas. No incluye retiros en el local.', 'envios')
      + card('Entregas pendientes', rlNum.format(a.envios_activos), `Demorados (+${dias} días): ${rlNum.format(a.envios_demorados)}`,
            'Envíos pendientes, despachados o en camino. Demorado = activo con más días que el umbral elegido (no hay fecha prometida registrada).',
            null, a.envios_demorados > 0 ? 'rl-alerta' : '')
      + card('Clientes que compraron', rlNum.format(a.clientes), 'Distintos, en el período',
            'Clientes distintos con al menos una operación válida. "Consumidor Final" cuenta como uno.', 'clientes');

    const fin = $('rl-kpis-fin');
    if (RL.esAdmin && a.cobrado !== undefined) {
        fin.classList.remove('oculto');
        fin.innerHTML =
            card('Cobrado', money.format(a.cobrado), 'POS + pedidos con pago aprobado',
                'Dinero efectivamente cobrado: ventas del POS (se cobran al momento) + pedidos online con pago aprobado.')
          + card('Pendiente de cobro', money.format(a.pendiente_cobro), 'Pedidos con pago pendiente o en revisión',
                'Pedidos online no cancelados cuyo pago todavía no se aprobó. No es dinero cobrado.')
          + card('Envíos cobrados al cliente', money.format(a.envio_cobrado), 'Aparte del importe de productos',
                'Lo que se le cobró al cliente por los envíos no cancelados. No es el costo interno de la entrega.');
    } else {
        fin.classList.add('oculto'); fin.innerHTML = '';
    }
}

// ---------- Gráficos ----------
function rlPaleta() {
    const t = chartTema();
    return [t.primary, t.accent, '#0ea5e9', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444', t.muted];
}

function rlDibujarChart() {
    if (!RL.datos || typeof Chart === 'undefined') return;
    if (RL.chart) { RL.chart.destroy(); RL.chart = null; }
    const g = RL.datos.graficos, zonas = g.zonas.filter((z) => z.operaciones > 0), t = chartTema(), pal = rlPaleta();
    const vacio = RL.vista === 'evolucion' ? !g.evolucion.series.length : !zonas.length;
    $('rl-chart-vacio').classList.toggle('oculto', !vacio);
    $('rl-chart').classList.toggle('oculto', vacio);
    const titulos = {
        importe: 'Importe vendido por zona (las 12 con más importe)',
        operaciones: 'Cantidad de ventas del POS y pedidos online por zona',
        distribucion: 'Participación de cada zona en el importe total',
        canal: 'Importe por zona: tienda online vs POS',
        evolucion: 'Evolución del importe — 5 zonas principales (' + { dia: 'por día', semana: 'por semana', mes: 'por mes' }[g.evolucion.grano] + ')',
    };
    $('rl-chart-titulo').textContent = titulos[RL.vista];
    if (vacio) return;

    const labels = zonas.map((z) => z.zona_nombre);
    const ejes = (dinero, stacked = false, horizontal = false) => {
        const val = { stacked, grid: { color: t.line }, ticks: { color: t.muted, callback: dinero ? (x) => montoCorto(x) : undefined, precision: dinero ? undefined : 0 } };
        const cat = { stacked, grid: { display: false }, ticks: { color: t.muted } };
        return horizontal ? { x: val, y: cat } : { x: cat, y: val };
    };
    const tooltipMoney = { callbacks: { label: (c) => `${c.dataset.label || c.label}: ${money.format(c.raw)}` } };
    let cfg;
    if (RL.vista === 'importe') {
        cfg = { type: 'bar', data: { labels, datasets: [{ label: 'Importe', data: zonas.map((z) => z.importe), backgroundColor: t.primary, borderRadius: 4, maxBarThickness: 26 }] },
            options: { indexAxis: 'y', scales: ejes(true, false, true), plugins: { legend: { display: false }, tooltip: tooltipMoney } } };
    } else if (RL.vista === 'operaciones') {
        cfg = { type: 'bar', data: { labels, datasets: [
                { label: 'Ventas POS', data: zonas.map((z) => z.ventas_pos), backgroundColor: t.primary, borderRadius: 4, maxBarThickness: 22 },
                { label: 'Pedidos online', data: zonas.map((z) => z.pedidos_online), backgroundColor: t.accent, borderRadius: 4, maxBarThickness: 22 }] },
            options: { scales: ejes(false) } };
    } else if (RL.vista === 'distribucion') {
        const top = zonas.slice(0, 7), resto = zonas.slice(7).reduce((s, z) => s + z.importe, 0);
        const d = top.map((z) => z.importe), l = top.map((z) => z.zona_nombre);
        if (resto > 0) { d.push(resto); l.push('Otras zonas'); }
        const total = d.reduce((s, x) => s + x, 0) || 1;
        cfg = { type: 'doughnut', data: { labels: l, datasets: [{ data: d, backgroundColor: pal, borderColor: cssVar('--surface') || '#fff', borderWidth: 2 }] },
            options: { cutout: '58%', plugins: { tooltip: { callbacks: { label: (c) => `${c.label}: ${money.format(c.raw)} (${(c.raw / total * 100).toFixed(1).replace('.', ',')}%)` } } } } };
    } else if (RL.vista === 'canal') {
        cfg = { type: 'bar', data: { labels, datasets: [
                { label: 'POS', data: zonas.map((z) => z.importe_pos), backgroundColor: t.primary, maxBarThickness: 26 },
                { label: 'Tienda online', data: zonas.map((z) => z.importe_online), backgroundColor: t.accent, maxBarThickness: 26 }] },
            options: { scales: ejes(true, true), plugins: { tooltip: tooltipMoney } } };
    } else {
        const ev = g.evolucion;
        const fmt = (s) => ev.grano === 'mes' ? s.slice(5, 7) + '/' + s.slice(0, 4) : rlDmy(s).slice(0, 5);
        cfg = { type: 'line', data: { labels: ev.etiquetas.map(fmt), datasets: ev.series.map((s, i) => ({
                label: s.nombre, data: s.datos, borderColor: pal[i % pal.length], backgroundColor: pal[i % pal.length],
                tension: 0.3, pointRadius: ev.etiquetas.length > 40 ? 0 : 3, borderWidth: 2 })) },
            options: { scales: ejes(true), plugins: { tooltip: tooltipMoney } } };
    }
    cfg.options = Object.assign({ responsive: true, maintainAspectRatio: false, animation: { duration: 250 } }, cfg.options);
    cfg.options.plugins = Object.assign({ legend: { labels: { color: t.ink, usePointStyle: true, boxWidth: 8, padding: 16 } } }, cfg.options.plugins || {});
    RL.chart = new Chart($('rl-chart').getContext('2d'), cfg);
}

// ---------- Tabla de zonas ----------
const RL_COLS = () => [
    { k: 'zona', t: 'Localidad / zona', orden: 'zona' },
    { k: 'ventas_pos', t: 'Ventas POS', orden: 'ventas_pos', num: true },
    { k: 'pedidos_online', t: 'Pedidos', orden: 'pedidos', num: true },
    { k: 'operaciones', t: 'Operaciones', orden: 'operaciones', num: true },
    { k: 'importe', t: 'Importe', orden: 'importe', num: true },
    { k: 'ticket', t: 'Promedio', orden: 'ticket', num: true },
    { k: 'clientes', t: 'Clientes', orden: 'clientes', num: true },
    { k: 'envios', t: 'Envíos', orden: 'envios', num: true },
    { k: 'estado', t: 'Entregas' },
    ...(RL.esAdmin ? [{ k: 'envio_cobrado', t: 'Envío cobrado', num: true }] : []),
    { k: 'variacion', t: 'Var. importe', num: true },
];

async function rlCargarZonas() {
    const r = await api.get(RL_API + '/zonas?' + rlParams({ page: RL.page, per_page: RL.perPage, orden: RL.orden, dir: RL.dir, q: RL.q }));
    rlPintarZonas(r);
    return r;
}

function rlPintarZonas(r) {
    const cols = RL_COLS();
    $('rl-thead').innerHTML = '<tr>' + cols.map((c) => {
        if (!c.orden) return `<th class="${c.num ? 'num' : ''}">${esc(c.t)}</th>`;
        const activo = RL.orden === c.orden;
        const flecha = activo ? (RL.dir === 'asc' ? ' ▲' : ' ▼') : '';
        return `<th class="${c.num ? 'num' : ''}" aria-sort="${activo ? (RL.dir === 'asc' ? 'ascending' : 'descending') : 'none'}">
            <button type="button" class="rl-orden${activo ? ' activo' : ''}" data-orden="${c.orden}">${esc(c.t)}${flecha}</button></th>`;
    }).join('') + '</tr>';
    $('rl-thead').querySelectorAll('[data-orden]').forEach((b) => b.addEventListener('click', () => {
        const o = b.dataset.orden;
        RL.dir = RL.orden === o ? (RL.dir === 'asc' ? 'desc' : 'asc') : (o === 'zona' ? 'asc' : 'desc');
        RL.orden = o; RL.page = 1;
        rlCargarZonas().catch((e) => toast('⚠ ' + e.message));
    }));

    const rows = r.data || [];
    $('rl-vacio').classList.toggle('oculto', rows.length > 0);
    $('rl-tbody').innerHTML = rows.map((z) => {
        const celdas = cols.map((c) => {
            switch (c.k) {
                case 'zona': return `<td><button type="button" class="rl-zona-link" data-zona="${esc(z.zona_key)}" title="Ver productos de esta zona">${esc(z.zona_nombre)}</button>
                    <div class="rl-sub">${esc(RL_TIPO[z.zona_tipo] || z.zona_tipo)}${z.provincias ? ' · ' + esc(z.provincias) : ''}${z.anuladas ? ` · ${z.anuladas} anulada(s)/cancelada(s)` : ''}</div></td>`;
                case 'importe': case 'envio_cobrado': return `<td class="num td-fuerte">${money.format(z[c.k] || 0)}</td>`;
                case 'ticket': return `<td class="num">${z.ticket === null ? '<span class="td-mute">—</span>' : money.format(z.ticket)}</td>`;
                case 'estado': return `<td>${rlBadgeEntrega(z)}</td>`;
                case 'variacion': return `<td class="num">${rlVarCelda(z.variacion_importe)}</td>`;
                default: return `<td class="num">${rlNum.format(z[c.k] || 0)}</td>`;
            }
        }).join('');
        return `<tr>${celdas}</tr>`;
    }).join('');
    $('rl-tbody').querySelectorAll('[data-zona]').forEach((b) => b.addEventListener('click', () => {
        $('rl-prod-zona').value = b.dataset.zona;
        rlCargarProductos();
        $('rl-prod-zona').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }));
    rlPaginacion(r);
}

function rlBadgeEntrega(z) {
    const cls = { 'Con demoras': 'danger', 'En curso': 'info', 'Al día': 'ok' }[z.estado_entregas] || 'neutral';
    const det = z.envios > 0
        ? `<div class="rl-sub">${z.entregados} entregados · ${z.activos} activos${z.demorados ? ` · <b>${z.demorados} demorados</b>` : ''}</div>` : '';
    return `<span class="badge ${cls}">${esc(z.estado_entregas)}</span>${det}`;
}

function rlVarCelda(v) {
    if (!v) return '<span class="td-mute">—</span>';
    if (v.pct === null) return `<span class="td-mute" title="Sin datos en el período anterior">${v.abs > 0 ? 'Nueva' : '—'}</span>`;
    return `<span class="rl-var ${v.pct >= 0 ? 'up' : 'down'}">${v.pct >= 0 ? '+' : ''}${String(v.pct).replace('.', ',')}%</span>`;
}

function rlPaginacion(r) {
    const cont = $('rl-paginacion');
    if (!r.total) { cont.innerHTML = ''; return; }
    const desde = (r.page - 1) * r.per_page + 1, hasta = Math.min(r.total, r.page * r.per_page);
    const ini = Math.max(1, Math.min(r.page - 2, r.total_pages - 4)), fin = Math.min(r.total_pages, ini + 4);
    let botones = `<button class="pag-btn" data-p="${r.page - 1}" ${r.page <= 1 ? 'disabled' : ''} aria-label="Página anterior">‹</button>`;
    for (let p = ini; p <= fin; p++) botones += `<button class="pag-btn ${p === r.page ? 'activo' : ''}" data-p="${p}">${p}</button>`;
    botones += `<button class="pag-btn" data-p="${r.page + 1}" ${r.page >= r.total_pages ? 'disabled' : ''} aria-label="Página siguiente">›</button>`;
    cont.innerHTML = `<span class="pag-info">${desde}–${hasta} de ${r.total} zonas</span><div class="pag-botones">${botones}</div>`;
    cont.querySelectorAll('[data-p]').forEach((b) => b.addEventListener('click', () => {
        RL.page = Number(b.dataset.p);
        rlCargarZonas().catch((e) => toast('⚠ ' + e.message));
    }));
}

// ---------- Productos por zona ----------
async function rlCargarProductos() {
    const cont = $('rl-productos');
    cont.innerHTML = '<p class="td-mute">Cargando…</p>';
    try {
        const r = await api.get(RL_API + '/productos?' + rlParams({ zona: $('rl-prod-zona').value, orden: RL.prodOrden }));
        rlPintarProductos(r.productos);
    } catch (e) { cont.innerHTML = `<p class="tabla-vacia">${esc(e.message)}</p>`; }
}

function rlPintarProductos(lista) {
    const cont = $('rl-productos');
    if (!lista || !lista.length) { cont.innerHTML = '<p class="tabla-vacia">Sin productos vendidos en esta zona con los filtros actuales.</p>'; return; }
    cont.innerHTML = `<table class="rl-mini"><thead><tr><th>Producto</th><th class="num">Unidades</th><th class="num">Importe</th><th class="num">Operaciones</th></tr></thead>
        <tbody>${lista.map((p) => `<tr><td>${esc(p.nombre)}</td><td class="num">${rlNum.format(p.unidades)}</td>
            <td class="num">${money.format(p.importe)}</td><td class="num">${rlNum.format(p.operaciones)}</td></tr>`).join('')}</tbody></table>
        <p class="rl-nota">Importe con el precio guardado en cada venta (precio histórico).</p>`;
}

// ---------- Comparar dos zonas ----------
async function rlCompararZonas() {
    const a = $('rl-zona-a').value, b = $('rl-zona-b').value, cont = $('rl-comparacion');
    if (!a || !b) { toast('⚠ Elegí las dos zonas'); return; }
    if (a === b) { toast('⚠ Elegí dos zonas distintas'); return; }
    cont.innerHTML = '<p class="td-mute">Comparando…</p>';
    try {
        const r = await api.get(RL_API + '/comparar-zonas?' + rlParams({ zona_a: a, zona_b: b }));
        const za = r.zonas.a, zb = r.zonas.b;
        const nom = (id) => $(id).options[$(id).selectedIndex].text;
        const fila = (t, ka, fmt) => {
            const va = za[ka], vb = zb[ka];
            const gana = va === vb || va === null || vb === null ? '' : (va > vb ? 'a' : 'b');
            return `<tr><td>${t}</td><td class="num ${gana === 'a' ? 'td-fuerte' : ''}">${va === null ? '—' : fmt(va)}</td>
                <td class="num ${gana === 'b' ? 'td-fuerte' : ''}">${vb === null ? '—' : fmt(vb)}</td></tr>`;
        };
        const n = (x) => rlNum.format(x), m = (x) => money.format(x);
        const top = (z) => z.top_productos.length ? z.top_productos.map((p) => esc(p.nombre)).join('<br>') : '<span class="td-mute">—</span>';
        cont.innerHTML = `<table class="rl-mini"><thead><tr><th></th><th class="num">${esc(nom('rl-zona-a'))}</th><th class="num">${esc(nom('rl-zona-b'))}</th></tr></thead><tbody>
            ${fila('Operaciones', 'operaciones', n)}${fila('Importe', 'importe', m)}${fila('Promedio por operación', 'ticket', m)}
            ${fila('Ventas POS', 'ventas_pos', n)}${fila('Pedidos online', 'pedidos_online', n)}${fila('Clientes', 'clientes', n)}
            ${fila('Envíos', 'envios', n)}${fila('Entregas demoradas', 'envios_demorados', n)}
            <tr><td>Más vendidos</td><td class="num">${top(za)}</td><td class="num">${top(zb)}</td></tr></tbody></table>`;
    } catch (e) { cont.innerHTML = `<p class="tabla-vacia">${esc(e.message)}</p>`; }
}

// ---------- Logística ----------
function rlPintarLogistica(log) {
    const admin = RL.esAdmin;
    $('rl-log-thead').innerHTML = `<tr><th>Zona</th><th>Medio de envío</th><th class="num">Envíos</th><th class="num">Pendientes</th>
        <th class="num">Despachados</th><th class="num">En camino</th><th class="num">Entregados</th><th class="num">Cancelados</th>
        <th class="num">Demorados</th><th class="num">Moto sin repartidor</th>
        ${admin ? '<th class="num">Cobrado al cliente</th><th class="num" title="Envíos de moto entregados × tarifa vigente del barrio">Paga repartidores</th>' : ''}</tr>`;
    const rows = log.rows || [];
    $('rl-log-vacio').classList.toggle('oculto', rows.length > 0);
    const tot = { envios: 0, pendientes: 0, despachados: 0, en_camino: 0, entregados: 0, cancelados: 0, demorados: 0, sin_repartidor: 0, costo_cobrado: 0, paga_moto: 0 };
    const celdas = (r, fuerte = '') => ['envios', 'pendientes', 'despachados', 'en_camino', 'entregados', 'cancelados'].map((k) =>
            `<td class="num ${fuerte}">${rlNum.format(r[k])}</td>`).join('')
        + `<td class="num ${r.demorados ? 'rl-rojo' : ''} ${fuerte}">${rlNum.format(r.demorados)}</td>`
        + `<td class="num ${fuerte}">${rlNum.format(r.sin_repartidor)}</td>`
        + (admin ? `<td class="num ${fuerte}">${money.format(r.costo_cobrado)}</td><td class="num ${fuerte}">${r.paga_moto ? money.format(r.paga_moto) : '<span class="td-mute">—</span>'}</td>` : '');
    $('rl-log-tbody').innerHTML = rows.map((r) => {
        Object.keys(tot).forEach((k) => { tot[k] += Number(r[k] || 0); });
        return `<tr><td>${esc(r.zona_nombre)}<div class="rl-sub">${esc(RL_TIPO[r.zona_tipo] || '')}</div></td><td>${esc(r.empresa || 'Sin medio')}</td>${celdas(r)}</tr>`;
    }).join('') + (rows.length > 1 ? `<tr class="rl-total"><td class="td-fuerte">Total</td><td></td>${celdas(tot, 'td-fuerte')}</tr>` : '');

    const rep = $('rl-repartidores');
    if (!admin || !log.repartidores) { rep.innerHTML = ''; return; }
    rep.innerHTML = `<div class="dash-panel rl-rep"><h3>🏍️ Repartidores (Moto Express)</h3>` + (log.repartidores.length
        ? `<table class="rl-mini"><thead><tr><th>Repartidor</th><th class="num">Asignados</th><th class="num">Entregados</th><th class="num">Activos</th><th class="num">Cancelados</th><th class="num">Zonas</th></tr></thead><tbody>
            ${log.repartidores.map((x) => `<tr><td>${esc(x.nombre)}</td><td class="num">${x.asignados}</td><td class="num">${x.entregados}</td>
                <td class="num">${x.activos}</td><td class="num">${x.cancelados}</td><td class="num">${x.zonas}</td></tr>`).join('')}</tbody></table>`
        : '<p class="td-mute">Ningún envío de moto con repartidor asignado en este período.</p>') + '</div>';
}

// ---------- Exportaciones ----------
/** Descarga generada en el servidor (respeta filtros y permisos). Muestra el error si falla. */
async function rlDescargar(url, btn, tipo) {
    const texto = btn.textContent;
    btn.disabled = true; btn.textContent = 'Generando…';
    try {
        const resp = await fetch(url);
        if (!resp.ok) {
            const j = await resp.json().catch(() => ({}));
            throw new Error(j.error || 'Error HTTP ' + resp.status);
        }
        const blob = await resp.blob();
        const m = /filename="([^"]+)"/.exec(resp.headers.get('Content-Disposition') || '');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = m ? m[1] : `reporte-localidades.${tipo.toLowerCase()}`;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(a.href), 2000);
        toast(`✓ ${tipo} descargado`);
    } catch (e) {
        toast('⚠ No se pudo exportar: ' + e.message, 3500);
    } finally {
        btn.disabled = false; btn.textContent = texto;
    }
}

/* ============================================================
 * Dashboard INDIVIDUAL del vendedor ("Mi panel").
 * Cada vendedor ve solo SU desempeño: sus ventas, los clientes que
 * le compraron, sus cajas y sus mejores momentos del período.
 * Todo en CANTIDADES. Un solo selector de período (Día/Semana/Mes/Año)
 * aplica a todos los gráficos.
 * Reusa los globals de admin.js ($, api, esc, money, kpi).
 * ========================================================== */

let vendPeriodo = 'semana';
const VEND_TXT_PERIODO = { dia: 'hoy', semana: 'últimos 7 días', mes: 'últimas 4 semanas', ano: 'últimos 12 meses' };
const VEND_TXT_BALDE   = { dia: 'Hora', semana: 'Día', mes: 'Semana', ano: 'Mes' };

// Barras agrupadas (2 series) con la CANTIDAD escrita arriba de cada barra.
// series: [{ campo, cls, nombre }] — cls reusa los colores del dashboard admin.
function vendBarras(serie, series) {
    const max = Math.max(1, ...serie.flatMap((x) => series.map((s) => Number(x[s.campo]))));
    return `<div class="barras grupos vend-barras ${serie.length > 12 ? 'compacto' : ''}">${serie.map((x) => `<div class="barra-col">
        <div class="barra-par">${series.map((s) => {
            const n = Number(x[s.campo]);
            const h = n > 0 ? Math.max(Math.round(n / max * 100), 2) : 0;
            return `<div class="barra ${s.cls}" style="height:${h}%" ${n > 0 ? `data-n="${n}"` : ''} title="${esc(s.nombre)}: ${n}"></div>`;
        }).join('')}</div>
        <span>${esc(x.label)}</span></div>`).join('')}</div>`;
}

// Leyenda con el TOTAL del período de cada serie.
function vendLeyenda(serie, series) {
    return `<div class="chart-legend">${series.map((s) => {
        const total = serie.reduce((acc, x) => acc + Number(x[s.campo]), 0);
        return `<span class="lg"><i class="sw ${s.cls}"></i>${esc(s.nombre)} · <b>${total}</b></span>`;
    }).join('')}</div>`;
}

function vendPanelSerie(id, titulo, serie, series) {
    $(id).innerHTML = `<div class="chart-head"><h3>${esc(titulo)} · ${VEND_TXT_PERIODO[vendPeriodo]}</h3></div>
        ${vendLeyenda(serie, series)}
        ${vendBarras(serie, series)}`;
}

async function renderVendedor() {
    document.querySelectorAll('#vend-periodo button').forEach((b) => b.classList.toggle('activo', b.dataset.p === vendPeriodo));
    let d;
    try { d = await api.get('/api/vendedor/dashboard?periodo=' + vendPeriodo); }
    catch { $('vend-kpis').innerHTML = '<p class="tabla-vacia">No se pudo cargar el panel.</p>'; return; }

    if (d.vendedor) $('vend-titulo').textContent = 'Mi panel · ' + d.vendedor;

    // --- Resumen del período (tarjetas) ---
    const r = d.resumen || {};
    $('vend-kpis').innerHTML = [
        kpi('Mis ventas', r.ventas ?? 0, VEND_TXT_PERIODO[vendPeriodo]),
        kpi('Clientes atendidos', r.clientes ?? 0, 'clientes identificados'),
        kpi('Ticket promedio', money.format(r.ticket ?? 0), 'por venta'),
        kpi('Anuladas', r.anuladas ?? 0, 'ventas anuladas'),
    ].join('');

    // --- 📈 Frecuencia de mis ventas ---
    vendPanelSerie('vend-ventas', '📈 Frecuencia de mis ventas', d.ventas, [
        { campo: 'ventas',   cls: 'fisica', nombre: 'Ventas' },
        { campo: 'anuladas', cls: 'gasto',  nombre: 'Anuladas' },
    ]);

    // --- 🛒 Frecuencia de compras de mis clientes ---
    vendPanelSerie('vend-compras', '🛒 Compras de mis clientes', d.compras, [
        { campo: 'identificados',    cls: 'fisica', nombre: 'Clientes identificados' },
        { campo: 'consumidor_final', cls: 'online', nombre: 'Consumidor final' },
    ]);

    // --- 📦 Mis cajas abiertas vs cerradas ---
    const cj = d.cajas || { abiertas: 0, cerradas: 0, serie: [] };
    const seriesCaja = [
        { campo: 'abiertas', cls: 'online', nombre: 'Abiertas' },
        { campo: 'cerradas', cls: 'fisica', nombre: 'Cerradas' },
    ];
    $('vend-cajas').innerHTML = `<h3>📦 Mis cajas abiertas y cerradas</h3>
        <div class="cards vend-cajas-kpis">
            ${kpi('Abiertas ahora', cj.abiertas, cj.abiertas > 0 ? 'sin cerrar todavía' : 'ninguna abierta')}
            ${kpi('Cerradas', cj.cerradas, VEND_TXT_PERIODO[vendPeriodo])}
        </div>
        ${vendLeyenda(cj.serie, seriesCaja)}
        ${vendBarras(cj.serie, seriesCaja)}`;

    // --- 🏆 Mis mejores momentos: mis ventas por hora/día/semana/mes, de MAYOR a MENOR ---
    const top = (d.ventas || []).filter((x) => Number(x.ventas) > 0)
        .sort((a, b) => Number(b.ventas) - Number(a.ventas));
    const maxTop = Math.max(1, ...top.map((x) => Number(x.ventas)));
    const medalla = ['🥇', '🥈', '🥉'];
    $('vend-ranking').innerHTML = `<h3>🏆 Mis ventas por ${VEND_TXT_BALDE[vendPeriodo].toLowerCase()} · de mayor a menor</h3>` + (top.length === 0
        ? `<p class="dash-vacio">Sin ventas ${VEND_TXT_PERIODO[vendPeriodo]}.</p>`
        : `<ol class="vend-ranking">${top.map((x, i) => `<li>
                <span class="vend-pos">${i < 3 ? medalla[i] : (i + 1)}</span>
                <span class="hbar-label">${esc(x.label)}</span>
                <div class="hbar-track"><div class="hbar-fill" style="width:${Math.max(3, Math.round(Number(x.ventas) / maxTop * 100))}%"></div></div>
                <span class="hbar-val">${Number(x.ventas)}</span></li>`).join('')}</ol>`);

    // --- ⭐ Mis clientes que más compran ---
    const cf = d.clientes_frecuentes || [];
    $('vend-clientes').innerHTML = `<h3>⭐ Mis clientes que más compran</h3>` + (cf.length === 0
        ? `<p class="dash-vacio">Sin ventas a clientes identificados en el período.</p>`
        : `<ul class="dash-lista">${cf.map((c) =>
            `<li><span>${esc(c.cliente)}</span><span class="dash-num">${Number(c.compras)} compra(s)</span></li>`).join('')}</ul>`);
}

// Selector de período (estático en admin.html): recarga todo el panel.
document.querySelectorAll('#vend-periodo button').forEach((b) => b.addEventListener('click', () => {
    if (b.dataset.p === vendPeriodo) return;
    vendPeriodo = b.dataset.p;
    renderVendedor();
}));

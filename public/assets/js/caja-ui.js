/* ============================================================
 * CajaUI — piezas visuales compartidas de la caja (POS + panel admin):
 * formatos, badges de estado, formas de pago con barras, libro de efectivo
 * y línea de tiempo de movimientos con filtros. Estilos en caja.css.
 * Depende de window.ICO (iconos.js).
 * ========================================================== */
window.CajaUI = (function () {
    const fmt = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });
    const m = (n) => { const v = Number(n) || 0; return v < 0 ? '−' + fmt.format(-v) : fmt.format(v); };
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const r2 = (n) => Math.round((Number(n) || 0) * 100) / 100;
    const fecha = (dt) => dt ? new Date(String(dt).replace(' ', 'T')) : null;
    const hora = (dt) => dt ? fecha(dt).toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit', hour12: false }) : '';
    const dia = (dt) => dt ? fecha(dt).toLocaleDateString('es-AR', { weekday: 'short', day: '2-digit', month: '2-digit' }) : '';
    const fechaHora = (dt) => dt ? fecha(dt).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false }) : '—';
    const ico = (n) => (window.ICO && ICO[n]) || '';

    /** "2 h 15 min", "3 días", "12 min" entre dos fechas (o hasta ahora). */
    function duracion(desde, hasta) {
        if (!desde) return '';
        const ms = (hasta ? fecha(hasta) : new Date()) - fecha(desde);
        const min = Math.max(0, Math.round(ms / 60000));
        if (min < 60) return `${min} min`;
        const h = Math.floor(min / 60);
        if (h < 24) return `${h} h ${min % 60 ? (min % 60) + ' min' : ''}`.trim();
        const d = Math.floor(h / 24);
        return d === 1 ? '1 día' : `${d} días`;
    }

    const cuadra = (d) => Math.abs(Number(d) || 0) < 0.005;
    const difTexto = (d) => cuadra(d) ? 'Cuadra' : (d > 0 ? `Sobra ${m(d)}` : `Falta ${m(-d)}`);

    /** Badge con punto + texto (el estado nunca depende solo del color). */
    const badge = (texto, tono) => `<span class="cx-badge cx-${tono}"><span class="cx-dot" aria-hidden="true"></span>${texto}</span>`;
    const difBadge = (d) => d == null ? badge('Sin arqueo', 'neutro') : badge(difTexto(d), cuadra(d) ? 'ok' : 'bad');
    const estadoBadge = (estado) => estado === 'abierta' ? badge('Abierta', 'info') : badge('Cerrada', 'neutro');

    /** Formas de pago con barra proporcional a su peso en el total. */
    function formas(lista, opt = {}) {
        const total = lista.reduce((a, f) => a + Number(f.total || 0), 0);
        if (!lista.length) return '<p class="cx-vacio">Sin formas de pago activas.</p>';
        const filas = lista.map((f) => {
            const pct = total > 0 ? Math.round((Number(f.total) / total) * 100) : 0;
            return `<div class="cx-forma${Number(f.total) === 0 ? ' cx-forma-cero' : ''}">
                <div class="cx-forma-top">
                    <span class="cx-forma-nom">${esc(f.nombre)}</span>
                    <span class="cx-forma-op">${f.operaciones} ${f.operaciones === 1 ? 'cobro' : 'cobros'}</span>
                    <strong class="cx-num">${m(f.total)}</strong>
                </div>
                <div class="cx-bar" role="img" aria-label="${pct}% del total"><span style="width:${pct}%"></span></div>
            </div>`;
        }).join('');
        return `<div class="cx-formas">${filas}
            ${opt.sinTotal ? '' : `<div class="cx-forma-tot"><span>Total cobrado</span><strong class="cx-num">${m(total)}</strong></div>`}</div>`;
    }

    /** Libro del efectivo: apertura + entradas − salidas = esperado. */
    function ledger(apertura, res, esperado, extra = '') {
        const fila = (signo, label, val, n) => `<div class="cx-led-row${Number(val) === 0 ? ' cx-led-cero' : ''}">
            <span class="cx-led-sig" aria-hidden="true">${signo}</span><span>${label}${n ? ` <small>(${n})</small>` : ''}</span><span class="cx-num">${m(val)}</span></div>`;
        return `<div class="cx-ledger">
            ${fila('', 'Apertura', apertura)}
            ${fila('+', 'Ventas en efectivo', res.efectivo)}
            ${fila('+', 'Ingresos', res.ingresos, res.mov_ingresos)}
            ${fila('−', 'Retiros', res.retiros, res.mov_retiros)}
            ${fila('−', 'Gastos', res.gastos, res.mov_gastos)}
            <div class="cx-led-tot"><span>Efectivo esperado</span><strong class="cx-num">${m(esperado)}</strong></div>
            ${extra}
        </div>`;
    }

    // --- Línea de tiempo -------------------------------------------------
    const TIPOS = {
        apertura:  { label: 'Apertura',  ico: 'unlock',    tono: 'ok' },
        venta:     { label: 'Venta',     ico: 'cart',      tono: 'primary' },
        anulacion: { label: 'Anulación', ico: 'undo',      tono: 'bad' },
        retiro:    { label: 'Retiro',    ico: 'arrowUp',   tono: 'bad' },
        ingreso:   { label: 'Ingreso',   ico: 'arrowDown', tono: 'ok' },
        gasto:     { label: 'Gasto',     ico: 'receipt',   tono: 'warn' },
        cierre:    { label: 'Cierre',    ico: 'lock',      tono: 'neutro' },
    };
    const FILTROS = [
        { k: 'todo',      label: 'Todo',        f: () => true },
        { k: 'ventas',    label: 'Ventas',      f: (t) => t.tipo === 'venta' },
        { k: 'efectivo',  label: 'Retiros, ingresos y gastos', f: (t) => ['retiro', 'ingreso', 'gasto'].includes(t.tipo) },
        { k: 'anulacion', label: 'Anulaciones', f: (t) => t.tipo === 'anulacion' },
    ];

    function timelineFilas(tl, filtro) {
        const f = (FILTROS.find((x) => x.k === filtro) || FILTROS[0]).f;
        const lista = tl.filter(f);
        if (!lista.length) return '<p class="cx-vacio">No hay movimientos de este tipo.</p>';
        const variosDias = new Set(tl.map((t) => String(t.fecha).slice(0, 10))).size > 1;
        let diaActual = '';
        return lista.map((t) => {
            const meta = TIPOS[t.tipo] || { label: t.tipo, ico: 'chevron', tono: 'neutro' };
            const d = String(t.fecha).slice(0, 10);
            const sep = variosDias && d !== diaActual ? `<div class="cx-tl-dia">${dia(t.fecha)}</div>` : '';
            diaActual = d;
            const ef = Number(t.efectivo);
            const formas = (t.formas || []).map((p) => `<span class="cx-pago">${esc(p.nombre)} ${m(p.monto)}</span>`).join('');
            const efTxt = t.tipo === 'cierre' ? '' : (ef === 0 ? '<span class="cx-tl-ef cx-mute">sin efectivo</span>'
                : `<span class="cx-tl-ef ${ef > 0 ? 'cx-mas' : 'cx-menos'}">${ef > 0 ? '+' : '−'}${m(Math.abs(ef))} ef.</span>`);
            return `${sep}<div class="cx-tl-item cx-t-${t.tipo}${t.anulada ? ' cx-anulada' : ''}">
                <span class="cx-tl-ic cx-tono-${meta.tono}" aria-hidden="true">${ico(meta.ico)}</span>
                <div class="cx-tl-main">
                    <div class="cx-tl-l1"><span class="cx-tl-det">${esc(t.detalle)}</span><strong class="cx-num cx-tl-monto">${m(t.monto)}</strong></div>
                    <div class="cx-tl-l2"><span class="cx-tl-hora">${hora(t.fecha)} · ${meta.label}${t.anulada ? ' · anulada' : ''}</span>${formas}
                        <span class="cx-tl-der">${efTxt}${t.tipo === 'cierre' ? '' : `<span class="cx-tl-saldo" title="Efectivo en caja después de este movimiento">en caja ${m(t.saldo)}</span>`}</span></div>
                </div>
            </div>`;
        }).join('');
    }

    /** Timeline + chips de filtro. Devuelve html; llamar bindTimeline(cont, tl) después de insertarlo. */
    function timeline(tl, filtro = 'todo') {
        const chips = FILTROS.map((x) => {
            const n = tl.filter(x.f).length;
            if (x.k !== 'todo' && n === 0) return '';
            return `<button type="button" class="cx-chip${x.k === filtro ? ' on' : ''}" data-tl-filtro="${x.k}" aria-pressed="${x.k === filtro}">${x.label} <span>${n}</span></button>`;
        }).join('');
        return `<div class="cx-chips" role="group" aria-label="Filtrar movimientos">${chips}</div>
            <div class="cx-tl" data-tl-lista>${timelineFilas(tl, filtro)}</div>`;
    }

    function bindTimeline(cont, tl) {
        cont.querySelectorAll('[data-tl-filtro]').forEach((b) => b.addEventListener('click', () => {
            cont.querySelectorAll('[data-tl-filtro]').forEach((x) => { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b); });
            cont.querySelector('[data-tl-lista]').innerHTML = timelineFilas(tl, b.dataset.tlFiltro);
        }));
    }

    /** Tabla del arqueo por forma (esperado / declarado / diferencia). */
    function arqueo(filas) {
        return `<div class="cx-arqueo">
            <div class="cx-arq-row cx-arq-hd"><span>Forma</span><span>Esperado</span><span>Declarado</span><span>Resultado</span></div>
            ${filas.map((f) => `<div class="cx-arq-row">
                <span>${esc(f.nombre)}</span><span class="cx-num">${m(f.esperado)}</span><span class="cx-num">${m(f.declarado)}</span>
                <span>${difBadge(Number(f.diferencia))}</span></div>`).join('')}
        </div>`;
    }

    /** Aviso breve abajo (usa el .toast de tokens.css). */
    function toast(msg) {
        let c = document.querySelector('.toast-cont');
        if (!c) { c = document.createElement('div'); c.className = 'toast-cont'; c.setAttribute('aria-live', 'polite'); document.body.appendChild(c); }
        const t = document.createElement('div');
        t.className = 'toast';
        t.innerHTML = `<span class="toast-ic">${ico('check')}</span>${esc(msg)}`;
        c.appendChild(t);
        requestAnimationFrame(() => t.classList.add('show'));
        setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 300); }, 2600);
    }

    return { m, esc, r2, hora, dia, fechaHora, duracion, cuadra, difTexto, badge, difBadge, estadoBadge,
             formas, ledger, timeline, bindTimeline, arqueo, toast, ico };
})();

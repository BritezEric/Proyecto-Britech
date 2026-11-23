/* ============================================================
 * Cajas (admin) — supervisión de aperturas/cierres del vendedor.
 *  - Lista: atajos de fecha, filtros, franja de resumen, aviso de cajas
 *    olvidadas abiertas y filas con veredicto del arqueo.
 *  - Detalle: veredicto, cifras, formas de pago, efectivo, arqueo por
 *    forma, conteo de billetes y movimientos con filtros. Reporte PDF.
 *  - Cierre del día (Z): consolidado de una fecha con navegación ‹ ›.
 * Piezas visuales en caja-ui.js (CajaUI) + caja.css. Usa $, api, esc de admin.js.
 * Expone renderCajas / abrirDetalleCaja / renderCierreDia (los usan admin.js
 * y los reportes operativos).
 * ========================================================== */
(function () {
    const U = window.CajaUI;
    const f = { preset: 'todo', desde: '', hasta: '', usuario_id: '', estado: '', con_diferencia: false, page: 1 };
    let vendedoresCache = [];

    const iso = (d) => { const x = new Date(d); x.setMinutes(x.getMinutes() - x.getTimezoneOffset()); return x.toISOString().slice(0, 10); };
    const hoy = () => iso(new Date());
    const sumarDias = (s, n) => { const d = new Date(s + 'T12:00:00'); d.setDate(d.getDate() + n); return iso(d); };
    const PRESETS = {
        hoy:    { label: 'Hoy',      rango: () => [hoy(), hoy()] },
        ayer:   { label: 'Ayer',     rango: () => [sumarDias(hoy(), -1), sumarDias(hoy(), -1)] },
        semana: { label: '7 días',   rango: () => [sumarDias(hoy(), -6), hoy()] },
        mes:    { label: 'Este mes', rango: () => [hoy().slice(0, 8) + '01', hoy()] },
        todo:   { label: 'Todo',     rango: () => ['', ''] },
    };
    const inicial = (n) => String(n || '?').trim().charAt(0).toUpperCase();
    const fechaLarga = (s) => { const t = new Date(s + 'T12:00:00').toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' }); return t.charAt(0).toUpperCase() + t.slice(1); };
    const btnLink = 'text-decoration:none;display:inline-flex;gap:6px;align-items:center';
    // Error visible con el motivo real (ej: "falta correr schema_caja_cierre.sql"), no un mensaje genérico.
    const falla = (titulo, e) => `<div class="cx-banner cx-bad">${U.ico('alert')}<div><strong>${titulo}</strong><p>${esc((e && e.message) || 'Probá de nuevo en un momento.')}</p></div></div>`;

    function aplicarPreset(k) {
        f.preset = k;
        [f.desde, f.hasta] = PRESETS[k].rango();
        f.page = 1;
    }
    aplicarPreset('todo');

    // ------------------------------------------------------------ Lista
    async function renderLista() {
        const cont = $('cajas-cont');
        if (!cont.querySelector('.cx-strip')) cont.innerHTML = '<p class="cx-vacio">Cargando…</p>';
        const qs = new URLSearchParams({ page: f.page, per_page: 15 });
        if (f.desde) qs.set('desde', f.desde);
        if (f.hasta) qs.set('hasta', f.hasta);
        if (f.usuario_id) qs.set('usuario_id', f.usuario_id);
        if (f.estado) qs.set('estado', f.estado);
        if (f.con_diferencia) qs.set('con_diferencia', '1');
        let r;
        try { r = await api.get('/api/admin/cajas?' + qs); }
        catch (e) { cont.innerHTML = falla('No se pudieron cargar las cajas', e); return; }
        vendedoresCache = r.vendedores || vendedoresCache;
        const s = r.resumen || {};

        const presets = Object.entries(PRESETS).map(([k, p]) =>
            `<button type="button" class="${f.preset === k ? 'on' : ''}" data-preset="${k}" aria-pressed="${f.preset === k}">${p.label}</button>`).join('')
            + `<button type="button" class="${f.preset === 'rango' ? 'on' : ''}" data-preset="rango" aria-pressed="${f.preset === 'rango'}">${U.ico('calendar')} Rango</button>`;
        const vendOpts = vendedoresCache.map((v) => `<option value="${v.id}" ${String(v.id) === String(f.usuario_id) ? 'selected' : ''}>${esc(v.nombre)}</option>`).join('');
        const activo = (k) => k === 'dif' ? f.con_diferencia : (!f.con_diferencia && f.estado === k);
        const estadoChip = (k, label) => `<button type="button" class="cx-chip${activo(k) ? ' on' : ''}" data-est="${k}" aria-pressed="${activo(k)}">${label}</button>`;

        const olvidadas = (r.olvidadas || []).map((o) => `<div class="cx-alerta" role="status">${U.ico('clock')}
            <span>La <b>caja #${o.id}</b> de ${esc(o.vendedor)} está abierta hace <b>${U.duracion(o.abierta_en)}</b>. ¿Se olvidaron de cerrarla?</span>
            <button type="button" data-ver="${o.id}">Revisar y cerrar</button></div>`).join('');

        const filas = (r.data || []).map((c) => {
            const abierta = c.estado === 'abierta';
            const difForm = Number(c.diferencia_formas);
            const dif = difForm !== 0 ? difForm : Number(c.diferencia || 0);
            const veredicto = abierta ? U.badge('Abierta', 'info') : U.difBadge(Number(c.con_diferencia) ? dif : 0);
            return `<button type="button" class="cx-row" data-ver="${c.id}" aria-label="Ver caja ${c.id} de ${esc(c.vendedor)}">
                <span class="cx-quien"><span class="cx-avatar" aria-hidden="true">${inicial(c.vendedor)}</span>
                    <span style="min-width:0"><b>${esc(c.vendedor)}</b><small>Caja #${c.id}</small></span></span>
                <span class="cx-cuando"><span>${U.fechaHora(c.abierta_en)}</span><small>${abierta ? 'abierta hace ' + U.duracion(c.abierta_en) : 'duró ' + U.duracion(c.abierta_en, c.cerrada_en)}</small></span>
                <span class="cx-r cx-num">${c.ventas}</span>
                <span class="cx-r cx-num"><b>${U.m(c.total_ventas)}</b></span>
                <span>${veredicto}</span>
                <span class="cx-row-go" aria-hidden="true">${U.ico('chevron')}</span>
            </button>`;
        }).join('');

        cont.innerHTML = `
            ${olvidadas}
            <div class="cx-adm-top">
                <div class="cx-adm-filtros">
                    <div class="cx-seg" role="group" aria-label="Período">${presets}</div>
                    ${f.preset === 'rango' ? `<span class="cx-rango"><input type="date" id="cx-desde" value="${f.desde}" aria-label="Desde"> a <input type="date" id="cx-hasta" value="${f.hasta}" aria-label="Hasta"></span>` : ''}
                    <select id="cx-vend" aria-label="Vendedor"><option value="">Todos los vendedores</option>${vendOpts}</select>
                </div>
                <div class="cx-chips" style="margin:0" role="group" aria-label="Estado">
                    ${estadoChip('', 'Todas')}${estadoChip('abierta', 'Abiertas')}${estadoChip('cerrada', 'Cerradas')}${estadoChip('dif', 'No cuadraron')}
                </div>
            </div>
            <div class="cx-strip" aria-label="Resumen del período">
                <div><span>Cajas</span><strong>${s.cajas ?? 0}</strong></div>
                <div><span>Total vendido</span><strong>${U.m(s.total_ventas)}</strong></div>
                <div><span>No cuadraron</span><strong class="${s.con_diferencia ? 'cx-bad-t' : 'cx-ok-t'}">${s.con_diferencia ?? 0}</strong></div>
                <div><span>Diferencia de efectivo</span><strong class="${U.cuadra(s.diferencia_efectivo) ? '' : 'cx-bad-t'}">${U.cuadra(s.diferencia_efectivo) ? U.m(0) : U.difTexto(s.diferencia_efectivo)}</strong></div>
                <div><span>Abiertas</span><strong>${s.abiertas ?? 0}</strong></div>
            </div>
            ${filas ? `<div class="cx-lista">
                <div class="cx-row cx-row-hd" aria-hidden="true"><span>Vendedor</span><span>Apertura</span><span>Ventas</span><span>Vendido</span><span>Arqueo</span><span></span></div>
                ${filas}</div>` : `<div class="cx-lista"><p class="cx-vacio">No hay cajas con estos filtros.${f.preset !== 'todo' ? ' Probá con <b>Todo</b>.' : ''}</p></div>`}
            ${r.total_pages > 1 ? `<div class="cx-pag"><span>${r.total} cajas · página ${r.page} de ${r.total_pages}</span><div>
                <button type="button" class="cx-icbtn" data-pag="${r.page - 1}" ${r.page <= 1 ? 'disabled' : ''} aria-label="Página anterior">${U.ico('chevronLeft')}</button>
                <button type="button" class="cx-icbtn" data-pag="${r.page + 1}" ${r.page >= r.total_pages ? 'disabled' : ''} aria-label="Página siguiente">${U.ico('chevron')}</button></div></div>` : ''}`;

        cont.querySelectorAll('[data-preset]').forEach((b) => b.addEventListener('click', () => {
            if (b.dataset.preset === 'rango') {
                f.preset = 'rango'; f.desde = f.desde || sumarDias(hoy(), -30); f.hasta = f.hasta || hoy(); f.page = 1;
            } else aplicarPreset(b.dataset.preset);
            renderLista();
        }));
        ['cx-desde', 'cx-hasta'].forEach((id) => { const i = $(id); if (i) i.addEventListener('change', () => { f.desde = $('cx-desde').value; f.hasta = $('cx-hasta').value; f.page = 1; renderLista(); }); });
        $('cx-vend').addEventListener('change', (e) => { f.usuario_id = e.target.value; f.page = 1; renderLista(); });
        cont.querySelectorAll('[data-est]').forEach((b) => b.addEventListener('click', () => {
            f.con_diferencia = b.dataset.est === 'dif'; f.estado = f.con_diferencia ? '' : b.dataset.est; f.page = 1; renderLista();
        }));
        cont.querySelectorAll('[data-pag]').forEach((b) => b.addEventListener('click', () => { f.page = Number(b.dataset.pag); renderLista(); }));
        cont.querySelectorAll('[data-ver]').forEach((b) => b.addEventListener('click', () => renderDetalle(Number(b.dataset.ver))));
    }

    // ------------------------------------------------------------ Detalle
    async function renderDetalle(id) {
        const cont = $('cajas-cont');
        cont.innerHTML = '<p class="cx-vacio">Cargando…</p>';
        let r;
        try { r = await api.get('/api/admin/cajas/detalle?id=' + id); }
        catch (e) { cont.innerHTML = falla('No se pudo cargar la caja', e); return; }

        const c = r.caja, res = r.resumen, cerrada = c.estado === 'cerrada', cierre = r.cierre || [];
        const hayDif = cierre.some((x) => !U.cuadra(x.diferencia));
        const difTot = U.r2(cierre.reduce((a, x) => a + Number(x.diferencia), 0));
        const esperado = cerrada && c.monto_esperado != null ? c.monto_esperado : r.esperado;

        const porAdmin = cerrada && c.cerrada_por && Number(c.cerrada_por) !== Number(c.usuario_id);
        const veredicto = !cerrada
            ? `<div class="cx-banner cx-info cx-banner-acc">${U.ico('clock')}<div><strong>Abierta hace ${U.duracion(c.abierta_en)}</strong><p>Los números son en vivo y pueden cambiar hasta que el vendedor la cierre.</p></div>
                <button type="button" class="cx-btn cx-btn-sec" id="cx-adm-abrir">${U.ico('lock')} Cerrar esta caja</button></div>
               <div id="cx-adm-cierre"></div>`
            : hayDif
                ? `<div class="cx-banner cx-bad">${U.ico('alert')}<div><strong>No cuadró: ${U.difTexto(difTot)}</strong><p>${c.observacion ? (porAdmin ? 'Motivo de ' + esc(c.cerrada_por_nombre || 'el admin') + ': “' : 'Explicación del vendedor: “') + esc(c.observacion) + '”' : 'El vendedor no dejó explicación.'}</p></div></div>`
                : `<div class="cx-banner cx-ok">${U.ico('checkCircle')}<div><strong>Cerró perfecto</strong><p>El efectivo y los demás medios coincidieron con lo esperado.</p></div></div>`;

        const conteo = c.conteo_efectivo && typeof c.conteo_efectivo === 'object' ? Object.entries(c.conteo_efectivo) : [];
        const contadoExtra = cerrada
            ? `<div class="cx-led-extra"><span>Contado por ${porAdmin ? esc(c.cerrada_por_nombre || 'el admin') : 'el vendedor'}</span><span style="display:flex;gap:8px;align-items:center"><b class="cx-num">${U.m(c.monto_contado)}</b>${U.difBadge(Number(c.diferencia))}</span></div>` : '';

        cont.innerHTML = `
            <button type="button" class="cx-volver" id="cx-volver">${U.ico('chevronLeft')} Todas las cajas</button>
            <article class="cx-det">
                <header class="cx-det-head">
                    <span class="cx-avatar" style="width:44px;height:44px;font-size:var(--text-lg)" aria-hidden="true">${inicial(c.vendedor)}</span>
                    <div>
                        <h2>Caja #${c.id} ${U.estadoBadge(c.estado)}</h2>
                        <p>${esc(c.vendedor)} · ${U.fechaHora(c.abierta_en)} → ${cerrada ? U.fechaHora(c.cerrada_en) : 'ahora'} · ${U.duracion(c.abierta_en, c.cerrada_en)}
                           ${porAdmin ? U.badge('Cerrada por ' + esc(c.cerrada_por_nombre || 'un admin'), 'warn') : ''}</p>
                    </div>
                    <div class="cx-det-acc"><a class="btn-secundario" style="${btnLink}" href="/api/caja/reporte?id=${c.id}" target="_blank" rel="noopener">${U.ico('download')} Reporte PDF</a></div>
                </header>
                ${veredicto}
                <div class="cx-strip">
                    <div><span>Vendido</span><strong>${U.m(res.total_ventas)}</strong></div>
                    <div><span>Ventas</span><strong>${res.ventas}</strong></div>
                    <div><span>Ticket promedio</span><strong>${U.m(res.ticket_promedio)}</strong></div>
                    <div><span>Descuentos</span><strong>${U.m(res.descuentos)}</strong></div>
                    <div><span>Anuladas</span><strong>${res.anuladas}${res.anuladas ? ' · ' + U.m(res.total_anulado) : ''}</strong></div>
                </div>
                <div class="cx-det-cols">
                    <section><h3 class="cx-sec-t">Cobrado por forma de pago</h3>${U.formas(res.formas || [])}</section>
                    <section><h3 class="cx-sec-t">Efectivo</h3>${U.ledger(c.monto_apertura, res, esperado, contadoExtra)}</section>
                </div>
                ${cerrada ? `<section class="cx-det-sec"><h3 class="cx-sec-t">Arqueo por forma de pago</h3>${U.arqueo(cierre)}</section>` : ''}
                ${conteo.length ? `<section class="cx-det-sec"><h3 class="cx-sec-t">Billetes contados</h3><div class="cx-billetes-ro">${conteo.map(([d, n]) => d === 'sueltos'
                    ? `<span>Sueltos <b>${U.m(n)}</b></span>` : `<span>${n} × ${U.m(Number(d))} = <b>${U.m(Number(d) * n)}</b></span>`).join('')}</div></section>` : ''}
                ${cerrada && c.observacion && !hayDif ? `<section class="cx-det-sec"><h3 class="cx-sec-t">Observación</h3><p class="cx-obs-ro">${esc(c.observacion)}</p></section>` : ''}
                <section class="cx-det-sec" id="cx-movs"><h3 class="cx-sec-t">Movimientos del turno</h3>${U.timeline(r.timeline || [])}</section>
            </article>`;
        U.bindTimeline($('cx-movs'), r.timeline || []);
        $('cx-volver').addEventListener('click', renderLista);
        if (!cerrada) $('cx-adm-abrir').addEventListener('click', () => panelCierreAdmin(r));
        window.scrollTo({ top: 0 });
    }

    // ------------------------------------------------------------ Cierre administrativo
    // El admin cierra la caja abierta de otro vendedor (ej: se olvidó de cerrarla).
    // Carga el efectivo que encuentra, verifica los otros medios y deja el motivo.
    function panelCierreAdmin(r) {
        const c = r.caja, cont = $('cx-adm-cierre'), btnAbrir = $('cx-adm-abrir');
        const otras = (r.resumen.formas || []).filter((x) => !x.es_efectivo && (x.operaciones > 0 || x.total > 0));
        btnAbrir.disabled = true;
        cont.innerHTML = `<div class="cx-adm-panel">
            <h3>Cerrar la caja #${c.id} de ${esc(c.vendedor)}</h3>
            <p class="cx-mute">Contá lo que hay en el cajón y verificá los demás medios. Queda registrado que la cerraste vos y se le avisa a ${esc(c.vendedor)}.</p>
            <div class="cx-adm-fila">
                <div><strong>Efectivo</strong><small>esperado ${U.m(r.esperado)}</small></div>
                <label class="cx-amount cx-amount-sm"><span>$</span><input type="number" inputmode="decimal" min="0" step="0.01" id="cx-adm-ef" placeholder="Efectivo contado" aria-label="Efectivo contado"></label>
                <span data-adm-dif="ef"></span>
            </div>
            <div class="cx-quick" style="justify-content:flex-start;margin:0 0 var(--sp-3)">
                <button type="button" class="cx-chip" id="cx-adm-igual">Usar el esperado (${U.m(r.esperado)})</button></div>
            ${otras.map((f) => `<div class="cx-adm-fila">
                <div><strong>${esc(f.nombre)}</strong><small>${f.operaciones} ${f.operaciones === 1 ? 'cobro' : 'cobros'} · esperado ${U.m(f.total)}</small></div>
                <label class="cx-amount cx-amount-sm"><span>$</span><input type="number" inputmode="decimal" min="0" step="0.01" data-adm-forma="${f.tipo_pago_id}" data-esp="${f.total}" value="${f.total}" aria-label="Declarado ${esc(f.nombre)}"></label>
                <span data-adm-dif="${f.tipo_pago_id}">${U.difBadge(0)}</span>
            </div>`).join('')}
            <label class="cx-sec-t" for="cx-adm-obs" style="display:block;margin-top:var(--sp-4)">Motivo <span class="cx-req">· obligatorio</span></label>
            <textarea id="cx-adm-obs" class="cx-input cx-obs" maxlength="250" placeholder="Ej: el vendedor se olvidó de cerrarla"></textarea>
            <div class="cx-quick" style="justify-content:flex-start">${['Se olvidó de cerrarla', 'El vendedor no está', 'Cierre de fin de día'].map((s) => `<button type="button" class="cx-chip" data-adm-obs="${s}">${s}</button>`).join('')}</div>
            <p id="cx-adm-err" class="cx-err oculto" role="alert"></p>
            <div class="cx-mov-btns">
                <button type="button" class="cx-btn cx-btn-ghost" id="cx-adm-cancel">Cancelar</button>
                <button type="button" class="cx-btn cx-btn-pri" id="cx-adm-ok">${U.ico('lock')} Cerrar caja</button>
            </div>
        </div>`;

        const ef = $('cx-adm-ef'), obs = $('cx-adm-obs'), ok = $('cx-adm-ok'), err = $('cx-adm-err');
        const recalcular = () => {
            const v = parseFloat(ef.value);
            cont.querySelector('[data-adm-dif="ef"]').innerHTML = isNaN(v) ? '' : U.difBadge(U.r2(v - r.esperado));
            cont.querySelectorAll('[data-adm-forma]').forEach((i) => {
                const d = U.r2((parseFloat(i.value) || 0) - Number(i.dataset.esp));
                cont.querySelector(`[data-adm-dif="${i.dataset.admForma}"]`).innerHTML = U.difBadge(d);
            });
            ok.dataset.confirmar = '';
            ok.innerHTML = `${U.ico('lock')} Cerrar caja`;
        };
        cont.querySelectorAll('input').forEach((i) => i.addEventListener('input', recalcular));
        $('cx-adm-igual').addEventListener('click', () => { ef.value = r.esperado; recalcular(); obs.focus(); });
        cont.querySelectorAll('[data-adm-obs]').forEach((b) => b.addEventListener('click', () => { obs.value = b.dataset.admObs; obs.focus(); }));
        $('cx-adm-cancel').addEventListener('click', () => { cont.innerHTML = ''; btnAbrir.disabled = false; });
        const mostrarErr = (m) => { err.textContent = m; err.classList.remove('oculto'); };

        ok.addEventListener('click', async () => {
            err.classList.add('oculto');
            const contado = parseFloat(ef.value);
            if (isNaN(contado) || contado < 0) { mostrarErr('Ingresá el efectivo que hay en el cajón.'); ef.focus(); return; }
            if (!obs.value.trim()) { mostrarErr('Escribí el motivo del cierre.'); obs.focus(); return; }
            // Doble clic de seguridad: el primero pide confirmar (no se puede reabrir).
            if (!ok.dataset.confirmar) {
                ok.dataset.confirmar = '1';
                ok.innerHTML = `${U.ico('alert')} Confirmar: cerrar con ${U.m(contado)}`;
                return;
            }
            const declarados = {};
            cont.querySelectorAll('[data-adm-forma]').forEach((i) => { declarados[i.dataset.admForma] = parseFloat(i.value) || 0; });
            ok.disabled = true;
            try {
                await api.post('/api/admin/cajas/cerrar', { caja_id: c.id, contado, declarados, observacion: obs.value });
                U.toast(`Caja #${c.id} cerrada`);
                renderDetalle(c.id);
            } catch (e) { mostrarErr(e.message); ok.disabled = false; ok.dataset.confirmar = ''; ok.innerHTML = `${U.ico('lock')} Cerrar caja`; }
        });
        cont.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        ef.focus({ preventScroll: true });
    }

    // ------------------------------------------------------------ Cierre del día
    async function renderDia(fecha) {
        const cont = $('cajas-cont');
        cont.innerHTML = '<p class="cx-vacio">Cargando…</p>';
        let z;
        try { z = await api.get('/api/admin/cajas/dia?fecha=' + encodeURIComponent(fecha)); }
        catch (e) { cont.innerHTML = `<p class="tabla-vacia">${esc(e.message || 'No se pudo cargar el cierre del día.')}</p>`; return; }

        const r = z.resumen, ef = z.efectivo;
        const noCuadran = z.cajas.filter((c) => c.diferencia_total != null && !U.cuadra(c.diferencia_total)).length;
        const filas = z.cajas.map((c) => `<button type="button" class="cx-row" data-ver="${c.id}" aria-label="Ver caja ${c.id}">
            <span class="cx-quien"><span class="cx-avatar" aria-hidden="true">${inicial(c.vendedor)}</span><span style="min-width:0"><b>${esc(c.vendedor)}</b><small>Caja #${c.id}</small></span></span>
            <span class="cx-cuando"><span>${U.hora(c.abierta_en)} – ${c.cerrada_en ? U.hora(c.cerrada_en) : 'abierta'}</span><small>${U.duracion(c.abierta_en, c.cerrada_en)}</small></span>
            <span class="cx-r cx-num">${c.ventas}</span>
            <span class="cx-r cx-num"><b>${U.m(c.total_ventas)}</b></span>
            <span>${c.estado === 'abierta' ? U.badge('Abierta', 'info') : U.difBadge(c.diferencia_total)}</span>
            <span class="cx-row-go" aria-hidden="true">${U.ico('chevron')}</span></button>`).join('');

        const banner = z.abiertas
            ? `<div class="cx-banner cx-warn">${U.ico('clock')}<div><strong>${z.abiertas} ${z.abiertas === 1 ? 'caja sigue abierta' : 'cajas siguen abiertas'}</strong><p>Los totales son parciales hasta que se cierren.</p></div></div>`
            : noCuadran
                ? `<div class="cx-banner cx-bad">${U.ico('alert')}<div><strong>${noCuadran} ${noCuadran === 1 ? 'caja no cuadró' : 'cajas no cuadraron'}</strong><p>Diferencia de efectivo del día: ${U.difTexto(ef.diferencia)}.</p></div></div>`
                : `<div class="cx-banner cx-ok">${U.ico('checkCircle')}<div><strong>Día cerrado sin diferencias en efectivo</strong><p>Todas las cajas cuadraron.</p></div></div>`;

        cont.innerHTML = `
            <button type="button" class="cx-volver" id="cx-volver">${U.ico('chevronLeft')} Todas las cajas</button>
            <article class="cx-det">
                <header class="cx-det-head">
                    <div><h2>Cierre del día</h2><p>${fechaLarga(z.fecha)} · ${z.cajas.length} ${z.cajas.length === 1 ? 'caja' : 'cajas'}</p></div>
                    <div class="cx-det-acc">
                        <div class="cx-dianav">
                            <button type="button" class="cx-icbtn" data-dia="${sumarDias(z.fecha, -1)}" aria-label="Día anterior">${U.ico('chevronLeft')}</button>
                            <input type="date" class="cx-date" id="cx-dia" value="${esc(z.fecha)}" max="${hoy()}" aria-label="Fecha">
                            <button type="button" class="cx-icbtn" data-dia="${sumarDias(z.fecha, 1)}" ${z.fecha >= hoy() ? 'disabled' : ''} aria-label="Día siguiente">${U.ico('chevron')}</button>
                        </div>
                        ${z.fecha !== hoy() ? `<button type="button" class="btn-secundario" data-dia="${hoy()}">Hoy</button>` : ''}
                        <a class="btn-secundario" style="${btnLink}" href="/api/admin/cajas/dia?fecha=${encodeURIComponent(z.fecha)}&pdf=1" target="_blank" rel="noopener">${U.ico('download')} PDF</a>
                    </div>
                </header>
                ${!z.cajas.length ? '<p class="cx-vacio">No se abrió ninguna caja este día.</p>' : `
                ${banner}
                <div class="cx-strip">
                    <div><span>Vendido</span><strong>${U.m(r.total_ventas)}</strong></div>
                    <div><span>Ventas</span><strong>${r.ventas}</strong></div>
                    <div><span>Ticket promedio</span><strong>${U.m(r.ticket_promedio)}</strong></div>
                    <div><span>Anuladas</span><strong>${r.anuladas}${r.anuladas ? ' · ' + U.m(r.total_anulado) : ''}</strong></div>
                </div>
                <div class="cx-det-cols">
                    <section><h3 class="cx-sec-t">Cobrado por forma de pago</h3>${U.formas(r.formas || [])}</section>
                    <section><h3 class="cx-sec-t">Efectivo del día</h3>
                        <div class="cx-ledger">
                            <div class="cx-led-row"><span class="cx-led-sig"></span><span>Aperturas</span><span class="cx-num">${U.m(ef.apertura)}</span></div>
                            <div class="cx-led-row"><span class="cx-led-sig">+</span><span>Ventas en efectivo</span><span class="cx-num">${U.m(r.efectivo)}</span></div>
                            <div class="cx-led-row"><span class="cx-led-sig">+</span><span>Ingresos <small>(${r.mov_ingresos})</small></span><span class="cx-num">${U.m(r.ingresos)}</span></div>
                            <div class="cx-led-row"><span class="cx-led-sig">−</span><span>Retiros <small>(${r.mov_retiros})</small></span><span class="cx-num">${U.m(r.retiros)}</span></div>
                            <div class="cx-led-row"><span class="cx-led-sig">−</span><span>Gastos <small>(${r.mov_gastos})</small></span><span class="cx-num">${U.m(r.gastos)}</span></div>
                            <div class="cx-led-tot"><span>Efectivo esperado</span><strong class="cx-num">${U.m(ef.esperado)}</strong></div>
                            ${z.abiertas < z.cajas.length
                                ? `<div class="cx-led-extra"><span>Contado (cajas cerradas)</span><span style="display:flex;gap:8px;align-items:center"><b class="cx-num">${U.m(ef.contado)}</b>${U.difBadge(ef.diferencia)}</span></div>`
                                : '<div class="cx-led-extra"><span>Contado</span><span class="cx-mute">todavía no cerró ninguna caja</span></div>'}
                        </div></section>
                </div>
                <section class="cx-det-sec"><h3 class="cx-sec-t">Cajas del día</h3>
                    <div class="cx-lista"><div class="cx-row cx-row-hd" aria-hidden="true"><span>Vendedor</span><span>Horario</span><span>Ventas</span><span>Vendido</span><span>Arqueo</span><span></span></div>${filas}</div>
                </section>`}
            </article>`;
        $('cx-volver').addEventListener('click', renderLista);
        cont.querySelectorAll('[data-dia]').forEach((b) => b.addEventListener('click', () => renderDia(b.dataset.dia)));
        $('cx-dia').addEventListener('change', (e) => { if (e.target.value) renderDia(e.target.value); });
        cont.querySelectorAll('[data-ver]').forEach((b) => b.addEventListener('click', () => renderDetalle(Number(b.dataset.ver))));
    }

    window.renderCajas = renderLista;
    window.abrirDetalleCaja = renderDetalle;
    window.renderCierreDia = renderDia;

    // Al recargar estando en Cajas (admin.html#cajas), admin.js puede intentar dibujar
    // la vista antes de que este archivo termine de cargar. Si la vista ya está
    // visible y vacía, la dibujamos ahora.
    const vista = $('vista-cajas');
    if (vista && !vista.classList.contains('oculto') && !$('cajas-cont').children.length) renderLista();

    const btnDia = $('cx-btn-dia');
    if (btnDia) btnDia.addEventListener('click', () => renderDia(hoy()));
    const volver = document.querySelector('#vista-cajas [data-ir-inicio]');
    if (volver) volver.addEventListener('click', () => seleccionar('inicio'));
})();

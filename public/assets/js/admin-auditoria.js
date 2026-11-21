// ===== Auditoría: centro de control (solo admin, solo lectura) =====
// Depende de los globales de admin.js: $, api, esc, toast, money.
// Todo lo que se muestra lo calcula el backend con datos reales. La única acción que
// escribe es "Marcar como revisada" en una alerta (la base no deja deshacerla ni borrarla).

const AU = {
    sec: 'historial', page: 1, iniciado: false, opciones: {}, valores: {}, pila: [],
    modulos: {}, acciones: {}, tipoDe: {},
};

const AU_SECCIONES = {
    historial: 'Todo lo registrado en el sistema: quién, qué, cuándo, desde dónde y qué cambió. Tocá un registro para ver el detalle.',
    alertas:   'Actividades que el sistema detectó automáticamente para revisar. Una alerta no acusa a nadie: señala algo que conviene mirar.',
    ventas:    'Ventas del POS (presenciales) y pedidos de la tienda (online), con su pago, anulación o cancelación. Tocá una para ver su recorrido completo.',
    cajas:     'Cada caja con su arqueo. Diferencia = esperado − declarado. Tocá una caja para ver todos sus movimientos en orden.',
    stock:     'Por qué subió o bajó el stock de cada producto. Las salidas por ventas se separan de los ajustes manuales.',
    usuarios:  'Ingresos, intentos fallidos, accesos sin permiso y cambios sobre las cuentas (rol, estado, contraseña). Nunca se guardan contraseñas.',
};

const AU_NIVEL = { informativa: 'Informativa', advertencia: 'Advertencia', critica: 'Crítica' };
const AU_RESULTADO = { sin_diferencia: 'Sin diferencia', faltante: 'Faltante', sobrante: 'Sobrante', abierta: 'Abierta' };

// Filtros de cada sección. Todos los usa el backend (no hay filtros decorativos).
const AU_FILTROS = {
    historial: [
        { k: 'usuario_id', t: 'select', todos: 'Todos los usuarios', ops: (o) => (o.usuarios || []).map((u) => [u.id, u.nombre]) },
        { k: 'accion', t: 'select', todos: 'Todas las acciones', ops: (o) => (o.acciones || []).map((a) => [a, AU.acciones[a] || a]) },
        { k: 'entidad', t: 'select', todos: 'Todos los módulos', ops: (o) => (o.entidades || []).map((m) => [m, AU.modulos[m] || m]) },
        { k: 'desde', t: 'date', l: 'Desde' }, { k: 'hasta', t: 'date', l: 'Hasta' },
    ],
    alertas: [
        { k: 'nivel', t: 'select', todos: 'Todos los niveles', ops: (o) => Object.entries(o.niveles || {}) },
        { k: 'estado', t: 'select', todos: 'Pendientes y revisadas', ops: () => [['pendiente', 'Pendientes'], ['revisada', 'Revisadas (historial)']] },
        { k: 'tipo', t: 'select', todos: 'Todos los tipos', ops: (o) => Object.entries(o.tipos || {}) },
        { k: 'usuario_id', t: 'select', todos: 'Todas las personas', ops: (o) => (o.usuarios || []).map((u) => [u.id, u.nombre]) },
        { k: 'desde', t: 'date', l: 'Desde' }, { k: 'hasta', t: 'date', l: 'Hasta' },
    ],
    ventas: [
        { k: 'numero', t: 'text', ph: 'N° (V-000123 / P-000045)', w: 150 },
        { k: 'cliente', t: 'text', ph: 'Cliente', w: 130 },
        { k: 'producto', t: 'text', ph: 'Producto o SKU', w: 130 },
        { k: 'tipo', t: 'select', todos: 'Presencial y online', ops: () => [['presencial', 'Presencial (POS)'], ['online', 'Online (tienda)']] },
        { k: 'estado', t: 'select', todos: 'Vigentes y anuladas', ops: () => [['vigente', 'Vigentes'], ['anulada', 'Anuladas / canceladas']] },
        { k: 'usuario_id', t: 'select', todos: 'Todos los vendedores', ops: (o) => (o.usuarios || []).map((u) => [u.id, u.nombre]) },
        { k: 'caja_id', t: 'number', ph: 'Caja N°', w: 90 },
        { k: 'desde', t: 'date', l: 'Desde' }, { k: 'hasta', t: 'date', l: 'Hasta' },
    ],
    cajas: [
        { k: 'caja_id', t: 'number', ph: 'Caja N°', w: 90 },
        { k: 'usuario_id', t: 'select', todos: 'Todos los cajeros', ops: (o) => (o.usuarios || []).map((u) => [u.id, u.nombre]) },
        { k: 'resultado', t: 'select', todos: 'Todos los resultados', ops: (o) => Object.entries(o.resultados || {}) },
        { k: 'desde', t: 'date', l: 'Desde' }, { k: 'hasta', t: 'date', l: 'Hasta' },
    ],
    stock: [
        { k: 'producto', t: 'text', ph: 'Producto o SKU', w: 140 },
        { k: 'numero', t: 'text', ph: 'Documento (V-, P-, OC-)', w: 150 },
        { k: 'tipo', t: 'select', todos: 'Todos los movimientos', ops: (o) => Object.entries(o.tipos || {}) },
        { k: 'revisar', t: 'select', todos: 'Todos', ops: () => [['manual', 'Solo ajustes manuales'], ['inusual', 'Inusuales o repetidos']] },
        { k: 'usuario_id', t: 'select', todos: 'Todos los usuarios', ops: (o) => (o.usuarios || []).map((u) => [u.id, u.nombre]) },
        { k: 'desde', t: 'date', l: 'Desde' }, { k: 'hasta', t: 'date', l: 'Hasta' },
    ],
    usuarios: [
        { k: 'accion', t: 'select', todos: 'Todas las acciones', ops: (o) => Object.entries(o.acciones || {}) },
        { k: 'usuario_id', t: 'select', todos: 'Todos los usuarios', ops: (o) => (o.usuarios || []).map((u) => [u.id, u.nombre]) },
        { k: 'desde', t: 'date', l: 'Desde' }, { k: 'hasta', t: 'date', l: 'Hasta' },
    ],
};
const AU_BUSQUEDA = {
    historial: 'Buscar en descripción, valores, usuario o N° de registro…',
    alertas: 'Buscar en título, descripción o persona…',
    usuarios: 'Buscar por IP, email o detalle…',
};

// Tarjetas del resumen del historial: clave del backend → etiqueta y tipo (color).
const AU_TARJETAS = [
    ['total', 'Registros', ''], ['alta', 'Altas', 'alta'], ['cambio', 'Modificaciones', 'cambio'],
    ['baja', 'Bajas y anulaciones', 'baja'], ['stock', 'Movimientos de stock', 'stock'],
    ['login_fallido', 'Logins fallidos', 'baja'], ['usuarios', 'Usuarios', ''],
];

// ---------- Inicio ----------
function renderAuditoria() {
    if (!AU.iniciado) {
        AU.iniciado = true;
        let deb;
        $('au-q').addEventListener('input', () => { clearTimeout(deb); deb = setTimeout(() => { auGuardar(); AU.page = 1; cargarAuditoria(); }, 300); });
        $('au-tabs').querySelectorAll('[data-s]').forEach((b) => b.addEventListener('click', () => auIrA(b.dataset.s)));
        $('au-pdf').addEventListener('click', () => exportarAuditoria('pdf'));
        $('au-excel').addEventListener('click', () => exportarAuditoria('excel'));
        $('au-integridad').addEventListener('click', auIntegridad);
        $('au-tbody').addEventListener('click', (ev) => {
            const el = ev.target.closest('[data-det]');   // un enlace dentro de la fila gana sobre la fila
            if (el) auAbrir(el.dataset.det, true);
        });
        $('au-tbody').addEventListener('keydown', (ev) => {
            const tr = ev.target.closest('tr[data-det]');
            if (tr && (ev.key === 'Enter' || ev.key === ' ')) { ev.preventDefault(); auAbrir(tr.dataset.det, true); }
        });
        $('au-det-cerrar').addEventListener('click', auCerrarDetalle);
        $('au-det-volver').addEventListener('click', () => { AU.pila.pop(); auAbrir(AU.pila.pop(), false); });
        $('modal-au').addEventListener('click', (ev) => {
            if (ev.target.id === 'modal-au') auCerrarDetalle();
            const ir = ev.target.closest('[data-det]');
            if (ir && ir.closest('#au-det-cuerpo')) auAbrir(ir.dataset.det, false);
        });
        document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && !$('modal-au').classList.contains('oculto')) auCerrarDetalle(); });
        $('au-kpis').addEventListener('click', (ev) => {
            const c = ev.target.closest('[data-ir]');
            if (c) auIrA(c.dataset.ir, JSON.parse(c.dataset.f || '{}'));
        });
    }
    AU.page = 1;
    auKpis();
    auIrA(AU.sec);
}

/** Cambia de sección (opcionalmente con filtros ya puestos, desde una tarjeta). */
function auIrA(sec, filtros) {
    if (AU.sec !== sec) auGuardar();
    AU.sec = sec;
    if (filtros) AU.valores[sec] = { ...filtros };
    AU.page = 1;
    $('au-tabs').querySelectorAll('[data-s]').forEach((b) => {
        b.classList.toggle('activo', b.dataset.s === sec);
        b.setAttribute('aria-current', b.dataset.s === sec ? 'page' : 'false');
    });
    $('au-sub').textContent = AU_SECCIONES[sec];
    $('au-resumen').classList.toggle('oculto', sec !== 'historial');
    $('au-q-wrap').classList.toggle('oculto', !AU_BUSQUEDA[sec]);
    $('au-q').placeholder = AU_BUSQUEDA[sec] || '';
    $('au-q').value = (AU.valores[sec] || {}).q || '';
    auPintarFiltros();
    cargarAuditoria();
}

// ---------- Filtros ----------
function auPintarFiltros() {
    const v = AU.valores[AU.sec] || {};
    $('au-filtros').innerHTML = AU_FILTROS[AU.sec].map((f) => {
        const val = esc(v[f.k] || '');
        if (f.t === 'select') return `<select data-f="${f.k}" aria-label="${esc(f.todos)}"><option value="">${esc(f.todos)}</option></select>`;
        if (f.t === 'date') return `<label class="au-fecha">${f.l} <input data-f="${f.k}" type="date" value="${val}"></label>`;
        return `<input data-f="${f.k}" class="au-input" type="${f.t === 'number' ? 'number' : 'search'}" ${f.t === 'number' ? 'min="1"' : ''}
                    placeholder="${esc(f.ph)}" aria-label="${esc(f.ph)}" value="${val}" style="width:${f.w || 130}px">`;
    }).join('') + '<button id="au-limpiar" class="btn-secundario btn-sm" type="button">Limpiar</button>';

    let deb;
    $('au-filtros').querySelectorAll('[data-f]').forEach((el) => {
        const recargar = () => { auGuardar(); AU.page = 1; cargarAuditoria(); };
        el.addEventListener('change', recargar);
        if (el.tagName === 'INPUT' && el.type !== 'date') el.addEventListener('input', () => { clearTimeout(deb); deb = setTimeout(recargar, 350); });
    });
    $('au-limpiar').addEventListener('click', () => { AU.valores[AU.sec] = {}; $('au-q').value = ''; auPintarFiltros(); AU.page = 1; cargarAuditoria(); });
    auLlenarSelects();
}

// Llena los selects con las opciones que mandó el backend (sin perder lo elegido).
function auLlenarSelects() {
    const v = AU.valores[AU.sec] || {};
    AU_FILTROS[AU.sec].filter((f) => f.t === 'select').forEach((f) => {
        const sel = $('au-filtros').querySelector(`[data-f="${f.k}"]`);
        if (!sel) return;
        const items = f.ops(AU.opciones[AU.sec] || {});
        const actual = sel.value || v[f.k] || '';
        sel.innerHTML = `<option value="">${esc(f.todos)}</option>` + items.map(([val, t]) => `<option value="${esc(val)}">${esc(t)}</option>`).join('');
        sel.value = items.some(([val]) => String(val) === String(actual)) ? actual : '';
    });
}

function auGuardar() {
    const v = { q: $('au-q').value.trim() };
    $('au-filtros').querySelectorAll('[data-f]').forEach((el) => { v[el.dataset.f] = el.value.trim(); });
    AU.valores[AU.sec] = v;
}

function auFiltros() {
    auGuardar();
    return Object.fromEntries(Object.entries(AU.valores[AU.sec] || {}).filter(([, val]) => val !== '' && val != null));
}

// ---------- Carga ----------
async function cargarAuditoria() {
    const err = $('au-error');
    err.classList.add('oculto');
    const sec = AU.sec;
    const params = new URLSearchParams({ ...auFiltros(), page: AU.page, per_page: 20 });
    const url = sec === 'historial' ? '/api/admin/auditoria?' + params : `/api/admin/auditoria/seccion?s=${sec}&` + params;
    let r;
    try { r = await api.get(url); }
    catch (e) { err.textContent = e.message; err.classList.remove('oculto'); return; }
    if (sec !== AU.sec) return;   // el usuario cambió de pestaña mientras cargaba

    if (sec === 'historial') {
        AU.modulos = r.modulos || {};
        AU.acciones = r.acciones || {};
        AU.tipoDe = {};
        Object.entries(r.tipos || {}).forEach(([tipo, lista]) => lista.forEach((a) => { AU.tipoDe[a] = tipo; }));
        auResumen(r.resumen || {});
    }
    AU.opciones[sec] = r.opciones || {};
    auLlenarSelects();
    auTabla(sec, r.data || []);
    auPaginacion(r);
}

async function auKpis() {
    let k;
    try { k = await api.get('/api/admin/auditoria/kpis'); } catch { return; }
    const hoy = auHoy();
    const t = [
        ['Alertas pendientes', k.alertas_pendientes, k.alertas_criticas ? `${k.alertas_criticas} crítica(s)` : 'sin críticas', 'alertas', { estado: 'pendiente' }, k.alertas_criticas ? 'baja' : (k.alertas_pendientes ? 'stock' : '')],
        ['Anulaciones de hoy', k.anulaciones_hoy, 'ventas y pedidos', 'ventas', { estado: 'anulada', desde: hoy }, k.anulaciones_hoy ? 'baja' : ''],
        ['Cajas con diferencia', k.cajas_diferencia_hoy, 'cerradas hoy', 'cajas', { desde: hoy }, k.cajas_diferencia_hoy ? 'stock' : ''],
        ['Actividades sospechosas', k.sospechosas_hoy, 'advertencias y críticas de hoy', 'alertas', { desde: hoy }, k.sospechosas_hoy ? 'baja' : ''],
        ['Ajustes manuales de stock', k.stock_manual_hoy, 'hoy', 'stock', { revisar: 'manual', desde: hoy }, k.stock_manual_hoy ? 'stock' : ''],
        ['Operaciones registradas', k.operaciones_hoy, 'hoy', 'historial', { desde: hoy }, ''],
    ];
    $('au-kpis').innerHTML = t.map(([l, n, pie, sec, f, tipo]) => `
        <button type="button" class="card kpi au-kpi au-kpi-btn ${tipo ? 'au-' + tipo : ''}" data-ir="${sec}" data-f='${esc(JSON.stringify(f))}'>
            <div class="card-label">${l}</div>
            <div class="card-num">${Number(n || 0).toLocaleString('es-AR')}</div>
            <div class="au-kpi-pie">${esc(pie)}</div>
        </button>`).join('');
    const badge = $('au-tab-alertas');
    badge.textContent = k.alertas_pendientes;
    badge.classList.toggle('oculto', !k.alertas_pendientes);
    badge.classList.toggle('au-badge-critica', !!k.alertas_criticas);
}

function auResumen(res) {
    $('au-resumen').innerHTML = AU_TARJETAS.map(([k, label, tipo]) => `
        <div class="card kpi au-kpi ${tipo ? 'au-' + tipo : ''}">
            <div class="card-label">${label}</div>
            <div class="card-num">${Number(res[k] || 0).toLocaleString('es-AR')}</div>
        </div>`).join('');
}

// ---------- Tablas ----------
const auMute = (t = '—') => `<span class="td-mute">${t}</span>`;
const auMoney = (n) => (n === null || n === undefined || n === '') ? auMute() : money.format(Number(n));
const auNivel = (n) => `<span class="au-tag au-nivel-${n}">${AU_NIVEL[n] || n}</span>`;

function auFecha(s) {
    if (!s) return auMute();
    const [d, h] = String(s).split(' ');
    const [y, m, dd] = (d || '').split('-');
    return `<span class="au-dia">${dd}/${m}/${y}</span> <span class="td-mute">${(h || '').slice(0, 5)}</span>`;
}
function auHoy() {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
function auCambio(a, b) {
    if ((a === null || a === undefined) && (b === null || b === undefined)) return auMute();
    return `<span class="au-antes">${a == null ? '—' : esc(a)}</span><span class="au-flecha">→</span><span class="au-despues">${b == null ? '—' : esc(b)}</span>`;
}

const AU_COLUMNAS = {
    historial: ['Fecha y hora', 'Nombre', 'Usuario', 'Acción', 'Módulo', 'Registro', 'Descripción', 'Antes → Después'],
    alertas: ['Fecha', 'Nivel', 'Alerta', 'Persona relacionada', 'Operación que la originó', 'Estado'],
    ventas: ['Fecha', 'N°', 'Tipo', 'Vendedor', 'Cliente', 'Total', 'Método de pago', 'Estado', 'Anulación / cancelación'],
    cajas: ['Caja', 'Cajero', 'Apertura', 'Cierre', 'Inicial', 'Esperado', 'Declarado', 'Esperado − Declarado', 'Resultado', 'Ventas'],
    stock: ['Fecha', 'Producto', 'Movimiento', 'Anterior', 'Cambio', 'Resultante', 'Motivo / documento', 'Usuario', 'Revisión'],
    usuarios: ['Fecha', 'Acción', 'Usuario afectado', 'Realizado por', 'Antes → Después', 'IP', 'Dispositivo'],
};

function auTabla(sec, filas) {
    $('au-thead').innerHTML = '<tr>' + AU_COLUMNAS[sec].map((c) => `<th>${c}</th>`).join('') + '</tr>';
    $('au-vacio').classList.toggle('oculto', filas.length > 0);
    const fila = (det, celdas, cls = '') => `<tr class="au-fila ${cls}" data-det="${det}" tabindex="0" title="Ver detalle">${celdas}</tr>`;
    const render = {
        historial: (a) => {
            const tipo = AU.tipoDe[a.accion] || 'otro';
            const fallido = ['login_fallido', 'acceso_denegado', 'bloqueo_ip'].includes(a.accion) ? ' au-tag-alerta' : '';
            const registro = a.entidad_id === null ? auMute() : `#${esc(a.entidad_id)}${a.referencia ? ` <span class="td-mute">· ${esc(a.referencia)}</span>` : ''}`;
            const desc = [a.campo ? `<strong>${esc(a.campo)}</strong>` : '', a.detalle ? esc(a.detalle) : ''].filter(Boolean).join(' · ') || auMute();
            return fila(`registro:${a.id}`, `
                <td class="au-nowrap">${auFecha(a.creado_en)}</td>
                <td>${a.usuario ? esc(a.usuario) : auMute('Sistema')}</td>
                <td>${a.usuario_email ? esc(a.usuario_email) : auMute()}</td>
                <td><span class="au-tag au-${tipo}${fallido}">${esc(AU.acciones[a.accion] || a.accion)}</span></td>
                <td>${esc(AU.modulos[a.entidad] || a.entidad)}</td>
                <td class="au-nowrap">${registro}</td>
                <td class="au-desc">${desc}</td>
                <td class="au-cambio">${auCambio(a.valor_anterior, a.valor_nuevo)}</td>`);
        },
        alertas: (a) => fila(`alerta:${a.id}`, `
            <td class="au-nowrap">${auFecha(a.creado_en)}</td>
            <td>${auNivel(a.nivel)}</td>
            <td class="au-desc"><strong>${esc(a.titulo)}</strong><div class="rl-sub">${esc(a.descripcion)}</div></td>
            <td>${a.usuario ? esc(a.usuario) : auMute()}</td>
            <td class="au-desc">${a.origen_detalle ? esc(a.origen_detalle) : auMute()}<div class="rl-sub">${a.origen_fecha ? 'Registro #' + esc(a.auditoria_id) : ''}</div></td>
            <td>${Number(a.revisada) ? `<span class="au-tag au-alta">Revisada</span><div class="rl-sub">${esc(a.revisor || '')} · ${auFecha(a.revisada_en)}</div>` : '<span class="au-tag au-stock">Pendiente</span>'}</td>`,
            Number(a.revisada) ? '' : 'au-fila-pendiente'),
        ventas: (v) => {
            const anulada = ['anulada', 'cancelado'].includes(v.estado);
            return fila(`${v.tipo === 'online' ? 'pedido' : 'venta'}:${v.id}`, `
                <td class="au-nowrap">${auFecha(v.fecha)}</td>
                <td class="au-nowrap"><strong>${esc(v.numero)}</strong></td>
                <td>${v.tipo === 'online' ? '<span class="au-tag au-cambio">Online</span>' : '<span class="au-tag au-sesion">Presencial</span>'}</td>
                <td>${v.usuario ? esc(v.usuario) : auMute('Tienda online')}</td>
                <td>${esc(v.cliente)}</td>
                <td class="num">${auMoney(v.total)}</td>
                <td>${v.metodo ? esc(v.metodo) : auMute()}${v.estado_pago ? `<div class="rl-sub">Pago: ${esc(v.estado_pago)}</div>` : ''}</td>
                <td><span class="au-tag ${anulada ? 'au-baja' : 'au-alta'}">${esc(v.estado)}</span></td>
                <td class="au-desc">${anulada ? `${v.motivo ? esc(v.motivo) : ''}<div class="rl-sub">${v.anulo ? esc(v.anulo) + ' · ' : ''}${auFecha(v.anulada_en)}</div>` : auMute()}</td>`);
        },
        cajas: (c) => {
            const dif = c.diferencia === null ? null : -Number(c.diferencia);
            const tag = { sin_diferencia: 'au-alta', faltante: 'au-baja', sobrante: 'au-stock', abierta: 'au-sesion' }[c.resultado];
            return fila(`caja:${c.id}`, `
                <td><strong>#${esc(c.id)}</strong></td><td>${esc(c.usuario)}</td>
                <td class="au-nowrap">${auFecha(c.abierta_en)}</td><td class="au-nowrap">${auFecha(c.cerrada_en)}</td>
                <td class="num">${auMoney(c.monto_apertura)}</td><td class="num">${auMoney(c.monto_esperado)}</td>
                <td class="num">${auMoney(c.monto_contado)}</td>
                <td class="num ${dif > 0.004 ? 'rl-rojo' : ''}">${c.estado === 'abierta' ? auMute() : auMoney(dif)}</td>
                <td><span class="au-tag ${tag}">${AU_RESULTADO[c.resultado]}</span></td>
                <td class="num">${Number(c.ventas)}${Number(c.anuladas) ? `<div class="rl-sub">${c.anuladas} anulada(s)</div>` : ''}</td>`);
        },
        stock: (x) => {
            const dif = Number(x.valor_nuevo) - Number(x.valor_anterior);
            const tipos = (AU.opciones.stock || {}).tipos || {};
            const marcas = [];
            if (x.tipo === 'ajuste') marcas.push('<span class="au-tag au-stock">Manual sin operación</span>');
            if (x.alerta) marcas.push(auNivel(x.alerta));
            if (Number(x.ajustes_mismo_dia) > 1) marcas.push(`<span class="au-tag au-baja">Repetido (${x.ajustes_mismo_dia} en el día)</span>`);
            const doc = x.documento ? `<button type="button" class="volver-link au-link" data-det="${x.documento.tipo === 'compra' ? 'registro:' + x.id : x.documento.tipo + ':' + x.documento.id}">${esc(x.documento.numero)}</button>` : '';
            return fila(`producto:${x.producto_id}`, `
                <td class="au-nowrap">${auFecha(x.creado_en)}</td>
                <td>${x.producto ? esc(x.producto) : '#' + esc(x.producto_id)}${x.sku ? `<div class="rl-sub">${esc(x.sku)}</div>` : ''}</td>
                <td>${esc(tipos[x.tipo] || x.tipo)}</td>
                <td class="num">${esc(x.valor_anterior)}</td>
                <td class="num ${dif < 0 ? 'rl-rojo' : ''}"><strong>${dif > 0 ? '+' : ''}${dif}</strong></td>
                <td class="num">${esc(x.valor_nuevo)}</td>
                <td class="au-desc">${esc(x.detalle || '')} ${doc}</td>
                <td>${x.usuario ? esc(x.usuario) : auMute('Sistema / cliente')}</td>
                <td>${marcas.join(' ') || auMute()}</td>`);
        },
        usuarios: (x) => {
            const acc = ((AU.opciones.usuarios || {}).acciones || {})[x.clase] || x.clase;
            const alerta = ['login_fallido', 'acceso_denegado', 'bloqueo_ip'].includes(x.clase) ? 'au-baja' : (x.clase === 'rol' ? 'au-stock' : 'au-sesion');
            return fila(`registro:${x.id}`, `
                <td class="au-nowrap">${auFecha(x.creado_en)}</td>
                <td><span class="au-tag ${alerta}">${esc(acc)}</span></td>
                <td>${x.afectado ? `${esc(x.afectado)}<div class="rl-sub">${esc(x.afectado_email)}</div>` : auMute(x.clase === 'login_fallido' ? 'Email no registrado' : '—')}</td>
                <td>${x.actor ? esc(x.actor) : auMute('Sin sesión')}</td>
                <td class="au-cambio">${x.valor_anterior !== null || x.valor_nuevo !== null ? auCambio(x.valor_anterior, x.valor_nuevo) : (x.detalle ? `<span class="rl-sub">${esc(x.detalle)}</span>` : auMute())}</td>
                <td class="au-nowrap">${x.ip ? esc(x.ip) : auMute()}</td>
                <td>${esc(x.dispositivo || '—')}</td>`);
        },
    };
    $('au-tbody').innerHTML = filas.map(render[sec]).join('');
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

// ---------- Detalle (panel sin salir de Auditoría) ----------
async function auAbrir(ref, nuevo) {
    if (!ref) return;
    const [tipo, id] = ref.split(':');
    if (nuevo) AU.pila = [];
    AU.pila.push(ref);
    const modal = $('modal-au');
    $('au-det-titulo').textContent = 'Cargando…';
    $('au-det-cuerpo').innerHTML = '<p class="td-mute">Cargando…</p>';
    $('au-det-volver').classList.toggle('oculto', AU.pila.length < 2);
    modal.classList.remove('oculto');
    let r;
    try { r = await api.get(`/api/admin/auditoria/detalle?tipo=${encodeURIComponent(tipo)}&id=${encodeURIComponent(id)}`); }
    catch (e) { $('au-det-titulo').textContent = 'No disponible'; $('au-det-cuerpo').innerHTML = `<p class="au-error">${esc(e.message)}</p>`; return; }
    const d = r.datos;
    const pintar = { registro: auDetRegistro, alerta: auDetAlerta, venta: auDetVenta, pedido: auDetVenta, caja: auDetCaja, producto: auDetProducto }[tipo];
    $('au-det-cuerpo').innerHTML = pintar(d, tipo);
    if (tipo === 'alerta') auCablearRevision(d);
}

function auCerrarDetalle() { $('modal-au').classList.add('oculto'); AU.pila = []; }

const auDato = (l, v) => `<div class="au-dato"><span>${l}</span><div>${v}</div></div>`;
const auTablaMini = (cols, filas) => filas.length
    ? `<div class="tabla-caja au-mini"><table><thead><tr>${cols.map((c) => `<th>${c}</th>`).join('')}</tr></thead><tbody>${filas.join('')}</tbody></table></div>`
    : `<p class="td-mute">Sin registros.</p>`;
const auLink = (ref, txt) => `<button type="button" class="volver-link au-link" data-det="${ref}">${esc(txt)} ›</button>`;

function auHistorial(h) {
    return auTablaMini(['Fecha', 'Acción', 'Antes → Después', 'Detalle', 'Usuario'], (h || []).map((a) => `<tr>
        <td class="au-nowrap">${auFecha(a.creado_en)}</td><td>${esc(AU.acciones[a.accion] || a.accion)}${a.campo ? ` <span class="td-mute">(${esc(a.campo)})</span>` : ''}</td>
        <td class="au-cambio">${auCambio(a.valor_anterior, a.valor_nuevo)}</td><td class="au-desc">${esc(a.detalle || '')}</td>
        <td>${a.usuario ? esc(a.usuario) : auMute('Sistema')}</td></tr>`));
}

function auDetRegistro(a) {
    $('au-det-titulo').textContent = `Registro #${a.id}`;
    const doc = a.documento ? auLink(`${a.documento.tipo === 'compra' ? 'registro:' + a.id : a.documento.tipo + ':' + a.documento.id}`, 'Ver ' + (a.documento.tipo === 'venta' ? 'venta' : a.documento.tipo === 'pedido' ? 'pedido' : a.documento.tipo === 'caja' ? 'caja' : 'documento') + ' ' + a.documento.numero) : '';
    const prod = a.producto ? auLink(`producto:${a.producto.id}`, 'Ver historial del producto') : '';
    return `<div class="au-datos">
        ${auDato('Fecha y hora', auFecha(a.creado_en))}
        ${auDato('Realizado por', a.usuario ? `${esc(a.usuario)}<div class="rl-sub">${esc(a.usuario_email || '')}</div>` : auMute('Sistema / sin sesión'))}
        ${auDato('Acción', esc(AU.acciones[a.accion] || a.accion))}
        ${auDato('Módulo', esc(AU.modulos[a.entidad] || a.entidad))}
        ${auDato('Registro afectado', a.entidad_id === null ? auMute() : '#' + esc(a.entidad_id))}
        ${auDato('Campo', a.campo ? esc(a.campo) : auMute())}
        ${auDato('Antes → Después', auCambio(a.valor_anterior, a.valor_nuevo))}
        ${auDato('IP', a.ip ? esc(a.ip) : auMute())}
        ${auDato('Dispositivo', esc(a.dispositivo || '—'))}
    </div>
    ${auDato('Descripción', a.detalle ? esc(a.detalle) : auMute())}
    <div class="au-det-links">${doc} ${prod}</div>
    ${(a.alertas || []).length ? `<h3>Alertas que generó</h3>` + a.alertas.map((x) => `<p>${auNivel(x.nivel)} ${auLink('alerta:' + x.id, x.titulo)}</p>`).join('') : ''}
    <p class="au-hash">Huella de integridad: <code>${a.hash ? esc(a.hash) : 'registro anterior a la cadena de hashes'}</code></p>`;
}

function auDetAlerta(a) {
    $('au-det-titulo').textContent = 'Alerta';
    const rel = a.entidad && a.entidad_id
        ? ({ venta: auLink('venta:' + a.entidad_id, 'Ver venta'), caja: auLink('caja:' + a.entidad_id, 'Ver caja #' + a.entidad_id),
             producto: auLink('producto:' + a.entidad_id, 'Ver historial del producto') }[a.entidad] || '') : '';
    return `<div class="au-alerta-cab">${auNivel(a.nivel)} <strong>${esc(a.titulo)}</strong></div>
        <p>${esc(a.descripcion)}</p>
        <div class="au-datos">
            ${auDato('Detectada', auFecha(a.creado_en))}
            ${auDato('Persona relacionada', a.usuario ? `${esc(a.usuario)}<div class="rl-sub">${esc(a.usuario_email || '')}</div>` : auMute())}
            ${auDato('Estado', Number(a.revisada) ? `<span class="au-tag au-alta">Revisada</span>` : '<span class="au-tag au-stock">Pendiente</span>')}
        </div>
        <h3>Operación que la originó</h3>
        ${a.origen ? `<p>${auFecha(a.origen.creado_en)} · ${esc(AU.acciones[a.origen.accion] || a.origen.accion)} · ${esc(a.origen.detalle || '')}</p>
            <div class="au-det-links">${auLink('registro:' + a.auditoria_id, 'Ver registro #' + a.auditoria_id)} ${rel}</div>` : auMute()}
        <p class="au-aviso">Una alerta señala una actividad para revisar: no determina que alguien sea responsable de una irregularidad.</p>
        ${Number(a.revisada)
            ? `<h3>Revisión</h3><div class="au-datos">${auDato('Revisó', esc(a.revisor || ''))}${auDato('Cuándo', auFecha(a.revisada_en))}${auDato('Nota', a.nota_revision ? esc(a.nota_revision) : auMute())}</div>`
            : `<h3>Marcar como revisada</h3>
               <textarea id="au-nota" class="au-nota" maxlength="255" rows="2" placeholder="Nota (opcional): qué se verificó o qué se decidió"></textarea>
               <p id="au-rev-error" class="au-error oculto"></p>
               <button type="button" id="au-revisar" class="btn-primary">Marcar como revisada</button>`}`;
}

function auCablearRevision(a) {
    const b = $('au-revisar');
    if (!b) return;
    b.addEventListener('click', async () => {
        b.disabled = true;
        try {
            await api.post('/api/admin/auditoria/alertas/revisar', { id: a.id, nota: $('au-nota').value.trim() });
            toast('✓ Alerta marcada como revisada');
            AU.pila.pop();
            auAbrir('alerta:' + a.id, false);
            auKpis();
            cargarAuditoria();
        } catch (e) {
            $('au-rev-error').textContent = e.message; $('au-rev-error').classList.remove('oculto'); b.disabled = false;
        }
    });
}

function auDetVenta(v, tipo) {
    const online = tipo === 'pedido';
    $('au-det-titulo').textContent = `${online ? 'Pedido online' : 'Venta presencial'} ${v.numero}`;
    const anulada = ['anulada', 'cancelado'].includes(v.estado);
    return `<div class="au-datos">
        ${auDato('Fecha', auFecha(v.fecha))}
        ${auDato('Tipo', online ? 'Online (tienda)' : 'Presencial (POS)')}
        ${auDato('Vendedor', v.usuario ? esc(v.usuario) : auMute('Tienda online'))}
        ${auDato('Cliente', esc(v.cliente))}
        ${auDato('Total', auMoney(v.total))}
        ${auDato('Método de pago', esc(v.metodo || '—') + (v.estado_pago ? `<div class="rl-sub">Pago: ${esc(v.estado_pago)}</div>` : ''))}
        ${auDato('Estado', `<span class="au-tag ${anulada ? 'au-baja' : 'au-alta'}">${esc(v.estado)}</span>`)}
        ${v.caja_id ? auDato('Caja', auLink('caja:' + v.caja_id, 'Caja #' + v.caja_id)) : ''}
    </div>
    ${anulada ? `<div class="au-anulacion"><strong>${online ? 'Cancelación' : 'Anulación'}</strong>
        ${v.motivo ? `· Motivo: ${esc(v.motivo)}` : ''} · ${v.anulo ? 'Autorizó: ' + esc(v.anulo) : 'Usuario no registrado'} · ${auFecha(v.anulada_en)}</div>` : ''}
    <h3>Productos</h3>
    ${auTablaMini(['Producto', 'Cant.', 'Precio', 'Subtotal'], (v.lineas || []).map((l) => `<tr><td>${esc(l.producto)}${l.estado === 'sobre_pedido' ? ' <span class="td-mute">(sobre pedido)</span>' : ''}</td>
        <td class="num">${l.cantidad}</td><td class="num">${auMoney(l.precio_unitario)}</td><td class="num">${auMoney(l.subtotal)}</td></tr>`))}
    <h3>Pago</h3>
    ${auTablaMini(['Método', 'Importe', 'Estado'], (v.pagos || []).map((p) => `<tr><td>${esc(p.metodo || '—')}</td><td class="num">${auMoney(p.monto)}</td><td>${esc(p.estado || (anulada ? 'anulado con la venta' : 'cobrado'))}</td></tr>`))}
    ${v.envio ? `<h3>Envío</h3><p>${esc(v.envio.estado)} · ${esc(v.envio.direccion || '')} ${esc(v.envio.localidad || '')} · ${auMoney(v.envio.costo)}${v.envio.repartidor ? ' · ' + esc(v.envio.repartidor) : ''}</p>` : ''}
    <h3>Movimientos de stock</h3>
    ${auTablaMini(['Fecha', 'Producto', 'Anterior → Resultante', 'Motivo', 'Usuario'], (v.stock || []).map((s) => `<tr><td class="au-nowrap">${auFecha(s.creado_en)}</td>
        <td>${esc(s.producto || '')}</td><td class="au-cambio">${auCambio(s.valor_anterior, s.valor_nuevo)}</td><td>${esc(s.detalle || '')}</td><td>${s.usuario ? esc(s.usuario) : auMute('Sistema / cliente')}</td></tr>`))}
    ${(v.stock_inventario || []).length && !(v.stock || []).length ? `<p class="td-mute">Movimientos en el inventario del sistema (anteriores a la auditoría):</p>` + auTablaMini(['Fecha', 'Producto', 'Tipo', 'Cantidad', 'Motivo'],
        v.stock_inventario.map((s) => `<tr><td>${auFecha(s.creado_en)}</td><td>${esc(s.producto)}</td><td>${esc(s.tipo)}</td><td class="num">${s.cantidad}</td><td>${esc(s.motivo || '')}</td></tr>`)) : ''}
    <h3>Historial de la operación</h3>
    ${auHistorial(v.historial)}
    <p class="au-aviso">Los registros históricos no se modifican al anular: la anulación se agrega como un registro nuevo.</p>`;
}

function auDetCaja(c) {
    $('au-det-titulo').textContent = `Caja #${c.id} · ${c.usuario}`;
    const dif = c.diferencia === null ? null : -Number(c.diferencia);
    const tag = { sin_diferencia: 'au-alta', faltante: 'au-baja', sobrante: 'au-stock', abierta: 'au-sesion' }[c.resultado];
    return `<div class="au-datos">
        ${auDato('Apertura', auFecha(c.abierta_en))}
        ${auDato('Cierre', auFecha(c.cerrada_en))}
        ${auDato('Dinero inicial', auMoney(c.monto_apertura))}
        ${auDato('Esperado (efectivo)', auMoney(c.monto_esperado))}
        ${auDato('Declarado', auMoney(c.monto_contado))}
        ${auDato('Esperado − Declarado', c.estado === 'abierta' ? auMute() : auMoney(dif))}
        ${auDato('Resultado', `<span class="au-tag ${tag}">${AU_RESULTADO[c.resultado]}</span>`)}
        ${auDato('Observación', c.observacion ? esc(c.observacion) : auMute())}
    </div>
    <h3>Movimientos en orden cronológico</h3>
    ${auTablaMini(['Fecha', 'Movimiento', 'Importe', 'Método de pago', 'Descripción', 'Usuario', 'Saldo anterior', 'Saldo posterior'],
        (c.movimientos || []).map((m) => `<tr class="${m.posterior_al_cierre ? 'au-posterior' : ''}">
            <td class="au-nowrap">${auFecha(m.fecha)}</td><td>${esc(m.tipo_txt)}</td>
            <td class="num ${m.importe < 0 ? 'rl-rojo' : ''}">${auMoney(m.importe)}</td><td>${esc(m.metodo)}</td>
            <td class="au-desc">${esc(m.descripcion)}${m.ref ? ' ' + auLink(m.ref.tipo + ':' + m.ref.id, m.ref.numero) : ''}${m.posterior_al_cierre ? '<div class="rl-sub">Posterior al cierre: no entró en este arqueo</div>' : ''}</td>
            <td>${m.usuario ? esc(m.usuario) : auMute()}</td>
            <td class="num">${auMoney(m.saldo_anterior)}</td><td class="num"><strong>${auMoney(m.saldo_posterior)}</strong></td></tr>`))}
    <p class="au-aviso">Saldos de efectivo reconstruidos con la regla del arqueo: inicial + ventas en efectivo − anuladas + ingresos − retiros.
        Solo lectura: el historial de una caja no se puede modificar desde Auditoría.</p>
    <h3>Registros de auditoría de la caja</h3>
    ${auHistorial(c.historial)}`;
}

function auDetProducto(p) {
    $('au-det-titulo').textContent = p.nombre;
    const tipos = (AU.opciones.stock || {}).tipos || {};
    return `<div class="au-datos">
        ${auDato('SKU', p.sku ? esc(p.sku) : auMute())}
        ${auDato('Código de barras', p.codigo_barras ? esc(p.codigo_barras) : auMute())}
        ${auDato('Stock actual', `<strong>${esc(p.stock_actual)}</strong>`)}
        ${auDato('Stock mínimo', esc(p.stock_minimo ?? '—'))}
        ${auDato('Estado', Number(p.activo) ? 'Activo' : 'Dado de baja')}
    </div>
    <h3>Movimientos registrados en auditoría</h3>
    ${auTablaMini(['Fecha', 'Movimiento', 'Anterior', 'Cambio', 'Resultante', 'Motivo', 'Usuario'], (p.movimientos || []).map((x) => {
        const dif = Number(x.valor_nuevo) - Number(x.valor_anterior);
        return `<tr><td class="au-nowrap">${auFecha(x.creado_en)}</td><td>${esc(tipos[x.tipo] || x.tipo)}${x.tipo === 'ajuste' ? ' <span class="au-tag au-stock">manual</span>' : ''}</td>
            <td class="num">${esc(x.valor_anterior)}</td><td class="num ${dif < 0 ? 'rl-rojo' : ''}">${dif > 0 ? '+' : ''}${dif}</td><td class="num">${esc(x.valor_nuevo)}</td>
            <td>${esc(x.detalle || '')}${x.documento && x.documento.tipo !== 'compra' ? ' ' + auLink(x.documento.tipo + ':' + x.documento.id, x.documento.numero) : ''}</td>
            <td>${x.usuario ? esc(x.usuario) : auMute('Sistema / cliente')}</td></tr>`;
    }))}
    <h3>Historial del inventario del sistema</h3>
    ${auTablaMini(['Fecha', 'Tipo', 'Cantidad', 'Motivo', 'Venta', 'Usuario'], (p.inventario || []).map((m) => `<tr><td class="au-nowrap">${auFecha(m.creado_en)}</td>
        <td>${esc(m.tipo)}</td><td class="num">${m.cantidad}</td><td>${esc(m.motivo || '')}</td><td>${m.venta ? esc(m.venta) : auMute()}</td><td>${m.usuario ? esc(m.usuario) : auMute()}</td></tr>`))}`;
}

// ---------- Integridad ----------
async function auIntegridad() {
    const b = $('au-integridad');
    b.disabled = true;
    let r;
    try { r = await api.get('/api/admin/auditoria/integridad'); }
    catch (e) { toast('⚠ ' + e.message); b.disabled = false; return; }
    b.disabled = false;
    AU.pila = [];
    $('au-det-volver').classList.add('oculto');
    $('au-det-titulo').textContent = 'Integridad del historial';
    $('au-det-cuerpo').innerHTML = !r.disponible
        ? '<p class="au-error">Falta aplicar database/schema_auditoria.sql en esta base.</p>'
        : `<div class="au-alerta-cab">${r.ok ? '<span class="au-tag au-alta">Sin alteraciones detectadas</span>' : '<span class="au-tag au-baja">Se detectaron problemas</span>'}</div>
           <div class="au-datos">
               ${auDato('Registros verificados', Number(r.verificadas).toLocaleString('es-AR'))}
               ${auDato('Desde el registro', r.desde_id ? '#' + r.desde_id : auMute())}
               ${auDato('Anteriores a la cadena', Number(r.sin_hash).toLocaleString('es-AR') + ' (sin huella)')}
               ${auDato('Firma con clave', r.con_clave ? 'Sí (HMAC)' : '<span class="rl-rojo">No: falta AUDIT_HMAC_KEY en .env</span>')}
               ${auDato('Contenido alterado', r.alteradas.length ? r.alteradas.map((i) => auLink('registro:' + i, '#' + i)).join(' ') : 'Ninguno')}
               ${auDato('Enlaces rotos (filas borradas)', r.enlaces_rotos.length ? r.enlaces_rotos.map((i) => '#' + i).join(', ') : 'Ninguno')}
           </div>
           ${r.ultima ? `<p class="au-hash">Huella actual (anotala o guardala fuera del servidor para comparar después):<br>
               <code>#${r.ultima.id} · ${esc(r.ultima.fecha)} · ${esc(r.ultima.hash)}</code></p>` : ''}
           <p class="au-aviso">Qué garantiza: si alguien cambia el contenido de un registro o borra uno del medio, la verificación lo marca.
               Qué no garantiza: no detecta que se borren los últimos registros, ni a alguien que tenga la clave HMAC y acceso a la base
               y recalcule toda la cadena. Por eso conviene guardar la huella actual y la clave fuera del servidor, y tener copias de seguridad.</p>`;
    $('modal-au').classList.remove('oculto');
}

// ---------- Exportación (respeta la sección y los filtros elegidos) ----------
async function exportarAuditoria(formato) {
    const btn = $(formato === 'pdf' ? 'au-pdf' : 'au-excel');
    const txt = btn.textContent;
    btn.disabled = true; btn.textContent = 'Generando…';
    try {
        const params = new URLSearchParams(auFiltros());
        const url = AU.sec === 'historial'
            ? `/api/admin/auditoria/${formato}?` + params
            : `/api/admin/auditoria/seccion/${formato}?s=${AU.sec}&` + params;
        const resp = await fetch(url, { credentials: 'same-origin' });
        const tipo = resp.headers.get('Content-Type') || '';
        if (!resp.ok || tipo.includes('application/json')) {
            let msg = 'No se pudo exportar.';
            try { msg = (await resp.json()).error || msg; } catch {}
            throw new Error(msg);
        }
        const nombre = (resp.headers.get('Content-Disposition') || '').match(/filename="?([^"]+)"?/)?.[1]
            || `auditoria.${formato === 'pdf' ? 'pdf' : 'xlsx'}`;
        const blobUrl = URL.createObjectURL(await resp.blob());
        const a = document.createElement('a');
        a.href = blobUrl; a.download = nombre; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(blobUrl), 2000);
        toast('✓ Descargado ' + nombre);
        auKpis();   // la exportación quedó registrada como operación
    } catch (e) {
        toast('⚠ ' + e.message);
    } finally {
        btn.disabled = false; btn.textContent = txt;
    }
}

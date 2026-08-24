// =====================================================================
// Panel admin — framework de ABM reutilizable.
// Una sola lógica (tabla + búsqueda + filtros + paginación + modal)
// que se configura por entidad en ENTIDADES. Consume la API /api/admin/*.
// =====================================================================

const money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const oMenos = (v) => (v === null || v === '' || v === undefined) ? '<span class="td-mute">—</span>' : esc(v);
// Miniatura para columnas de imagen (toma la primera URL si vinieran varias).
const thumbImg = (v) => v ? `<img class="td-thumb" src="${esc(String(v).split('\n')[0])}" alt="">` : '<span class="td-mute">—</span>';
const badgeEstado = (v) => Number(v) === 1
    ? '<span class="badge ok">Activo</span>'
    : '<span class="badge off">Inactivo</span>';

// Estado del cliente considerando la verificación de correo de la tienda:
// - dado de baja → Inactivo
// - registrado en la tienda pero sin verificar el correo → Sin verificar
// - resto (verificado, o contacto cargado por el admin sin cuenta web) → Activo
const badgeEstadoCliente = (v, row) => {
    if (Number(row.activo) !== 1) return '<span class="badge off">Inactivo</span>';
    if (Number(row.email_verificado) !== 1 && row.email) return '<span class="badge pend">Sin verificar</span>';
    return '<span class="badge ok">Activo</span>';
};

// Opciones dinámicas (categorías/marcas/proveedores) cacheadas.
const cacheOpciones = {};
async function opcionesDe(entidad) {
    if (cacheOpciones[entidad]) return cacheOpciones[entidad];
    const r = await api.get(`/api/admin/${entidad}?per_page=200&activo=1`);
    cacheOpciones[entidad] = r.data.map((x) => ({ v: x.id, t: x.nombre }));
    return cacheOpciones[entidad];
}

// ---- Configuración por entidad ----
const LISTAS = [{ v: 1, t: 'Minorista' }, { v: 2, t: 'Mayorista' }];

const ENTIDADES = {
    clientes: {
        titulo: 'Clientes', sub: 'Alta y gestión de clientes con sus datos de contacto.',
        singular: 'cliente', endpoint: '/api/admin/clientes',
        columnas: [
            { key: 'nombre', label: 'Nombre', clase: 'td-fuerte' },
            { key: 'documento', label: 'Documento', render: oMenos },
            { key: 'email', label: 'Email', render: oMenos },
            { key: 'telefono', label: 'Teléfono', render: oMenos },
            { key: 'localidad', label: 'Localidad', render: oMenos },
            { key: 'lista', label: 'Lista', render: (v) => `<span class="chip">${esc(v)}</span>` },
            { key: 'activo', label: 'Estado', render: badgeEstadoCliente },
        ],
        filtros: [
            { key: 'activo', label: 'Estado', opciones: [{ v: '', t: 'Todos' }, { v: '1', t: 'Activos' }, { v: '0', t: 'Inactivos' }] },
            { key: 'lista_precio_id', label: 'Lista', opciones: [{ v: '', t: 'Todas' }, { v: '1', t: 'Minorista' }, { v: '2', t: 'Mayorista' }] },
        ],
        campos: [
            { key: 'nombre', label: 'Nombre', tipo: 'text', req: true, ancho: true },
            { key: 'documento', label: 'Documento', tipo: 'text' },
            { key: 'email', label: 'Email', tipo: 'email' },
            { key: 'telefono', label: 'Teléfono', tipo: 'text' },
            { key: 'direccion', label: 'Dirección', tipo: 'text', ancho: true },
            { key: 'localidad', label: 'Localidad', tipo: 'text' },
            { key: 'lista_precio_id', label: 'Lista de precios', tipo: 'select', req: true, opciones: LISTAS },
            { key: 'activo', label: 'Activo', tipo: 'check', def: true },
        ],
    },

    productos: {
        titulo: 'Productos', sub: 'Alta de productos con precio, categoría, marca, proveedor y stock inicial.',
        singular: 'producto', endpoint: '/api/admin/productos', detalle: '/api/admin/productos/detalle',
        columnas: [
            { key: 'nombre', label: 'Producto', clase: 'td-fuerte' },
            { key: 'sku', label: 'SKU', render: oMenos },
            { key: 'categoria', label: 'Categoría', render: oMenos },
            { key: 'marca', label: 'Marca', render: oMenos },
            { key: 'precio_minorista', label: 'Precio', num: true, render: (v) => v === null ? '—' : money.format(v) },
            { key: 'stock', label: 'Stock', num: true, render: (v) => `<span class="td-fuerte">${Number(v)}</span>` },
            { key: 'activo', label: 'Estado', render: badgeEstado },
        ],
        filtros: [
            { key: 'activo', label: 'Estado', opciones: [{ v: '', t: 'Todos' }, { v: '1', t: 'Activos' }, { v: '0', t: 'Inactivos' }] },
            { key: 'categoria_id', label: 'Categoría', origen: 'categorias' },
            { key: 'marca_id', label: 'Marca', origen: 'marcas' },
            { key: 'proveedor_id', label: 'Proveedor', origen: 'proveedores' },
        ],
        campos: [
            { key: 'nombre', label: 'Nombre', tipo: 'text', req: true, ancho: true },
            { key: 'descripcion', label: 'Descripción / especificaciones', tipo: 'textarea', ancho: true },
            { key: 'imagenes', label: 'Imágenes', tipo: 'imagenes', ancho: true },
            { key: 'sku', label: 'SKU', tipo: 'text' },
            { key: 'codigo_barras', label: 'Código de barras', tipo: 'text' },
            { key: 'categoria_id', label: 'Categoría', tipo: 'select', origen: 'categorias', vacio: '(sin categoría)' },
            { key: 'marca_id', label: 'Marca', tipo: 'select', origen: 'marcas', vacio: '(sin marca)' },
            { key: 'proveedor_id', label: 'Proveedor', tipo: 'select', origen: 'proveedores', vacio: '(sin proveedor)' },
            { key: 'precio_minorista', label: 'Precio minorista', tipo: 'number', req: true },
            { key: 'precio_mayorista', label: 'Precio mayorista', tipo: 'number' },
            { key: 'precio_anterior', label: 'Precio anterior (oferta)', tipo: 'number' },
            { key: 'stock', label: 'Stock', tipo: 'number', req: true, def: 0 },
            { key: 'min_mayorista', label: 'Cant. mínima mayorista', tipo: 'number', def: 1 },
            { key: 'es_sobre_pedido', label: 'Se vende sobre pedido (sin stock)', tipo: 'check' },
            { key: 'activo', label: 'Activo', tipo: 'check', def: true },
        ],
    },

    proveedores: {
        titulo: 'Proveedores', sub: 'Alta y gestión de proveedores.',
        singular: 'proveedor', endpoint: '/api/admin/proveedores',
        columnas: [
            { key: 'nombre', label: 'Nombre', clase: 'td-fuerte' },
            { key: 'cuit', label: 'CUIT', render: oMenos },
            { key: 'email', label: 'Email', render: oMenos },
            { key: 'telefono', label: 'Teléfono', render: oMenos },
            { key: 'activo', label: 'Estado', render: badgeEstado },
        ],
        filtros: [
            { key: 'activo', label: 'Estado', opciones: [{ v: '', t: 'Todos' }, { v: '1', t: 'Activos' }, { v: '0', t: 'Inactivos' }] },
        ],
        campos: [
            { key: 'nombre', label: 'Nombre', tipo: 'text', req: true, ancho: true },
            { key: 'cuit', label: 'CUIT', tipo: 'text' },
            { key: 'email', label: 'Email', tipo: 'email' },
            { key: 'telefono', label: 'Teléfono', tipo: 'text' },
            { key: 'direccion', label: 'Dirección', tipo: 'text', ancho: true },
            { key: 'activo', label: 'Activo', tipo: 'check', def: true },
        ],
    },

    categorias: { ...maestraSimple('categorias', 'Categorías', 'categoría', 'Clasificación de los productos. La imagen se usa en el carrusel de categorías de la tienda.', { imagen: true }) },
    marcas: { ...maestraSimple('marcas', 'Marcas', 'marca', 'Marcas de los productos.') },

    gastos: {
        titulo: 'Gastos', sub: 'Finanzas del negocio. Si cargás producto + cantidad, esa compra SUMA stock al inventario (al crear el gasto).',
        singular: 'gasto', endpoint: '/api/admin/gastos',
        columnas: [
            { key: 'fecha', label: 'Fecha', render: (v) => `<span class="td-mute">${esc(v)}</span>` },
            { key: 'concepto', label: 'Concepto', clase: 'td-fuerte' },
            { key: 'proveedor', label: 'Proveedor', render: oMenos },
            { key: 'producto', label: 'Suma stock', render: (v, row) => row.producto
                ? `${esc(row.producto)} <span class="chip">×${Number(row.cantidad)}</span>`
                : '<span class="td-mute">—</span>' },
            { key: 'monto', label: 'Monto', num: true, render: (v) => money.format(v) },
            { key: 'activo', label: 'Estado', render: badgeEstado },
        ],
        filtros: [
            { key: 'activo', label: 'Estado', opciones: [{ v: '', t: 'Todos' }, { v: '1', t: 'Activos' }, { v: '0', t: 'Anulados' }] },
            { key: 'proveedor_id', label: 'Proveedor', origen: 'proveedores' },
        ],
        campos: [
            { key: 'fecha', label: 'Fecha', tipo: 'date', req: true },
            { key: 'concepto', label: 'Concepto (qué se compró / pagó)', tipo: 'text', req: true, ancho: true },
            { key: 'proveedor_id', label: 'Proveedor', tipo: 'select', origen: 'proveedores', vacio: '(sin proveedor)' },
            { key: 'producto_id', label: 'Producto (opcional: compra de stock)', tipo: 'select', origen: 'productos', vacio: '(no suma stock)' },
            { key: 'cantidad', label: 'Cantidad comprada (suma al stock)', tipo: 'number' },
            { key: 'monto', label: 'Monto', tipo: 'number', req: true },
            { key: 'observacion', label: 'Observación', tipo: 'textarea', ancho: true },
            { key: 'activo', label: 'Activo', tipo: 'check', def: true },
        ],
    },

    pedidos: {
        titulo: 'Pedidos', sub: 'Pedidos de la tienda online. Cambiá el estado a medida que los gestionás.',
        singular: 'pedido', endpoint: '/api/admin/pedidos', detalle: '/api/admin/pedidos/detalle',
        noCrear: true, acciones: ['ver'],
        columnas: [
            { key: 'numero', label: 'N°', clase: 'td-fuerte' },
            { key: 'cliente', label: 'Cliente' },
            { key: 'items', label: 'Ítems', num: true },
            { key: 'total', label: 'Total', num: true, render: (v) => money.format(v) },
            { key: 'creado_en', label: 'Fecha', render: (v) => `<span class="td-mute">${esc(v)}</span>` },
            { key: 'estado', label: 'Estado', render: renderEstadoInline },
        ],
        filtros: [
            { key: 'estado', label: 'Estado', opciones: [
                { v: '', t: 'Todos' }, { v: 'pendiente', t: 'Pendiente' }, { v: 'preparando', t: 'Preparando' },
                { v: 'entregado', t: 'Entregado' }, { v: 'cancelado', t: 'Cancelado' },
            ] },
        ],
    },

    solicitudes: {
        titulo: 'Solicitudes mayoristas', sub: 'Clientes que piden acceso a precios mayoristas. Aprobá o rechazá.',
        singular: 'solicitud', endpoint: '/api/admin/solicitudes', noCrear: true,
        columnas: [
            { key: 'cliente', label: 'Cliente', clase: 'td-fuerte' },
            { key: 'email', label: 'Email', render: (v) => `<span class="td-mute">${esc(v)}</span>` },
            { key: 'mensaje', label: 'Mensaje', render: oMenos },
            { key: 'creado_en', label: 'Fecha', render: (v) => `<span class="td-mute">${esc(v)}</span>` },
            { key: 'estado', label: 'Estado', render: renderBadgeSolic },
        ],
        filtros: [
            { key: 'estado', label: 'Estado', opciones: [
                { v: '', t: 'Todas' }, { v: 'pendiente', t: 'Pendientes' },
                { v: 'aprobada', t: 'Aprobadas' }, { v: 'rechazada', t: 'Rechazadas' },
            ] },
        ],
        accionesFn: (row) => row.estado !== 'pendiente' ? '' :
            `<button class="btn-fila aprobar" data-accion="aprobar" data-id="${row.id}">Aprobar</button>
             <button class="btn-fila peligro" data-accion="rechazar" data-id="${row.id}">Rechazar</button>`,
    },

    envios: {
        titulo: 'Empresas de envío', sub: 'Medios de envío y su costo base para el checkout.',
        singular: 'empresa de envío', endpoint: '/api/admin/empresas-envio',
        columnas: [
            { key: 'nombre', label: 'Nombre', clase: 'td-fuerte' },
            { key: 'costo_base', label: 'Costo', num: true, render: (v) => Number(v) === 0 ? 'Gratis' : money.format(v) },
            { key: 'activo', label: 'Estado', render: badgeEstado },
        ],
        filtros: [
            { key: 'activo', label: 'Estado', opciones: [{ v: '', t: 'Todos' }, { v: '1', t: 'Activos' }, { v: '0', t: 'Inactivos' }] },
        ],
        campos: [
            { key: 'nombre', label: 'Nombre', tipo: 'text', req: true, ancho: true },
            { key: 'costo_base', label: 'Costo base', tipo: 'number', req: true, def: 0 },
            { key: 'url_tracking', label: 'URL de seguimiento (usá {tracking} donde va el nº)', tipo: 'text', ancho: true },
            { key: 'es_retiro', label: 'Es retiro en local (sin dirección)', tipo: 'check' },
            { key: 'activo', label: 'Activo', tipo: 'check', def: true },
        ],
    },
};

const ESTADOS_ENVIO_ADMIN = ['pendiente', 'despachado', 'en_camino', 'entregado', 'cancelado'];

const ESTADOS_PEDIDO = ['pendiente', 'preparando', 'entregado', 'cancelado'];
function renderBadgeSolic(v) {
    const clase = v === 'aprobada' ? 'ok' : (v === 'rechazada' ? 'off' : 'pend');
    return `<span class="badge ${clase}">${esc(v)}</span>`;
}
function renderEstadoInline(valor, row) {
    const ops = ESTADOS_PEDIDO.map((e) =>
        `<option value="${e}" ${e === valor ? 'selected' : ''}>${e}</option>`).join('');
    return `<select class="estado-inline" data-id="${row.id}">${ops}</select>`;
}

// Config común de las maestras simples (id + nombre + activo).
function maestraSimple(endpoint, titulo, singular, sub, opts = {}) {
    return {
        titulo, sub, singular, endpoint: `/api/admin/${endpoint}`,
        columnas: [
            { key: 'nombre', label: 'Nombre', clase: 'td-fuerte' },
            ...(opts.imagen ? [{ key: 'imagen', label: 'Imagen', render: thumbImg }] : []),
            { key: 'activo', label: 'Estado', render: badgeEstado },
        ],
        filtros: [
            { key: 'activo', label: 'Estado', opciones: [{ v: '', t: 'Todos' }, { v: '1', t: 'Activos' }, { v: '0', t: 'Inactivos' }] },
        ],
        campos: [
            { key: 'nombre', label: 'Nombre', tipo: 'text', req: true, ancho: true },
            ...(opts.imagen ? [{ key: 'imagen', label: 'Imagen (para el carrusel de la tienda) — subí o pegá una', tipo: 'imagenes' }] : []),
            { key: 'activo', label: 'Activo', tipo: 'check', def: true },
        ],
    };
}

// ---- Estado ----
let entActual = null;   // clave de ENTIDADES
let cfg = null;         // config actual
let page = 1;
let q = '';
let filtros = {};       // {key: valor}
let filasActuales = []; // filas de la última carga (para el botón Editar)
let imagenesForm = [];  // URLs de imágenes del producto en edición (widget de subida)

// ---- Elementos ----
const $ = (id) => document.getElementById(id);
const vistaAbm = $('vista-abm'), vistaInicio = $('vista-inicio');

// ---- Navegación ----
async function seleccionar(ent) {
    document.querySelectorAll('.nav-item').forEach((b) => b.classList.toggle('activo', b.dataset.ent === ent));
    const vistaBloques = $('vista-bloques'), vistaEmpleados = $('vista-empleados');
    const ocultarTodo = () => { vistaAbm.classList.add('oculto'); vistaInicio.classList.add('oculto');
        vistaBloques.classList.add('oculto'); vistaEmpleados.classList.add('oculto'); };
    if (ent === 'inicio') {
        ocultarTodo(); vistaInicio.classList.remove('oculto');
        return renderInicio();
    }
    if (ent === 'bloques') {
        ocultarTodo(); vistaBloques.classList.remove('oculto');
        return renderBloques();   // en admin-bloques.js
    }
    if (ent === 'empleados') {
        ocultarTodo(); vistaEmpleados.classList.remove('oculto');
        return renderEmpleados(); // en admin-empleados.js
    }
    entActual = ent; cfg = ENTIDADES[ent];
    page = 1; q = ''; filtros = {};
    $('q').value = '';
    $('ent-title').textContent = cfg.titulo;
    $('ent-sub').textContent = cfg.sub;
    $('btn-nuevo').classList.toggle('oculto', !!cfg.noCrear);
    vistaInicio.classList.add('oculto'); vistaBloques.classList.add('oculto'); vistaEmpleados.classList.add('oculto');
    vistaAbm.classList.remove('oculto');
    await renderFiltros();
    renderThead();
    await cargar();
}

// ---- Toolbar: filtros ----
async function renderFiltros() {
    const cont = $('filtros'); cont.innerHTML = '';
    for (const f of cfg.filtros) {
        const sel = document.createElement('select');
        sel.dataset.key = f.key;
        let ops = f.opciones;
        if (f.origen) ops = [{ v: '', t: `Todas (${f.label})` }, ...(await opcionesDe(f.origen))];
        sel.innerHTML = ops.map((o) => `<option value="${esc(o.v)}">${esc(o.t)}</option>`).join('');
        sel.addEventListener('change', () => {
            const v = sel.value;
            if (v === '') delete filtros[f.key]; else filtros[f.key] = v;
            page = 1; cargar();
        });
        cont.appendChild(sel);
    }
}

// ---- Tabla ----
function renderThead() {
    $('thead').innerHTML = '<tr>' +
        cfg.columnas.map((c) => `<th class="${c.num ? 'num' : ''}">${esc(c.label)}</th>`).join('') +
        '<th></th></tr>';
}

async function cargar() {
    const params = new URLSearchParams({ page, per_page: 10 });
    if (q) params.set('q', q);
    for (const k in filtros) params.set(k, filtros[k]);
    const r = await api.get(`${cfg.endpoint}?${params}`);
    renderFilas(r.data);
    renderPaginacion(r);
}

function renderFilas(filas) {
    filasActuales = filas;
    const tbody = $('tbody');
    $('vacio').classList.toggle('oculto', filas.length > 0);
    tbody.innerHTML = filas.map((row) => {
        const celdas = cfg.columnas.map((c) => {
            const val = c.render ? c.render(row[c.key], row) : esc(row[c.key]);
            return `<td class="${c.num ? 'num' : ''} ${c.clase || ''}">${val}</td>`;
        }).join('');
        let botones;
        if (cfg.accionesFn) {
            botones = cfg.accionesFn(row);
        } else {
            const acc = cfg.acciones || ['editar', 'baja'];
            botones = acc.map((a) => {
                if (a === 'editar') return `<button class="btn-fila" data-accion="editar" data-id="${row.id}">Editar</button>`;
                if (a === 'baja')   return `<button class="btn-fila peligro" data-accion="baja" data-id="${row.id}" data-nombre="${esc(row.nombre)}">Baja</button>`;
                if (a === 'ver')    return `<button class="btn-fila" data-accion="ver" data-id="${row.id}" data-numero="${esc(row.numero)}">Ver</button>`;
                return '';
            }).join('');
        }
        return `<tr>${celdas}<td class="acciones">${botones}</td></tr>`;
    }).join('');
}

// ---- Paginación ----
function renderPaginacion(r) {
    const cont = $('paginacion');
    const desde = r.total === 0 ? 0 : (r.page - 1) * r.per_page + 1;
    const hasta = Math.min(r.page * r.per_page, r.total);
    const btn = (etq, destino, { activo = false, disabled = false } = {}) =>
        `<button class="pag-btn ${activo ? 'activo' : ''}" data-page="${destino}" ${disabled ? 'disabled' : ''}>${etq}</button>`;

    // ventana de hasta 5 números centrada en la página actual
    const tp = r.total_pages;
    let ini = Math.max(1, r.page - 2), fin = Math.min(tp, ini + 4);
    ini = Math.max(1, fin - 4);
    let nums = '';
    for (let p = ini; p <= fin; p++) nums += btn(p, p, { activo: p === r.page });

    const sumaTxt = r.suma !== undefined ? ` · <strong>Total: ${money.format(r.suma)}</strong>` : '';
    cont.innerHTML = `
        <span class="pag-info">${desde}–${hasta} de ${r.total}${sumaTxt}</span>
        <div class="pag-botones">
            ${btn('‹', r.page - 1, { disabled: r.page <= 1 })}
            ${nums}
            ${btn('›', r.page + 1, { disabled: r.page >= tp })}
        </div>`;
    cont.querySelectorAll('.pag-btn').forEach((b) => b.addEventListener('click', () => {
        if (b.disabled) return;
        page = Number(b.dataset.page); cargar();
    }));
}

// ---- Modal (crear / editar) ----
async function abrirModal(modo, row = null) {
    $('modal-title').textContent = (modo === 'editar' ? 'Editar ' : 'Nuevo ') + cfg.singular;
    $('form-error').classList.add('oculto');

    // Para productos, al editar traemos el detalle completo (precios, descripción…).
    let datos = row;
    if (modo === 'editar' && cfg.detalle) {
        const r = await api.get(`${cfg.detalle}?id=${row.id}`);
        datos = r.producto;
    }

    const cont = $('form-campos'); cont.innerHTML = '';
    for (const campo of cfg.campos) {
        cont.appendChild(await renderCampo(campo, datos));
    }

    // Widget de imágenes: cargar las actuales y conectar subir/quitar.
    const campoImg = cfg.campos.find((c) => c.tipo === 'imagenes');
    if (campoImg) {
        imagenesForm = (datos && datos[campoImg.key]) ? String(datos[campoImg.key]).split(/\r?\n/).filter(Boolean) : [];
        renderImagenesGrid();
        $('img-file').addEventListener('change', (e) => subirArchivos(e.target.files));
        $('img-grid').addEventListener('click', (e) => {
            const b = e.target.closest('.img-quitar');
            if (b) { imagenesForm.splice(Number(b.dataset.i), 1); renderImagenesGrid(); }
        });
    }

    $('form').dataset.id = (modo === 'editar' && row) ? row.id : '';
    $('modal').classList.remove('oculto');
    const primero = cont.querySelector('input, select, textarea');
    if (primero) primero.focus();
}

async function renderCampo(campo, datos) {
    const div = document.createElement('div');
    div.className = 'campo' + (campo.ancho ? ' ancho' : '') + (campo.tipo === 'check' ? ' check' : '');
    const val = datos ? datos[campo.key] : (campo.def ?? '');

    if (campo.tipo === 'check') {
        const checked = datos ? Number(datos[campo.key]) === 1 : !!campo.def;
        div.innerHTML = `<input type="checkbox" id="f_${campo.key}" ${checked ? 'checked' : ''}>
                         <label for="f_${campo.key}">${esc(campo.label)}</label>`;
        return div;
    }

    if (campo.tipo === 'imagenes') {
        div.className = 'campo ancho';
        div.innerHTML = `<label>${esc(campo.label)}</label>
            <div class="img-widget">
                <div class="img-grid" id="img-grid"></div>
                <div class="img-acciones">
                    <label class="img-subir">📁 Subir imágenes
                        <input type="file" id="img-file" accept="image/*" multiple hidden>
                    </label>
                    <span class="img-hint">o pegá una imagen con <kbd>Ctrl</kbd>+<kbd>V</kbd></span>
                </div>
                <p class="img-error oculto" id="img-error"></p>
            </div>`;
        return div;
    }

    const req = campo.req ? ' <span class="req">*</span>' : '';
    let control;
    if (campo.tipo === 'select') {
        let ops = campo.opciones;
        if (campo.origen) ops = [{ v: '', t: campo.vacio || '(elegir)' }, ...(await opcionesDe(campo.origen))];
        const actual = val ?? '';
        control = `<select id="f_${campo.key}">` +
            ops.map((o) => `<option value="${esc(o.v)}" ${String(o.v) === String(actual) ? 'selected' : ''}>${esc(o.t)}</option>`).join('') +
            `</select>`;
    } else if (campo.tipo === 'textarea') {
        control = `<textarea id="f_${campo.key}">${esc(val ?? '')}</textarea>`;
    } else {
        const paso = campo.tipo === 'number' ? ' step="0.01" min="0"' : '';
        control = `<input type="${campo.tipo}" id="f_${campo.key}" value="${esc(val ?? '')}"${paso}>`;
    }
    div.innerHTML = `<label for="f_${campo.key}">${esc(campo.label)}${req}</label>${control}`;
    return div;
}

// --- Widget de imágenes (subir archivos / pegar Ctrl+V) ---
function renderImagenesGrid() {
    const grid = $('img-grid');
    if (!grid) return;
    grid.innerHTML = imagenesForm.length === 0
        ? '<span class="img-vacio">Sin imágenes todavía.</span>'
        : imagenesForm.map((u, i) =>
            `<div class="img-item"><img src="${esc(u)}" alt="">
             <button type="button" class="img-quitar" data-i="${i}" aria-label="Quitar">×</button></div>`).join('');
}

async function subirArchivos(archivos) {
    const err = $('img-error');
    if (err) err.classList.add('oculto');
    for (const file of archivos) {
        if (!file.type.startsWith('image/')) continue;
        const fd = new FormData();
        fd.append('imagen', file);
        try {
            const r = await api.subir('/api/admin/productos/imagen', fd);
            imagenesForm.push(r.url);
            renderImagenesGrid();
        } catch (e) {
            if (err) { err.textContent = e.message; err.classList.remove('oculto'); }
        }
    }
}

function leerFormulario() {
    const datos = {};
    for (const campo of cfg.campos) {
        if (campo.tipo === 'imagenes') { datos[campo.key] = imagenesForm.join('\n'); continue; }
        const el = $(`f_${campo.key}`);
        if (campo.tipo === 'check') datos[campo.key] = el.checked ? 1 : 0;
        else datos[campo.key] = el.value;
    }
    const id = $('form').dataset.id;
    if (id) datos.id = Number(id);
    return datos;
}

async function guardar(e) {
    e.preventDefault();
    const err = $('form-error');
    err.classList.add('oculto');
    const btn = $('btn-guardar'); btn.disabled = true;
    try {
        const resp = await api.post(`${cfg.endpoint}/guardar`, leerFormulario());
        cerrarModal();
        // Si tocamos una maestra, invalidamos su caché de opciones.
        delete cacheOpciones[entActual];
        await cargar();
        if (resp && resp.mensaje) toast('✓ ' + resp.mensaje);
    } catch (ex) {
        err.textContent = ex.message;
        err.classList.remove('oculto');
    } finally {
        btn.disabled = false;
    }
}

async function baja(id, nombre) {
    if (!confirm(`¿Dar de baja "${nombre}"? Podés reactivarlo después desde Editar.`)) return;
    try {
        await api.post(`${cfg.endpoint}/baja`, { id: Number(id) });
        delete cacheOpciones[entActual];
        await cargar();
    } catch (ex) {
        alert(ex.message);
    }
}

function cerrarModal() { $('modal').classList.add('oculto'); }

// Aprobar / rechazar una solicitud mayorista
async function resolverSolicitud(id, accion) {
    const estado = accion === 'aprobar' ? 'aprobada' : 'rechazada';
    const verbo = accion === 'aprobar' ? 'aprobar' : 'rechazar';
    if (!confirm(`¿Seguro que querés ${verbo} esta solicitud?`)) return;
    try {
        await api.post('/api/admin/solicitudes/resolver', { id: Number(id), estado });
        await cargar();
    } catch (ex) { alert(ex.message); }
}

// Detalle de un pedido (solo lectura)
async function verPedido(id, numero) {
    $('detalle-titulo').textContent = 'Pedido ' + numero;
    const cont = $('detalle-items');
    cont.innerHTML = '<p class="td-mute">Cargando…</p>';
    $('modal-detalle').classList.remove('oculto');
    const r = await api.get(`/api/admin/pedidos/detalle?id=${id}`);
    let total = 0;
    const filas = r.items.map((it) => {
        total += Number(it.subtotal);
        return `<div class="pedido-linea">
            <span>${Number(it.cantidad)} × ${esc(it.producto)}</span>
            <span class="tabular">${money.format(it.subtotal)}</span>
        </div>`;
    }).join('');

    const e = r.envio;
    let envioHtml = '';
    if (e) {
        const costo = Number(e.costo) === 0 ? 'Gratis' : money.format(e.costo);
        total += Number(e.costo);
        const seg = e.seguimiento_url
            ? `<a class="envio-seg" href="${esc(e.seguimiento_url)}" target="_blank" rel="noopener">🔎 Seguir envío en ${esc(e.empresa || 'el correo')}</a>`
            : '';
        envioHtml = `
            <h4 class="detalle-sub">Envío</h4>
            <div class="pedido-linea"><span>${esc(e.empresa || 'Sin empresa')}</span><span class="tabular">${costo}</span></div>
            <div class="envio-form">
                <div class="envio-grid2">
                    <div><label>Destinatario</label><input id="env-destinatario" value="${esc(e.destinatario || '')}" placeholder="Quién recibe"></div>
                    <div><label>Teléfono</label><input id="env-telefono" value="${esc(e.telefono || '')}" placeholder="Teléfono"></div>
                    <div><label>Calle</label><input id="env-direccion" value="${esc(e.direccion || '')}" placeholder="Calle"></div>
                    <div><label>Número</label><input id="env-numero" value="${esc(e.numero || '')}" placeholder="Altura"></div>
                    <div class="col-2"><label>Referencia</label><input id="env-referencia" value="${esc(e.referencia || '')}" placeholder="Entre calles / piso / depto"></div>
                    <div><label>Localidad</label><input id="env-localidad" value="${esc(e.localidad || '')}" placeholder="Localidad"></div>
                    <div><label>Provincia</label><input id="env-provincia" value="${esc(e.provincia || '')}" placeholder="Provincia"></div>
                    <div><label>Código postal</label><input id="env-cp" value="${esc(e.cp || '')}" placeholder="CP"></div>
                </div>
                <div class="envio-fila">
                    <div>
                        <label>Estado del envío</label>
                        <select id="envio-estado">${ESTADOS_ENVIO_ADMIN.map((s) =>
                            `<option value="${s}" ${s === e.estado ? 'selected' : ''}>${s.replace('_', ' ')}</option>`).join('')}</select>
                    </div>
                    <div>
                        <label>Seguimiento (tracking)</label>
                        <input id="envio-tracking" placeholder="Nº de seguimiento" value="${esc(e.tracking || '')}">
                    </div>
                </div>
                ${seg}
                <button class="btn-primary" id="envio-guardar" data-id="${id}">Guardar envío</button>
            </div>`;
    }

    cont.innerHTML = filas +
        `<div class="pedido-linea total"><strong>Total ${e ? 'con envío' : ''}</strong><strong class="tabular">${money.format(total)}</strong></div>` +
        envioHtml;

    const btn = $('envio-guardar');
    if (btn) btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
            await api.post('/api/admin/pedidos/envio', {
                pedido_id: Number(id),
                estado: $('envio-estado').value,
                tracking: $('envio-tracking').value,
                datos: {
                    destinatario: $('env-destinatario').value,
                    telefono: $('env-telefono').value,
                    direccion: $('env-direccion').value,
                    numero: $('env-numero').value,
                    referencia: $('env-referencia').value,
                    localidad: $('env-localidad').value,
                    provincia: $('env-provincia').value,
                    cp: $('env-cp').value,
                },
            });
            btn.textContent = 'Guardado ✓';
            // Recargar el detalle para refrescar el link de seguimiento con el nuevo tracking.
            setTimeout(() => verPedido(id, numero), 700);
        } catch (ex) { alert(ex.message); btn.disabled = false; }
    });
}

// ---- Inicio (dashboard con métricas reales) ----
function kpi(label, num, foot) {
    return `<div class="card kpi"><div class="card-label">${esc(label)}</div>
            <div class="card-num">${num}</div><div class="card-foot">${esc(foot)}</div></div>`;
}
function accionRow(label, n, ir) {
    return `<div class="accion-row ${n > 0 ? 'alerta' : ''}" data-ir="${ir}">
            <span>${esc(label)}</span><span class="accion-num">${n}</span></div>`;
}

function renderSerie(serie) {
    const max = Math.max(1, ...serie.flatMap((x) => [Number(x.fisica), Number(x.online)]));
    $('barras-serie').innerHTML = serie.map((x) => {
        const hf = Math.round((Number(x.fisica) / max) * 100);
        const ho = Math.round((Number(x.online) / max) * 100);
        return `<div class="barra-col">
            <div class="barra-par">
                <div class="barra fisica" style="height:${Number(x.fisica) > 0 ? Math.max(hf, 2) : 0}%" title="Físicas: ${money.format(x.fisica)}"></div>
                <div class="barra online" style="height:${Number(x.online) > 0 ? Math.max(ho, 2) : 0}%" title="Online: ${money.format(x.online)}"></div>
            </div>
            <span>${esc(x.label)}</span>
        </div>`;
    }).join('');
}

async function cargarSerie(periodo) {
    document.querySelectorAll('.periodo-toggle button').forEach((b) => b.classList.toggle('activo', b.dataset.p === periodo));
    try { renderSerie((await api.get('/api/admin/dashboard/serie?periodo=' + periodo)).serie); }
    catch { /* deja la serie anterior */ }
}

// Barras agrupadas Ingresos vs Gastos (6 meses).
function renderBarrasFin(serie) {
    const max = Math.max(1, ...serie.flatMap((x) => [Number(x.ingresos), Number(x.gastos)]));
    $('barras-fin').innerHTML = serie.map((x) => {
        const hi = Math.round((Number(x.ingresos) / max) * 100);
        const hg = Math.round((Number(x.gastos) / max) * 100);
        return `<div class="barra-col"><div class="barra-par">
            <div class="barra ingreso" style="height:${Number(x.ingresos) > 0 ? Math.max(hi, 2) : 0}%" title="Ingresos: ${money.format(x.ingresos)}"></div>
            <div class="barra gasto" style="height:${Number(x.gastos) > 0 ? Math.max(hg, 2) : 0}%" title="Gastos: ${money.format(x.gastos)}"></div>
        </div><span>${esc(x.label)}</span></div>`;
    }).join('');
}

async function renderInicio() {
    let d;
    try { d = await api.get('/api/admin/dashboard'); }
    catch { $('kpis').innerHTML = '<p class="tabla-vacia">No se pudo cargar el dashboard.</p>'; return; }

    // --- KPIs ---
    const comp = d.comparativa || { variacion: null, anterior: 0 };
    const up = comp.variacion !== null && comp.variacion >= 0;
    const varTxt = comp.variacion === null ? '—' : (up ? '▲ ' : '▼ ') + Math.abs(comp.variacion) + '%';
    const compCard = `<div class="card kpi"><div class="card-label">Físicas vs mes ant.</div>
        <div class="card-num ${comp.variacion === null ? '' : (up ? 'var-up' : 'var-down')}">${varTxt}</div>
        <div class="card-foot">mes ant: ${money.format(comp.anterior)}</div></div>`;

    $('kpis').innerHTML = [
        kpi('Ventas de hoy', money.format(d.ventas_hoy.monto), `${d.ventas_hoy.cantidad} venta(s) en el local`),
        kpi('Físicas (mes)', money.format(d.ventas_mes), 'POS / local'),
        kpi('Online (mes)', money.format(d.ventas_online_mes), 'tienda online'),
        compCard,
        kpi('Ticket promedio', money.format(d.ticket_promedio_mes), 'ventas físicas del mes'),
        kpi('Clientes', d.clientes.total, `+${d.clientes.nuevos_mes} este mes`),
    ].join('');

    // --- Totales del negocio (finanzas) ---
    const t = d.totales || {};
    const balPos = (t.balance ?? 0) >= 0;
    $('totales').innerHTML = `
        <h3 class="dash-seccion">Totales del negocio</h3>
        <div class="cards">
            ${kpi('Clientes totales', t.clientes ?? 0, 'registrados')}
            ${kpi('Ventas físicas', money.format(t.ventas_monto ?? 0), `${t.ventas_cant ?? 0} ventas (POS)`)}
            ${kpi('Ventas online', money.format(t.pedidos_monto ?? 0), `${t.pedidos_cant ?? 0} pedidos`)}
            ${kpi('Total generado', money.format(t.generado ?? 0), 'ingresos históricos')}
            <div class="card kpi accion-card" data-ir="gastos" role="button" tabindex="0">
                <div class="card-label">Total invertido</div>
                <div class="card-num">${money.format(t.invertido ?? 0)}</div>
                <div class="card-foot">gastos · ver detalle →</div></div>
            <div class="card kpi"><div class="card-label">Balance</div>
                <div class="card-num ${balPos ? 'var-up' : 'var-down'}">${money.format(t.balance ?? 0)}</div>
                <div class="card-foot">generado − invertido</div></div>
        </div>`;
    const irGastos = $('totales').querySelector('[data-ir="gastos"]');
    if (irGastos) irGastos.addEventListener('click', () => seleccionar('gastos'));

    // --- Gráfico: Ingresos vs Gastos (6 meses) ---
    $('dash-finanzas').innerHTML = `
        <div class="chart-head"><h3>Ingresos vs Gastos · 6 meses</h3></div>
        <div class="chart-legend">
            <span class="lg"><i class="sw ingreso"></i>Ingresos</span>
            <span class="lg"><i class="sw gasto"></i>Gastos</span>
        </div>
        <div class="barras grupos" id="barras-fin"></div>`;
    renderBarrasFin(d.serie_finanzas || []);

    // --- Gastos recientes (se cargan y gestionan desde acá) ---
    const gs = d.gastos_recientes || [];
    $('dash-gastos').innerHTML = `
        <div class="chart-head">
            <h3>Gastos recientes</h3>
            <div class="panel-acciones">
                <button class="btn-mini" data-nuevo-gasto>+ Nuevo gasto</button>
                <button class="btn-mini ghost" data-ver-gastos>Ver todos</button>
            </div>
        </div>
        ${gs.length === 0
            ? `<p class="dash-vacio">Todavía no hay gastos. Cargá el primero.</p>`
            : `<ul class="dash-lista">${gs.map((g) => `<li>
                <span>${esc(g.fecha)} · ${esc(g.concepto)}${g.producto ? ` <span class="chip">${esc(g.producto)} ×${Number(g.cantidad)}</span>` : ''}</span>
                <span class="dash-num">${money.format(g.monto)}</span></li>`).join('')}</ul>`}`;
    $('dash-gastos').querySelector('[data-nuevo-gasto]').addEventListener('click', async () => { await seleccionar('gastos'); abrirModal('nuevo'); });
    $('dash-gastos').querySelector('[data-ver-gastos]').addEventListener('click', () => seleccionar('gastos'));

    // --- Ventas por categoría (90 días) ---
    const cats = d.ventas_categoria || [];
    const maxCat = Math.max(1, ...cats.map((c) => Number(c.monto)));
    $('dash-categorias').innerHTML = `<h3>Ventas por categoría · 90 días</h3>` + (cats.length === 0
        ? `<p class="dash-vacio">Sin ventas en el período.</p>`
        : `<div class="hbars">${cats.map((c) => `<div class="hbar-row">
            <span class="hbar-label">${esc(c.categoria)}</span>
            <div class="hbar-track"><div class="hbar-fill" style="width:${Math.max(3, Math.round(Number(c.monto) / maxCat * 100))}%"></div></div>
            <span class="hbar-val">${money.format(c.monto)}</span></div>`).join('')}</div>`);

    // --- Gráfico rotable: físicas vs online ---
    $('dash-ventas7').innerHTML = `
        <div class="chart-head">
            <h3>Ventas: físicas vs online</h3>
            <div class="periodo-toggle">
                <button data-p="semana" class="activo">Semana</button>
                <button data-p="mes">Mes</button>
                <button data-p="ano">Año</button>
            </div>
        </div>
        <div class="chart-legend">
            <span class="lg"><i class="sw fisica"></i>Físicas (POS)</span>
            <span class="lg"><i class="sw online"></i>Online (tienda)</span>
        </div>
        <div class="barras grupos" id="barras-serie"></div>`;
    renderSerie(d.serie);
    $('dash-ventas7').querySelectorAll('.periodo-toggle button').forEach((b) =>
        b.addEventListener('click', () => cargarSerie(b.dataset.p)));

    // --- Requiere acción (clickable a su sección) ---
    $('dash-acciones').innerHTML = `<h3>Requiere acción</h3>
        ${accionRow('Pedidos pendientes', d.pedidos_pendientes, 'pedidos')}
        ${accionRow('Solicitudes mayoristas', d.solicitudes_pendientes, 'solicitudes')}
        ${accionRow('Productos sin stock', d.sin_stock, 'productos')}`;
    $('dash-acciones').querySelectorAll('[data-ir]').forEach((el) =>
        el.addEventListener('click', () => seleccionar(el.dataset.ir)));

    // --- Stock bajo ---
    $('dash-stock').innerHTML = `<h3>Stock bajo (≤ 5)</h3>` + (d.stock_bajo.length === 0
        ? `<p class="dash-vacio">Todo con stock suficiente 👍</p>`
        : `<ul class="dash-lista">${d.stock_bajo.map((p) =>
            `<li><span>${esc(p.nombre)}</span><span class="dash-num ${Number(p.stock) === 0 ? 'cero' : ''}">${Number(p.stock)}</span></li>`).join('')}</ul>`);

    // --- Top productos ---
    $('dash-top').innerHTML = `<h3>Top productos (30 días)</h3>` + (d.top_productos.length === 0
        ? `<p class="dash-vacio">Sin ventas en el período.</p>`
        : `<ul class="dash-lista">${d.top_productos.map((p) =>
            `<li><span>${esc(p.nombre)}</span><span class="dash-num">${Number(p.unidades)} u</span></li>`).join('')}</ul>`);

    // --- Ventas por vendedor (mes) ---
    $('dash-vendedores').innerHTML = `<h3>Ventas por vendedor (mes)</h3>` + ((d.por_vendedor || []).length === 0
        ? `<p class="dash-vacio">Sin ventas este mes.</p>`
        : `<ul class="dash-lista">${d.por_vendedor.map((v) =>
            `<li><span>${esc(v.vendedor)}</span><span class="dash-num">${money.format(v.monto)}</span></li>`).join('')}</ul>`);

    // --- Productos sin movimiento (30 días) ---
    const sm = d.sin_movimiento || { total: 0, lista: [] };
    $('dash-sinmov').innerHTML = `<h3>Sin ventas en 30 días · ${sm.total}</h3>` + (sm.lista.length === 0
        ? `<p class="dash-vacio">Todos tuvieron movimiento 👍</p>`
        : `<ul class="dash-lista">${sm.lista.map((p) =>
            `<li><span>${esc(p.nombre)}</span><span class="dash-num">${Number(p.stock)} u</span></li>`).join('')}</ul>`);
}

// ---- Init ----
let debounce;
function wire() {
    document.querySelectorAll('.nav-item').forEach((b) =>
        b.addEventListener('click', () => seleccionar(b.dataset.ent)));

    $('q').addEventListener('input', (e) => {
        clearTimeout(debounce);
        debounce = setTimeout(() => { q = e.target.value.trim(); page = 1; cargar(); }, 300);
    });

    $('btn-nuevo').addEventListener('click', () => abrirModal('nuevo'));
    $('btn-cancelar').addEventListener('click', cerrarModal);
    $('form').addEventListener('submit', guardar);

    // Pegar imágenes con Ctrl+V dentro del formulario de producto
    document.addEventListener('paste', (e) => {
        if ($('modal').classList.contains('oculto')) return;
        if (!cfg || !cfg.campos.some((c) => c.tipo === 'imagenes')) return;
        const imgs = [...(e.clipboardData?.items || [])]
            .filter((it) => it.type.startsWith('image/'))
            .map((it) => it.getAsFile())
            .filter(Boolean);
        if (imgs.length) { e.preventDefault(); subirArchivos(imgs); }
    });
    $('modal').addEventListener('click', (e) => { if (e.target === $('modal')) cerrarModal(); });

    $('tbody').addEventListener('click', async (e) => {
        const b = e.target.closest('.btn-fila');
        if (!b) return;
        const id = b.dataset.id;
        if (b.dataset.accion === 'baja') return baja(id, b.dataset.nombre);
        if (b.dataset.accion === 'ver')  return verPedido(id, b.dataset.numero);
        if (b.dataset.accion === 'aprobar' || b.dataset.accion === 'rechazar') return resolverSolicitud(id, b.dataset.accion);
        const fila = filasActuales.find((f) => String(f.id) === String(id));
        abrirModal('editar', fila);
    });

    // Cambio de estado inline (pedidos)
    $('tbody').addEventListener('change', async (e) => {
        const sel = e.target.closest('.estado-inline');
        if (!sel) return;
        try { await api.post('/api/admin/pedidos/estado', { id: Number(sel.dataset.id), estado: sel.value }); }
        catch (ex) { alert(ex.message); cargar(); }
    });

    $('detalle-cerrar').addEventListener('click', () => $('modal-detalle').classList.add('oculto'));
    $('modal-detalle').addEventListener('click', (e) => { if (e.target === $('modal-detalle')) $('modal-detalle').classList.add('oculto'); });

    $('btn-salir').addEventListener('click', async () => {
        try { await api.post('/api/logout', {}); } catch {}
        window.location.href = '/login.html';
    });

    // Tema claro / oscuro (se guarda en el navegador)
    const pintarTema = () => {
        const oscuro = document.documentElement.dataset.theme === 'dark';
        $('btn-tema').textContent = oscuro ? '☀️ Tema claro' : '🌙 Tema oscuro';
    };
    pintarTema();
    $('btn-tema').addEventListener('click', () => {
        const oscuro = document.documentElement.dataset.theme === 'dark';
        if (oscuro) { delete document.documentElement.dataset.theme; localStorage.setItem('britech_admin_theme', ''); }
        else { document.documentElement.dataset.theme = 'dark'; localStorage.setItem('britech_admin_theme', 'dark'); }
        pintarTema();
    });
}

(async function iniciar() {
    let sesion;
    try { sesion = await api.get('/api/yo'); }
    catch { window.location.href = '/login.html'; return; }
    if (sesion.usuario.rol !== 'admin') {
        alert('El panel admin es solo para administradores.');
        window.location.href = '/pos.html';
        return;
    }
    wire();
    seleccionar('inicio');
})();

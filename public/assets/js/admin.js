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

// Badge del estado de pago del pedido.
const badgePago = (v) => {
    const m = { pendiente: ['warn', 'Pendiente'], en_revision: ['info', 'En revisión'],
        pagado: ['ok', 'Pagado'], rechazado: ['danger', 'Rechazado'] };
    const [cls, txt] = m[v] || ['off', v || '—'];
    return `<span class="badge ${cls}">${esc(txt)}</span>`;
};

// Estado del comprobante que sube el cliente (solo aplica a Transferencia).
const badgeComprobante = (url, row) => {
    if (row.metodo_pago && row.metodo_pago !== 'transferencia')
        return '<span class="badge neutral">no aplica</span>';
    return url
        ? '<span class="badge ok">Recibido</span>'
        : '<span class="badge danger">Sin comprobante</span>';
};

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
    const filas = r.data || r[entidad] || [];   // paginado (data) o { empleados: [...] }
    cacheOpciones[entidad] = filas.map((x) => ({ v: x.id, t: x.nombre }));
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
            { key: 'razon_social', label: 'Razón social (facturación)', tipo: 'text', ancho: true },
            { key: 'documento', label: 'Documento', tipo: 'text' },
            { key: 'cuit', label: 'CUIT / CUIL', tipo: 'text' },
            { key: 'condicion_iva', label: 'Condición IVA', tipo: 'select', opciones: [
                { v: '', t: 'Sin especificar' },
                { v: 'consumidor_final', t: 'Consumidor final' },
                { v: 'responsable_inscripto', t: 'Responsable inscripto' },
                { v: 'monotributo', t: 'Monotributo' },
                { v: 'exento', t: 'Exento' },
            ] },
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
            { key: 'costo', label: 'Costo (compra al proveedor)', tipo: 'number' },
            { key: 'precio_minorista', label: 'Precio minorista', tipo: 'number', req: true },
            { key: 'precio_mayorista', label: 'Precio mayorista', tipo: 'number' },
            { key: 'precio_anterior', label: 'Precio anterior (oferta)', tipo: 'number' },
            { key: 'stock', label: 'Stock', tipo: 'number', req: true, def: 0 },
            { key: 'stock_minimo', label: 'Stock mínimo (avisa reposición)', tipo: 'number', def: 0 },
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
    marcas: { ...maestraSimple('marcas', 'Marcas', 'marca', 'Marcas de los productos. El logo se usa en el carrusel de marcas de la tienda.', { imagen: true }) },

    gastos: {
        titulo: 'Gastos', sub: 'Finanzas del negocio. Si cargás producto + cantidad, esa compra SUMA stock al inventario (al crear el gasto).',
        singular: 'gasto', endpoint: '/api/admin/gastos',
        columnas: [
            { key: 'fecha', label: 'Fecha', render: (v) => `<span class="td-mute">${fechaCorta(v)}</span>` },
            { key: 'concepto', label: 'Concepto', clase: 'td-fuerte' },
            { key: 'proveedor', label: 'Proveedor', render: oMenos },
            { key: 'producto', label: 'Suma stock', render: (v, row) => row.producto
                ? `${esc(row.producto)} <span class="chip">×${Number(row.cantidad)}</span>`
                : '<span class="td-mute">—</span>' },
            { key: 'empleado', label: 'Sueldo de', render: (v, row) => row.empleado
                ? `${esc(row.empleado)}${row.periodo ? ` <span class="chip">${esc(row.periodo)}</span>` : ''}`
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
            { key: 'usuario_id', label: 'Empleado (si es un sueldo)', tipo: 'select', origen: 'empleados', vacio: '(no es sueldo)' },
            { key: 'periodo', label: 'Mes que cubre el sueldo', tipo: 'month' },
            { key: 'monto', label: 'Monto', tipo: 'number', req: true },
            { key: 'observacion', label: 'Observación', tipo: 'textarea', ancho: true },
            { key: 'activo', label: 'Activo', tipo: 'check', def: true },
        ],
    },

    pedidos: {
        titulo: 'Pedidos y envíos', sub: 'Pedidos de la tienda: hacete cargo de cada envío — estado, datos de entrega y seguimiento.',
        singular: 'pedido', endpoint: '/api/admin/pedidos', detalle: '/api/admin/pedidos/detalle',
        noCrear: true, acciones: ['ver'], accionExtra: { label: '⚙ Empresas de envío', ent: 'envios' },
        columnas: [
            { key: 'numero', label: 'N°', clase: 'td-fuerte' },
            { key: 'cliente', label: 'Cliente' },
            { key: 'items', label: 'Ítems', num: true },
            { key: 'total', label: 'Total', num: true, render: (v) => money.format(v) },
            { key: 'creado_en', label: 'Fecha', render: (v) => `<span class="td-mute">${fechaCorta(v)}</span>` },
            { key: 'estado_pago', label: 'Pago', render: (v) => badgePago(v) },
            { key: 'comprobante_url', label: 'Comprobante', render: (v, row) => badgeComprobante(v, row) },
            { key: 'estado', label: 'Envío', render: renderEstadoInline },
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
            { key: 'creado_en', label: 'Fecha', render: (v) => `<span class="td-mute">${fechaCorta(v)}</span>` },
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

    barrios: {
        titulo: 'Barrios de envío', sub: 'Barrios de Formosa que cubre el moto y el precio de envío de cada uno.',
        singular: 'barrio', endpoint: '/api/admin/barrios',
        columnas: [
            { key: 'nombre', label: 'Barrio', clase: 'td-fuerte' },
            { key: 'costo', label: 'Precio de envío', num: true, render: (v) => money.format(v) },
            { key: 'activo', label: 'Estado', render: badgeEstado },
        ],
        filtros: [
            { key: 'activo', label: 'Estado', opciones: [{ v: '', t: 'Todos' }, { v: '1', t: 'Activos' }, { v: '0', t: 'Inactivos' }] },
        ],
        campos: [
            { key: 'nombre', label: 'Nombre del barrio', tipo: 'text', req: true, ancho: true },
            { key: 'costo', label: 'Precio de envío', tipo: 'number', req: true, def: 0 },
            { key: 'activo', label: 'Activo', tipo: 'check', def: true },
        ],
    },

    repartidores: {
        titulo: 'Repartidores', sub: 'Motoqueros del Moto Express. Agregá, editá y mirá la paga del día según los barrios que entregó cada uno.',
        singular: 'repartidor', endpoint: '/api/admin/repartidores',
        acciones: ['ver', 'editar', 'baja'],
        verFn: (id) => abrirRepartidor(id),
        columnas: [
            { key: 'nombre', label: 'Nombre', clase: 'td-fuerte' },
            { key: 'telefono', label: 'Teléfono', render: (v) => v ? esc(v) : '<span class="td-mute">—</span>' },
            { key: 'activos', label: 'A repartir', num: true, render: (v) => Number(v) > 0 ? `<strong>${Number(v)}</strong>` : '<span class="td-mute">0</span>' },
            { key: 'envios_hoy', label: 'Entregó hoy', num: true },
            { key: 'pago_hoy', label: 'A pagar hoy', num: true, render: (v) => money.format(v) },
            { key: 'activo', label: 'Estado', render: badgeEstado },
        ],
        filtros: [
            { key: 'activo', label: 'Estado', opciones: [{ v: '', t: 'Todos' }, { v: '1', t: 'Activos' }, { v: '0', t: 'Inactivos' }] },
        ],
        campos: [
            { key: 'nombre', label: 'Nombre', tipo: 'text', req: true, ancho: true },
            { key: 'telefono', label: 'Teléfono (opcional)', tipo: 'text' },
            { key: 'pin', label: 'PIN para su app (4-6 números, dejá vacío para no cambiarlo)', tipo: 'text' },
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
let pasteWired = false; // listener global de pegar imagen (Ctrl+V) armado una sola vez

// ---- Elementos ----
const $ = (id) => document.getElementById(id);
const vistaAbm = $('vista-abm'), vistaInicio = $('vista-inicio');

// ---- Ajustes: datos de transferencia ----
async function renderAjustes() {
    const cont = $('ajustes-cont');
    cont.innerHTML = '<p class="td-mute">Cargando…</p>';
    const r = await api.get('/api/admin/config');
    const c = r.config || {};
    const loc = r.local || {};
    cont.innerHTML = `
        <div class="ajustes-card">
            <h3>💳 Datos de transferencia</h3>
            <p class="sub">Se le muestran al cliente en el checkout para que transfiera y suba el comprobante.</p>
            <div class="campo"><label for="cfg-alias">Alias</label><input id="cfg-alias" value="${esc(c.pago_alias || '')}" placeholder="mi.alias.mp"></div>
            <div class="campo"><label for="cfg-titular">Titular</label><input id="cfg-titular" value="${esc(c.pago_titular || '')}" placeholder="Nombre y apellido"></div>
            <div class="campo"><label for="cfg-cbu">CBU / CVU</label><input id="cfg-cbu" value="${esc(c.pago_cbu || '')}" placeholder="0000000000000000000000"></div>
            <div class="campo"><label for="cfg-banco">Banco / Billetera</label><input id="cfg-banco" value="${esc(c.pago_banco || '')}" placeholder="Ej: Mercado Pago"></div>
            <button class="btn-primary" id="cfg-guardar">Guardar</button>
        </div>
        <div class="ajustes-card">
            <h3>📍 Ubicación del negocio</h3>
            <p class="sub">Desde acá sale el cadete a buscar los pedidos. Arrastrá el pin (o tocá el mapa) a tu local.</p>
            <div class="campo"><label for="cfg-local-dir">Dirección</label><input id="cfg-local-dir" value="${esc(loc.dir || '')}" placeholder="Calle y altura"></div>
            <div id="cfg-local-mapa" style="height:220px;border-radius:12px;overflow:hidden;margin:10px 0"></div>
            <input type="hidden" id="cfg-local-lat" value="${esc(loc.lat ?? '')}">
            <input type="hidden" id="cfg-local-lng" value="${esc(loc.lng ?? '')}">
            <button class="btn-primary" id="cfg-local-guardar">Guardar ubicación</button>
        </div>`;
    $('cfg-guardar').addEventListener('click', async () => {
        const btn = $('cfg-guardar'); btn.disabled = true;
        try {
            await api.post('/api/admin/config/guardar', {
                pago_alias: $('cfg-alias').value, pago_titular: $('cfg-titular').value,
                pago_cbu: $('cfg-cbu').value, pago_banco: $('cfg-banco').value,
            });
            toast('✓ Datos de pago guardados');
        } catch (e) { toast('⚠ ' + e.message); }
        btn.disabled = false;
    });

    // Mapa de la ubicación del negocio: pin arrastrable.
    const llat = Number(loc.lat) || -26.1849, llng = Number(loc.lng) || -58.1731;
    setTimeout(() => {
        if (!window.L) return;
        const map = L.map('cfg-local-mapa').setView([llat, llng], 15);
        (window.tileBritech ? tileBritech() : L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' })).addTo(map);
        const icono = L.divIcon({ className: '', iconSize: [20, 20], iconAnchor: [10, 10],
            html: '<div style="width:18px;height:18px;border-radius:50%;background:#e0a23b;border:3px solid #fff;box-shadow:0 1px 5px rgba(0,0,0,.5)"></div>' });
        const pin = L.marker([llat, llng], { draggable: true, icon: icono }).addTo(map);
        const guardarPin = () => { const ll = pin.getLatLng(); $('cfg-local-lat').value = ll.lat.toFixed(7); $('cfg-local-lng').value = ll.lng.toFixed(7); };
        guardarPin();
        pin.on('dragend', guardarPin);
        map.on('click', (ev) => { pin.setLatLng(ev.latlng); guardarPin(); });
        setTimeout(() => map.invalidateSize(), 80);
    }, 60);
    $('cfg-local-guardar').addEventListener('click', async () => {
        const btn = $('cfg-local-guardar'); btn.disabled = true;
        try {
            await api.post('/api/admin/config/local', {
                lat: Number($('cfg-local-lat').value), lng: Number($('cfg-local-lng').value), dir: $('cfg-local-dir').value,
            });
            toast('✓ Ubicación del negocio guardada');
        } catch (e) { toast('⚠ ' + e.message); }
        btn.disabled = false;
    });
}

// ---- Menú lateral: grupos y buscador ----
const sinAcentos = (t) => t.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
const itemsMenu = () => [...document.querySelectorAll('#nav .nav-item')];

function marcarGrupo(g, abierto) {
    g.classList.toggle('abierto', abierto);
    g.querySelector('.nav-grupo-tit').setAttribute('aria-expanded', abierto);
}

// Sin texto: deja abierto solo el grupo de la sección actual. Con texto: muestra lo que coincide.
function filtrarMenu(texto) {
    const t = sinAcentos(texto.trim());
    const grupos = document.querySelectorAll('.nav-grupo');
    itemsMenu().forEach((it) => it.classList.toggle('nav-filtrado',
        !!t && !sinAcentos(it.textContent + ' ' + (it.dataset.k || '')).includes(t)));
    const activo = document.querySelector('.nav-item.activo');
    grupos.forEach((g) => {
        g.classList.toggle('nav-filtrado', !!t && !g.querySelector('.nav-item:not(.nav-filtrado):not(.oculto)'));
        marcarGrupo(g, t ? true : g.contains(activo));
    });
    $('nav-vacio').classList.toggle('oculto', !t || itemsMenu().some((it) => it.offsetParent !== null));
}

// Solo se puede abrir lo que el rol ve en el menú (también vale para links con #seccion).
function vistaPermitida(ent) {
    const item = itemsMenu().find((b) => b.dataset.ent === ent);
    if (item) return !item.closest('.oculto');
    return ES_ADMIN && Object.hasOwn(ENTIDADES, ent);
}

// ---- Navegación ----
let navLista = false;   // la primera vista reemplaza el historial; las siguientes lo suman
async function seleccionar(ent) {
    if (!vistaPermitida(ent)) ent = ES_ADMIN ? 'inicio' : 'vendedor';
    if (location.hash.slice(1) !== ent) history[navLista ? 'pushState' : 'replaceState'](null, '', '#' + ent);
    navLista = true;
    itemsMenu().forEach((b) => b.classList.toggle('activo', b.dataset.ent === ent));
    $('nav-q').value = '';
    filtrarMenu('');
    $('btn-menu').setAttribute('aria-expanded', 'false');
    document.querySelector('.side').classList.remove('abierto');
    const vistaBloques = $('vista-bloques'), vistaEmpleados = $('vista-empleados'),
          vistaAjustes = $('vista-ajustes'), vistaRepartos = $('vista-repartos'),
          vistaReposicion = $('vista-reposicion'), vistaCompras = $('vista-compras'), vistaCajas = $('vista-cajas'),
          vistaReclamos = $('vista-reclamos'), vistaMapa = $('vista-mapa'), vistaVoz = $('vista-voz'), vistaReportes = $('vista-reportes'), vistaVendedor = $('vista-vendedor');
    const ocultarTodo = () => [vistaAbm, vistaInicio, vistaBloques, vistaEmpleados, vistaAjustes, vistaRepartos, vistaReposicion, vistaCompras, vistaCajas, vistaReclamos, vistaMapa, vistaVoz, vistaReportes, vistaVendedor]
        .forEach((v) => v && v.classList.add('oculto'));
    if (ent === 'inicio')   { ocultarTodo(); vistaInicio.classList.remove('oculto');   return renderInicio(); }
    if (ent === 'vendedor') { ocultarTodo(); vistaVendedor.classList.remove('oculto'); return renderVendedor(); }
    if (ent === 'bloques')  { ocultarTodo(); vistaBloques.classList.remove('oculto');  return renderBloques(); }
    if (ent === 'empleados'){ ocultarTodo(); vistaEmpleados.classList.remove('oculto'); return renderEmpleados(); }
    if (ent === 'repartos') { ocultarTodo(); vistaRepartos.classList.remove('oculto'); return renderRepartos(); }
    if (ent === 'reposicion'){ ocultarTodo(); vistaReposicion.classList.remove('oculto'); return renderReposicion(); }
    if (ent === 'compras')   { ocultarTodo(); vistaCompras.classList.remove('oculto');    return renderCompras(); }
    if (ent === 'cajas')     { ocultarTodo(); vistaCajas.classList.remove('oculto');      return renderCajas(); }
    if (ent === 'reclamos')  { ocultarTodo(); vistaReclamos.classList.remove('oculto');   return renderReclamos(); }
    if (ent === 'mapa')      { ocultarTodo(); vistaMapa.classList.remove('oculto');       return renderMapaEnvios(); }
    if (ent === 'voz')       { ocultarTodo(); vistaVoz.classList.remove('oculto');        return renderVoz(); }
    if (ent === 'reportes')  { ocultarTodo(); vistaReportes.classList.remove('oculto');   return renderReportes(); }
    if (ent === 'ajustes')  { ocultarTodo(); vistaAjustes.classList.remove('oculto');  return renderAjustes(); }
    entActual = ent; cfg = ENTIDADES[ent];
    page = 1; q = ''; filtros = {};
    $('q').value = '';
    $('ent-title').textContent = cfg.titulo;
    $('ent-sub').textContent = cfg.sub;
    $('btn-nuevo').classList.toggle('oculto', !!cfg.noCrear);

    // Botón de acción extra (ej: desde el gestor de envíos, ir a "Empresas de envío").
    const accion = $('btn-ent-accion');
    // La acción extra lleva a un ABM admin (ej: Empresas de envío) → solo admin.
    accion.classList.toggle('oculto', !cfg.accionExtra || !ES_ADMIN);
    if (cfg.accionExtra && ES_ADMIN) { accion.textContent = cfg.accionExtra.label; accion.onclick = () => seleccionar(cfg.accionExtra.ent); }

    ocultarTodo(); vistaAbm.classList.remove('oculto');
    await renderFiltros();
    renderThead();
    await cargar();
}

// Convierte los <select class="js-ts"> en selects con buscador (Tom Select).
// Si el CDN no cargó, quedan como selects nativos (degradación limpia).
function initTomSelects(root) {
    if (!window.TomSelect || !root) return;
    root.querySelectorAll('select.js-ts').forEach((el) => {
        if (el.tomselect) return;                       // ya inicializado
        new TomSelect(el, { allowEmptyOption: true, maxOptions: null });
    });
}

// ---- Toolbar: filtros ----
async function renderFiltros() {
    const cont = $('filtros'); cont.innerHTML = '';
    for (const f of cfg.filtros) {
        const sel = document.createElement('select');
        sel.dataset.key = f.key;
        if (f.origen) sel.className = 'js-ts';
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
    initTomSelects(cont);
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
    const acc = cfg.acciones || ['editar', 'baja'];
    // Acción principal que dispara el click en toda la fila: ver detalle, o editar.
    // (Las tablas con accionesFn —ej. solicitudes— no tienen fila clickeable.)
    const primaria = cfg.accionesFn ? null : (acc.includes('ver') ? 'ver' : (acc.includes('editar') ? 'editar' : null));
    tbody.innerHTML = filas.map((row) => {
        const celdas = cfg.columnas.map((c) => {
            const val = c.render ? c.render(row[c.key], row) : esc(row[c.key]);
            return `<td class="${c.num ? 'num' : ''} ${c.clase || ''}">${val}</td>`;
        }).join('');
        let botones;
        if (cfg.accionesFn) {
            botones = cfg.accionesFn(row);
        } else {
            // No repetimos el botón de la acción principal: ya la hace el click en la fila.
            botones = acc.filter((a) => a !== primaria).map((a) => {
                if (a === 'editar') return `<button class="btn-fila" data-accion="editar" data-id="${row.id}">Editar</button>`;
                if (a === 'baja')   return `<button class="btn-fila peligro" data-accion="baja" data-id="${row.id}" data-nombre="${esc(row.nombre)}">Baja</button>`;
                if (a === 'ver')    return `<button class="btn-fila ver-detalle" data-accion="ver" data-id="${row.id}" data-numero="${esc(row.numero)}">${ICO.eye || ''} Ver detalle</button>`;
                return '';
            }).join('');
        }
        const trAttrs = primaria
            ? ` class="fila-link" data-fila-accion="${primaria}" data-id="${row.id}" data-numero="${esc(row.numero || '')}"`
            : '';
        return `<tr${trAttrs}>${celdas}<td class="acciones">${botones}</td></tr>`;
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
    initTomSelects(cont);

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
        // Pegar imagen con Ctrl+V (mientras el modal con widget de imágenes está abierto).
        if (!pasteWired) {
            pasteWired = true;
            document.addEventListener('paste', (e) => {
                if (!document.getElementById('img-grid') || $('modal').classList.contains('oculto')) return;
                const imgs = [...(e.clipboardData?.items || [])]
                    .filter((it) => it.type.startsWith('image/'))
                    .map((it) => it.getAsFile()).filter(Boolean);
                if (imgs.length) { e.preventDefault(); subirArchivos(imgs); }
            });
        }
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
        // Listas largas (proveedor, producto, categoría…) → select con buscador.
        const clase = campo.origen ? ' class="js-ts"' : '';
        control = `<select id="f_${campo.key}"${clase}>` +
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

// Detalle de un pedido (solo lectura). numero es opcional: si no viene (ej. deep-link
// desde una notificación), se toma del propio detalle.
async function verPedido(id, numero) {
    $('detalle-titulo').textContent = numero ? 'Pedido ' + numero : 'Pedido…';
    const cont = $('detalle-items');
    cont.innerHTML = '<p class="td-mute">Cargando…</p>';
    $('modal-detalle').classList.remove('oculto');
    const r = await api.get(`/api/admin/pedidos/detalle?id=${id}`);
    numero = numero || r.numero || '';
    $('detalle-titulo').textContent = 'Pedido ' + numero;
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
        const esRetiro = Number(e.es_retiro) === 1;
        const esMoto = Number(e.es_moto) === 1;
        const selEstado = `<select id="envio-estado">${ESTADOS_ENVIO_ADMIN.map((s) =>
            `<option value="${s}" ${s === e.estado ? 'selected' : ''}>${s.replace('_', ' ')}</option>`).join('')}</select>`;
        // Selector de repartidor (motoquero) para los envíos de Moto Express.
        const selRepart = `<select id="env-repartidor"><option value="">— sin asignar —</option>${
            (r.repartidores || []).map((rp) =>
                `<option value="${rp.id}" ${Number(e.repartidor_id) === Number(rp.id) ? 'selected' : ''}>${esc(rp.nombre)}</option>`).join('')}</select>`;

        // Seguimiento en vivo: avisar por WhatsApp + link público + destino en el mapa.
        const pubUrl = e.token ? `${location.origin}/seguimiento.html?t=${e.token}` : '';
        const tieneDest = e.dest_lat != null && e.dest_lng != null;
        const metodoPago = (r.pago || {}).metodo_pago || '';
        const trackHtml = `
            <div class="track-admin">
                ${(e.token && e.telefono) ? `<button type="button" class="btn-mini" data-wa data-tel="${esc(e.telefono)}" data-dest="${esc(e.destinatario || '')}" data-num="${esc(numero || '')}" data-token="${esc(e.token)}" data-total="${total}" data-efectivo="${metodoPago === 'efectivo' ? 1 : ''}">Avisar por WhatsApp</button>` : ''}
                ${pubUrl ? `<button type="button" class="btn-mini ghost" data-copy-track="${esc(pubUrl)}">Copiar link de seguimiento</button>` : ''}
                <button type="button" class="btn-mini ghost" data-pin-dest="${e.id}" data-lat="${tieneDest ? e.dest_lat : ''}" data-lng="${tieneDest ? e.dest_lng : ''}">${tieneDest ? 'Corregir' : 'Fijar'} destino en el mapa</button>
                <span class="td-mute track-dest-ok">${tieneDest ? 'Destino ubicado' : 'Sin destino en el mapa'}</span>
            </div>`;

        if (esMoto) {
            // Moto Express: barrio (precio fijo) + dirección con altura + repartidor a cargo.
            envioHtml = `
                <h4 class="detalle-sub">Envío — Moto Express</h4>
                <div class="pedido-linea"><span>🛵 ${esc(e.barrio || e.localidad || 'Barrio')}</span><span class="tabular">${costo}</span></div>
                <div class="envio-form">
                    <div class="envio-grid2">
                        <div><label>Destinatario</label><input id="env-destinatario" value="${esc(e.destinatario || '')}" placeholder="Quién recibe"></div>
                        <div><label>Teléfono</label><input id="env-telefono" value="${esc(e.telefono || '')}" placeholder="Teléfono"></div>
                        <div><label>Calle</label><input id="env-direccion" value="${esc(e.direccion || '')}" placeholder="Calle"></div>
                        <div><label>Altura</label><input id="env-numero" value="${esc(e.numero || '')}" placeholder="Altura"></div>
                        <div class="col-2"><label>Referencia</label><input id="env-referencia" value="${esc(e.referencia || '')}" placeholder="Entre calles / piso / depto"></div>
                    </div>
                    <div class="envio-fila">
                        <div><label>Estado del envío</label>${selEstado}</div>
                        <div><label>Repartidor a cargo</label>${selRepart}</div>
                    </div>
                    ${trackHtml}
                    <button class="btn-primary" id="envio-guardar" data-id="${id}">Guardar envío</button>
                </div>`;
        } else if (esRetiro) {
            // Retiro en el local: no hay envío → sin datos de dirección ni seguimiento.
            envioHtml = `
                <h4 class="detalle-sub">Entrega</h4>
                <div class="pedido-linea"><span class="ico-txt">${ICO.mappin}${esc(e.empresa || 'Retiro en el local')}</span><span class="tabular">${costo}</span></div>
                <div class="envio-form">
                    <label>Estado</label>${selEstado}
                    <button class="btn-primary" id="envio-guardar" data-id="${id}">Guardar</button>
                </div>`;
        } else {
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
                        <div><label>Estado del envío</label>${selEstado}</div>
                        <div><label>Seguimiento (tracking)</label><input id="envio-tracking" placeholder="Nº de seguimiento" value="${esc(e.tracking || '')}"></div>
                    </div>
                    ${seg}
                    ${trackHtml}
                    <button class="btn-primary" id="envio-guardar" data-id="${id}">Guardar envío</button>
                </div>`;
        }
    }

    const pg = r.pago || {};
    const compro = pg.mp_payment_id
        ? `<p class="td-mute">💳 Cobrado con Mercado Pago · pago #${esc(pg.mp_payment_id)}</p>`
        : pg.comprobante_url
        ? `<a class="envio-seg" href="${esc(pg.comprobante_url)}" target="_blank" rel="noopener">📄 Ver comprobante</a>`
        : `<p class="td-mute">El cliente todavía no subió comprobante.</p>`;
    const metodoLbl = { transferencia: 'Transferencia', efectivo: 'Efectivo', mercadopago: 'Mercado Pago', tarjeta: 'Tarjeta' }[pg.metodo_pago] || pg.metodo_pago || '—';
    const pagoHtml = `
        <h4 class="detalle-sub">Pago</h4>
        <div class="pedido-linea"><span>Método</span><strong>${esc(metodoLbl)}</strong></div>
        <div class="pedido-linea"><span>Estado del pago</span>${badgePago(pg.estado_pago)}</div>
        ${compro}
        <div class="pago-acciones">
            <button class="btn-mini" data-pago="pagado" data-id="${id}">Aprobar pago</button>
            <button class="btn-mini ghost" data-pago="rechazado" data-id="${id}">Rechazar</button>
        </div>`;

    cont.innerHTML = `<div class="pedido-cols">
        <div class="pedido-col">
            <h4 class="detalle-sub">Productos</h4>
            ${filas}
            <div class="pedido-linea total"><strong>Total ${e ? 'con envío' : ''}</strong><strong class="tabular">${money.format(total)}</strong></div>
            ${pagoHtml}
        </div>
        <div class="pedido-col">
            ${envioHtml || '<p class="td-mute">Este pedido no tiene envío asociado.</p>'}
        </div>
    </div>`;

    cont.querySelectorAll('[data-pago]').forEach((b) => b.addEventListener('click', async () => {
        try {
            await api.post('/api/admin/pedidos/pago', { pedido_id: Number(id), estado_pago: b.dataset.pago });
            toast(b.dataset.pago === 'pagado' ? '✓ Pago aprobado' : '✗ Pago rechazado');
            verPedido(id, numero);
            cargar();   // refresca la columna "Pago" del listado de fondo
        } catch (ex) { alert(ex.message); }
    }));

    // Avisar al cliente por WhatsApp (abre WhatsApp con el mensaje + link ya escritos).
    cont.querySelectorAll('[data-wa]').forEach((b) => b.addEventListener('click', () => {
        const num = waNumero(b.dataset.tel);
        if (!num) { alert('El cliente no tiene un teléfono válido cargado.'); return; }
        const link = `${location.origin}/seguimiento.html?t=${encodeURIComponent(b.dataset.token)}`;
        let msg = `Hola ${b.dataset.dest || ''}, tu pedido ${b.dataset.num} de Britech está en camino. Seguilo en vivo acá: ${link}.`;
        if (b.dataset.efectivo) msg += ` Tené ${money.format(Number(b.dataset.total))} en efectivo listo para pagar.`;
        window.open(`https://wa.me/${num}?text=${encodeURIComponent(msg)}`, '_blank', 'noopener');
    }));
    // Copiar link público de seguimiento (para mandar por WhatsApp a mano).
    cont.querySelectorAll('[data-copy-track]').forEach((b) => b.addEventListener('click', async () => {
        const url = b.dataset.copyTrack;
        try { await navigator.clipboard.writeText(url); toast('✓ Link de seguimiento copiado'); }
        catch (e) { prompt('Link de seguimiento:', url); }
    }));
    // Fijar / corregir el destino en el mapa.
    cont.querySelectorAll('[data-pin-dest]').forEach((b) => b.addEventListener('click', () => {
        const lat = b.dataset.lat ? Number(b.dataset.lat) : null;
        const lng = b.dataset.lng ? Number(b.dataset.lng) : null;
        fijarDestinoMapa(Number(b.dataset.pinDest), lat, lng, () => verPedido(id, numero));
    }));

    const btn = $('envio-guardar');
    if (btn) btn.addEventListener('click', async () => {
        btn.disabled = true;
        try {
            const val = (elId) => { const el = document.getElementById(elId); return el ? el.value : undefined; };
            const datos = {};
            ['destinatario', 'telefono', 'direccion', 'numero', 'referencia', 'localidad', 'provincia', 'cp']
                .forEach((k) => { const v = val('env-' + k); if (v !== undefined) datos[k] = v; });
            const payload = {
                pedido_id: Number(id),
                estado: $('envio-estado').value,
                tracking: val('envio-tracking') || '',
                datos,
            };
            // Repartidor a cargo (solo Moto Express): '' = sin asignar.
            const rep = val('env-repartidor');
            if (rep !== undefined) payload.repartidor_id = rep === '' ? 0 : Number(rep);
            await api.post('/api/admin/pedidos/envio', payload);
            btn.textContent = 'Guardado ✓';
            cargar();   // refresca la columna "Envío" del listado de fondo
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

// ===== Gráfico principal del dashboard (Chart.js, con ejes + selector) =====
let chartMain = null;            // instancia Chart.js
let chartVista = 'ventas';       // vista activa
let chartPeriodo = 'semana';     // sub-período (solo vista ventas)
let DASH = null;                 // último dashboard cargado (datos de los gráficos)

const CHART_VISTAS = [
    { k: 'ventas',     t: 'Ventas' },
    { k: 'finanzas',   t: 'Ingresos / Gastos' },
    { k: 'categorias', t: 'Por categoría' },
    { k: 'top',        t: 'Top productos' },
    { k: 'vendedores', t: 'Por vendedor' },
];

// Colores desde los tokens CSS (se adaptan al tema claro/oscuro).
function cssVar(n) { return getComputedStyle(document.documentElement).getPropertyValue(n).trim(); }
function chartTema() {
    return {
        primary: cssVar('--primary') || '#6d28d9',
        accent:  cssVar('--accent')  || '#db2777',
        danger:  cssVar('--danger')  || '#dc2626',
        ink:     cssVar('--ink')      || '#111',
        muted:   cssVar('--ink-muted')|| '#666',
        line:    cssVar('--line')     || '#e5e7eb',
    };
}
// Formato corto para los ejes de dinero ($1,2 M / $350 k).
function montoCorto(v) {
    v = Number(v) || 0;
    if (Math.abs(v) >= 1e6) return '$' + (v / 1e6).toFixed(1).replace('.', ',') + ' M';
    if (Math.abs(v) >= 1e3) return '$' + Math.round(v / 1e3) + ' k';
    return '$' + v;
}

// Arma la config de Chart.js para la vista pedida. Devuelve null si no hay datos.
function chartConfig(vista, tema) {
    const ejeDinero = {
        ticks: { color: tema.muted, callback: (v) => montoCorto(v) },
        grid: { color: tema.line }, border: { color: tema.line },
    };
    const ejeCat = { ticks: { color: tema.muted }, grid: { display: false }, border: { color: tema.line } };
    const money$ = (ctx) => money.format(ctx.parsed.y ?? ctx.parsed.x ?? ctx.parsed);

    if (vista === 'ventas') {
        const s = DASH.serie || [];
        if (!s.length) return null;
        return {
            type: 'bar',
            data: { labels: s.map((x) => x.label), datasets: [
                { label: 'Físicas (POS)', data: s.map((x) => Number(x.fisica)), backgroundColor: tema.primary, borderRadius: 4, maxBarThickness: 34 },
                { label: 'Online (tienda)', data: s.map((x) => Number(x.online)), backgroundColor: tema.accent, borderRadius: 4, maxBarThickness: 34 },
            ] },
            options: { scales: { x: ejeCat, y: ejeDinero }, plugins: { tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + money.format(c.parsed.y) } } } },
        };
    }
    if (vista === 'finanzas') {
        const s = DASH.serie_finanzas || [];
        if (!s.length) return null;
        return {
            type: 'line',
            data: { labels: s.map((x) => x.label), datasets: [
                { label: 'Ingresos', data: s.map((x) => Number(x.ingresos)), borderColor: tema.primary, backgroundColor: tema.primary + '22', fill: true, tension: 0.3, pointRadius: 3, borderWidth: 2 },
                { label: 'Gastos', data: s.map((x) => Number(x.gastos)), borderColor: tema.danger, backgroundColor: tema.danger + '22', fill: true, tension: 0.3, pointRadius: 3, borderWidth: 2 },
            ] },
            options: { scales: { x: ejeCat, y: ejeDinero }, plugins: { tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + money.format(c.parsed.y) } } } },
        };
    }
    if (vista === 'categorias') {
        const s = DASH.ventas_categoria || [];
        if (!s.length) return null;
        return {
            type: 'bar',
            data: { labels: s.map((x) => x.categoria), datasets: [
                { label: 'Ventas', data: s.map((x) => Number(x.monto)), backgroundColor: tema.primary, borderRadius: 4, maxBarThickness: 26 },
            ] },
            options: { indexAxis: 'y', scales: { x: ejeDinero, y: ejeCat }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => money.format(c.parsed.x) } } } },
        };
    }
    if (vista === 'top') {
        const s = DASH.top_productos || [];
        if (!s.length) return null;
        return {
            type: 'bar',
            data: { labels: s.map((x) => x.nombre), datasets: [
                { label: 'Unidades', data: s.map((x) => Number(x.unidades)), backgroundColor: tema.accent, borderRadius: 4, maxBarThickness: 26 },
            ] },
            options: { indexAxis: 'y', scales: { x: { ticks: { color: tema.muted, precision: 0 }, grid: { color: tema.line } }, y: ejeCat }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => c.parsed.x + ' u' } } } },
        };
    }
    if (vista === 'vendedores') {
        const s = DASH.por_vendedor || [];
        if (!s.length) return null;
        return {
            type: 'bar',
            data: { labels: s.map((x) => x.vendedor), datasets: [
                { label: 'Ventas', data: s.map((x) => Number(x.monto)), backgroundColor: tema.primary, borderRadius: 4, maxBarThickness: 46 },
            ] },
            options: { scales: { x: ejeCat, y: ejeDinero }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => money.format(c.parsed.y) } } } },
        };
    }
    return null;
}

const CHART_TITULOS = {
    ventas: 'Ventas físicas vs online', finanzas: 'Ingresos vs Gastos · últimos 6 meses',
    categorias: 'Ventas por categoría · 90 días', top: 'Productos más vendidos · 30 días',
    vendedores: 'Ventas por vendedor · mes',
};

function dibujarChart() {
    if (!window.Chart) return;
    const tema = chartTema();
    const cfg = chartConfig(chartVista, tema);
    const vacio = $('chart-vacio'), box = document.querySelector('.chart-box');
    $('chart-titulo').textContent = CHART_TITULOS[chartVista] || '';
    $('chart-periodo').classList.toggle('oculto', chartVista !== 'ventas');
    if (chartMain) { chartMain.destroy(); chartMain = null; }
    if (!cfg) { box.classList.add('oculto'); vacio.textContent = 'Sin datos en este período todavía.'; vacio.classList.remove('oculto'); return; }
    box.classList.remove('oculto'); vacio.classList.add('oculto');
    cfg.options = Object.assign({
        responsive: true, maintainAspectRatio: false,
        interaction: { intersect: false, mode: 'index' },
    }, cfg.options);
    cfg.options.plugins = Object.assign({
        legend: { labels: { color: tema.ink, usePointStyle: true, boxWidth: 8, padding: 16 } },
    }, cfg.options.plugins || {});
    chartMain = new Chart($('chart-main').getContext('2d'), cfg);
}

async function cambiarPeriodoChart(p) {
    chartPeriodo = p;
    $('chart-periodo').querySelectorAll('button').forEach((b) => b.classList.toggle('activo', b.dataset.p === p));
    try { DASH.serie = (await api.get('/api/admin/dashboard/serie?periodo=' + p)).serie; } catch {}
    if (chartVista === 'ventas') dibujarChart();
}

async function montarChart() {
    $('chart-switch').innerHTML = CHART_VISTAS.map((v) =>
        `<button data-k="${v.k}" class="${v.k === chartVista ? 'activo' : ''}">${v.t}</button>`).join('');
    $('chart-switch').querySelectorAll('button').forEach((b) => b.addEventListener('click', () => {
        chartVista = b.dataset.k;
        $('chart-switch').querySelectorAll('button').forEach((x) => x.classList.toggle('activo', x === b));
        dibujarChart();
    }));
    $('chart-periodo').querySelectorAll('button').forEach((b) => {
        b.classList.toggle('activo', b.dataset.p === chartPeriodo);
        b.addEventListener('click', () => cambiarPeriodoChart(b.dataset.p));
    });
    // Abre con la serie del período elegido (la del dashboard es "semana").
    if (chartVista === 'ventas' && chartPeriodo !== 'semana') {
        try { DASH.serie = (await api.get('/api/admin/dashboard/serie?periodo=' + chartPeriodo)).serie; } catch {}
    }
    dibujarChart();
}

// Re-dibuja el gráfico al cambiar el tema (colores de ejes/leyenda).
window.redibujarDash = () => { if (chartMain && !vistaInicio.classList.contains('oculto')) dibujarChart(); };

// Barras horizontales genéricas (comparación de valores de un vistazo).
// opts: value(x), label(x), fmt(v), cls?(x), max?
function hbarList(items, opts) {
    const max = Number(opts.max) || Math.max(1, ...items.map((x) => Number(opts.value(x))));
    return `<div class="hbars">${items.map((x) => {
        const v = Number(opts.value(x));
        const w = Math.max(3, Math.round(v / max * 100));
        return `<div class="hbar-row">
            <span class="hbar-label" title="${esc(opts.label(x))}">${esc(opts.label(x))}</span>
            <div class="hbar-track"><div class="hbar-fill ${opts.cls ? opts.cls(x) : ''}" style="width:${w}%"></div></div>
            <span class="hbar-val">${opts.fmt(v)}</span></div>`;
    }).join('')}</div>`;
}

// Gráfico de dona (SVG). segs: [{label, valor, color}]. opts: {c1, c2} = texto centro.
function donut(segs, opts = {}) {
    const total = segs.reduce((s, x) => s + Number(x.valor), 0) || 1;
    const r = 45, c = 2 * Math.PI * r;
    let off = 0;
    const arcs = segs.map((s) => {
        const len = Number(s.valor) / total * c;
        const el = `<circle r="${r}" cx="60" cy="60" fill="none" stroke="${s.color}" stroke-width="15"
            stroke-dasharray="${len} ${c - len}" stroke-dashoffset="${-off}" transform="rotate(-90 60 60)"></circle>`;
        off += len;
        return el;
    }).join('');
    return `<div class="donut-wrap">
        <svg viewBox="0 0 120 120" class="donut">
            <circle r="${r}" cx="60" cy="60" fill="none" stroke="var(--surface-sunk)" stroke-width="15"></circle>
            ${arcs}
            <text x="60" y="58" class="donut-c1">${esc(opts.c1 || '')}</text>
            <text x="60" y="74" class="donut-c2">${esc(opts.c2 || '')}</text>
        </svg>
        <div class="donut-leg">${segs.map((s) =>
            `<span class="lg"><i class="sw" style="background:${s.color}"></i>${esc(s.label)} · <b>${Math.round(Number(s.valor) / total * 100)}%</b></span>`).join('')}</div>
    </div>`;
}

async function renderInicio() {
    let d;
    try { d = await api.get('/api/admin/dashboard'); }
    catch { $('kpis').innerHTML = '<p class="tabla-vacia">No se pudo cargar el dashboard.</p>'; return; }

    // --- Accesos rápidos (Envíos · Repartos · POS) ---
    const sinAsignar = Number(d.envios_sin_asignar || 0);
    const faltantes = Number(d.reposicion_faltantes || 0);
    $('dash-accesos').innerHTML = `
        <a class="acceso-btn" href="/pos.html"><span class="acceso-ic">${ICO.receipt}</span> Vender</a>
        <button class="acceso-btn" data-ir="pedidos"><span class="acceso-ic">${ICO.package}</span> Ver pedidos</button>
        <button class="acceso-btn" data-ir="repartos"><span class="acceso-ic">${ICO.bike}</span> Repartir envíos${sinAsignar > 0 ? `<span class="acceso-badge">${sinAsignar}</span>` : ''}</button>
        <button class="acceso-btn" data-ir="reposicion"><span class="acceso-ic">${ICO.cart}</span> Reponer stock${faltantes > 0 ? `<span class="acceso-badge">${faltantes}</span>` : ''}</button>
        <button class="acceso-btn" data-ir="compras"><span class="acceso-ic">${ICO.download}</span> Cargar compra</button>
        <button class="acceso-btn" data-ir="reportes"><span class="acceso-ic">${ICO.chart}</span> Ver reportes</button>`;
    $('dash-accesos').querySelectorAll('[data-ir]').forEach((b) =>
        b.addEventListener('click', () => seleccionar(b.dataset.ir)));

    // --- KPIs ---
    const comp = d.comparativa || { variacion: null, anterior: 0 };
    const up = comp.variacion !== null && comp.variacion >= 0;
    const varTxt = comp.variacion === null ? '—' : (up ? '▲ ' : '▼ ') + Math.abs(comp.variacion) + '%';
    const compCard = `<div class="card kpi"><div class="card-label">Físicas vs mes ant.</div>
        <div class="card-num ${comp.variacion === null ? '' : (up ? 'var-up' : 'var-down')}">${varTxt}</div>
        <div class="card-foot">mes ant: ${money.format(comp.anterior)}</div></div>`;

    $('kpis').innerHTML = [
        kpi('Ventas de hoy', money.format(d.ventas_hoy.monto), `${d.ventas_hoy.cantidad} venta(s) en el local`),
        kpi('Página hoy', money.format((d.ventas_online_hoy || {}).monto || 0), `${(d.ventas_online_hoy || {}).cantidad || 0} pedido(s) online`),
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

    // --- Gráfico principal (Chart.js con ejes + selector de vistas) ---
    DASH = d;
    chartVista = 'ventas'; chartPeriodo = 'ano';
    montarChart();

    // ===== Paneles secundarios (resumen) =====
    // --- Dona: ventas del mes físicas vs online ---
    const vf = Number(d.ventas_mes || 0), vo = Number(d.ventas_online_mes || 0);
    $('dash-split').innerHTML = `<h3>Ventas del mes · físicas vs online</h3>` + ((vf + vo) === 0
        ? `<p class="dash-vacio">Sin ventas este mes.</p>`
        : donut([
            { label: 'Físicas', valor: vf, color: 'var(--primary)' },
            { label: 'Online', valor: vo, color: 'var(--accent)' },
        ], { c1: money.format(vf + vo), c2: 'total' }));

    // --- Requiere acción (clickable a su sección) ---
    $('dash-acciones').innerHTML = `<h3>Para atender hoy</h3>
        ${accionRow('Pedidos pendientes', d.pedidos_pendientes, 'pedidos')}
        ${accionRow('Envíos sin repartidor', sinAsignar, 'repartos')}
        ${accionRow('Solicitudes mayoristas', d.solicitudes_pendientes, 'solicitudes')}
        ${accionRow('Productos sin stock', d.sin_stock, 'productos')}`;
    $('dash-acciones').querySelectorAll('[data-ir]').forEach((el) =>
        el.addEventListener('click', () => seleccionar(el.dataset.ir)));

    // --- Stock bajo ---
    $('dash-stock').innerHTML = `<h3>Stock bajo (≤ 5)</h3>` + (d.stock_bajo.length === 0
        ? `<p class="dash-vacio">Todo con stock suficiente 👍</p>`
        : hbarList(d.stock_bajo, {
            value: (p) => p.stock, label: (p) => p.nombre, max: 5,
            cls: (p) => Number(p.stock) === 0 ? 'bajo' : (Number(p.stock) <= 2 ? 'medio' : ''),
            fmt: (v) => v + ' u',
        }));

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

    // --- Productos sin movimiento (30 días) ---
    const sm = d.sin_movimiento || { total: 0, lista: [] };
    $('dash-sinmov').innerHTML = `<h3>Sin ventas en 30 días · ${sm.total}</h3>` + (sm.lista.length === 0
        ? `<p class="dash-vacio">Todos tuvieron movimiento 👍</p>`
        : `<ul class="dash-lista">${sm.lista.map((p) =>
            `<li><span>${esc(p.nombre)}</span><span class="dash-num">${Number(p.stock)} u</span></li>`).join('')}</ul>`);
}

// ---- Init ----
// ---- Novedades / avisos (campana con bandeja de leídas) ----
const NOTI_IC = { pedido_nuevo: ICO.cart, comprobante: ICO.receipt, solicitud: ICO.mail, stock_bajo: ICO.alert,
    compra_recibida: ICO.package, reclamo: ICO.megaphone, venta_anulada: ICO.xcircle, compra_creada: ICO.receipt,
    compra_anulada: ICO.xcircle, caja_cierre: ICO.wallet };

function fechaCorta(s) {
    if (!s) return '<span class="td-mute">—</span>';
    const d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return esc(String(s));
    const dia = d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit' });
    // Si el origen no trae hora (ej. "2026-10-26"), mostramos solo la fecha.
    if (!String(s).includes(':')) return dia;
    const hora = d.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
    return dia + ' ' + hora;
}

async function cargarNotificaciones() {
    let r;
    try { r = await api.get('/api/admin/notificaciones'); } catch { return; }
    const badge = $('noti-badge');
    badge.textContent = r.no_leidas > 99 ? '99+' : r.no_leidas;
    badge.classList.toggle('oculto', !r.no_leidas);
    $('btn-noti').classList.toggle('tiene', !!r.no_leidas);

    const lista = r.items.length
        ? r.items.map((n) => `<button class="noti-item ${Number(n.leida) ? '' : 'no-leida'}" data-id="${n.id}" data-ir="${esc(n.ir || '')}" data-ref="${n.ref_id || ''}">
              <span class="noti-ic">${NOTI_IC[n.tipo] || ICO.bell}</span>
              <span class="noti-tx">${esc(n.titulo)}<span class="noti-fecha">${fechaCorta(n.creado_en)}</span></span>
           </button>`).join('')
        : '<p class="noti-vacio">Sin novedades ✓</p>';
    $('noti-panel').innerHTML = `<div class="noti-head">Novedades
            ${r.no_leidas ? '<button class="noti-todas" id="noti-todas">Marcar leídas</button>' : ''}
        </div>${lista}`;

    const todas = $('noti-todas');
    if (todas) todas.addEventListener('click', async (e) => {
        e.stopPropagation();
        try { await api.post('/api/admin/notificaciones/leer-todas', {}); } catch {}
        cargarNotificaciones();
    });
    $('noti-panel').querySelectorAll('.noti-item').forEach((b) => b.addEventListener('click', async () => {
        $('noti-panel').classList.add('oculto');
        if (b.dataset.id) {
            try { await api.post('/api/admin/notificaciones/leer', { id: Number(b.dataset.id) }); } catch {}
            cargarNotificaciones();   // baja el contador y saca el aviso, sin reabrir la campana
        }
        // Pedido con referencia → abre el detalle al instante (ver comprobante, etc.).
        if (b.dataset.ir === 'pedidos' && b.dataset.ref) {
            await seleccionar('pedidos');
            verPedido(Number(b.dataset.ref));
        } else if (b.dataset.ir) {
            seleccionar(b.dataset.ir);
        }
    }));
}

let debounce;
function wire() {
    document.querySelectorAll('.nav-item[data-ent]').forEach((b) =>
        b.addEventListener('click', () => seleccionar(b.dataset.ent)));
    document.querySelectorAll('[data-nav-ic]').forEach((el) => { el.innerHTML = ICO[el.dataset.navIc] || ''; });
    document.querySelectorAll('.nav-grupo-tit').forEach((b) => b.addEventListener('click', () => {
        const grupo = b.closest('.nav-grupo'), abrir = !grupo.classList.contains('abierto');
        document.querySelectorAll('.nav-grupo').forEach((g) => marcarGrupo(g, g === grupo && abrir));
    }));
    $('nav-q').addEventListener('input', (e) => filtrarMenu(e.target.value));
    $('nav-q').addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        const primero = itemsMenu().find((it) => it.offsetParent !== null);
        if (primero) primero.click();
    });
    $('btn-menu').addEventListener('click', () => {
        const abierto = document.querySelector('.side').classList.toggle('abierto');
        $('btn-menu').setAttribute('aria-expanded', abierto);
    });
    window.addEventListener('popstate', () => seleccionar(location.hash.slice(1)));

    // Campana de novedades: refresca al abrir; se cierra al clickear afuera.
    $('btn-noti').addEventListener('click', async (e) => {
        e.stopPropagation();
        const panel = $('noti-panel');
        const abrir = panel.classList.contains('oculto');
        if (abrir) await cargarNotificaciones();
        panel.classList.toggle('oculto', !abrir);
    });
    document.addEventListener('click', (e) => {
        if (!$('noti-panel').classList.contains('oculto')
            && !e.target.closest('#noti-panel') && !e.target.closest('#btn-noti'))
            $('noti-panel').classList.add('oculto');
    });

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

    // Abre la acción principal de una fila (ver detalle o editar).
    function abrirFila(accion, id, numero) {
        if (accion === 'ver') return cfg.verFn ? cfg.verFn(id) : verPedido(id, numero);
        const fila = filasActuales.find((f) => String(f.id) === String(id));
        if (fila) abrirModal('editar', fila);
    }

    $('tbody').addEventListener('click', async (e) => {
        const b = e.target.closest('.btn-fila');
        if (b) {
            const id = b.dataset.id;
            if (b.dataset.accion === 'baja') return baja(id, b.dataset.nombre);
            if (b.dataset.accion === 'ver')  return abrirFila('ver', id, b.dataset.numero);
            if (b.dataset.accion === 'aprobar' || b.dataset.accion === 'rechazar') return resolverSolicitud(id, b.dataset.accion);
            return abrirFila('editar', id);
        }
        // Click en cualquier parte de la fila → acción principal.
        // Ignoramos clicks sobre controles propios (select de estado, link de comprobante…).
        if (e.target.closest('a, select, input, button, label')) return;
        const tr = e.target.closest('tr.fila-link');
        if (tr) abrirFila(tr.dataset.filaAccion, tr.dataset.id, tr.dataset.numero);
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

    // Conectar Telegram: pide el link de vinculación y lo muestra en el modal de detalle.
    if ($('tg-ic')) $('tg-ic').innerHTML = ICO.send || ICO.bell || '';
    const btnTg = $('btn-telegram');
    if (btnTg) btnTg.addEventListener('click', async () => {
        let r;
        try { r = await api.get('/api/admin/telegram/vincular'); } catch { r = null; }
        $('detalle-titulo').textContent = 'Conectar Telegram';
        if (!r || !r.ok) {
            $('detalle-items').innerHTML = `<p>El bot de Telegram todavía no está configurado. Avisá al administrador para cargar el token.</p>`;
        } else {
            $('detalle-items').innerHTML = `
                <ol style="margin:0 0 14px 18px;line-height:1.8">
                    <li>Abrí el bot <b>@${r.bot}</b> en Telegram (buscalo o tocá el botón de abajo).</li>
                    <li>Presioná <b>Iniciar / Start</b>.</li>
                    <li>Pegá este código en el chat y envialo:</li>
                </ol>
                <div style="display:flex;gap:8px;align-items:center;margin:0 0 14px">
                    <code id="tg-codigo" style="font-size:22px;letter-spacing:3px;font-weight:700;padding:8px 14px;border:1px solid var(--borde,#ccc);border-radius:8px">${r.codigo}</code>
                    <button type="button" id="tg-copiar" class="btn-secundario">Copiar</button>
                </div>
                <a href="${r.url}" target="_blank" rel="noopener" class="btn-primary" style="display:inline-block;text-decoration:none">Abrir @${r.bot}</a>
                <p style="color:var(--muted,#888);font-size:12px;margin-top:12px">Cuando el bot te responda “listo”, ya estás conectado.</p>`;
            const bc = $('tg-copiar');
            if (bc) bc.addEventListener('click', async () => {
                try { await navigator.clipboard.writeText(r.codigo); bc.textContent = 'Copiado'; }
                catch { const n = document.getElementById('tg-codigo'); if (n) { const rg = document.createRange(); rg.selectNode(n); getSelection().removeAllRanges(); getSelection().addRange(rg); } }
            });
        }
        $('modal-detalle').classList.remove('oculto');
    });

    // Íconos de línea en el chrome fijo (campana + headers del dashboard).
    $('noti-ic').innerHTML = ICO.bell;
    document.querySelectorAll('[data-ico]').forEach((el) => {
        if (ICO[el.dataset.ico]) el.insertAdjacentHTML('afterbegin', `<span class="ico dash-h-ic">${ICO[el.dataset.ico]}</span>`);
    });

    // Tema claro / oscuro (se guarda en el navegador)
    const pintarTema = () => {
        const oscuro = document.documentElement.dataset.theme === 'dark';
        $('tema-ic').innerHTML = oscuro ? ICO.sun : ICO.moon;
        $('tema-tx').textContent = oscuro ? 'Tema claro' : 'Tema oscuro';
    };
    pintarTema();
    $('btn-tema').addEventListener('click', () => {
        const oscuro = document.documentElement.dataset.theme === 'dark';
        if (oscuro) { delete document.documentElement.dataset.theme; localStorage.setItem('britech_admin_theme', ''); }
        else { document.documentElement.dataset.theme = 'dark'; localStorage.setItem('britech_admin_theme', 'dark'); }
        pintarTema();
        if (window.redibujarDash) window.redibujarDash();
    });
}

let ES_ADMIN = true;   // el vendedor entra con acceso acotado (solo Pedidos)

(async function iniciar() {
    let sesion;
    try { sesion = await api.get('/api/yo'); }
    catch { window.location.href = '/login.html'; return; }
    ES_ADMIN = sesion.usuario.rol === 'admin';
    wire();
    // El vendedor ve su panel y lo operativo (pedidos y envíos); lo demás es solo admin.
    if (!ES_ADMIN) document.querySelectorAll('[data-admin]').forEach((el) => el.classList.add('oculto'));
    // Abre la sección del link (#vendedor desde el POS, o al recargar); si no hay, la de inicio.
    seleccionar(location.hash.slice(1));
    cargarNotificaciones();
    setInterval(cargarNotificaciones, 60000);   // refresca cada 60s
})();

// ===== Modal con mapa para fijar el destino del envío (pin manual del admin) =====
function fijarDestinoMapa(envioId, lat, lng, onSaved) {
    let m = document.getElementById('dest-modal');
    if (!m) {
        m = document.createElement('div');
        m.id = 'dest-modal';
        m.className = 'oculto';
        m.innerHTML = `<div class="dm-card">
            <div class="dm-head"><b>Fijar destino del envío</b><button class="dm-x" aria-label="Cerrar">✕</button></div>
            <div id="dest-map" class="dm-map"></div>
            <p class="dm-hint">Tocá el mapa donde queda la dirección del cliente.</p>
            <button class="btn-primary" id="dest-guardar">Guardar destino</button>
        </div>`;
        document.body.appendChild(m);
    }
    m.classList.remove('oculto');
    const cerrar = () => m.classList.add('oculto');
    m.querySelector('.dm-x').onclick = cerrar;
    m.onclick = (ev) => { if (ev.target === m) cerrar(); };

    let sel = (lat != null && lng != null) ? { lat, lng } : null;
    const centro = sel ? [sel.lat, sel.lng] : [-26.1849, -58.1731];   // Formosa capital
    const guardarBtn = m.querySelector('#dest-guardar');
    guardarBtn.disabled = !sel;

    const init = () => {
        if (!window.L) { setTimeout(init, 200); return; }
        const cont = m.querySelector('#dest-map');
        cont.innerHTML = '';
        const map = L.map(cont).setView(centro, sel ? 16 : 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap', maxZoom: 19 }).addTo(map);
        setTimeout(() => map.invalidateSize(), 80);
        let marker = sel ? L.marker([sel.lat, sel.lng]).addTo(map) : null;
        map.on('click', (ev) => {
            sel = { lat: ev.latlng.lat, lng: ev.latlng.lng };
            if (marker) marker.setLatLng(ev.latlng); else marker = L.marker(ev.latlng).addTo(map);
            guardarBtn.disabled = false;
        });
    };
    init();

    guardarBtn.onclick = async () => {
        if (!sel) return;
        guardarBtn.disabled = true;
        try {
            await api.post('/api/admin/envios/destino', { envio_id: envioId, lat: sel.lat, lng: sel.lng });
            toast('✓ Destino guardado');
            cerrar();
            if (onSaved) onSaved();
        } catch (e) { alert(e.message); guardarBtn.disabled = false; }
    };
}

// Normaliza un teléfono al formato de WhatsApp (Argentina). Mejor esfuerzo; el admin verifica.
function waNumero(tel) {
    let d = String(tel || '').replace(/\D/g, '');
    if (!d) return '';
    if (d.startsWith('54')) return d;        // ya tiene código de país
    if (d.startsWith('0')) d = d.slice(1);   // saca el 0 de larga distancia
    if (d.startsWith('15')) d = d.slice(2);  // saca el 15 del celular local
    return '549' + d;                        // 54 (AR) + 9 (celular) + número
}

// =====================================================================
// Tienda online — catálogo + carrito + cuenta de cliente (login/registro)
// + checkout que crea un Pedido. Consume /api/tienda/*.
// =====================================================================

// $, money, esc, inicial y los componentes de la home están en tienda-home.js
// (se carga antes que este archivo).

// ---- Estado ----
let cliente = null;                 // {id, nombre, email, lista_precio_id} o null
let page = 1, q = '', categoria = '';
let precioMin = '', precioMax = '', orden = 'nombre';   // filtros de la vista catálogo
let categoriasCache = [];
let carrito = cargarCarrito();      // [{id, nombre, precio, cantidad}]
let empresasEnvio = [];             // medios de envío disponibles
let favoritos = new Set();          // ids de productos favoritos del cliente
let pedidoPagoId = null;            // pedido que espera comprobante de transferencia

async function cargarFavoritos() {
    if (!cliente) { favoritos = new Set(); return; }
    try { favoritos = new Set((await api.get('/api/tienda/favoritos')).ids); }
    catch { favoritos = new Set(); }
}

function cargarCarrito() {
    try { return JSON.parse(localStorage.getItem('britech_cart')) || []; }
    catch { return []; }
}
function guardarCarrito() {
    localStorage.setItem('britech_cart', JSON.stringify(carrito));
    actualizarBadge();
}

// ============ Catálogo ============
async function cargarCatalogo() {
    const params = new URLSearchParams({ page, per_page: 12 });
    if (q) params.set('q', q);
    if (categoria) params.set('categoria_id', categoria);
    if (precioMin !== '') params.set('precio_min', precioMin);
    if (precioMax !== '') params.set('precio_max', precioMax);
    if (orden) params.set('orden', orden);
    const r = await api.get(`/api/tienda/catalogo?${params}`);
    $('lista-info').textContent = r.lista_precio_id === 2 ? 'Precios mayoristas' : 'Precios minoristas';
    $('catalogo-count').textContent = `${r.total} producto${r.total === 1 ? '' : 's'}`;
    renderClasificacion();
    renderCatalogo(r.data);
    renderPaginacion(r);
}

// Lista de categorías en el sidebar (clasificación), resaltando la activa.
function renderClasificacion() {
    const cont = $('clasificacion');
    if (!cont) return;
    const items = [{ id: '', nombre: 'Todas las categorías' }, ...categoriasCache];
    cont.innerHTML = items.map((c) =>
        `<button class="clasif-item ${String(c.id) === String(categoria) ? 'activo' : ''}" data-ir-cat="${c.id}" data-titulo="${esc(c.nombre)}">${esc(c.nombre)}</button>`).join('');
    // Marca la categoría activa también en la barra superior.
    document.querySelectorAll('.cat-menu-item').forEach((b) =>
        b.classList.toggle('activo', String(b.dataset.irCat) === String(categoria) && categoria !== ''));
}

// ============ Vistas: HOME (bloques) vs CATÁLOGO (búsqueda / ver todos) ============
async function cargarHome() {
    try {
        const r = await api.get('/api/tienda/home');
        renderHome(r.bloques);
    } catch { $('home-bloques').innerHTML = '<p class="t-vacio">No se pudo cargar la home.</p>'; }
}

function mostrarHome() {
    $('vista-catalogo').classList.add('oculto');
    $('vista-producto').classList.add('oculto');
    $('vista-home').classList.remove('oculto');
    q = ''; categoria = ''; $('buscar').value = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Recarga la vista activa (los precios pueden cambiar según el modo del cliente).
async function refrescarVista() {
    await cargarHome();
    if (!$('vista-catalogo').classList.contains('oculto')) await cargarCatalogo();
}

// Muestra el grid filtrado por búsqueda o categoría.
function mostrarCatalogo({ q: nq = '', categoria: ncat = '', titulo = 'Catálogo' } = {}) {
    q = nq; categoria = ncat; page = 1;
    precioMin = ''; precioMax = ''; orden = 'nombre';   // filtros limpios al entrar
    if ($('precio-min')) { $('precio-min').value = ''; $('precio-max').value = ''; }
    if ($('orden')) $('orden').value = 'nombre';
    $('catalogo-titulo').textContent = titulo;
    $('filtro-categoria').value = ncat || '';
    $('vista-home').classList.add('oculto');
    $('vista-producto').classList.add('oculto');
    $('vista-catalogo').classList.remove('oculto');
    cargarCatalogo();
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Menú de categorías (barra superior) + select de filtro.
async function cargarCategorias() {
    let cats = [];
    try { cats = (await api.get('/api/tienda/categorias')).categorias; } catch {}
    categoriasCache = cats;
    $('cat-menu').innerHTML = cats.map((c) =>
        `<button class="cat-menu-item" data-ir-cat="${c.id}" data-titulo="${esc(c.nombre)}">${esc(c.nombre)}</button>`).join('');
    $('filtro-categoria').insertAdjacentHTML('beforeend',
        cats.map((c) => `<option value="${c.id}">${esc(c.nombre)}</option>`).join(''));
}

// Click en cualquier tarjeta/botón de producto (compartido por home / catálogo / ficha).
function manejarClickProducto(e) {
    const irInicio = e.target.closest('[data-ir-inicio]');
    if (irInicio) { e.preventDefault(); mostrarHome(); return true; }
    const irCat = e.target.closest('[data-ir-cat]');
    if (irCat) {
        e.preventDefault();
        mostrarCatalogo({ categoria: irCat.dataset.irCat, titulo: irCat.dataset.titulo || 'Catálogo' });
        return true;
    }
    const fav = e.target.closest('.fav-btn');
    if (fav) { toggleFavorito(Number(fav.dataset.fav)); return true; }
    const add = e.target.closest('.prod-add');
    if (add) {
        const cant = (cliente && cliente.modo === 'mayorista') ? (Number(add.dataset.min) || 1) : 1;
        agregar(Number(add.dataset.id), add.dataset.nombre, add.dataset.precio, cant);
        return true;
    }
    const card = e.target.closest('.prod');
    if (card && !card.classList.contains('prod-banner')) { mostrarProducto(Number(card.dataset.id)); return true; }
    return false;
}

// Página completa de producto (ficha estilo e-commerce).
async function mostrarProducto(id) {
    $('vista-home').classList.add('oculto');
    $('vista-catalogo').classList.add('oculto');
    $('vista-producto').classList.remove('oculto');
    $('producto-cont').innerHTML = '<p class="t-vacio">Cargando…</p>';
    window.scrollTo({ top: 0, behavior: 'smooth' });

    let p;
    try { p = (await api.get('/api/tienda/producto?id=' + id)).producto; }
    catch { $('producto-cont').innerHTML = '<p class="t-vacio">No se pudo cargar el producto.</p>'; return; }

    let rel = [];
    if (p.categoria_id) {
        try {
            rel = (await api.get('/api/tienda/catalogo?categoria_id=' + p.categoria_id + '&per_page=12')).data
                .filter((x) => Number(x.id) !== Number(p.id));
        } catch {}
    }
    renderProducto(p, rel);
}

function renderCatalogo(items) {
    const cont = $('catalogo');
    cont.innerHTML = items.length === 0
        ? '<p class="t-vacio">No encontramos productos con ese criterio.</p>'
        : items.map(cardProducto).join('');   // misma tarjeta que la home
}

function renderPaginacion(r) {
    const cont = $('paginacion');
    const desde = r.total === 0 ? 0 : (r.page - 1) * r.per_page + 1;
    const hasta = Math.min(r.page * r.per_page, r.total);
    const btn = (etq, dest, dis, act) =>
        `<button class="pag-btn ${act ? 'activo' : ''}" data-page="${dest}" ${dis ? 'disabled' : ''}>${etq}</button>`;
    let nums = '';
    let ini = Math.max(1, r.page - 2), fin = Math.min(r.total_pages, ini + 4);
    ini = Math.max(1, fin - 4);
    for (let p = ini; p <= fin; p++) nums += btn(p, p, false, p === r.page);
    cont.innerHTML = r.total === 0 ? '' : `
        <span class="pag-info">${desde}–${hasta} de ${r.total} productos</span>
        <div class="pag-botones">
            ${btn('‹', r.page - 1, r.page <= 1, false)}${nums}${btn('›', r.page + 1, r.page >= r.total_pages, false)}
        </div>`;
    cont.querySelectorAll('.pag-btn').forEach((b) => b.addEventListener('click', () => {
        if (!b.disabled) { page = Number(b.dataset.page); cargarCatalogo(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
    }));
}

// ============ Favoritos ============
async function toggleFavorito(id) {
    if (!cliente) { abrirAuth(false); return; }   // hay que estar logueado
    try {
        const r = await api.post('/api/tienda/favoritos/toggle', { producto_id: id });
        if (r.favorito) favoritos.add(id); else favoritos.delete(id);
        actualizarCorazones();
    } catch (e) { alert(e.message); }
}

function actualizarCorazones() {
    document.querySelectorAll('.fav-btn').forEach((b) => {
        const on = favoritos.has(Number(b.dataset.fav));
        b.classList.toggle('activo', on);
        b.textContent = on ? '❤️' : '🤍';
    });
}

async function verFavoritos() {
    cerrar('cuenta-menu');
    const cont = $('favoritos-lista');
    cont.innerHTML = '<p class="cart-vacio">Cargando…</p>';
    abrir('modal-favoritos');
    const r = await api.get('/api/tienda/favoritos');
    favoritos = new Set(r.ids);
    if (!r.productos.length) {
        cont.innerHTML = '<p class="cart-vacio">Todavía no tenés favoritos. Tocá el ❤️ en un producto.</p>';
        return;
    }
    cont.innerHTML = r.productos.map((p) => `
        <div class="fav-row">
            <div>
                <div class="cart-nombre">${esc(p.nombre)}</div>
                <div class="cart-precio">${esc(p.categoria || 'General')} · ${money.format(p.precio)}</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <button class="prod-add" data-id="${p.id}" data-nombre="${esc(p.nombre)}" data-precio="${p.precio}" data-min="${Number(p.min_mayorista) || 1}">Agregar</button>
                <button class="fav-quitar" data-fav="${p.id}" title="Quitar de favoritos">❤️</button>
            </div>
        </div>`).join('');
}

// La ficha de producto ahora es una PÁGINA completa (mostrarProducto / renderProducto),
// no un modal. El viejo modal quedó eliminado.

// ============ Carrito ============
function agregar(id, nombre, precio, cant = 1) {
    const item = carrito.find((i) => i.id === id);
    if (item) item.cantidad += cant;
    else carrito.push({ id, nombre, precio: Number(precio), cantidad: cant });
    guardarCarrito();
    // Feedback visual: pulso del contador + toast de confirmación.
    const badge = $('cart-count');
    if (badge) { badge.classList.remove('cart-bump'); void badge.offsetWidth; badge.classList.add('cart-bump'); }
    toast(`✓ ${nombre} agregado al carrito`);
}
function cambiarCantidad(id, delta) {
    const item = carrito.find((i) => i.id === id);
    if (!item) return;
    item.cantidad += delta;
    if (item.cantidad <= 0) carrito = carrito.filter((i) => i.id !== id);
    guardarCarrito();
    renderCarrito();
}
function totalCarrito() {
    return carrito.reduce((s, i) => s + i.precio * i.cantidad, 0);
}
function actualizarBadge() {
    const n = carrito.reduce((s, i) => s + i.cantidad, 0);
    const badge = $('cart-count');
    badge.textContent = n;
    badge.classList.toggle('oculto', n === 0);
}

function renderCarrito() {
    const cont = $('cart-items');
    if (carrito.length === 0) {
        cont.innerHTML = '<p class="cart-vacio">Tu carrito está vacío.</p>';
        $('cart-resumen').classList.add('oculto');
        return;
    }
    cont.innerHTML = carrito.map((i) => `
        <div class="cart-item">
            <div>
                <div class="cart-nombre">${esc(i.nombre)}</div>
                <div class="cart-precio">${money.format(i.precio)} c/u</div>
            </div>
            <div class="cart-cant">
                <button data-id="${i.id}" data-d="-1">−</button>
                <span>${i.cantidad}</span>
                <button data-id="${i.id}" data-d="1">+</button>
            </div>
            <div style="display:flex;align-items:center;gap:8px">
                <span class="cart-sub">${money.format(i.precio * i.cantidad)}</span>
                <button class="cart-quitar" data-id="${i.id}" data-quitar="1" aria-label="Quitar">×</button>
            </div>
        </div>`).join('');
    prepararEnvio();
    actualizarMontos();
    // Siempre arranca en el paso 1 (entrega).
    $('paso-pago').classList.add('oculto');
    $('paso-entrega').classList.remove('oculto');
    $('cart-error').classList.add('oculto');
    $('cart-error2').classList.add('oculto');
    $('cart-resumen').classList.remove('oculto');
}

// Prepara el select de envío (una vez) y prefill de dirección del cliente.
function prepararEnvio() {
    const sel = $('envio-empresa');
    if (!sel.dataset.listo) {
        sel.innerHTML = empresasEnvio.map((e) =>
            `<option value="${e.id}" data-costo="${e.costo_base}" data-retiro="${Number(e.es_retiro) ? 1 : 0}">${esc(e.nombre)} — ${Number(e.costo_base) === 0 ? 'gratis' : money.format(e.costo_base)}</option>`).join('');
        sel.dataset.listo = '1';
    }
    // Prefill con los datos que ya tenga el cliente (si están cargados).
    if (cliente) {
        if (!$('envio-destinatario').value && cliente.nombre) $('envio-destinatario').value = cliente.nombre;
        if (!$('envio-telefono').value && cliente.telefono) $('envio-telefono').value = cliente.telefono;
        if (!$('envio-direccion').value && cliente.direccion) $('envio-direccion').value = cliente.direccion;
        if (!$('envio-localidad').value && cliente.localidad) $('envio-localidad').value = cliente.localidad;
    }
    toggleEnvioCampos();
}

function envioEsRetiro() {
    const opt = $('envio-empresa').selectedOptions[0];
    return opt ? opt.dataset.retiro === '1' : false;
}

// Si es "retiro en local", ocultamos todos los datos de entrega.
function toggleEnvioCampos() {
    $('envio-campos').classList.toggle('oculto', envioEsRetiro());
}

function costoEnvioSel() {
    const opt = $('envio-empresa').selectedOptions[0];
    return opt ? Number(opt.dataset.costo) : 0;
}

function actualizarMontos() {
    const sub = totalCarrito();
    const env = costoEnvioSel();
    $('cart-subtotal').textContent = money.format(sub);
    $('cart-envio').textContent = env === 0 ? 'Gratis' : money.format(env);
    $('cart-total').textContent = money.format(sub + env);
}

// Datos de entrega tal como están en el formulario.
function datosEnvioForm() {
    return {
        empresa_envio_id: Number($('envio-empresa').value) || null,
        destinatario: $('envio-destinatario').value.trim(),
        telefono: $('envio-telefono').value.trim(),
        direccion: $('envio-direccion').value.trim(),
        numero: $('envio-numero').value.trim(),
        referencia: $('envio-referencia').value.trim(),
        localidad: $('envio-localidad').value.trim(),
        provincia: $('envio-provincia').value.trim(),
        cp: $('envio-cp').value.trim(),
    };
}

// Valida el paso de entrega. Referencia es opcional; retiro no pide dirección.
function validarEntrega() {
    const err = $('cart-error'); err.classList.add('oculto');
    if (envioEsRetiro()) return true;
    const e = datosEnvioForm();
    const req = { destinatario: 'quién recibe', telefono: 'teléfono', direccion: 'calle',
        numero: 'número', localidad: 'localidad', provincia: 'provincia', cp: 'código postal' };
    const faltan = Object.keys(req).filter((k) => !e[k]).map((k) => req[k]);
    if (faltan.length) { err.textContent = 'Para el envío falta: ' + faltan.join(', ') + '.'; err.classList.remove('oculto'); return false; }
    return true;
}

// Paso 1 → Paso 2: exige login y entrega válida antes de mostrar el pago.
function irAPago() {
    if (!cliente) { abrirAuth(true); return; }
    if (!validarEntrega()) return;
    $('paso-entrega').classList.add('oculto');
    $('paso-pago').classList.remove('oculto');
}

function volverEntrega() {
    $('paso-pago').classList.add('oculto');
    $('paso-entrega').classList.remove('oculto');
}

function metodoSel() {
    const r = document.querySelector('input[name="metodo"]:checked');
    return r ? r.value : 'transferencia';
}

async function confirmarPedido() {
    if (!cliente) { abrirAuth(true); return; }
    const err = $('cart-error2'); err.classList.add('oculto');
    if (!validarEntrega()) { volverEntrega(); return; }   // por las dudas
    const metodo = metodoSel();
    const btn = $('btn-confirmar-pedido'); btn.disabled = true;
    try {
        const payload = {
            items: carrito.map((i) => ({ producto_id: i.id, cantidad: i.cantidad })),
            envio: datosEnvioForm(),
            metodo_pago: metodo,
        };
        const r = await api.post('/api/tienda/pedidos', payload);
        carrito = []; guardarCarrito();
        cerrar('modal-carrito');
        $('ok-texto').textContent = `Pedido ${r.pedido.numero} · ${money.format(r.pedido.total_final)}`;
        await mostrarPago(metodo, r.pedido.pedido_id, r.pedido.total_final);
        abrir('modal-ok');
    } catch (e) {
        err.textContent = e.message; err.classList.remove('oculto');
    } finally {
        btn.disabled = false;
    }
}

// Según el método: transferencia muestra alias/CBU + comprobante; el resto, un aviso.
async function mostrarPago(metodo, pedidoId, total) {
    if (metodo === 'transferencia') { await mostrarPagoTransferencia(pedidoId, total); return; }
    pedidoPagoId = null;
    const nombre = metodo === 'mercadopago' ? 'Mercado Pago' : 'tarjeta';
    $('pago-datos').innerHTML =
        `<div class="pago-total"><span>Total</span><strong>${money.format(total)}</strong></div>
         <p class="pago-pie">Elegiste pagar con <strong>${esc(nombre)}</strong>. Te vamos a contactar para coordinar el pago.</p>`;
    $('pago-drop').classList.add('oculto');
    $('pago-estado').classList.add('oculto');
    $('ok-pago').classList.remove('oculto');
}

// ============ Pago por transferencia ============
// Muestra los datos de transferencia (config del negocio) + el botón de subir
// comprobante. Si el negocio no cargó alias/CBU, no muestra nada.
async function mostrarPagoTransferencia(pedidoId, total) {
    pedidoPagoId = pedidoId;
    const box = $('ok-pago');
    try {
        const r = await api.get('/api/tienda/pago-info');
        const p = r.pago || {};
        if (!p.alias && !p.cbu) { box.classList.add('oculto'); return; }
        // Total protagonista + alias/CBU en una sola línea, tap para copiar.
        const copiables = [];
        if (p.alias) copiables.push(['Alias', p.alias]);
        if (p.cbu)   copiables.push(['CBU', p.cbu]);
        const pie = [p.titular, p.banco].filter(Boolean).map(esc).join(' · ');
        $('pago-datos').innerHTML =
            `<div class="pago-total"><span>Transferí</span><strong>${money.format(total)}</strong></div>` +
            copiables.map(([k, v]) =>
                `<button type="button" class="pago-copy" data-copiar="${esc(v)}">
                    <span class="pago-copy-k">${esc(k)}</span>
                    <span class="pago-copy-v">${esc(v)}</span>
                    <span class="pago-copy-ic">copiar</span>
                </button>`).join('') +
            (pie ? `<p class="pago-pie">${pie}</p>` : '');
        $('pago-datos').querySelectorAll('[data-copiar]').forEach((b) => b.addEventListener('click', async () => {
            try { await navigator.clipboard.writeText(b.dataset.copiar); toast('✓ Copiado');
                const ic = b.querySelector('.pago-copy-ic'); ic.textContent = '✓ copiado';
                setTimeout(() => { ic.textContent = 'copiar'; }, 1200);
            } catch (e) { toast('No se pudo copiar'); }
        }));
        $('pago-estado').classList.add('oculto');
        $('pago-drop').classList.remove('oculto', 'ok');
        $('pago-file-label').textContent = 'Subí el comprobante';
        box.classList.remove('oculto');
    } catch (e) { box.classList.add('oculto'); }
}

async function subirComprobante(file, pedidoId, labelEl, estadoEl) {
    if (!file || !pedidoId) return;
    const drop = $('pago-drop');
    labelEl.textContent = 'Subiendo…'; drop.classList.add('cargando');
    const fd = new FormData();
    fd.append('pedido_id', pedidoId);
    fd.append('comprobante', file);
    try {
        const resp = await fetch('/api/tienda/comprobante', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await resp.json();
        if (!data.ok) throw new Error(data.error || 'No se pudo subir.');
        drop.classList.remove('cargando'); drop.classList.add('ok');
        labelEl.textContent = '✓ Comprobante recibido';
        estadoEl.textContent = 'Tu pago quedó en revisión.';
        estadoEl.className = 'pago-estado ok';
    } catch (e) {
        drop.classList.remove('cargando');
        labelEl.textContent = 'Reintentar';
        estadoEl.textContent = '⚠ ' + e.message;
        estadoEl.className = 'pago-estado err';
    }
    estadoEl.classList.remove('oculto');
}

// ============ Cuenta (login / registro) ============
function pintarCuenta() {
    $('btn-cuenta').textContent = cliente ? `Hola, ${cliente.nombre.split(' ')[0]} ▾` : 'Ingresar';
}

// Tema de la tienda según el modo: minorista = claro, mayorista = oscuro.
// Usa la View Transitions API para un crossfade fluido y liviano (lo hace el
// compositor, no la CPU). Fallback: cambio instantáneo si no está soportada o
// si el usuario pidió menos movimiento.
function aplicarTemaModo(modo, animar = true) {
    const oscuro = modo === 'mayorista';
    try { localStorage.setItem('britech_modo', oscuro ? 'mayorista' : 'minorista'); } catch (e) {}
    if ((document.documentElement.dataset.theme === 'dark') === oscuro) return;   // sin cambio real
    const aplicar = () => {
        if (oscuro) document.documentElement.dataset.theme = 'dark';
        else delete document.documentElement.dataset.theme;
    };
    const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (animar && !reduce && document.startViewTransition) document.startViewTransition(aplicar);
    else aplicar();
}

// Refresca el estado del cliente desde el backend (incluye estado mayorista + modo).
async function refrescarCliente() {
    try { const r = await api.get('/api/tienda/yo'); cliente = r.cliente; }
    catch { cliente = null; }
    aplicarTemaModo(cliente && cliente.modo === 'mayorista' ? 'mayorista' : 'minorista', false);
    pintarCuenta();
    pintarMayorista();
}

// ============ Acceso mayorista (B2B) ============
function pintarMayorista() {
    const z = $('mayorista-zona');
    if (!cliente) { z.innerHTML = ''; return; }
    if (cliente.mayorista_aprobado) {
        const esMin = cliente.modo !== 'mayorista';
        z.innerHTML = `<div class="modo-toggle" title="Elegí con qué precios navegar">
            <button data-modo="minorista" class="${esMin ? 'activo' : ''}">Minorista</button>
            <button data-modo="mayorista" class="may ${!esMin ? 'activo' : ''}">Mayorista</button>
        </div>`;
    } else if (cliente.solicitud_estado === 'pendiente') {
        z.innerHTML = `<span class="may-pend">Solicitud mayorista en revisión</span>`;
    } else {
        const txt = cliente.solicitud_estado === 'rechazada' ? 'Reintentar cuenta mayorista' : 'Solicitar cuenta mayorista';
        z.innerHTML = `<button class="may-cta" id="btn-solic-may">${txt}</button>`;
    }
}

async function cambiarModo(modo) {
    try {
        await api.post('/api/tienda/modo', { modo });
        cliente.modo = modo;
        aplicarTemaModo(modo);   // minorista → claro · mayorista → oscuro (crossfade)
        pintarMayorista();
        page = 1;
        await refrescarVista();
    } catch (e) { alert(e.message); }
}

async function enviarSolicitudMayorista() {
    const err = $('may-error'); err.classList.add('oculto');
    const btn = $('may-enviar'); btn.disabled = true;
    try {
        await api.post('/api/tienda/solicitud-mayorista', { mensaje: $('may-mensaje').value });
        cliente.solicitud_estado = 'pendiente';
        pintarMayorista();
        cerrar('modal-mayorista');
        $('may-mensaje').value = '';
    } catch (e) {
        err.textContent = e.message; err.classList.remove('oculto');
    } finally {
        btn.disabled = false;
    }
}

function abrirAuth(desdeCheckout = false) {
    // Resetear el estado de "correo enviado" por si venía de un registro previo.
    document.querySelector('#modal-auth .tabs').classList.remove('oculto');
    $('registro-ok').classList.add('oculto');
    $('auth-cerrar').textContent = 'Cancelar';
    $('auth-nota').classList.toggle('oculto', !desdeCheckout);
    seleccionarTab('login');
    abrir('modal-auth');
}

function seleccionarTab(cual) {
    document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('activo', t.dataset.tab === cual));
    $('form-login').classList.toggle('oculto', cual !== 'login');
    $('form-registro').classList.toggle('oculto', cual !== 'registro');
    $('registro-ok')?.classList.add('oculto');       // limpiar mensajes previos
    $('registro-error')?.classList.add('oculto');
    $('login-msg')?.classList.add('oculto');
    $('login-error')?.classList.add('oculto');
    $('link-reenviar')?.classList.add('oculto');
    $('auth-titulo').textContent = cual === 'login' ? 'Ingresá a tu cuenta' : 'Creá tu cuenta';
}

async function trasAutenticar(c) {
    await refrescarCliente();             // trae estado completo (mayorista, modo)
    await cargarFavoritos();
    await pintarStaff();                   // en modo comprador se ocultan los accesos de staff
    cerrar('modal-auth');
    await refrescarVista();               // los precios pueden cambiar según la lista
    if (carrito.length > 0) { renderCarrito(); abrir('modal-carrito'); }  // retomar checkout
}

async function login(e) {
    e.preventDefault();
    const err = $('login-error'); err.classList.add('oculto');
    $('login-msg').classList.add('oculto');
    $('link-reenviar').classList.add('oculto');
    const f = e.target;
    try {
        const r = await api.post('/api/tienda/login', { email: f.email.value, password: f.password.value });
        await trasAutenticar(r.cliente);
        f.reset();
    } catch (ex) {
        err.textContent = ex.message; err.classList.remove('oculto');
        // Si la cuenta no está activada/verificada, ofrecemos reenviar el correo.
        if (/activar|verificar/i.test(ex.message)) $('link-reenviar').classList.remove('oculto');
    }
}

// Pide el mail del form de login; si está vacío, avisa.
function emailLogin() {
    const v = $('form-login').email.value.trim();
    if (!v) { $('login-error').textContent = 'Escribí tu email arriba primero.'; $('login-error').classList.remove('oculto'); return null; }
    return v;
}

async function olvidePassword() {
    const email = emailLogin(); if (!email) return;
    $('login-error').classList.add('oculto');
    try {
        const r = await api.post('/api/tienda/olvide', { email });
        $('login-msg').textContent = '📧 ' + r.mensaje;
        $('login-msg').classList.remove('oculto');
    } catch (ex) { $('login-error').textContent = ex.message; $('login-error').classList.remove('oculto'); }
}

async function reenviarActivacion() {
    const email = emailLogin(); if (!email) return;
    $('login-error').classList.add('oculto');
    try {
        const r = await api.post('/api/tienda/reenviar', { email });
        $('login-msg').textContent = '📧 ' + r.mensaje;
        $('login-msg').classList.remove('oculto');
        $('link-reenviar').classList.add('oculto');
    } catch (ex) { $('login-error').textContent = ex.message; $('login-error').classList.remove('oculto'); }
}

async function registro(e) {
    e.preventDefault();
    const err = $('registro-error'); err.classList.add('oculto');
    const ok = $('registro-ok'); ok.classList.add('oculto');
    const f = e.target;
    const email = f.email.value.trim();
    const btn = f.querySelector('button[type="submit"]');
    const txtBtn = btn.textContent;
    btn.disabled = true; btn.textContent = 'Enviando correo…';   // feedback mientras sale el mail
    try {
        await api.post('/api/tienda/registro', { nombre: f.nombre.value, email, telefono: f.telefono.value });
        f.reset();
        // Estado de éxito bien visible: no hay login automático, hay que activar por correo.
        f.classList.add('oculto');
        $('form-login').classList.add('oculto');
        document.querySelector('#modal-auth .tabs').classList.add('oculto');
        $('auth-nota').classList.add('oculto');
        $('auth-titulo').textContent = '✉️ ¡Revisá tu correo!';
        ok.innerHTML = `Te enviamos un correo a <strong>${esc(email)}</strong>.<br>
            Abrilo y tocá el enlace para <strong>elegir tu contraseña y activar tu cuenta</strong>.
            <br><small>Puede tardar unos minutos. Mirá también el correo no deseado (spam).</small>`;
        ok.classList.remove('oculto');
        $('auth-cerrar').textContent = 'Entendido';
    } catch (ex) {
        err.textContent = ex.message; err.classList.remove('oculto');
    } finally {
        btn.disabled = false; btn.textContent = txtBtn;
    }
}

async function salir() {
    try { await api.post('/api/tienda/logout', {}); } catch {}
    cliente = null; favoritos = new Set();
    aplicarTemaModo('minorista');          // al salir, vuelve al tema claro
    pintarCuenta(); pintarMayorista();
    cerrar('cuenta-menu');
    await pintarStaff();                   // si además hay sesión de staff, vuelve a mostrarse
    await refrescarVista();
}

async function verMisPedidos() {
    cerrar('cuenta-menu');
    const cont = $('pedidos-lista');
    cont.innerHTML = '<p class="cart-vacio">Cargando…</p>';
    abrir('modal-pedidos');
    const r = await api.get('/api/tienda/mis-pedidos');
    if (r.pedidos.length === 0) { cont.innerHTML = '<p class="cart-vacio">Todavía no hiciste pedidos.</p>'; return; }
    cont.innerHTML = r.pedidos.map((p) => {
        const total = Number(p.total) + Number(p.envio_costo || 0);
        const envio = p.envio_estado
            ? `<span class="badge ${esc(p.envio_estado)}" title="Envío">📦 ${esc(p.envio_estado.replace('_', ' '))}</span>`
            : '';
        const seg = p.seguimiento_url
            ? `<a class="badge-link" href="${esc(p.seguimiento_url)}" target="_blank" rel="noopener">🔎 Seguir</a>`
            : '';
        const pago = pagoBadge(p.estado_pago);
        // Si el pago está pendiente o fue rechazado, puede (re)subir el comprobante.
        const subir = (p.estado_pago === 'pendiente' || p.estado_pago === 'rechazado')
            ? `<label class="badge-link">📎 Comprobante<input type="file" accept="image/*,application/pdf" hidden data-pago="${p.id}"></label>`
            : '';
        return `<div class="pedido-row">
            <div>
                <div class="cart-nombre">${esc(p.numero)}</div>
                <div class="cart-precio">${new Date(p.creado_en.replace(' ', 'T')).toLocaleDateString('es-AR')}</div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end">
                <span class="cart-sub">${money.format(total)}</span>
                <span class="badge ${esc(p.estado)}">${esc(p.estado)}</span>
                ${pago}
                ${envio}
                ${seg}
                ${subir}
            </div>
        </div>`;
    }).join('');
}

// Badge del estado de pago (texto legible + clase de color).
function pagoBadge(estado) {
    const t = { pendiente: '⏳ pago pendiente', en_revision: '🔎 pago en revisión',
        pagado: '✓ pagado', rechazado: '✗ pago rechazado' };
    return estado ? `<span class="badge pago-${esc(estado)}">${esc(t[estado] || estado)}</span>` : '';
}

async function subirComprobantePedido(file, pedidoId) {
    if (!file) return;
    const fd = new FormData();
    fd.append('pedido_id', pedidoId);
    fd.append('comprobante', file);
    try {
        const resp = await fetch('/api/tienda/comprobante', { method: 'POST', body: fd, credentials: 'same-origin' });
        const data = await resp.json();
        if (!data.ok) throw new Error(data.error || 'No se pudo subir.');
        toast('✓ Comprobante enviado, pago en revisión');
        verMisPedidos();
    } catch (e) { toast('⚠ ' + e.message); }
}

// ============ Utilidades de modal ============
function abrir(id) { $(id).classList.remove('oculto'); }
function cerrar(id) { $(id).classList.add('oculto'); }

// ============ Init / eventos ============
let debounce;
async function iniciar() {
    // ¿hay cliente logueado? (trae estado mayorista + modo)
    await refrescarCliente();
    actualizarBadge();

    // categorías (menú superior + select del filtro)
    await cargarCategorias();

    // medios de envío para el checkout
    try { empresasEnvio = (await api.get('/api/tienda/envios')).empresas; } catch { empresasEnvio = []; }

    await cargarFavoritos();
    await cargarHome();          // vista por defecto: la home modular

    // Clicks de producto (agregar / favorito / ficha / ir a categoría): home + catálogo + menú
    $('home-bloques').addEventListener('click', manejarClickProducto);
    $('catalogo').addEventListener('click', manejarClickProducto);
    $('cat-menu').addEventListener('click', manejarClickProducto);
    $('producto-cont').addEventListener('click', manejarClickProducto);

    // Navegación entre vistas
    $('ir-inicio').addEventListener('click', mostrarHome);
    $('volver-inicio').addEventListener('click', mostrarHome);

    // búsqueda: escribir lleva al catálogo; vacío vuelve a la home
    $('buscar').addEventListener('input', (e) => {
        clearTimeout(debounce);
        debounce = setTimeout(() => {
            const v = e.target.value.trim();
            if (v === '') mostrarHome();
            else mostrarCatalogo({ q: v, titulo: `Resultados: "${v}"` });
        }, 300);
    });
    $('filtro-categoria').addEventListener('change', (e) => {
        categoria = e.target.value; page = 1; cargarCatalogo();
    });
    // Orden
    $('orden').addEventListener('change', (e) => { orden = e.target.value; page = 1; cargarCatalogo(); });
    // Rango de precio
    const aplicarPrecio = () => {
        precioMin = $('precio-min').value.trim();
        precioMax = $('precio-max').value.trim();
        page = 1; cargarCatalogo();
    };
    $('precio-aplicar').addEventListener('click', aplicarPrecio);
    [$('precio-min'), $('precio-max')].forEach((el) =>
        el.addEventListener('keydown', (e) => { if (e.key === 'Enter') aplicarPrecio(); }));

    // carrito
    $('btn-carrito').addEventListener('click', () => { renderCarrito(); abrir('modal-carrito'); });
    $('cart-cerrar').addEventListener('click', () => cerrar('modal-carrito'));
    $('cart-items').addEventListener('click', (e) => {
        const b = e.target.closest('button');
        if (!b) return;
        if (b.dataset.quitar) cambiarCantidad(Number(b.dataset.id), -1e9);
        else if (b.dataset.d) cambiarCantidad(Number(b.dataset.id), Number(b.dataset.d));
    });
    $('btn-a-pago').addEventListener('click', irAPago);
    $('btn-volver-entrega').addEventListener('click', volverEntrega);
    $('btn-confirmar-pedido').addEventListener('click', confirmarPedido);
    document.querySelectorAll('input[name="metodo"]').forEach((r) => r.addEventListener('change', () => {
        document.querySelectorAll('.metodo').forEach((l) => l.classList.toggle('activo', l.querySelector('input').checked));
    }));
    $('envio-empresa').addEventListener('change', () => { toggleEnvioCampos(); actualizarMontos(); });

    // acceso mayorista (toggle modo / solicitar)
    $('mayorista-zona').addEventListener('click', (e) => {
        const t = e.target.closest('[data-modo]');
        if (t) return cambiarModo(t.dataset.modo);
        if (e.target.closest('#btn-solic-may')) abrir('modal-mayorista');
    });
    $('may-enviar').addEventListener('click', enviarSolicitudMayorista);
    $('may-cerrar').addEventListener('click', () => cerrar('modal-mayorista'));

    // cuenta
    $('btn-cuenta').addEventListener('click', () => {
        if (cliente) $('cuenta-menu').classList.toggle('oculto');
        else abrirAuth(false);
    });
    document.querySelectorAll('.tab').forEach((t) => t.addEventListener('click', () => seleccionarTab(t.dataset.tab)));
    $('form-login').addEventListener('submit', login);
    $('form-registro').addEventListener('submit', registro);
    $('link-olvide').addEventListener('click', olvidePassword);
    $('link-reenviar').addEventListener('click', reenviarActivacion);
    $('auth-cerrar').addEventListener('click', () => cerrar('modal-auth'));
    $('menu-salir').addEventListener('click', salir);
    $('menu-pedidos').addEventListener('click', verMisPedidos);
    $('menu-favoritos').addEventListener('click', verFavoritos);
    $('pedidos-cerrar').addEventListener('click', () => cerrar('modal-pedidos'));
    $('pedidos-lista').addEventListener('change', (e) => {
        const inp = e.target.closest('input[data-pago]');
        if (inp) subirComprobantePedido(inp.files[0], Number(inp.dataset.pago));
    });
    $('favoritos-cerrar').addEventListener('click', () => cerrar('modal-favoritos'));

    // Acciones dentro de "Mis favoritos": agregar al carrito o quitar de favoritos
    $('favoritos-lista').addEventListener('click', async (e) => {
        const add = e.target.closest('.prod-add');
        if (add) {
            const cant = (cliente && cliente.modo === 'mayorista') ? (Number(add.dataset.min) || 1) : 1;
            agregar(Number(add.dataset.id), add.dataset.nombre, add.dataset.precio, cant);
            add.textContent = '✓ Agregado';
            setTimeout(() => { add.textContent = 'Agregar'; }, 1200);
            return;
        }
        const quit = e.target.closest('.fav-quitar');
        if (quit) { await toggleFavorito(Number(quit.dataset.fav)); verFavoritos(); }
    });
    $('ok-cerrar').addEventListener('click', () => cerrar('modal-ok'));
    const subir = (file) => subirComprobante(file, pedidoPagoId, $('pago-file-label'), $('pago-estado'));
    $('pago-file').addEventListener('change', (e) => subir(e.target.files[0]));
    // Drag & drop sobre el dropzone (0 clicks).
    const drop = $('pago-drop');
    ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('drag'); }));
    ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('drag'); }));
    drop.addEventListener('drop', (e) => { const f = e.dataTransfer.files[0]; if (f) subir(f); });

    // cerrar modales al clickear el fondo
    document.querySelectorAll('.modal').forEach((m) =>
        m.addEventListener('click', (e) => { if (e.target === m) m.classList.add('oculto'); }));

    // Si vino desde el login con ?registro=1 y no hay sesión, abrir el registro.
    if (new URLSearchParams(location.search).get('registro') === '1' && !cliente) {
        abrirAuth(false);
        seleccionarTab('registro');
    }

    await pintarStaff();   // botón "Ir al POS / Panel" si además sos del equipo
}

// Si hay un usuario del equipo logueado (admin/vendedor) y NO hay sesión de
// cliente, mostramos un acceso directo a su área de trabajo. Staff y cliente son
// identidades separadas: iniciar sesión como cliente cierra la sesión de staff.
async function pintarStaff() {
    const zona = $('staff-zona');
    // Si estás logueado como CLIENTE, estás en modo comprador: nada de staff.
    // (Un cliente nunca ve el panel admin ni el acceso al POS.)
    if (cliente) { zona.innerHTML = ''; return; }

    let staff = null;
    try { staff = (await api.get('/api/yo')).usuario; } catch { staff = null; }
    if (!staff) { zona.innerHTML = ''; return; }
    const destino = staff.rol === 'admin' ? '/admin.html' : '/pos.html';
    const texto   = staff.rol === 'admin' ? 'Panel admin' : 'Ir al POS';
    zona.innerHTML = `<a class="t-btn staff" href="${destino}">🧰 ${texto}</a>`;
}

iniciar();

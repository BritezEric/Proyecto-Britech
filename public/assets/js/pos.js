// ===== POS - buscar + carrito + descuentos + cobro (pago mixto) + ticket =====

const input       = document.getElementById('busqueda');
const resultados  = document.getElementById('resultados');
const selCliente  = document.getElementById('cliente');
const cajaCarrito = document.getElementById('carrito');
const elSubtotal  = document.getElementById('subtotal');
const descTotalIn = document.getElementById('desc-total');
const elTotal     = document.getElementById('total');
const btnCobrar   = document.getElementById('btn-cobrar');

const modalCobro  = document.getElementById('modal-cobro');
const cobroTotal  = document.getElementById('cobro-total');
const pagosLista  = document.getElementById('pagos-lista');
const btnAgrPago  = document.getElementById('btn-agregar-pago');
const elPagado    = document.getElementById('pagado');
const faltaLabel  = document.getElementById('falta-label');
const elFalta     = document.getElementById('falta');
const cobroError  = document.getElementById('cobro-error');
const btnConfirmar= document.getElementById('btn-confirmar');
const btnCancelar = document.getElementById('btn-cancelar');

const modalTicket = document.getElementById('modal-ticket');
const elTicket    = document.getElementById('ticket');
const btnImprimir = document.getElementById('btn-imprimir');
const btnNuevaVta = document.getElementById('btn-nueva-venta');

// --- Estado ---
let clientes = [], clienteActual = null, tiposPago = [];
let ultimosResultados = [];
let carrito = [];          // { id, nombre, precio, cantidad, stock, sobrePedido, descuento }
let descuentoTotal = 0;    // descuento sobre el total
let pagos = [];            // cobro mixto: [{ tipoId, monto }]
let empresasEnvio = [];    // medios de envío (para el envío opcional de la venta)
let barriosPos = [];       // barrios del Moto Express

const money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });
const r2 = n => Math.round((Number(n) || 0) * 100) / 100;

// ================= CLIENTES / MEDIOS =================

async function cargarClientes() {
    const { datos } = await api.get('/api/clientes');
    clientes = datos;
    clienteActual = clientes[0];
    pintarOpcionesCliente(clientes);
}

// Rellena el select con una lista de clientes (respeta la selección actual si sigue visible).
function pintarOpcionesCliente(lista) {
    const prev = selCliente.value;
    selCliente.innerHTML = lista.map(c =>
        `<option value="${c.id}">${c.nombre} — ${c.lista}</option>`).join('');
    if (lista.some(c => String(c.id) === prev)) selCliente.value = prev;
}

// Busca el cliente por nombre o documento (para cuando NO es consumidor final).
const inpBuscarCli = document.getElementById('cliente-buscar');
if (inpBuscarCli) inpBuscarCli.addEventListener('input', () => {
    const t = inpBuscarCli.value.trim().toLowerCase();
    const lista = t === '' ? clientes : clientes.filter(c =>
        (c.nombre || '').toLowerCase().includes(t) || String(c.documento || '').toLowerCase().includes(t));
    pintarOpcionesCliente(lista);
});
async function cargarTiposPago() {
    const { datos } = await api.get('/api/tipos-pago');
    tiposPago = datos;
}

selCliente.addEventListener('change', async () => {
    clienteActual = clientes.find(c => c.id == selCliente.value);
    await recalcularPreciosCarrito();
    if (input.value.trim() !== '') buscar(input.value);
});

async function recalcularPreciosCarrito() {
    if (carrito.length === 0) return;
    const ids = carrito.map(i => i.id).join(',');
    const { datos } = await api.get(`/api/productos/precios?ids=${ids}&lista=${clienteActual.lista_precio_id}`);
    carrito.forEach(item => {
        const p = datos.find(d => d.producto_id == item.id);
        if (p) item.precio = Number(p.precio);
    });
    renderCarrito();
}

// ================= BÚSQUEDA =================

async function buscar(texto) {
    if (texto.trim() === '') {
        resultados.innerHTML = '<p class="vacio">Escribí o escaneá para buscar un producto.</p>';
        ultimosResultados = [];
        return;
    }
    try {
        const lista = clienteActual ? clienteActual.lista_precio_id : 1;
        const { datos } = await api.get(`/api/productos/buscar?q=${encodeURIComponent(texto)}&lista=${lista}`);
        ultimosResultados = datos;
        mostrarResultados(datos);
    } catch (e) {
        resultados.innerHTML = '<p class="error">Error al buscar.</p>';
        console.error(e);
    }
}

function mostrarResultados(productos) {
    if (productos.length === 0) {
        resultados.innerHTML = '<p class="vacio">No se encontró ningún producto.</p>';
        return;
    }
    resultados.innerHTML = productos.map(p => {
        const sinStock    = p.stock == 0 && p.es_sobre_pedido == 0;
        const sobrePedido = p.es_sobre_pedido == 1;
        let estado = `<span class="stock ok">Stock: ${p.stock}</span>`;
        if (sobrePedido) estado = `<span class="stock pedido">Sobre pedido</span>`;
        else if (sinStock) estado = `<span class="stock agotado">Sin stock</span>`;
        return `
            <article class="producto ${sinStock ? 'inhabilitado' : 'clic'}" data-id="${p.id}">
                <div class="info">
                    <span class="nombre">${p.nombre}</span>
                    <span class="meta">${p.sku ?? ''} · ${p.codigo_barras ?? 'sin código'}</span>
                </div>
                <div class="derecha">
                    <span class="precio">${money.format(p.precio ?? 0)}</span>
                    ${estado}
                </div>
            </article>`;
    }).join('');
}

resultados.addEventListener('click', (e) => {
    const fila = e.target.closest('.producto');
    if (!fila || fila.classList.contains('inhabilitado')) return;
    agregarAlCarrito(Number(fila.dataset.id));
});

// ================= CARRITO =================

function agregarAlCarrito(idProducto) {
    const p = ultimosResultados.find(r => r.id == idProducto);
    if (!p) return;
    if (p.stock == 0 && p.es_sobre_pedido == 0) { alert('Este producto no tiene stock.'); return; }
    const existente = carrito.find(i => i.id == p.id);
    if (existente) {
        if (!p.es_sobre_pedido && existente.cantidad + 1 > p.stock) { alert('No hay más stock.'); return; }
        existente.cantidad++;
    } else {
        carrito.push({
            id: p.id, nombre: p.nombre, precio: Number(p.precio),
            cantidad: 1, stock: Number(p.stock), sobrePedido: p.es_sobre_pedido == 1, descuento: 0,
        });
    }
    renderCarrito();
}

function cambiarCantidad(idProducto, delta) {
    const item = carrito.find(i => i.id == idProducto);
    if (!item) return;
    const nueva = item.cantidad + delta;
    if (nueva <= 0) { quitar(idProducto); return; }
    if (!item.sobrePedido && nueva > item.stock) { alert('No hay más stock.'); return; }
    item.cantidad = nueva;
    if (item.descuento > item.precio * item.cantidad) item.descuento = 0; // desc no puede superar el bruto
    renderCarrito();
}

function quitar(idProducto) {
    carrito = carrito.filter(i => i.id != idProducto);
    renderCarrito();
}

const brutoLinea     = i => i.precio * i.cantidad;
const subtotalLinea  = i => Math.max(0, r2(brutoLinea(i) - (i.descuento || 0)));
const calcularSubtotal = () => r2(carrito.reduce((s, i) => s + subtotalLinea(i), 0));
const descuentoAplicado = () => Math.min(r2(descuentoTotal), calcularSubtotal());
const calcularTotal  = () => r2(calcularSubtotal() - descuentoAplicado());

function renderCarrito() {
    if (carrito.length === 0) {
        cajaCarrito.innerHTML = '<p class="vacio-carrito">Carrito vacío.<br>Buscá o escaneá un producto para agregarlo.</p>';
    } else {
        cajaCarrito.innerHTML = carrito.map(item => `
            <div class="linea" data-id="${item.id}">
                <div class="linea-info">
                    <span class="linea-nombre">${item.nombre}${item.sobrePedido ? ' <em>(pedido)</em>' : ''}</span>
                    <span class="linea-precio">
                        ${money.format(item.precio)} c/u
                        <label class="desc-linea">Desc $
                            <input type="number" min="0" step="0.01" value="${item.descuento || 0}" data-accion="desc">
                        </label>
                    </span>
                </div>
                <div class="linea-cant">
                    <button data-accion="menos" aria-label="Restar">−</button>
                    <span>${item.cantidad}</span>
                    <button data-accion="mas" aria-label="Sumar">+</button>
                </div>
                <span class="linea-sub">${money.format(subtotalLinea(item))}</span>
                <button class="linea-quitar" data-accion="quitar" aria-label="Quitar">×</button>
            </div>`).join('');
    }
    actualizarTotales();
}

function actualizarTotales() {
    elSubtotal.textContent = money.format(calcularSubtotal());
    elTotal.textContent = money.format(calcularTotal());
    btnCobrar.disabled = carrito.length === 0;
}

// Clicks (cantidad / quitar)
cajaCarrito.addEventListener('click', (e) => {
    const boton = e.target.closest('button[data-accion]');
    if (!boton) return;
    const id = Number(boton.closest('.linea').dataset.id);
    if (boton.dataset.accion === 'mas')    cambiarCantidad(id, +1);
    if (boton.dataset.accion === 'menos')  cambiarCantidad(id, -1);
    if (boton.dataset.accion === 'quitar') quitar(id);
});

// Descuento por línea (sin re-render, para no perder el foco del input)
cajaCarrito.addEventListener('input', (e) => {
    const inp = e.target.closest('input[data-accion="desc"]');
    if (!inp) return;
    const linea = inp.closest('.linea');
    const item = carrito.find(i => i.id == linea.dataset.id);
    let d = Number(inp.value) || 0;
    const bruto = brutoLinea(item);
    if (d < 0) d = 0;
    if (d > bruto) { d = bruto; inp.value = d; }
    item.descuento = d;
    linea.querySelector('.linea-sub').textContent = money.format(subtotalLinea(item));
    actualizarTotales();
});

// Descuento total
descTotalIn.addEventListener('input', () => {
    let d = Number(descTotalIn.value) || 0;
    if (d < 0) { d = 0; descTotalIn.value = 0; }
    descuentoTotal = d;
    actualizarTotales();
});

// ================= COBRO (pago mixto) =================

btnCobrar.addEventListener('click', () => {
    if (carrito.length === 0) return;
    if (!cajaAbierta) { abrirModalCaja(); return; }   // sin caja abierta no se vende
    cobroError.classList.add('oculto');
    const total = r2(calcularTotal() + costoEnvioPOS());
    // arranca con un pago por el total (productos + envío), en el primer medio
    pagos = [{ tipoId: tiposPago[0].id, monto: total }];
    renderPagos();
    modalCobro.classList.remove('oculto');
});

btnCancelar.addEventListener('click', () => modalCobro.classList.add('oculto'));

function renderPagos() {
    pagosLista.innerHTML = pagos.map((p, idx) => `
        <div class="pago-row" data-idx="${idx}">
            <select data-campo="tipo">
                ${tiposPago.map(t => `<option value="${t.id}" ${t.id == p.tipoId ? 'selected' : ''}>${t.nombre}</option>`).join('')}
            </select>
            <input type="number" min="0" step="0.01" data-campo="monto" value="${p.monto}">
            <button data-campo="quitar" aria-label="Quitar" ${pagos.length === 1 ? 'style="visibility:hidden"' : ''}>×</button>
        </div>`).join('');
    actualizarEstadoCobro();
}

function actualizarEstadoCobro() {
    const envio  = costoEnvioPOS();
    const total  = r2(calcularTotal() + envio);
    cobroTotal.textContent = money.format(total);
    const pagado = r2(pagos.reduce((s, p) => s + (Number(p.monto) || 0), 0));
    const dif    = r2(total - pagado);
    elPagado.textContent = money.format(pagado);
    if (dif > 0)      { faltaLabel.textContent = 'Falta';  elFalta.textContent = money.format(dif);  elFalta.className = 'neg'; }
    else if (dif < 0) { faltaLabel.textContent = 'Sobra';  elFalta.textContent = money.format(-dif); elFalta.className = 'neg'; }
    else              { faltaLabel.textContent = 'Falta';  elFalta.textContent = money.format(0);    elFalta.className = 'ok'; }
    btnConfirmar.disabled = Math.abs(dif) > 0.001;
}

pagosLista.addEventListener('input', (e) => {
    const row = e.target.closest('.pago-row');
    const idx = Number(row.dataset.idx);
    if (e.target.dataset.campo === 'monto') pagos[idx].monto = Number(e.target.value) || 0;
    if (e.target.dataset.campo === 'tipo')  pagos[idx].tipoId = Number(e.target.value);
    actualizarEstadoCobro();
});
pagosLista.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-campo="quitar"]');
    if (!btn) return;
    pagos.splice(Number(btn.closest('.pago-row').dataset.idx), 1);
    renderPagos();
});
btnAgrPago.addEventListener('click', () => {
    const falta = r2(calcularTotal() + costoEnvioPOS() - pagos.reduce((s, p) => s + (Number(p.monto) || 0), 0));
    pagos.push({ tipoId: tiposPago[0].id, monto: falta > 0 ? falta : 0 });
    renderPagos();
});

btnConfirmar.addEventListener('click', async () => {
    const payload = {
        cliente_id: Number(clienteActual.id),
        descuento: descuentoAplicado(),
        items: carrito.map(i => ({ producto_id: i.id, cantidad: i.cantidad, descuento_linea: i.descuento || 0 })),
        pagos: pagos.filter(p => Number(p.monto) > 0).map(p => ({ tipo_pago_id: Number(p.tipoId), monto: Number(p.monto) })),
    };
    const envio = envioPayloadPOS();
    if (envio) payload.envio = envio;
    btnConfirmar.disabled = true;
    try {
        const resp = await api.post('/api/ventas', payload);
        if (!resp.ok) throw new Error(resp.error || 'No se pudo registrar la venta.');
        const pagosTicket = payload.pagos.map(p => ({
            nombre: tiposPago.find(t => t.id == p.tipo_pago_id)?.nombre ?? '', monto: p.monto,
        }));
        renderTicket(resp.venta.numero, [...carrito], clienteActual.nombre,
                     calcularSubtotal(), descuentoAplicado(),
                     Number(resp.venta.envio_costo || 0), Number(resp.venta.total), pagosTicket);
        document.getElementById('btn-pdf').href = '/api/ventas/ticket?id=' + resp.venta.venta_id;
        // reset
        carrito = []; descuentoTotal = 0; descTotalIn.value = 0;
        document.getElementById('envio-on').checked = false;
        document.getElementById('envio-box').classList.add('oculto');
        document.getElementById('pos-envio-barrio').value = '';
        document.querySelectorAll('.pos-barrio-card.activo').forEach((c) => c.classList.remove('activo'));
        ['pos-env-destinatario', 'pos-env-telefono', 'pos-env-calle', 'pos-env-altura', 'pos-env-ref']
            .forEach((id) => { document.getElementById(id).value = ''; });
        renderCarrito();
        cargarCaja();   // actualiza el resumen del turno (efectivo esperado)
        modalCobro.classList.add('oculto');
        modalTicket.classList.remove('oculto');
    } catch (e) {
        cobroError.textContent = e.message;
        cobroError.classList.remove('oculto');
        actualizarEstadoCobro();
    }
});

// ================= TICKET =================

function renderTicket(numero, items, cliente, subtotal, descuento, envioCosto, total, pagosTk) {
    const fecha = new Date().toLocaleString('es-AR');
    const lineas = items.map(i => `
        <div class="t-item"><span>${i.cantidad} x ${i.nombre}</span><span>${money.format(subtotalLinea(i))}</span></div>`).join('');
    const filaDesc = descuento > 0
        ? `<div class="t-item"><span>Descuento</span><span>- ${money.format(descuento)}</span></div>` : '';
    const filaEnvio = envioCosto > 0
        ? `<div class="t-item"><span>🛵 Envío</span><span>${money.format(envioCosto)}</span></div>` : '';
    const filasPago = pagosTk.map(p =>
        `<div class="t-pago">Pago (${p.nombre}): ${money.format(p.monto)}</div>`).join('');

    elTicket.innerHTML = `
        <div class="t-cabecera"><strong>BRITECH</strong><span>Punto de Venta</span></div>
        <div class="t-sep"></div>
        <div class="t-datos">
            <div><span>Ticket:</span><span>${numero}</span></div>
            <div><span>Fecha:</span><span>${fecha}</span></div>
            <div><span>Cliente:</span><span>${cliente}</span></div>
        </div>
        <div class="t-sep"></div>
        ${lineas}
        <div class="t-sep"></div>
        <div class="t-item"><span>Subtotal</span><span>${money.format(subtotal)}</span></div>
        ${filaDesc}
        ${filaEnvio}
        <div class="t-total"><span>TOTAL</span><span>${money.format(total)}</span></div>
        ${filasPago}
        <div class="t-sep"></div>
        <div class="t-gracias">¡Gracias por su compra!</div>`;
}

btnImprimir.addEventListener('click', () => window.print());
btnNuevaVta.addEventListener('click', () => {
    modalTicket.classList.add('oculto');
    input.value = ''; buscar(''); input.focus();
});

// ================= BUSCADOR (eventos) =================

let temporizador = null;
input.addEventListener('input', () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => buscar(input.value), 250);
});
input.addEventListener('keydown', async (e) => {
    if (e.key !== 'Enter') return;
    clearTimeout(temporizador);
    await buscar(input.value);
    if (ultimosResultados.length === 1) {
        agregarAlCarrito(ultimosResultados[0].id);
        input.value = ''; buscar('');
    }
    input.focus();
});

// ================= ARRANQUE =================

(async function iniciar() {
    // Sin sesión activa → al login (protección del lado del cliente;
    // la seguridad real está en el backend, que exige login en cada endpoint).
    let sesion;
    try { sesion = await api.get('/api/yo'); }
    catch { window.location.href = '/login.html'; return; }
    document.getElementById('vendedor-nombre').textContent = sesion.usuario.nombre;

    // El link a "Usuarios" solo lo ve el admin.
    if (sesion.usuario.rol === 'admin') {
        document.getElementById('link-panel').classList.remove('oculto');
        document.getElementById('link-ventas').classList.remove('oculto');
        document.getElementById('link-usuarios').classList.remove('oculto');
    }

    document.getElementById('btn-logout').addEventListener('click', async () => {
        try { await api.post('/api/logout', {}); } catch {}
        window.location.href = '/login.html';
    });

    // En paralelo (antes iba una tras otra): clientes, medios de pago, envío y caja.
    await Promise.all([cargarClientes(), cargarTiposPago(), configurarEnvio(), cargarCaja()]);
    renderCarrito();
    input.focus();
})();

// ================= ENVÍO OPCIONAL DE LA VENTA =================
async function configurarEnvio() {
    try { empresasEnvio = (await api.get('/api/tienda/envios')).empresas; } catch { empresasEnvio = []; }
    try { barriosPos = (await api.get('/api/tienda/barrios')).barrios; } catch { barriosPos = []; }

    const selE = document.getElementById('pos-envio-empresa');
    selE.innerHTML = empresasEnvio.map((e) => {
        const moto = Number(e.es_moto) ? 1 : 0;
        const etq = moto ? 'según barrio' : (Number(e.costo_base) === 0 ? 'gratis' : money.format(e.costo_base));
        return `<option value="${e.id}" data-retiro="${Number(e.es_retiro) ? 1 : 0}" data-moto="${moto}">${e.nombre} — ${etq}</option>`;
    }).join('');

    // Barrios como botones (se elige tocando uno).
    document.getElementById('pos-barrio-cards').innerHTML = barriosPos.map((b) =>
        `<button type="button" class="pos-barrio-card" data-barrio="${b.id}">
            <span class="pos-barrio-nom">${b.nombre}</span>
            <span class="pos-barrio-precio">${money.format(b.costo)}</span>
        </button>`).join('');

    document.getElementById('envio-on').addEventListener('change', (e) => {
        document.getElementById('envio-box').classList.toggle('oculto', !e.target.checked);
        toggleEnvioPOS();
        recalcularCobro();
    });
    selE.addEventListener('change', () => { toggleEnvioPOS(); recalcularCobro(); });
    document.getElementById('pos-barrio-cards').addEventListener('click', (e) => {
        const c = e.target.closest('.pos-barrio-card');
        if (!c) return;
        document.getElementById('pos-envio-barrio').value = c.dataset.barrio;
        document.querySelectorAll('.pos-barrio-card').forEach((x) => x.classList.toggle('activo', x === c));
        recalcularCobro();
    });
    toggleEnvioPOS();
}

// Muestra según el medio: RETIRO en local → sin barrio ni dirección; MOTO → barrios
// (botones) + dirección; otro correo → solo dirección.
function toggleEnvioPOS() {
    const opt = document.getElementById('pos-envio-empresa').selectedOptions[0];
    const retiro = !!(opt && opt.dataset.retiro === '1');
    const moto   = !!(opt && opt.dataset.moto === '1');
    document.getElementById('pos-barrio-cards').classList.toggle('oculto', !moto);
    document.getElementById('pos-envio-grid').classList.toggle('oculto', retiro);
    document.getElementById('pos-envio-hint').classList.toggle('oculto', retiro);
    if (retiro) { document.getElementById('pos-envio-barrio').value = ''; }
}

// Costo del envío según lo elegido (moto = barrio, otros = costo base, retiro/off = 0).
function costoEnvioPOS() {
    if (!document.getElementById('envio-on').checked) return 0;
    const selE = document.getElementById('pos-envio-empresa');
    const opt = selE.selectedOptions[0];
    if (!opt || opt.dataset.retiro === '1') return 0;
    if (opt.dataset.moto === '1') {
        const b = document.getElementById('pos-envio-barrio').value;
        const barrio = barriosPos.find((x) => String(x.id) === String(b));
        return barrio ? Number(barrio.costo) : 0;
    }
    const emp = empresasEnvio.find((e) => String(e.id) === String(selE.value));
    return emp ? Number(emp.costo_base) : 0;
}

// Al cambiar el envío dentro del cobro, re-sincroniza el total a cobrar.
function recalcularCobro() {
    if (modalCobro.classList.contains('oculto')) return;
    if (pagos.length === 1) pagos[0].monto = r2(calcularTotal() + costoEnvioPOS());
    renderPagos();
}

// Devuelve el objeto de envío para el payload, o null si no hay envío.
function envioPayloadPOS() {
    if (!document.getElementById('envio-on').checked) return null;
    const g = (id) => document.getElementById(id).value.trim();
    return {
        empresa_envio_id: Number(document.getElementById('pos-envio-empresa').value) || null,
        barrio_id: Number(document.getElementById('pos-envio-barrio').value) || null,
        destinatario: g('pos-env-destinatario'),
        telefono: g('pos-env-telefono'),
        direccion: g('pos-env-calle'),
        numero: g('pos-env-altura'),
        referencia: g('pos-env-ref'),
    };
}

// ================= CAJA (apertura / movimientos / cierre) =================
// Un solo modal con 4 vistas: apertura · turno (Resumen / Movimientos) ·
// cierre en 3 pasos (Efectivo → Otros medios → Confirmar) · resultado.
// Piezas visuales compartidas con el admin en caja-ui.js (CajaUI) + caja.css.
const CX = window.CajaUI;
let cajaAbierta = false;
let cajaInfo = null;            // respuesta de /api/caja/estado (caja abierta)
let cajaUltimo = null;          // último cierre del vendedor (sugerencia de apertura)
let cajaFalla = null;           // error al consultar la caja (ej: falta actualizar la base)
let cajaDenoms = [20000, 10000, 2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];
const cx = {                    // estado de la UI del modal
    vista: 'turno', tab: 'resumen', tlFiltro: 'todo', movTipo: null,
    paso: 1, modoBilletes: true, conteo: {}, sueltos: 0, total: '', verif: {}, obs: '',
    cierre: null, enviando: false,
};
const MOV_INFO = {
    retiro:  { label: 'Retiro',  ico: 'arrowUp',   hint: 'Sacás efectivo de la caja (ej: llevarlo a tesorería).', sug: ['A tesorería', 'Depósito en banco', 'Pago a proveedor'] },
    ingreso: { label: 'Ingreso', ico: 'arrowDown', hint: 'Ponés efectivo en la caja (ej: más cambio).',           sug: ['Cambio', 'Reposición de caja'] },
    gasto:   { label: 'Gasto',   ico: 'receipt',   hint: 'Pagás algo con la caja. Se anota también en Gastos.',   sug: ['Artículos de limpieza', 'Librería', 'Flete / cadete', 'Comida'] },
};

async function cargarCaja() {
    try {
        const r = await api.get('/api/caja/estado');
        cajaAbierta = !!r.abierta;
        cajaInfo = r.abierta ? r : null;
        cajaUltimo = r.ultimo_cierre || null;
        if (Array.isArray(r.denominaciones)) cajaDenoms = r.denominaciones;
        cajaFalla = null;
    } catch (e) { cajaAbierta = false; cajaInfo = null; cajaFalla = e.message || 'No se pudo consultar la caja.'; }
    pintarCaja();
}

function pintarCaja() {
    const btn = document.getElementById('btn-caja');
    const pill = document.getElementById('caja-pill');
    btn.classList.toggle('caja-cerrada', !cajaAbierta);
    btn.classList.toggle('cx-caja-abierta', cajaAbierta);
    pill.innerHTML = cajaAbierta
        ? `<span class="cx-dot" aria-hidden="true"></span>Caja #${cajaInfo.caja.id} <span class="cx-pill-monto">${CX.m(cajaInfo.esperado)}</span>`
        : (cajaFalla ? `${CX.ico('alert')} Caja: error` : `<span class="cx-dot" aria-hidden="true"></span>Abrir caja`);
    btn.title = cajaAbierta ? 'Efectivo en caja · ver movimientos o cerrar' : 'Abrí la caja para poder vender';
    if (!document.getElementById('modal-caja-ov').classList.contains('oculto')) pintarModalCaja();
}

function abrirModalCaja() {
    if (cx.vista !== 'listo' && cx.vista !== 'cierre') { cx.vista = 'turno'; cx.tab = 'resumen'; }
    document.getElementById('modal-caja-ov').classList.remove('oculto');
    pintarModalCaja();
}
function cerrarModalCaja() {
    document.getElementById('modal-caja-ov').classList.add('oculto');
    if (cx.vista === 'listo') { cx.vista = 'turno'; cx.cierre = null; }
    cx.movTipo = null;
}

// ---- Armado del modal (cabecera / cuerpo / pie) ----
function cxShell({ titulo, sub = '', antesBody = '', body, despuesBody = '', pie = '', angosto = false, alto = false }) {
    const cont = document.getElementById('caja-cont');
    cont.classList.toggle('cx-angosto', angosto);
    cont.classList.toggle('cx-alto', alto);
    cont.innerHTML = `
        <header class="cx-mhead">
            <div><h2 id="cx-titulo">${titulo}</h2>${sub ? `<p class="cx-sub">${sub}</p>` : ''}</div>
            <button type="button" class="cx-x" data-cx="cerrar" aria-label="Cerrar">${CX.ico('x')}</button>
        </header>
        ${antesBody}
        <div class="cx-mbody">${body}</div>
        ${despuesBody}
        ${pie ? `<footer class="cx-mfoot">${pie}</footer>` : ''}`;
    cont.querySelector('[data-cx="cerrar"]').addEventListener('click', cerrarModalCaja);
    return cont;
}

function pintarModalCaja() {
    if (cajaFalla) return pintarCajaFalla();
    if (cx.vista === 'listo' && cx.cierre) return pintarCajaListo();
    if (!cajaAbierta) return pintarCajaApertura();
    if (cx.vista === 'cierre') return pintarCajaCierre();
    return pintarCajaTurno();
}

function cajaError(msg) {
    const e = document.getElementById('cx-error');
    if (e) { e.textContent = msg; e.classList.remove('oculto'); e.scrollIntoView({ block: 'nearest' }); }
}

// ---- 0) Error al consultar la caja ----
function pintarCajaFalla() {
    const cont = cxShell({
        titulo: 'Caja no disponible', angosto: true,
        body: `<div class="cx-banner cx-bad">${CX.ico('alert')}<div><strong>No se pudo cargar la caja</strong><p>${CX.esc(cajaFalla)}</p></div></div>`,
        pie: `<span class="cx-grow"></span><button type="button" class="cx-btn cx-btn-sec" data-reint>Reintentar</button>`,
    });
    cont.querySelector('[data-reint]').addEventListener('click', cargarCaja);
}

// ---- 1) Apertura ----
function pintarCajaApertura() {
    const sugeridos = [0, 10000, 20000, 50000];
    const ult = cajaUltimo && cajaUltimo.monto_contado != null ? Number(cajaUltimo.monto_contado) : null;
    const chips = (ult != null ? `<button type="button" class="cx-chip" data-monto="${ult}">Lo que quedó: ${CX.m(ult)}</button>` : '')
        + sugeridos.filter((v) => v !== ult).map((v) => `<button type="button" class="cx-chip" data-monto="${v}">${CX.m(v)}</button>`).join('');
    const cont = cxShell({
        titulo: 'Abrir caja', angosto: true,
        body: `<div class="cx-apertura">
                <div class="cx-hero-ic" aria-hidden="true">${CX.ico('wallet')}</div>
                <p>Contá el cambio con el que arrancás. Sin caja abierta no se puede vender.</p>
                <label class="cx-amount" for="cx-apertura"><span>$</span>
                    <input id="cx-apertura" type="number" inputmode="decimal" min="0" step="0.01" placeholder="0" value="${ult ?? ''}" aria-label="Monto de apertura"></label>
                <div class="cx-quick">${chips}</div>
                <p id="cx-error" class="cx-err oculto" role="alert"></p>
            </div>`,
        pie: `<button type="button" class="cx-btn cx-btn-pri cx-btn-full" id="cx-abrir"></button>`,
    });
    const inp = cont.querySelector('#cx-apertura');
    const btn = cont.querySelector('#cx-abrir');
    const sync = () => { btn.innerHTML = `${CX.ico('unlock')} Abrir caja con ${CX.m(parseFloat(inp.value) || 0)}`; };
    inp.addEventListener('input', sync);
    inp.addEventListener('keydown', (e) => { if (e.key === 'Enter') btn.click(); });
    cont.querySelectorAll('[data-monto]').forEach((c) => c.addEventListener('click', () => { inp.value = c.dataset.monto; sync(); inp.focus(); }));
    btn.addEventListener('click', accionAbrirCaja);
    sync();
    setTimeout(() => { inp.focus(); inp.select(); }, 50);
}

// ---- 2) Turno ----
function pintarCajaTurno() {
    const c = cajaInfo.caja, res = cajaInfo.resumen, tl = cajaInfo.timeline || [];
    const tabs = `<nav class="cx-tabs" role="tablist" style="margin-top:0">
        <button type="button" role="tab" class="cx-tab${cx.tab === 'resumen' ? ' on' : ''}" data-tab="resumen" aria-selected="${cx.tab === 'resumen'}">Resumen</button>
        <button type="button" role="tab" class="cx-tab${cx.tab === 'movs' ? ' on' : ''}" data-tab="movs" aria-selected="${cx.tab === 'movs'}">Movimientos <small>${tl.length}</small></button>
    </nav>`;

    let body;
    if (cx.tab === 'movs') {
        body = CX.timeline(tl, cx.tlFiltro);
    } else {
        const mov = cx.movTipo ? MOV_INFO[cx.movTipo] : null;
        body = `
        <div class="cx-hero">
            <div class="cx-hero-ef"><span>Efectivo en caja</span><strong>${CX.m(cajaInfo.esperado)}</strong>
                <small>Lo que debería haber en el cajón ahora</small></div>
            <div class="cx-cifras">
                <div class="cx-cifra"><span>Vendido</span><strong>${CX.m(res.total_ventas)}</strong></div>
                <div class="cx-cifra"><span>Ventas</span><strong>${res.ventas}</strong></div>
                <div class="cx-cifra"><span>Ticket promedio</span><strong>${CX.m(res.ticket_promedio)}</strong></div>
                ${res.anuladas ? `<div class="cx-cifra"><span>Anuladas</span><strong>${res.anuladas} · ${CX.m(res.total_anulado)}</strong></div>` : ''}
            </div>
        </div>
        <div class="cx-cols">
            <section><h3 class="cx-sec-t">Cobrado por forma de pago</h3>${CX.formas(res.formas || [])}</section>
            <section><h3 class="cx-sec-t">Efectivo</h3>${CX.ledger(c.monto_apertura, res, cajaInfo.esperado)}</section>
        </div>
        <div class="cx-acciones" role="group" aria-label="Movimiento de efectivo">
            ${Object.entries(MOV_INFO).map(([k, v]) => `<button type="button" class="cx-accion${cx.movTipo === k ? ' on' : ''}" data-mov="${k}" aria-expanded="${cx.movTipo === k}">${CX.ico(v.ico)} ${v.label}</button>`).join('')}
        </div>
        ${mov ? `<div class="cx-mov-form">
            <div class="cx-mov-grid">
                <label class="cx-amount cx-amount-sm"><span>$</span><input id="cx-mov-monto" type="number" inputmode="decimal" min="0" step="0.01" placeholder="Monto" aria-label="Monto"></label>
                <input id="cx-mov-motivo" class="cx-input" type="text" maxlength="200" placeholder="${cx.movTipo === 'ingreso' ? 'Motivo (opcional)' : 'Motivo'}" aria-label="Motivo">
            </div>
            <div class="cx-quick">${mov.sug.map((s) => `<button type="button" class="cx-chip" data-sug="${CX.esc(s)}">${CX.esc(s)}</button>`).join('')}</div>
            <div class="cx-mov-hint"><span>${mov.hint}</span>${cx.movTipo !== 'ingreso' ? `<span>Disponible: <b class="cx-num">${CX.m(cajaInfo.esperado)}</b></span>` : ''}</div>
            <p id="cx-error" class="cx-err oculto" role="alert"></p>
            <div class="cx-mov-btns">
                <button type="button" class="cx-btn cx-btn-ghost" data-mov-cancel>Cancelar</button>
                <button type="button" class="cx-btn cx-btn-pri" id="cx-mov-ok">Registrar ${mov.label.toLowerCase()}</button>
            </div>
        </div>` : ''}`;
    }

    const cont = cxShell({
        titulo: `Caja #${c.id}`,
        sub: `${CX.badge('Abierta', 'info')} <span>${CX.esc(c.vendedor || '')} · desde ${CX.hora(c.abierta_en)} (${CX.duracion(c.abierta_en)})</span>`,
        antesBody: `<div style="padding:0 var(--sp-5)">${tabs}</div>`,
        body,
        pie: `<a class="cx-btn cx-btn-ghost" href="/api/caja/reporte?id=${c.id}" target="_blank" rel="noopener">${CX.ico('file')} Reporte parcial</a>
              <span class="cx-grow"></span>
              <button type="button" class="cx-btn cx-btn-pri" id="cx-ir-cierre">${CX.ico('lock')} Cerrar caja</button>`,
    });

    cont.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => { cx.tab = b.dataset.tab; cx.movTipo = null; pintarModalCaja(); }));
    if (cx.tab === 'movs') {
        CX.bindTimeline(cont, tl);
        cont.querySelectorAll('[data-tl-filtro]').forEach((b) => b.addEventListener('click', () => { cx.tlFiltro = b.dataset.tlFiltro; }));
    }
    cont.querySelectorAll('[data-mov]').forEach((b) => b.addEventListener('click', () => {
        cx.movTipo = cx.movTipo === b.dataset.mov ? null : b.dataset.mov; pintarModalCaja();
        const m = document.getElementById('cx-mov-monto');
        if (m) { m.focus({ preventScroll: true }); document.querySelector('.cx-mov-form').scrollIntoView({ block: 'end', behavior: 'smooth' }); }
    }));
    if (cx.movTipo) {
        const monto = cont.querySelector('#cx-mov-monto'), motivo = cont.querySelector('#cx-mov-motivo');
        cont.querySelectorAll('[data-sug]').forEach((s) => s.addEventListener('click', () => { motivo.value = s.dataset.sug; motivo.focus(); }));
        cont.querySelector('[data-mov-cancel]').addEventListener('click', () => { cx.movTipo = null; pintarModalCaja(); });
        cont.querySelector('#cx-mov-ok').addEventListener('click', accionMovimientoCaja);
        [monto, motivo].forEach((i) => i.addEventListener('keydown', (e) => { if (e.key === 'Enter') accionMovimientoCaja(); }));
    }
    cont.querySelector('#cx-ir-cierre').addEventListener('click', () => {
        Object.assign(cx, { vista: 'cierre', paso: 1, conteo: {}, sueltos: 0, total: '', verif: {}, obs: '', movTipo: null });
        pintarModalCaja();
    });
}

// ---- 3) Cierre en 3 pasos ----
const otrasFormas = () => (cajaInfo.resumen.formas || []).filter((f) => !f.es_efectivo && (f.operaciones > 0 || f.total > 0));

function contadoActual() {
    if (!cx.modoBilletes) { const v = parseFloat(cx.total); return isNaN(v) ? null : CX.r2(v); }
    let t = Number(cx.sueltos) || 0;
    for (const [d, n] of Object.entries(cx.conteo)) t += Number(d) * n;
    return CX.r2(t);
}
function declaradoDe(f) {
    const v = cx.verif[f.tipo_pago_id];
    if (!v || v.ok) return Number(f.total);
    const n = parseFloat(v.monto); return isNaN(n) ? Number(f.total) : CX.r2(n);
}
function filasArqueo() {
    const contado = contadoActual() ?? 0;
    const filas = [{ nombre: 'Efectivo', esperado: cajaInfo.esperado, declarado: contado, diferencia: CX.r2(contado - cajaInfo.esperado) }];
    otrasFormas().forEach((f) => { const d = declaradoDe(f); filas.push({ nombre: f.nombre, esperado: f.total, declarado: d, diferencia: CX.r2(d - f.total) }); });
    return filas;
}

function pintarCajaCierre() {
    const otras = otrasFormas();
    const pasos = ['Contar efectivo', otras.length ? 'Otros medios' : 'Otros medios (no hubo)', 'Confirmar'];
    const steps = `<ol class="cx-steps" aria-label="Pasos del cierre">${pasos.map((p, i) =>
        `<li class="${i + 1 < cx.paso ? 'hecho' : ''}${i + 1 === cx.paso ? ' on' : ''}"${i + 1 === cx.paso ? ' aria-current="step"' : ''}>${i + 1}. ${p}</li>`).join('')}</ol>`;

    let body = '', sumbar = '', pie = '';
    if (cx.paso === 1) {
        const billetes = cajaDenoms.map((d) => {
            const n = cx.conteo[d] || 0;
            return `<div class="cx-billete${n ? ' tiene' : ''}" data-den-card="${d}">
                <span class="cx-bil-den">${CX.m(d)}</span>
                <div class="cx-stepper">
                    <button type="button" data-menos="${d}" aria-label="Uno menos de ${CX.m(d)}">${CX.ico('minus')}</button>
                    <input type="number" inputmode="numeric" min="0" step="1" data-den="${d}" value="${n || ''}" placeholder="0" aria-label="Cantidad de ${CX.m(d)}">
                    <button type="button" data-mas="${d}" aria-label="Uno más de ${CX.m(d)}">${CX.ico('plus')}</button>
                </div>
                <span class="cx-bil-sub" data-sub="${d}">${n ? '= ' + CX.m(d * n) : ''}</span>
            </div>`;
        }).join('');
        body = `
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:var(--sp-4)">
                <p class="cx-mute" style="margin:0;font-size:var(--text-sm)">Contá la plata del cajón. El sistema compara con lo esperado.</p>
                <div class="cx-seg" role="group" aria-label="Forma de contar">
                    <button type="button" data-modo="billetes" class="${cx.modoBilletes ? 'on' : ''}" aria-pressed="${cx.modoBilletes}">Por billete</button>
                    <button type="button" data-modo="total" class="${cx.modoBilletes ? '' : 'on'}" aria-pressed="${!cx.modoBilletes}">Total</button>
                </div>
            </div>
            ${cx.modoBilletes ? `<div class="cx-billetes">${billetes}
                <div class="cx-billete${cx.sueltos ? ' tiene' : ''}"><span class="cx-bil-den">Monedas / sueltos</span>
                    <label class="cx-amount cx-amount-sm" style="grid-row:1/span 2;grid-column:2;max-width:120px"><span>$</span>
                    <input type="number" inputmode="decimal" min="0" step="0.01" id="cx-sueltos" value="${cx.sueltos || ''}" placeholder="0" aria-label="Monedas o sueltos"></label>
                    <span class="cx-bil-sub"></span></div>
              </div>`
            : `<label class="cx-amount" for="cx-total"><span>$</span><input id="cx-total" type="number" inputmode="decimal" min="0" step="0.01" placeholder="Efectivo contado" value="${cx.total}"></label>`}
            <p id="cx-error" class="cx-err oculto" role="alert"></p>`;
        sumbar = `<div class="cx-sumbar" aria-live="polite">
            <div><span>Esperado</span><strong>${CX.m(cajaInfo.esperado)}</strong></div>
            <div><span>Contado</span><strong id="cx-contado">—</strong></div>
            <div><span>Resultado</span><span id="cx-resultado"></span></div></div>`;
        pie = `<button type="button" class="cx-btn cx-btn-ghost" data-volver>${CX.ico('chevronLeft')} Volver al turno</button><span class="cx-grow"></span>
               <button type="button" class="cx-btn cx-btn-pri" data-sig>Siguiente ${CX.ico('arrowRight')}</button>`;
    } else if (cx.paso === 2) {
        body = otras.length ? `<p class="cx-mute" style="margin:0 0 var(--sp-4);font-size:var(--text-sm)">Revisá en el homebanking / posnet que haya entrado lo mismo que registró el sistema.</p>`
            + otras.map((f) => {
                const v = cx.verif[f.tipo_pago_id] || { ok: true, monto: '' };
                const dif = CX.r2(declaradoDe(f) - f.total);
                return `<div class="cx-verif" data-verif="${f.tipo_pago_id}">
                    <div class="cx-verif-top">
                        <div><strong>${CX.esc(f.nombre)}</strong><small>${f.operaciones} ${f.operaciones === 1 ? 'cobro' : 'cobros'} · esperado ${CX.m(f.total)}</small></div>
                        <div class="cx-seg" role="group" aria-label="¿Coincide ${CX.esc(f.nombre)}?">
                            <button type="button" data-ok="1" class="${v.ok ? 'on' : ''}" aria-pressed="${v.ok}">${CX.ico('check')} Coincide</button>
                            <button type="button" data-ok="0" class="${v.ok ? '' : 'on'}" aria-pressed="${!v.ok}">No coincide</button>
                        </div>
                    </div>
                    ${v.ok ? '' : `<div class="cx-verif-in">
                        <label class="cx-amount cx-amount-sm"><span>$</span><input type="number" inputmode="decimal" min="0" step="0.01" data-monto-forma="${f.tipo_pago_id}" value="${v.monto}" placeholder="Monto que entró" aria-label="Monto real de ${CX.esc(f.nombre)}"></label>
                        <span data-dif-forma="${f.tipo_pago_id}">${CX.difBadge(dif)}</span></div>`}
                </div>`;
            }).join('')
            : `<div class="cx-banner cx-neutro">${CX.ico('checkCircle')}<div><strong>No hubo cobros con otros medios</strong><p>Todo lo de este turno fue en efectivo.</p></div></div>`;
        pie = `<button type="button" class="cx-btn cx-btn-ghost" data-atras>${CX.ico('chevronLeft')} Atrás</button><span class="cx-grow"></span>
               <button type="button" class="cx-btn cx-btn-pri" data-sig>Siguiente ${CX.ico('arrowRight')}</button>`;
    } else {
        const filas = filasArqueo();
        const hayDif = filas.some((f) => !CX.cuadra(f.diferencia));
        const difTot = CX.r2(filas.reduce((a, f) => a + f.diferencia, 0));
        body = `
            ${hayDif
                ? `<div class="cx-banner cx-bad">${CX.ico('alert')}<div><strong>La caja no cuadra (${CX.difTexto(difTot)})</strong><p>Revisá el conteo o explicá la diferencia abajo.</p></div></div>`
                : `<div class="cx-banner cx-ok">${CX.ico('checkCircle')}<div><strong>Todo cuadra</strong><p>El efectivo y los demás medios coinciden con lo esperado.</p></div></div>`}
            <div style="margin:var(--sp-4) 0">${CX.arqueo(filas)}</div>
            <p class="cx-resumen-linea">${cajaInfo.resumen.ventas} ventas por ${CX.m(cajaInfo.resumen.total_ventas)} · turno de ${CX.duracion(cajaInfo.caja.abierta_en)}</p>
            <label class="cx-sec-t" for="cx-obs" style="display:block">Observación ${hayDif ? '<span class="cx-req">· obligatoria</span>' : '(opcional)'}</label>
            <textarea id="cx-obs" class="cx-input cx-obs" maxlength="250" placeholder="${hayDif ? 'Explicá por qué no cuadra' : 'Algo para dejar anotado'}">${CX.esc(cx.obs)}</textarea>
            ${hayDif ? `<div class="cx-quick" style="justify-content:flex-start">${['Vuelto mal dado', 'Transferencia pendiente de acreditar', 'Error al cargar una venta'].map((s) => `<button type="button" class="cx-chip" data-obs="${s}">${s}</button>`).join('')}</div>` : ''}
            <p id="cx-error" class="cx-err oculto" role="alert"></p>
            <p class="cx-mute" style="font-size:var(--text-xs);margin:var(--sp-3) 0 0">Al confirmar, la caja se cierra y no se puede reabrir.</p>`;
        pie = `<button type="button" class="cx-btn cx-btn-ghost" data-atras>${CX.ico('chevronLeft')} Atrás</button><span class="cx-grow"></span>
               <button type="button" class="cx-btn cx-btn-pri" id="cx-confirmar"${cx.enviando ? ' disabled' : ''}>${CX.ico('lock')} ${cx.enviando ? 'Cerrando…' : 'Confirmar cierre'}</button>`;
    }

    const cont = cxShell({ titulo: `Cerrar caja #${cajaInfo.caja.id}`, sub: `<span>${CX.esc(cajaInfo.caja.vendedor || '')} · ${CX.duracion(cajaInfo.caja.abierta_en)} de turno</span>`,
                           antesBody: steps, body, despuesBody: sumbar, pie, alto: true });

    const volver = cont.querySelector('[data-volver]');
    if (volver) volver.addEventListener('click', () => { cx.vista = 'turno'; pintarModalCaja(); });
    const atras = cont.querySelector('[data-atras]');
    if (atras) atras.addEventListener('click', () => { cx.paso = cx.paso === 3 && !otras.length ? 1 : cx.paso - 1; pintarModalCaja(); });
    const sig = cont.querySelector('[data-sig]');
    if (sig) sig.addEventListener('click', () => {
        if (cx.paso === 1 && contadoActual() === null) { cajaError('Ingresá el efectivo contado.'); return; }
        cx.paso = cx.paso === 1 && !otras.length ? 3 : cx.paso + 1;
        pintarModalCaja();
    });

    if (cx.paso === 1) {
        const actualizar = () => {
            const c = contadoActual();
            cont.querySelector('#cx-contado').textContent = c === null ? '—' : CX.m(c);
            cont.querySelector('#cx-resultado').innerHTML = c === null ? '' : CX.difBadge(CX.r2(c - cajaInfo.esperado));
        };
        const setN = (d, n) => {
            n = Math.max(0, Math.floor(n) || 0);
            if (n) cx.conteo[d] = n; else delete cx.conteo[d];
            const inp = cont.querySelector(`[data-den="${d}"]`); if (inp && document.activeElement !== inp) inp.value = n || '';
            cont.querySelector(`[data-sub="${d}"]`).textContent = n ? '= ' + CX.m(d * n) : '';
            cont.querySelector(`[data-den-card="${d}"]`).classList.toggle('tiene', !!n);
            actualizar();
        };
        cont.querySelectorAll('[data-modo]').forEach((b) => b.addEventListener('click', () => { cx.modoBilletes = b.dataset.modo === 'billetes'; pintarModalCaja(); }));
        cont.querySelectorAll('[data-mas]').forEach((b) => b.addEventListener('click', () => setN(+b.dataset.mas, (cx.conteo[b.dataset.mas] || 0) + 1)));
        cont.querySelectorAll('[data-menos]').forEach((b) => b.addEventListener('click', () => setN(+b.dataset.menos, (cx.conteo[b.dataset.menos] || 0) - 1)));
        cont.querySelectorAll('[data-den]').forEach((i) => {
            i.addEventListener('input', () => setN(+i.dataset.den, parseInt(i.value, 10)));
            i.addEventListener('focus', () => i.select());
        });
        const su = cont.querySelector('#cx-sueltos');
        if (su) su.addEventListener('input', () => { cx.sueltos = parseFloat(su.value) || 0; su.closest('.cx-billete').classList.toggle('tiene', !!cx.sueltos); actualizar(); });
        const tot = cont.querySelector('#cx-total');
        if (tot) { tot.addEventListener('input', () => { cx.total = tot.value; actualizar(); }); tot.addEventListener('keydown', (e) => { if (e.key === 'Enter') sig.click(); }); setTimeout(() => tot.focus(), 50); }
        actualizar();
    } else if (cx.paso === 2) {
        cont.querySelectorAll('[data-verif]').forEach((card) => {
            const id = card.dataset.verif;
            card.querySelectorAll('[data-ok]').forEach((b) => b.addEventListener('click', () => {
                cx.verif[id] = { ...(cx.verif[id] || { monto: '' }), ok: b.dataset.ok === '1' };
                pintarModalCaja();
                const inp = document.querySelector(`[data-monto-forma="${id}"]`); if (inp) inp.focus();
            }));
            const inp = card.querySelector('[data-monto-forma]');
            if (inp) inp.addEventListener('input', () => {
                cx.verif[id].monto = inp.value;
                const f = otras.find((x) => String(x.tipo_pago_id) === id);
                card.querySelector(`[data-dif-forma="${id}"]`).innerHTML = CX.difBadge(CX.r2(declaradoDe(f) - f.total));
            });
        });
    } else {
        const obs = cont.querySelector('#cx-obs');
        obs.addEventListener('input', () => { cx.obs = obs.value; });
        cont.querySelectorAll('[data-obs]').forEach((b) => b.addEventListener('click', () => { obs.value = cx.obs = b.dataset.obs; obs.focus(); }));
        cont.querySelector('#cx-confirmar').addEventListener('click', accionCerrarCaja);
    }
}

// ---- 4) Resultado ----
function pintarCajaListo() {
    const r = cx.cierre;
    const ok = r.formas.every((f) => CX.cuadra(f.diferencia));
    const difTot = CX.r2(r.formas.reduce((a, f) => a + Number(f.diferencia), 0));
    const cont = cxShell({
        titulo: 'Caja cerrada', angosto: true,
        body: `<div class="cx-listo">
                <div class="cx-hero-ic ${ok ? 'cx-ok' : 'cx-bad'}" aria-hidden="true">${CX.ico(ok ? 'checkCircle' : 'alert')}</div>
                <h3>${ok ? 'Cerró perfecto' : CX.difTexto(difTot)}</h3>
                <p>Caja #${r.caja_id} · ${r.resumen.ventas} ventas · ${CX.m(r.resumen.total_ventas)}</p>
            </div>
            ${CX.arqueo(r.formas)}
            <a class="cx-btn cx-btn-pri cx-btn-full" style="margin-top:var(--sp-5)" href="/api/caja/reporte?id=${r.caja_id}" target="_blank" rel="noopener">${CX.ico('download')} Descargar reporte de cierre</a>`,
        pie: `<button type="button" class="cx-btn cx-btn-ghost" data-cx-fin>Listo</button><span class="cx-grow"></span>
              <button type="button" class="cx-btn cx-btn-sec" data-cx-nueva>${CX.ico('unlock')} Abrir otra caja</button>`,
    });
    cont.querySelector('[data-cx-fin]').addEventListener('click', cerrarModalCaja);
    cont.querySelector('[data-cx-nueva]').addEventListener('click', () => { cx.vista = 'turno'; cx.cierre = null; pintarModalCaja(); });
}

// ---- Acciones ----
async function accionAbrirCaja() {
    const btn = document.getElementById('cx-abrir');
    const monto = parseFloat(document.getElementById('cx-apertura').value) || 0;
    btn.disabled = true;
    try {
        await api.post('/api/caja/abrir', { monto });
        cx.vista = 'turno'; cx.tab = 'resumen';
        await cargarCaja();
        cerrarModalCaja();
        CX.toast(`Caja abierta con ${CX.m(monto)}`);
        if (typeof input !== 'undefined') input.focus();
    } catch (e) { cajaError(e.message); btn.disabled = false; }
}

async function accionMovimientoCaja() {
    const tipo = cx.movTipo;
    const monto = parseFloat(document.getElementById('cx-mov-monto').value) || 0;
    const motivo = document.getElementById('cx-mov-motivo').value;
    const btn = document.getElementById('cx-mov-ok');
    if (monto <= 0) { cajaError('Ingresá un monto mayor a 0.'); return; }
    btn.disabled = true;
    try {
        await api.post('/api/caja/movimiento', { tipo, monto, motivo });
        cx.movTipo = null;
        await cargarCaja();
        CX.toast(`${MOV_INFO[tipo].label} de ${CX.m(monto)} registrado`);
    } catch (e) { cajaError(e.message); btn.disabled = false; }
}

async function accionCerrarCaja() {
    const filas = filasArqueo();
    const hayDif = filas.some((f) => !CX.cuadra(f.diferencia));
    if (hayDif && !cx.obs.trim()) { cajaError('La caja no cuadra: dejá una observación explicando la diferencia.'); document.getElementById('cx-obs').focus(); return; }

    const declarados = {};
    otrasFormas().forEach((f) => { declarados[f.tipo_pago_id] = declaradoDe(f); });
    const body = { declarados, observacion: cx.obs };
    const hayBilletes = Object.keys(cx.conteo).length || cx.sueltos;
    if (cx.modoBilletes && hayBilletes) { body.billetes = { ...cx.conteo }; body.monedas_sueltas = Number(cx.sueltos) || 0; }
    else body.contado = contadoActual() ?? 0;

    cx.enviando = true; pintarModalCaja();
    try {
        const r = await api.post('/api/caja/cerrar', body);
        cx.cierre = r.cierre; cx.vista = 'listo';
        Object.assign(cx, { conteo: {}, sueltos: 0, total: '', verif: {}, obs: '', paso: 1 });
        await cargarCaja();
    } catch (e) { cx.enviando = false; pintarModalCaja(); cajaError(e.message); return; }
    cx.enviando = false;
    pintarModalCaja();
}

document.getElementById('btn-caja').addEventListener('click', abrirModalCaja);
document.getElementById('modal-caja-ov').addEventListener('click', (e) => {
    if (e.target === document.getElementById('modal-caja-ov') && cx.vista !== 'cierre') cerrarModalCaja();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !document.getElementById('modal-caja-ov').classList.contains('oculto')) cerrarModalCaja();
});

// ================= VOZ (tocar para hablar) =================
// Reusa las funciones del POS. El cerebro son reglas: entiende "agregá X",
// "sacá X", "cobrar / vendí / hice una venta", "limpiar". Cuando haya API key,
// se cambia Voz.procesar por un endpoint con Claude sin tocar estos handlers.
const NUMEROS = { un: 1, uno: 1, una: 1, dos: 2, tres: 3, cuatro: 4, cinco: 5, seis: 6, siete: 7, ocho: 8, nueve: 9, diez: 10 };

// Separa "dos coca", "3 de birra", "una coca" → { qty, nombre }.
function vozCantNombre(resto) {
    let r = resto.trim();
    const tok = r.split(/\s+/);
    let qty = 1;
    if (/^\d+$/.test(tok[0])) { qty = parseInt(tok[0], 10); tok.shift(); }
    else if (NUMEROS[tok[0]] !== undefined) { qty = NUMEROS[tok[0]]; tok.shift(); }
    if (tok[0] === 'de') tok.shift();
    return { qty: Math.max(1, qty), nombre: tok.join(' ').trim() };
}

async function vozAgregar(nombre, qty) {
    if (!nombre) return 'Decime qué producto agregar.';
    await buscar(nombre);
    if (!ultimosResultados.length) return `No encontré "${nombre}".`;
    const p = ultimosResultados[0];
    agregarAlCarrito(p.id);
    const item = carrito.find((i) => i.id == p.id);
    if (item && qty > 1) { item.cantidad = item.sobrePedido ? qty : Math.min(qty, item.stock); renderCarrito(); }
    input.value = ''; buscar('');
    return `Agregué ${qty > 1 ? qty + ' × ' : ''}${p.nombre}.`;
}

function vozQuitar(nombre) {
    const n = Voz.norm(nombre);
    const item = carrito.find((i) => Voz.norm(i.nombre).includes(n));
    if (!item) return `No está "${nombre}" en el carrito.`;
    quitar(item.id);
    return `Saqué ${item.nombre}.`;
}

function vozLimpiar() {
    carrito = []; descuentoTotal = 0; descTotalIn.value = 0; renderCarrito();
    return 'Vacié el carrito.';
}

function vozCobrar() {
    if (!carrito.length) return 'El carrito está vacío.';
    btnCobrar.click();
    return cajaAbierta ? 'Abriendo el cobro.' : 'Primero abrí la caja.';
}

const VOZ_INTENTS = [
    { // cobrar / vender / finalizar (sin verbo de agregar)
        match: (t) => /\b(cobrar|cobra|a cobrar|finaliz\w*|termin\w*|pagar|hice una venta|vend[ií])\b/.test(t)
            && !/\b(agreg\w*|sum\w*|pon\w*|anad\w*|carg\w*)\b/.test(t) ? {} : null,
        run: () => vozCobrar(),
    },
    { // limpiar / nueva venta
        match: (t) => /\b(limpi\w*|vaci\w*|cancel\w*|borra todo|nueva venta|empez\w*)\b/.test(t) ? {} : null,
        run: () => vozLimpiar(),
    },
    { // sacar / quitar <producto>
        match: (t) => { const m = t.match(/\b(?:quit\w*|sac\w*|elimin\w*|borr\w*)\s+(.+)/); return m ? { nombre: m[1] } : null; },
        run: (s) => vozQuitar(s.nombre),
    },
    { // agregar <cant?> <producto>
        match: (t) => { const m = t.match(/\b(?:agreg\w*|sum\w*|pon\w*|anad\w*|carg\w*|quiero|dame|vende\w*)\s+(.+)/); return m ? vozCantNombre(m[1]) : null; },
        run: (s) => vozAgregar(s.nombre, s.qty),
    },
];

// El cerebro del asistente ahora es el backend (n8n + IA). Las reglas locales
// (VOZ_INTENTS) quedan como respaldo por si se quiere volver al modo offline.
if (window.Voz) Voz.montar({ endpoint: '/api/voz/interpretar' });

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

const money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });
const r2 = n => Math.round((Number(n) || 0) * 100) / 100;

// ================= CLIENTES / MEDIOS =================

async function cargarClientes() {
    const { datos } = await api.get('/api/clientes');
    clientes = datos;
    clienteActual = clientes[0];
    selCliente.innerHTML = clientes.map(c =>
        `<option value="${c.id}">${c.nombre} — ${c.lista}</option>`).join('');
}
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
    cobroError.classList.add('oculto');
    cobroTotal.textContent = money.format(calcularTotal());
    // arranca con un pago por el total, en el primer medio
    pagos = [{ tipoId: tiposPago[0].id, monto: calcularTotal() }];
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
    const total  = calcularTotal();
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
    const falta = r2(calcularTotal() - pagos.reduce((s, p) => s + (Number(p.monto) || 0), 0));
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
    btnConfirmar.disabled = true;
    try {
        const resp = await api.post('/api/ventas', payload);
        if (!resp.ok) throw new Error(resp.error || 'No se pudo registrar la venta.');
        const pagosTicket = payload.pagos.map(p => ({
            nombre: tiposPago.find(t => t.id == p.tipo_pago_id)?.nombre ?? '', monto: p.monto,
        }));
        renderTicket(resp.venta.numero, [...carrito], clienteActual.nombre,
                     calcularSubtotal(), descuentoAplicado(), calcularTotal(), pagosTicket);
        document.getElementById('btn-pdf').href = '/api/ventas/ticket?id=' + resp.venta.venta_id;
        // reset
        carrito = []; descuentoTotal = 0; descTotalIn.value = 0;
        renderCarrito();
        modalCobro.classList.add('oculto');
        modalTicket.classList.remove('oculto');
    } catch (e) {
        cobroError.textContent = e.message;
        cobroError.classList.remove('oculto');
        actualizarEstadoCobro();
    }
});

// ================= TICKET =================

function renderTicket(numero, items, cliente, subtotal, descuento, total, pagosTk) {
    const fecha = new Date().toLocaleString('es-AR');
    const lineas = items.map(i => `
        <div class="t-item"><span>${i.cantidad} x ${i.nombre}</span><span>${money.format(subtotalLinea(i))}</span></div>`).join('');
    const filaDesc = descuento > 0
        ? `<div class="t-item"><span>Descuento</span><span>- ${money.format(descuento)}</span></div>` : '';
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

    await cargarClientes();
    await cargarTiposPago();
    renderCarrito();
    input.focus();
})();

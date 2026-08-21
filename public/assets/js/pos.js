// ===== POS - Pasos 2 y 3: buscar + carrito + cliente + cobro + ticket =====
// Usa la capa AJAX (api.js).

const input       = document.getElementById('busqueda');
const resultados  = document.getElementById('resultados');
const selCliente  = document.getElementById('cliente');
const cajaCarrito = document.getElementById('carrito');
const elTotal     = document.getElementById('total');
const btnCobrar   = document.getElementById('btn-cobrar');

// Modal de cobro
const modalCobro  = document.getElementById('modal-cobro');
const cobroTotal  = document.getElementById('cobro-total');
const selMedio    = document.getElementById('medio-pago');
const cobroError  = document.getElementById('cobro-error');
const btnConfirmar= document.getElementById('btn-confirmar');
const btnCancelar = document.getElementById('btn-cancelar');

// Ticket
const modalTicket = document.getElementById('modal-ticket');
const elTicket    = document.getElementById('ticket');
const btnImprimir = document.getElementById('btn-imprimir');
const btnNuevaVta = document.getElementById('btn-nueva-venta');

// --- Estado ---
let clientes = [], clienteActual = null, tiposPago = [];
let ultimosResultados = [];
let carrito = []; // { id, nombre, precio, cantidad, stock, sobrePedido }

const money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });

// ================= CLIENTES / MEDIOS DE PAGO =================

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
    selMedio.innerHTML = tiposPago.map(t => `<option value="${t.id}">${t.nombre}</option>`).join('');
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
            cantidad: 1, stock: Number(p.stock), sobrePedido: p.es_sobre_pedido == 1,
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
    renderCarrito();
}

function quitar(idProducto) {
    carrito = carrito.filter(i => i.id != idProducto);
    renderCarrito();
}

function renderCarrito() {
    if (carrito.length === 0) {
        cajaCarrito.innerHTML = '<p class="vacio-carrito">Carrito vacío.<br>Buscá o escaneá un producto para agregarlo.</p>';
        elTotal.textContent = money.format(0);
        btnCobrar.disabled = true;
        return;
    }
    cajaCarrito.innerHTML = carrito.map(item => `
        <div class="linea" data-id="${item.id}">
            <div class="linea-info">
                <span class="linea-nombre">${item.nombre}${item.sobrePedido ? ' <em>(pedido)</em>' : ''}</span>
                <span class="linea-precio">${money.format(item.precio)} c/u</span>
            </div>
            <div class="linea-cant">
                <button data-accion="menos" aria-label="Restar">−</button>
                <span>${item.cantidad}</span>
                <button data-accion="mas" aria-label="Sumar">+</button>
            </div>
            <span class="linea-sub">${money.format(item.precio * item.cantidad)}</span>
            <button class="linea-quitar" data-accion="quitar" aria-label="Quitar">×</button>
        </div>`).join('');
    elTotal.textContent = money.format(calcularTotal());
    btnCobrar.disabled = false;
}

function calcularTotal() {
    return carrito.reduce((s, i) => s + i.precio * i.cantidad, 0);
}

cajaCarrito.addEventListener('click', (e) => {
    const boton = e.target.closest('button[data-accion]');
    if (!boton) return;
    const id = Number(boton.closest('.linea').dataset.id);
    if (boton.dataset.accion === 'mas')    cambiarCantidad(id, +1);
    if (boton.dataset.accion === 'menos')  cambiarCantidad(id, -1);
    if (boton.dataset.accion === 'quitar') quitar(id);
});

// ================= COBRO =================

btnCobrar.addEventListener('click', () => {
    if (carrito.length === 0) return;
    cobroError.classList.add('oculto');
    cobroTotal.textContent = money.format(calcularTotal());
    modalCobro.classList.remove('oculto');
});

btnCancelar.addEventListener('click', () => modalCobro.classList.add('oculto'));

btnConfirmar.addEventListener('click', async () => {
    const total = Number(calcularTotal().toFixed(2));
    const payload = {
        cliente_id: Number(clienteActual.id),
        items: carrito.map(i => ({ producto_id: i.id, cantidad: i.cantidad })),
        pagos: [{ tipo_pago_id: Number(selMedio.value), monto: total }],
    };

    btnConfirmar.disabled = true; // evita doble clic
    try {
        const resp = await api.post('/api/ventas', payload);
        if (!resp.ok) throw new Error(resp.error || 'No se pudo registrar la venta.');

        // Guardamos los datos para el ticket ANTES de vaciar el carrito.
        const medio = tiposPago.find(t => t.id == selMedio.value)?.nombre ?? '';
        renderTicket(resp.venta.numero, [...carrito], clienteActual.nombre, medio, total);

        // Reset de la venta.
        carrito = [];
        renderCarrito();
        modalCobro.classList.add('oculto');
        modalTicket.classList.remove('oculto');
    } catch (e) {
        cobroError.textContent = e.message;
        cobroError.classList.remove('oculto');
    } finally {
        btnConfirmar.disabled = false;
    }
});

// ================= TICKET =================

function renderTicket(numero, items, cliente, medio, total) {
    const fecha = new Date().toLocaleString('es-AR');
    const lineas = items.map(i => `
        <div class="t-item">
            <span>${i.cantidad} x ${i.nombre}</span>
            <span>${money.format(i.precio * i.cantidad)}</span>
        </div>`).join('');

    elTicket.innerHTML = `
        <div class="t-cabecera">
            <strong>BRITECH</strong>
            <span>Punto de Venta</span>
        </div>
        <div class="t-sep"></div>
        <div class="t-datos">
            <div><span>Ticket:</span><span>${numero}</span></div>
            <div><span>Fecha:</span><span>${fecha}</span></div>
            <div><span>Cliente:</span><span>${cliente}</span></div>
        </div>
        <div class="t-sep"></div>
        ${lineas}
        <div class="t-sep"></div>
        <div class="t-total"><span>TOTAL</span><span>${money.format(total)}</span></div>
        <div class="t-pago">Pago: ${medio}</div>
        <div class="t-sep"></div>
        <div class="t-gracias">¡Gracias por su compra!</div>`;
}

btnImprimir.addEventListener('click', () => window.print());

btnNuevaVta.addEventListener('click', () => {
    modalTicket.classList.add('oculto');
    input.value = '';
    buscar('');
    input.focus();
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
    // Esperamos la búsqueda fresca (importante al escanear: el Enter llega
    // apenas termina de "tipear" el código, antes de que corra el debounce).
    await buscar(input.value);
    if (ultimosResultados.length === 1) {
        agregarAlCarrito(ultimosResultados[0].id);
        input.value = '';
        buscar('');
    }
    input.focus();
});

// ================= ARRANQUE =================

(async function iniciar() {
    await cargarClientes();
    await cargarTiposPago();
    renderCarrito();
    input.focus();
})();

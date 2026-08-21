// ===== POS - Paso 2: buscar + carrito + cliente =====
// Usa la capa AJAX (api.js). El frontend arma el carrito en memoria;
// recién en el Paso 3 se envía al backend para confirmar la venta.

const input       = document.getElementById('busqueda');
const resultados  = document.getElementById('resultados');
const selCliente  = document.getElementById('cliente');
const cajaCarrito = document.getElementById('carrito');
const elTotal     = document.getElementById('total');
const btnCobrar   = document.getElementById('btn-cobrar');

// --- Estado en memoria ---
let clientes         = [];   // lista de clientes traída del backend
let clienteActual    = null; // el cliente elegido (define la lista de precios)
let ultimosResultados = [];  // resultados de la última búsqueda (para agregar)
let carrito          = [];   // líneas: { id, nombre, precio, cantidad, stock, sobrePedido }

// Formatea plata argentina: 15000 -> "$ 15.000,00"
const money = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });

// ================= CLIENTES =================

async function cargarClientes() {
    const { datos } = await api.get('/api/clientes');
    clientes = datos;
    clienteActual = clientes[0]; // por defecto: Consumidor Final

    selCliente.innerHTML = clientes.map(c =>
        `<option value="${c.id}">${c.nombre} — ${c.lista}</option>`
    ).join('');
}

// Al cambiar de cliente: cambia la lista de precios → recalcular todo.
selCliente.addEventListener('change', async () => {
    clienteActual = clientes.find(c => c.id == selCliente.value);
    await recalcularPreciosCarrito();
    if (input.value.trim() !== '') buscar(input.value); // refrescar resultados
});

// Pide al backend los precios de los productos del carrito para la nueva lista.
async function recalcularPreciosCarrito() {
    if (carrito.length === 0) return;

    const ids = carrito.map(i => i.id).join(',');
    const { datos } = await api.get(`/api/productos/precios?ids=${ids}&lista=${clienteActual.lista_precio_id}`);

    // datos = [{producto_id, precio}] -> actualizamos cada línea.
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
            </article>
        `;
    }).join('');
}

// Clic en un resultado = agregarlo al carrito (un solo clic, RNF6).
resultados.addEventListener('click', (e) => {
    const fila = e.target.closest('.producto');
    if (!fila || fila.classList.contains('inhabilitado')) return;
    agregarAlCarrito(Number(fila.dataset.id));
});

// ================= CARRITO =================

function agregarAlCarrito(idProducto) {
    const p = ultimosResultados.find(r => r.id == idProducto);
    if (!p) return;

    const sinStock = p.stock == 0 && p.es_sobre_pedido == 0;
    if (sinStock) { alert('Este producto no tiene stock.'); return; }

    // ¿Ya está en el carrito? Sumamos cantidad; si no, lo agregamos.
    const existente = carrito.find(i => i.id == p.id);
    if (existente) {
        if (!p.es_sobre_pedido && existente.cantidad + 1 > p.stock) {
            alert('No hay más stock de este producto.');
            return;
        }
        existente.cantidad++;
    } else {
        carrito.push({
            id: p.id,
            nombre: p.nombre,
            precio: Number(p.precio),
            cantidad: 1,
            stock: Number(p.stock),
            sobrePedido: p.es_sobre_pedido == 1,
        });
    }
    renderCarrito();
}

function cambiarCantidad(idProducto, delta) {
    const item = carrito.find(i => i.id == idProducto);
    if (!item) return;

    const nueva = item.cantidad + delta;
    if (nueva <= 0) { quitar(idProducto); return; }
    if (!item.sobrePedido && nueva > item.stock) {
        alert('No hay más stock de este producto.');
        return;
    }
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
        </div>
    `).join('');

    elTotal.textContent = money.format(calcularTotal());
    btnCobrar.disabled = false;
}

function calcularTotal() {
    return carrito.reduce((suma, i) => suma + i.precio * i.cantidad, 0);
}

// Clics dentro del carrito (delegación de eventos).
cajaCarrito.addEventListener('click', (e) => {
    const boton = e.target.closest('button[data-accion]');
    if (!boton) return;
    const id = Number(boton.closest('.linea').dataset.id);

    if (boton.dataset.accion === 'mas')    cambiarCantidad(id, +1);
    if (boton.dataset.accion === 'menos')  cambiarCantidad(id, -1);
    if (boton.dataset.accion === 'quitar') quitar(id);
});

// ================= BUSCADOR (eventos) =================

// Debounce: espera 250ms tras la última tecla antes de buscar.
let temporizador = null;
input.addEventListener('input', () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => buscar(input.value), 250);
});

// Enter (o fin del escaneo): si hay UN solo resultado, lo agrega y limpia.
input.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    clearTimeout(temporizador);

    if (ultimosResultados.length === 1) {
        agregarAlCarrito(ultimosResultados[0].id);
        input.value = '';
        buscar('');       // limpia resultados
    } else {
        buscar(input.value);
    }
    input.focus();        // el foco vuelve al buscador para seguir escaneando
});

// ================= ARRANQUE =================

(async function iniciar() {
    await cargarClientes();
    renderCarrito();
    input.focus();
})();

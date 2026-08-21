// FRONTEND: pide los productos a la API y arma la tabla.
// No sabe nada de SQL ni de la base: solo consume JSON del backend.

async function cargarProductos() {
    const cuerpo = document.getElementById('cuerpo');

    try {
        // 1. Pide los datos al backend.
        const respuesta = await fetch('/api/productos');
        const json = await respuesta.json();
        const productos = json.datos;

        // 2. Si no hay productos, avisa.
        if (productos.length === 0) {
            cuerpo.innerHTML = '<tr><td colspan="4">No hay productos.</td></tr>';
            return;
        }

        // 3. Arma una fila por producto.
        cuerpo.innerHTML = productos.map(p => `
            <tr>
                <td>${p.nombre}</td>
                <td>${p.codigo_barras ?? '-'}</td>
                <td>${p.stock}</td>
                <td>${p.es_sobre_pedido == 1 ? 'Sobre pedido' : 'Con stock'}</td>
            </tr>
        `).join('');

    } catch (error) {
        cuerpo.innerHTML = '<tr><td colspan="4">Error al cargar los productos.</td></tr>';
        console.error(error);
    }
}

cargarProductos();

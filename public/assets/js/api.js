// ===== Capa AJAX =====
// Centraliza TODAS las llamadas al backend en un solo lugar (patrón "Module").
// Así no repetimos el código de fetch/errores en cada pantalla.
// AJAX = pedir datos al servidor sin recargar la página.

const api = {
    // Pedir datos (GET). Ej: api.get('/api/clientes')
    async get(url) {
        const resp = await fetch(url);
        if (!resp.ok) throw new Error('Error HTTP ' + resp.status);
        return resp.json();
    },

    // Enviar datos (POST). Se usará en el Paso 3 al confirmar la venta.
    async post(url, datos) {
        const resp = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(datos),
        });
        if (!resp.ok) throw new Error('Error HTTP ' + resp.status);
        return resp.json();
    },
};

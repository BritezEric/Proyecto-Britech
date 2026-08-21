// ===== Capa AJAX =====
// Centraliza las llamadas al backend (patrón "Module").
// AJAX = pedir/enviar datos al servidor sin recargar la página.

async function pedir(url, opciones) {
    const resp = await fetch(url, opciones);
    const json = await resp.json().catch(() => ({}));
    if (!resp.ok) {
        // Usamos el mensaje del backend si vino; si no, uno genérico.
        throw new Error(json.error || ('Error HTTP ' + resp.status));
    }
    return json;
}

const api = {
    get: (url) => pedir(url),
    post: (url, datos) => pedir(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(datos),
    }),
};

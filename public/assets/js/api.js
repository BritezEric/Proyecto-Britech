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

// Aviso breve tipo "toast". Uso: toast('✓ Agregado al carrito').
// Si el mensaje empieza con un símbolo (✓, ✉…), lo separa para colorearlo.
// Todo con textContent → a prueba de XSS.
function toast(msg, ms = 2200) {
    let cont = document.querySelector('.toast-cont');
    if (!cont) { cont = document.createElement('div'); cont.className = 'toast-cont'; document.body.appendChild(cont); }
    const el = document.createElement('div');
    el.className = 'toast';
    const sp = msg.indexOf(' ');
    const ini = sp > 0 ? msg.slice(0, sp) : '';
    if (ini && /[^\p{L}\p{N}]/u.test(ini)) {
        const ic = document.createElement('span'); ic.className = 'toast-ic'; ic.textContent = ini;
        const tx = document.createElement('span'); tx.textContent = msg.slice(sp + 1);
        el.append(ic, tx);
    } else {
        el.textContent = msg;
    }
    cont.appendChild(el);
    requestAnimationFrame(() => el.classList.add('show'));
    setTimeout(() => { el.classList.remove('show'); setTimeout(() => el.remove(), 320); }, ms);
}

const api = {
    get: (url) => pedir(url),
    post: (url, datos) => pedir(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(datos),
    }),
    // Subir archivos (multipart). No seteamos Content-Type: el navegador
    // arma el boundary solo cuando el body es un FormData.
    subir: (url, formData) => pedir(url, { method: 'POST', body: formData }),
};

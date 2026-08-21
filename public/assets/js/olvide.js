// "Olvidé mi contraseña": pide el email y manda el enlace de recuperación.

const form = document.getElementById('form-olvide');
const ok   = document.getElementById('msg-ok');

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const boton = form.querySelector('button');
    boton.disabled = true;
    try {
        await api.post('/api/olvide', { email: document.getElementById('email').value });
    } catch (_) { /* ignoramos: la respuesta es siempre la misma por seguridad */ }

    // Mismo mensaje exista o no el email (no revelamos cuáles están registrados).
    form.classList.add('oculto');
    ok.textContent = 'Si el email está registrado, te enviamos un correo con instrucciones.';
    ok.classList.remove('oculto');
});

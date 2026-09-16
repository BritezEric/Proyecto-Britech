// Activación de cuenta de cliente: define la contraseña con el token del correo.

const form   = document.getElementById('form-activar');
const error  = document.getElementById('msg-error');
const ok     = document.getElementById('msg-ok');
const enviar = form.querySelector('button[type=submit]');
const validarPw = pwCampos(6);   // ojo + pista en vivo; cliente = mínimo 6

const token = new URLSearchParams(location.search).get('token');
if (!token) {
    error.textContent = 'Enlace inválido (falta el token).';
    error.classList.remove('oculto');
    enviar.disabled = true;
}

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    error.classList.add('oculto');

    const p1 = document.getElementById('password').value;
    const p2 = document.getElementById('password2').value;
    const problema = validarPw(p1, p2);
    if (problema) { error.textContent = problema; error.classList.remove('oculto'); return; }

    const txt = enviar.textContent;
    enviar.disabled = true;
    enviar.textContent = 'Activando…';
    try {
        await api.post('/api/tienda/activar', { token, password: p1 });
        form.classList.add('oculto');
        ok.innerHTML = '¡Cuenta activada! Ya iniciaste sesión. Te llevamos a la tienda…';
        ok.classList.remove('oculto');
        setTimeout(() => { location.href = '/tienda.html'; }, 1500);
    } catch (err) {
        error.textContent = err.message;
        error.classList.remove('oculto');
        enviar.disabled = false;
        enviar.textContent = txt;
    }
});

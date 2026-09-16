// Activar cuenta (staff): toma el token del link del correo y define la contraseña.

const form   = document.getElementById('form-verificar');
const error  = document.getElementById('msg-error');
const ok     = document.getElementById('msg-ok');
const enviar = form.querySelector('button[type=submit]');
const validarPw = pwCampos(8);   // ojo + pista en vivo; staff = mínimo 8

// El token viene en la URL: verificar.html?token=xxxx
const token = new URLSearchParams(window.location.search).get('token');
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
        await api.post('/api/verificar', { token, password: p1 });
        form.classList.add('oculto');
        ok.innerHTML = '¡Cuenta activada! Ya podés <a href="/login.html">iniciar sesión</a>.';
        ok.classList.remove('oculto');
    } catch (err) {
        error.textContent = err.message;
        error.classList.remove('oculto');
        enviar.disabled = false;
        enviar.textContent = txt;
    }
});

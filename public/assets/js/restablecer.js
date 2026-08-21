// Restablecer contraseña con el token del correo.

const form  = document.getElementById('form-restablecer');
const error = document.getElementById('msg-error');
const ok    = document.getElementById('msg-ok');

const token = new URLSearchParams(window.location.search).get('token');
if (!token) {
    error.textContent = 'Enlace inválido (falta el token).';
    error.classList.remove('oculto');
    form.querySelector('button').disabled = true;
}

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    error.classList.add('oculto');

    const p1 = document.getElementById('password').value;
    const p2 = document.getElementById('password2').value;
    if (p1 !== p2) {
        error.textContent = 'Las contraseñas no coinciden.';
        error.classList.remove('oculto');
        return;
    }

    const boton = form.querySelector('button');
    boton.disabled = true;
    try {
        await api.post('/api/restablecer', { token, password: p1 });
        form.classList.add('oculto');
        ok.innerHTML = '¡Contraseña actualizada! Ya podés <a href="/login.html">iniciar sesión</a>.';
        ok.classList.remove('oculto');
    } catch (err) {
        error.textContent = err.message;
        error.classList.remove('oculto');
        boton.disabled = false;
    }
});

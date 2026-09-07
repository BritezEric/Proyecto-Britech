// Login: envía email + contraseña al backend; si está ok, va al POS.

const form  = document.getElementById('form-login');
const error = document.getElementById('login-error');

// Aviso al volver del login con Google (?auth=...).
(() => {
    const p = new URLSearchParams(location.search).get('auth');
    if (!p) return;
    const msg = {
        staff_no_hab: 'Tu cuenta de Google no está habilitada como staff. Pedile a un admin que te dé de alta.',
        google_no_config: 'El login con Google todavía no está configurado.',
        google_email: 'Tu cuenta de Google no tiene un email verificado.',
        google_fallo: 'No se pudo ingresar con Google. Probá de nuevo.',
    }[p];
    if (msg) { error.textContent = msg; error.classList.remove('oculto'); }
    history.replaceState(null, '', location.pathname);
})();

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    error.classList.add('oculto');
    const boton = form.querySelector('button');
    boton.disabled = true;

    try {
        await api.post('/api/login', {
            email:    document.getElementById('email').value,
            password: document.getElementById('password').value,
        });
        // Según el rol: admin → panel, vendedor → POS.
        const { usuario } = await api.get('/api/yo');
        window.location.href = usuario.rol === 'admin' ? '/admin.html' : '/pos.html';
    } catch (err) {
        error.textContent = err.message;       // mensaje del backend
        error.classList.remove('oculto');
        boton.disabled = false;
    }
});

// Crear usuario (solo admin). Al crear, el backend le manda un correo para activar.

const form  = document.getElementById('form-usuario');
const error = document.getElementById('msg-error');
const ok    = document.getElementById('msg-ok');

// Protección: solo admin logueado.
(async function () {
    let sesion;
    try { sesion = await api.get('/api/yo'); }
    catch { window.location.href = '/login.html'; return; }
    if (sesion.usuario.rol !== 'admin') {
        alert('Solo el administrador puede crear usuarios.');
        window.location.href = '/pos.html';
    }
})();

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    error.classList.add('oculto');
    ok.classList.add('oculto');
    const boton = form.querySelector('button');
    boton.disabled = true;

    try {
        const email = document.getElementById('email').value;
        await api.post('/api/usuarios', {
            nombre: document.getElementById('nombre').value,
            email:  email,
            rol_id: Number(document.getElementById('rol').value),
        });
        ok.textContent = `Usuario creado. Se envió un correo a ${email} para que active su cuenta.`;
        ok.classList.remove('oculto');
        form.reset();
    } catch (err) {
        error.textContent = err.message;
        error.classList.remove('oculto');
    } finally {
        boton.disabled = false;
    }
});

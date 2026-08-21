// Login: envía email + contraseña al backend; si está ok, va al POS.

const form  = document.getElementById('form-login');
const error = document.getElementById('login-error');

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
        window.location.href = '/pos.html';
    } catch (err) {
        error.textContent = err.message;       // mensaje del backend
        error.classList.remove('oculto');
        boton.disabled = false;
    }
});

// Ayuda común a las páginas de contraseña (activar / restablecer, staff y tienda).
// Cablea el botón "ojo" (mostrar/ocultar) de cada .pw-campo y la pista en vivo,
// y devuelve un validador para el submit.
//
// El HTML tiene que tener: inputs #password / #password2, cada uno dentro de un
// .pw-campo con su botón .pw-ojo[data-ver], y un <p class="login-hint" id="pw-hint">.
//
// Uso:  const validarPw = pwCampos(8);
//       const problema = validarPw(p1, p2);  // string con el error, o null si va bien
function pwCampos(min) {
    const hint = document.getElementById('pw-hint');
    const p1El = document.getElementById('password');
    const p2El = document.getElementById('password2');

    // Ojo: mostrar/ocultar cada contraseña.
    document.querySelectorAll('.pw-ojo').forEach((b) => {
        b.addEventListener('click', () => {
            const inp = document.getElementById(b.dataset.ver);
            const mostrar = inp.type === 'password';
            inp.type = mostrar ? 'text' : 'password';
            b.classList.toggle('activo', mostrar);
            b.setAttribute('aria-label', mostrar ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    });

    // Pista en vivo: largo mínimo y coincidencia.
    function revisar() {
        const p1 = p1El.value, p2 = p2El.value;
        hint.classList.remove('malo', 'bien');
        if (p1 && p1.length < min)              { hint.textContent = `Te faltan caracteres (mínimo ${min}).`; hint.classList.add('malo'); }
        else if (p2 && p1 !== p2)               { hint.textContent = 'Las contraseñas no coinciden.';         hint.classList.add('malo'); }
        else if (p1.length >= min && p1 === p2) { hint.textContent = 'Las contraseñas coinciden.';            hint.classList.add('bien'); }
        else                                    { hint.textContent = `Mínimo ${min} caracteres.`; }
    }
    if (hint) {
        p1El.addEventListener('input', revisar);
        p2El.addEventListener('input', revisar);
    }

    return (p1, p2) => {
        if (p1.length < min) return `La contraseña debe tener al menos ${min} caracteres.`;
        if (p1 !== p2)       return 'Las contraseñas no coinciden.';
        return null;
    };
}

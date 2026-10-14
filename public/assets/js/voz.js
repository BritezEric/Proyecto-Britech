// ===== Asistente de voz (tocar para hablar) — reutilizable =====
// STT nativo del navegador (Web Speech API) + botón flotante + toast + dispatch.
// El "cerebro" hoy son reglas locales: la página registra sus intents con
// Voz.montar({ intents }). Cada intent = { match(textoNorm) -> slots|null, run(slots) -> mensaje }.
// Para pasar a Claude más adelante, reemplazar el cuerpo de procesar() por un
// fetch a /api/voz/interpretar que devuelva { intent, slots } y despachar por nombre.

window.Voz = (function () {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;

    // Quita acentos y pasa a minúsculas para comparar sin depender de la tilde.
    const norm = (s) => (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();

    let intents = [];
    let rec = null, escuchando = false, finalTexto = '';
    let boton, toast;

    function inyectarEstilo() {
        if (document.getElementById('voz-estilo')) return;
        const st = document.createElement('style');
        st.id = 'voz-estilo';
        st.textContent = `
        #voz-btn{position:fixed;right:20px;bottom:20px;z-index:9000;width:60px;height:60px;border-radius:50%;
            border:none;cursor:pointer;background:var(--primario,#2563eb);color:#fff;font-size:26px;
            box-shadow:0 6px 18px rgba(0,0,0,.25);display:flex;align-items:center;justify-content:center;transition:transform .1s}
        #voz-btn:active{transform:scale(.94)}
        #voz-btn.escuchando{background:var(--danger,#dc2626);animation:voz-pulso 1s infinite}
        @keyframes voz-pulso{0%{box-shadow:0 0 0 0 rgba(220,38,38,.5)}70%{box-shadow:0 0 0 14px rgba(220,38,38,0)}100%{box-shadow:0 0 0 0 rgba(220,38,38,0)}}
        #voz-toast{position:fixed;right:20px;bottom:92px;z-index:9000;max-width:340px;background:#111827;color:#f9fafb;
            padding:10px 14px;border-radius:10px;font:14px/1.35 system-ui,sans-serif;box-shadow:0 6px 18px rgba(0,0,0,.3);
            opacity:0;transform:translateY(8px);transition:opacity .15s,transform .15s;pointer-events:none}
        #voz-toast.ver{opacity:1;transform:translateY(0)}
        #voz-toast .voz-chip{display:inline-block;font-size:11px;letter-spacing:.04em;text-transform:uppercase;opacity:.7;margin-bottom:2px}`;
        document.head.appendChild(st);
    }

    function decir(msg, chip) {
        if (!toast) return;
        toast.innerHTML = (chip ? `<span class="voz-chip">${chip}</span><br>` : '') + msg;
        toast.classList.add('ver');
        clearTimeout(decir._t);
        decir._t = setTimeout(() => toast.classList.remove('ver'), 3500);
    }

    async function procesar(texto) {
        const t = norm(texto);
        for (const it of intents) {
            let slots;
            try { slots = it.match(t); } catch { slots = null; }
            if (slots) {
                try { decir(await it.run(slots, { texto, t }) || 'Listo.', 'Hecho'); }
                catch (e) { decir(e.message || 'No pude hacerlo.', 'Error'); }
                return;
            }
        }
        decir(`No te entendí: "${texto}"`, 'Sin acción');
    }

    function nuevaSesion() {
        rec = new SR();
        rec.lang = 'es-AR';
        rec.interimResults = true;
        rec.continuous = false;
        rec.maxAlternatives = 1;
        finalTexto = '';
        rec.onresult = (e) => {
            let interim = '';
            for (let i = e.resultIndex; i < e.results.length; i++) {
                const txt = e.results[i][0].transcript;
                if (e.results[i].isFinal) finalTexto += txt; else interim += txt;
            }
            decir(finalTexto || interim || '…', 'Escuchando');
        };
        rec.onerror = (e) => {
            escuchando = false; boton.classList.remove('escuchando');
            decir(e.error === 'not-allowed' ? 'Permiso de micrófono denegado.' : 'No pude escuchar.', 'Error');
        };
        rec.onend = () => {
            escuchando = false; boton.classList.remove('escuchando');
            const t = finalTexto.trim();
            if (t) procesar(t);
        };
    }

    function alternar() {
        if (!SR) { decir('Tu navegador no soporta dictado por voz (usá Chrome/Edge).', 'Sin soporte'); return; }
        if (escuchando) { rec && rec.stop(); return; }
        nuevaSesion();
        escuchando = true;
        boton.classList.add('escuchando');
        decir('Hablá ahora…', 'Escuchando');
        try { rec.start(); } catch { escuchando = false; boton.classList.remove('escuchando'); }
    }

    function montar(opts) {
        intents = (opts && opts.intents) || [];
        inyectarEstilo();
        boton = document.createElement('button');
        boton.id = 'voz-btn';
        boton.type = 'button';
        boton.title = 'Tocar para hablar';
        boton.setAttribute('aria-label', 'Asistente de voz');
        boton.textContent = '🎤';
        toast = document.createElement('div');
        toast.id = 'voz-toast';
        document.body.append(boton, toast);
        boton.addEventListener('click', alternar);
        if (!SR) boton.title = 'Dictado por voz no soportado en este navegador';
    }

    return { montar, decir, norm, get soportado() { return !!SR; } };
})();

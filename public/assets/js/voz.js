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
    let endpoint = null;   // si se setea, el cerebro es el backend (n8n + IA) en vez de reglas locales
    let historial = [];    // contexto conversacional (lo mantiene el agente de n8n)
    let onNavegar = null;  // callback de navegación por voz (la página decide a dónde ir)
    let rec = null, escuchando = false, finalTexto = '';
    let boton, toast;
    let panel = null, vpEstadoEl = null, vpUserEl = null, vpReplyEl = null;

    // --- Ventana emergente del asistente (estado + diálogo) ---
    function vpShow(b) { if (panel) panel.classList.toggle('ver', !!b); }
    function vpEstado(modo, label) {
        if (!panel) return;
        panel.classList.remove('idle', 'listen', 'think', 'speak');
        panel.classList.add(modo);
        if (vpEstadoEl) vpEstadoEl.textContent = label;
    }
    function vpUser(t) { if (vpUserEl) { vpUserEl.textContent = t ? ('Vos: ' + t) : ''; vpUserEl.classList.toggle('ver', !!t); } }
    function vpReply(t) { if (vpReplyEl) vpReplyEl.textContent = t || ''; }

    function inyectarEstilo() {
        if (document.getElementById('voz-estilo')) return;
        const st = document.createElement('style');
        st.id = 'voz-estilo';
        st.textContent = `
        #voz-btn{position:fixed;right:22px;bottom:22px;z-index:9000;width:50px;height:50px;border-radius:50%;
            cursor:pointer;background:var(--surface,#fff);color:var(--ink,#1f2937);
            border:1px solid var(--line,#e5e7eb);display:flex;align-items:center;justify-content:center;
            box-shadow:0 2px 12px rgba(0,0,0,.08);transition:transform .18s ease,box-shadow .2s ease,background .2s,color .2s,border-color .2s;-webkit-tap-highlight-color:transparent}
        #voz-btn svg{width:21px;height:21px;position:relative;z-index:1}
        #voz-btn:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(0,0,0,.12)}
        #voz-btn:active{transform:scale(.95)}
        #voz-btn:focus-visible{outline:2px solid var(--primary,#2563eb);outline-offset:3px}
        /* Activo (conversación): superficie oscura sobria + anillos que respiran. */
        #voz-btn.escuchando{background:var(--ink,#111827);color:#fff;border-color:transparent;box-shadow:0 4px 18px rgba(0,0,0,.22)}
        #voz-btn.escuchando::before,#voz-btn.escuchando::after{content:'';position:absolute;inset:-1px;border-radius:50%;
            border:1.5px solid var(--primary,#2563eb);opacity:0;animation:voz-ring 2.4s cubic-bezier(.33,0,.2,1) infinite}
        #voz-btn.escuchando::after{animation-delay:1.2s}
        @keyframes voz-ring{0%{transform:scale(1);opacity:.45}100%{transform:scale(2.2);opacity:0}}
        @media (prefers-reduced-motion:reduce){#voz-btn.escuchando::before,#voz-btn.escuchando::after{animation:none}}
        #voz-toast{position:fixed;right:22px;bottom:84px;z-index:9000;max-width:320px;background:var(--ink,#111827);color:#f9fafb;
            padding:10px 13px;border-radius:12px;font:13.5px/1.4 system-ui,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.18);
            opacity:0;transform:translateY(6px);transition:opacity .18s,transform .18s;pointer-events:none}
        #voz-toast.ver{opacity:1;transform:translateY(0)}
        #voz-toast .voz-chip{display:inline-block;font-size:10.5px;letter-spacing:.05em;text-transform:uppercase;opacity:.6;margin-bottom:2px}
        /* ===== Ventana emergente del asistente ===== */
        #voz-panel{position:fixed;right:22px;bottom:84px;z-index:9001;width:320px;max-width:calc(100vw - 44px);
            background:var(--surface,#fff);color:var(--ink,#111827);border:1px solid var(--line,#e5e7eb);
            border-radius:16px;box-shadow:0 16px 44px rgba(0,0,0,.16);padding:14px 16px;
            opacity:0;transform:translateY(12px) scale(.98);pointer-events:none;
            transition:opacity .22s cubic-bezier(.16,1,.3,1),transform .22s cubic-bezier(.16,1,.3,1)}
        #voz-panel.ver{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}
        #voz-panel .vp-head{display:flex;align-items:center;gap:8px}
        #voz-panel .vp-dot{width:8px;height:8px;border-radius:50%;background:var(--ink-muted,#9ca3af);flex:0 0 auto;transition:background .2s}
        #voz-panel .vp-estado{font:600 11px/1 system-ui,sans-serif;letter-spacing:.06em;text-transform:uppercase;color:var(--ink-muted,#6b7280)}
        #voz-panel .vp-wave{display:flex;align-items:center;gap:3px;height:16px;margin-left:auto}
        #voz-panel .vp-wave i{width:3px;height:4px;border-radius:2px;background:var(--ink-muted,#9ca3af);display:block}
        #voz-panel .vp-body{margin-top:11px;font:14px/1.45 system-ui,sans-serif}
        #voz-panel .vp-user{color:var(--ink-muted,#6b7280);font-size:12.5px;margin-bottom:5px;display:none}
        #voz-panel .vp-user.ver{display:block}
        #voz-panel .vp-reply{color:var(--ink,#111827);min-height:1.2em}
        /* Estados */
        #voz-panel.listen .vp-dot,#voz-panel.speak .vp-dot{background:var(--primary,#2563eb)}
        #voz-panel.think .vp-dot{background:var(--primary,#2563eb);animation:vp-pulse 1s ease-in-out infinite}
        #voz-panel.listen .vp-wave i,#voz-panel.speak .vp-wave i{background:var(--primary,#2563eb);animation:vp-bar .9s ease-in-out infinite}
        #voz-panel.speak .vp-wave i{animation-duration:.55s}
        #voz-panel .vp-wave i:nth-child(2){animation-delay:.12s}
        #voz-panel .vp-wave i:nth-child(3){animation-delay:.24s}
        #voz-panel .vp-wave i:nth-child(4){animation-delay:.36s}
        #voz-panel.think .vp-wave i{background:var(--primary,#2563eb);animation:vp-pulse 1.1s ease-in-out infinite}
        @keyframes vp-bar{0%,100%{height:4px}50%{height:15px}}
        @keyframes vp-pulse{0%,100%{opacity:.35}50%{opacity:1}}
        @media (prefers-reduced-motion:reduce){#voz-panel .vp-wave i,#voz-panel .vp-dot{animation:none!important}}`;
        document.head.appendChild(st);
    }

    function decir(msg, chip) {
        if (!toast) return;
        toast.innerHTML = (chip ? `<span class="voz-chip">${chip}</span><br>` : '') + msg;
        toast.classList.add('ver');
        clearTimeout(decir._t);
        decir._t = setTimeout(() => toast.classList.remove('ver'), 3500);
    }

    // Dice la respuesta en voz (TTS del navegador). Silencioso si no hay soporte.
    function hablar(texto) {
        try {
            if (!window.speechSynthesis) return;
            speechSynthesis.cancel();
            const u = new SpeechSynthesisUtterance(texto);
            u.lang = 'es-AR';
            speechSynthesis.speak(u);
        } catch (e) { /* sin TTS, queda solo el toast */ }
    }

    // Reproduce el audio de ElevenLabs (mp3 base64). Devuelve una promesa que se
    // resuelve cuando termina de hablar (para el modo conversación). Fallback TTS.
    let audioEl = null;
    function reproducirVoz(mp3b64, texto) {
        return new Promise((resolve) => {
            if (!mp3b64) { hablar(texto); resolve(); return; }
            try {
                if (window.speechSynthesis) speechSynthesis.cancel();
                if (!audioEl) audioEl = new Audio();
                audioEl.onended = () => resolve();
                audioEl.onerror = () => { hablar(texto); resolve(); };
                audioEl.src = 'data:audio/mpeg;base64,' + mp3b64;
                audioEl.play().catch(() => { hablar(texto); resolve(); });
            } catch (e) { hablar(texto); resolve(); }
        });
    }

    // Maneja la respuesta del backend: contexto, ventana (lo que escuchó + respuesta) y voz.
    async function manejarRespuesta(d) {
        const msg = (d && d.respuesta) || 'No pude procesar eso.';
        if (d && Array.isArray(d.historial)) historial = d.historial;
        if (d && d.texto) vpUser(d.texto);
        vpReply(msg);
        vpEstado('speak', 'Hablando');
        if (d && d.vista) navegarVista(d.vista);   // navegación por voz (plomería)
        await reproducirVoz(d && d.audio, msg);
    }

    // La página decide a dónde ir cuando el asistente lo pide (montar({onNavegar})).
    function navegarVista(vista) { try { if (onNavegar) onNavegar(vista); } catch (e) {} }

    async function procesar(texto) {
        // Modo backend: el texto va a n8n (IA) por /api/voz/interpretar y hablamos la respuesta.
        if (endpoint) {
            decir(`"${texto}"`, 'Procesando');
            try {
                const r = await fetch(endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ texto, historial }),
                });
                manejarRespuesta(await r.json());
            } catch (e) {
                decir('No pude contactar al asistente.', 'Error');
            }
            return;
        }
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

    // ===== Grabación de audio (modo backend) =====
    // Para navegadores sin STT confiable (Brave bloquea el Web Speech de Google):
    // grabamos el micrófono como WAV y lo mandamos al backend, que lo transcribe.
    let mediaStream = null;

    function encodeWav(samples, rate) {
        const buf = new ArrayBuffer(44 + samples.length * 2);
        const v = new DataView(buf);
        const w = (o, s) => { for (let i = 0; i < s.length; i++) v.setUint8(o + i, s.charCodeAt(i)); };
        w(0, 'RIFF'); v.setUint32(4, 36 + samples.length * 2, true); w(8, 'WAVE');
        w(12, 'fmt '); v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
        v.setUint32(24, rate, true); v.setUint32(28, rate * 2, true); v.setUint16(32, 2, true); v.setUint16(34, 16, true);
        w(36, 'data'); v.setUint32(40, samples.length * 2, true);
        let o = 44;
        for (let i = 0; i < samples.length; i++) {
            const s = Math.max(-1, Math.min(1, samples[i]));
            v.setInt16(o, s < 0 ? s * 0x8000 : s * 0x7FFF, true); o += 2;
        }
        return new Blob([buf], { type: 'audio/wav' });
    }

    // ===== Modo conversación (tipo ChatGPT) =====
    // Abre el micrófono UNA vez; detecta fin de frase por silencio (VAD), responde
    // con voz, y vuelve a escuchar solo, hasta que tocás el botón para terminar.
    let convCtx, convSrc, convProc;
    let hayVoz = false, vozDesde = 0, ultimaVoz = 0, bufFrames = [], frameRate = 48000;
    let ocupado = false;            // mientras procesa/habla, no escucha (evita realimentación)
    const SILENCIO_MS = 800;        // silencio tras hablar => fin de frase (turno más ágil)
    const UMBRAL = 0.02;            // nivel mínimo para considerar "voz"
    const MIN_VOZ_MS = 350;         // frase mínima para enviar (ignora ruiditos)

    function rmsNivel(frame) {
        let s = 0; for (let i = 0; i < frame.length; i++) s += frame[i] * frame[i];
        return Math.sqrt(s / frame.length);
    }

    function wavDeFrames(frames) {
        const len = frames.reduce((a, c) => a + c.length, 0);
        const all = new Float32Array(len);
        let off = 0; for (const c of frames) { all.set(c, off); off += c.length; }
        return { blob: encodeWav(all, frameRate), ms: (len / frameRate) * 1000 };
    }

    function onFrame(e) {
        if (!escuchando || ocupado) return;
        const frame = new Float32Array(e.inputBuffer.getChannelData(0));
        const nivel = rmsNivel(frame);
        const now = performance.now();
        if (nivel > UMBRAL) {
            if (!hayVoz) { hayVoz = true; vozDesde = now; bufFrames = []; vpEstado('listen', 'Escuchando'); }
            ultimaVoz = now;
            bufFrames.push(frame);
        } else if (hayVoz) {
            bufFrames.push(frame);                       // cola de silencio corta
            if (now - ultimaVoz > SILENCIO_MS) {
                const dur = ultimaVoz - vozDesde;
                const { blob, ms } = wavDeFrames(bufFrames);
                hayVoz = false; bufFrames = [];
                if (ms >= MIN_VOZ_MS && dur >= 0) { ocupado = true; procesarAudio(blob); }
            }
        }
    }

    async function convIniciar() {
        const stream = await navigator.mediaDevices.getUserMedia({
            audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
        });
        mediaStream = stream;
        convCtx = new (window.AudioContext || window.webkitAudioContext)();
        frameRate = convCtx.sampleRate;
        convSrc = convCtx.createMediaStreamSource(stream);
        convProc = convCtx.createScriptProcessor(4096, 1, 1);
        convProc.onaudioprocess = onFrame;
        convSrc.connect(convProc); convProc.connect(convCtx.destination);
        hayVoz = false; bufFrames = []; ocupado = false;
    }

    function convDetener() {
        escuchando = false; ocupado = false; hayVoz = false; bufFrames = [];
        try { convProc && convProc.disconnect(); convSrc && convSrc.disconnect(); } catch (e) {}
        mediaStream && mediaStream.getTracks().forEach((t) => t.stop());
        try { convCtx && convCtx.close(); } catch (e) {}
        try { if (audioEl) audioEl.pause(); } catch (e) {}
        boton.classList.remove('escuchando');
        vpEstado('idle', 'Listo');
        setTimeout(() => { if (!escuchando) vpShow(false); }, 1200);
    }

    const blobAb64 = (blob) => new Promise((res) => {
        const r = new FileReader();
        r.onloadend = () => res(String(r.result).split(',')[1] || '');
        r.readAsDataURL(blob);
    });

    async function procesarAudio(blob) {
        vpEstado('think', 'Pensando');
        try {
            const audio = await blobAb64(blob);
            const r = await fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ audio, mime: 'audio/wav', historial }),
            });
            await manejarRespuesta(await r.json());   // espera a que termine de hablar
        } catch (e) {
            vpEstado('idle', 'Error'); vpReply('No pude contactar al asistente.');
        } finally {
            // Volver a escuchar si la conversación sigue activa.
            if (escuchando) { ocupado = false; hayVoz = false; bufFrames = []; vpEstado('listen', 'Escuchando'); }
        }
    }

    async function toggleConversacion() {
        if (escuchando) { convDetener(); return; }
        try {
            await convIniciar();
            escuchando = true;
            boton.classList.add('escuchando');
            vpUser(''); vpReply('Hablá cuando quieras; te respondo. Tocá el botón para terminar.');
            vpEstado('listen', 'Escuchando');
            vpShow(true);
        } catch (e) {
            vpShow(true); vpEstado('idle', 'Error');
            vpReply(e && e.name === 'NotAllowedError' ? 'Permiso de micrófono denegado.' : 'No pude abrir el micrófono.');
        }
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
            const msgs = {
                'not-allowed': 'Permiso de micrófono denegado.',
                'service-not-allowed': 'Permiso de micrófono denegado.',
                'no-speech': 'No te escuché, probá de nuevo.',
                'audio-capture': 'No hay micrófono disponible.',
                'network': 'Sin internet para el dictado (Web Speech usa Google).',
            };
            decir(msgs[e.error] || ('No pude escuchar (' + e.error + ').'), 'Error');
        };
        rec.onend = () => {
            escuchando = false; boton.classList.remove('escuchando');
            const t = finalTexto.trim();
            if (t) procesar(t);
        };
    }

    function alternar() {
        // Modo backend: conversación continua (graba, transcribe y responde en bucle).
        if (endpoint) { toggleConversacion(); return; }
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
        endpoint = (opts && opts.endpoint) || null;
        onNavegar = (opts && opts.onNavegar) || null;
        inyectarEstilo();
        boton = document.createElement('button');
        boton.id = 'voz-btn';
        boton.type = 'button';
        boton.title = 'Asistente de voz';
        boton.setAttribute('aria-label', 'Asistente de voz');
        boton.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0"/><line x1="12" y1="18" x2="12" y2="22"/></svg>';
        toast = document.createElement('div');
        toast.id = 'voz-toast';
        panel = document.createElement('div');
        panel.id = 'voz-panel';
        panel.innerHTML =
            '<div class="vp-head"><span class="vp-dot"></span><span class="vp-estado">Listo</span>' +
            '<span class="vp-wave"><i></i><i></i><i></i><i></i></span></div>' +
            '<div class="vp-body"><div class="vp-user"></div><div class="vp-reply"></div></div>';
        document.body.append(boton, toast, panel);
        vpEstadoEl = panel.querySelector('.vp-estado');
        vpUserEl = panel.querySelector('.vp-user');
        vpReplyEl = panel.querySelector('.vp-reply');
        boton.addEventListener('click', alternar);
        if (!SR && !endpoint) boton.title = 'Dictado por voz no soportado en este navegador';
    }

    return { montar, decir, norm, get soportado() { return !!SR; } };
})();

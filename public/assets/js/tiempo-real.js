// ===== Tiempo real (WebSockets) =====
// Cliente compartido por el panel, la tienda y la app del repartidor. No depende de otros scripts.
//
// 1) Pide un ticket a /api/tiempo-real/ticket: el backend lo firma con la identidad de la
//    sesión (o del token del repartidor). Sin sesión, o con el WebSocket desactivado, no conecta
//    y cada pantalla sigue con su refresco periódico de siempre.
// 2) Abre el WebSocket con ese ticket. El servidor decide qué eventos le llegan a cada uno.
// 3) Latido cada 25 s; si la conexión se cae, reintenta con espera creciente (1, 2, 4… hasta 30 s)
//    y al volver avisa "reconectado" para que las pantallas recarguen lo que se perdieron.
//
// Uso:  BritechRT.on('notificacion', (datos) => …);  BritechRT.iniciar();  BritechRT.conectado();
window.BritechRT = (function () {
    const oyentes = {};
    let ws = null, abierto = false, intentos = 0, latido = null, detenido = true, opciones = {}, huboConexion = false, timer = null;

    function on(evento, fn) { (oyentes[evento] = oyentes[evento] || []).push(fn); }

    function avisar(evento, datos) {
        (oyentes[evento] || []).forEach((fn) => { try { fn(datos); } catch (e) { console.warn('[tiempo real]', e); } });
    }

    function marcar(estado) {
        document.documentElement.dataset.rt = estado;   // el CSS puede mostrar "en vivo"
        avisar('estado', { estado });
    }

    async function conectar() {
        if (detenido) return;
        let r;
        try {
            const q = opciones.token ? '?token=' + encodeURIComponent(opciones.token) : '';
            const resp = await fetch('/api/tiempo-real/ticket' + q, { credentials: 'same-origin', cache: 'no-store' });
            if (resp.status === 401) { marcar('off'); return; }   // sin sesión: no insistir
            r = await resp.json();
        } catch { return reintentar(); }
        if (!r || !r.ok || !r.activo) { marcar('off'); return; }   // tiempo real desactivado en el servidor

        try { ws = new WebSocket(r.url + '?ticket=' + encodeURIComponent(r.ticket)); }
        catch { return reintentar(); }

        ws.onopen = () => {
            abierto = true;
            marcar('on');
            if (huboConexion) avisar('reconectado', {});
            huboConexion = true;
            intentos = 0;
            clearInterval(latido);
            latido = setInterval(() => enviar({ tipo: 'ping' }), 25000);
        };
        ws.onmessage = (m) => {
            let d;
            try { d = JSON.parse(m.data); } catch { return; }
            if (d && typeof d.evento === 'string') avisar(d.evento, d.datos || {});
        };
        ws.onclose = () => {
            abierto = false;
            clearInterval(latido);
            marcar('off');
            reintentar();
        };
        ws.onerror = () => { /* el onclose se encarga de reintentar */ };
    }

    function reintentar() {
        if (detenido) return;
        clearTimeout(timer);
        intentos++;
        const espera = Math.min(30000, 1000 * 2 ** Math.min(intentos - 1, 5)) + Math.random() * 800;
        timer = setTimeout(conectar, espera);
    }

    function enviar(obj) {
        if (abierto && ws && ws.readyState === 1) ws.send(JSON.stringify(obj));
    }

    /** Conecta (una sola vez). opts.token: token del repartidor (su app no usa sesión). */
    function iniciar(opts) {
        if (!('WebSocket' in window)) return;
        opciones = opts || {};
        if (!detenido) return;
        detenido = false;
        conectar();
    }

    /** Cierra y no reintenta (al cerrar sesión). */
    function detener() {
        detenido = true;
        huboConexion = false;
        clearTimeout(timer);
        clearInterval(latido);
        if (ws) { try { ws.close(); } catch {} }
        marcar('off');
    }

    return { on, iniciar, detener, conectado: () => abierto };
})();

// ===== Notificaciones push del navegador (Pusher Beams) =====
// Se incluye en el panel, el POS, la tienda y el login. No depende de otros scripts.
// - Con sesión iniciada: registra este navegador para la persona logueada
//   (u-<id> staff / c-<id> cliente). El servidor firma el token solo para su sesión.
// - Sin sesión: si el navegador estaba registrado, lo desvincula (deja de recibir avisos).
// - Si el permiso todavía no se pidió, muestra un botón chico "Activar notificaciones".
// Los avisos los manda el backend (NotificacionRepository → BeamsService).
(function () {
    const SDK = 'https://js.pusher.com/beams/2.1.0/push-notifications-cdn.js';
    const CLAVE_DESCARTE = 'britech_push_descartado';
    let cfg = null, cliente = null, sdk = null;

    const soportado = () => window.isSecureContext && 'serviceWorker' in navigator
        && 'PushManager' in window && 'Notification' in window;

    function cargarSdk() {
        if (window.PusherPushNotifications) return Promise.resolve();
        if (!sdk) sdk = new Promise((ok, mal) => {
            const s = document.createElement('script');
            s.src = SDK; s.async = true; s.onload = ok; s.onerror = () => { sdk = null; mal(new Error('No se pudo cargar Beams')); };
            document.head.appendChild(s);
        });
        return sdk;
    }

    async function obtenerCliente() {
        await cargarSdk();
        if (!cliente) cliente = new PusherPushNotifications.Client({ instanceId: cfg.instance_id });
        return cliente;
    }

    // Asocia el navegador al usuario de la sesión (pide permiso si hace falta).
    async function registrar() {
        const c = await obtenerCliente();
        await c.start();
        const actual = await c.getUserId();
        if (actual === cfg.user_id) return;
        if (actual) await c.clearAllState();           // estaba con otra cuenta en este navegador
        await c.setUserId(cfg.user_id, new PusherPushNotifications.TokenProvider({ url: '/api/beams/token' }));
    }

    async function sincronizar() {
        if (!soportado()) return;
        try {
            cfg = await fetch('/api/beams/config', { credentials: 'same-origin', cache: 'no-store' }).then((r) => r.json());
        } catch { return; }
        if (!cfg || !cfg.activo) { ocultarChip(); return; }

        try {
            if (!cfg.user_id) {
                ocultarChip();
                if (Notification.permission === 'granted') await salir();
                return;
            }
            if (Notification.permission === 'granted') { await registrar(); ocultarChip(); }
            else if (Notification.permission === 'default' && !descartado()) mostrarChip();
            else ocultarChip();
        } catch (e) {
            console.warn('[push]', e);
        }
    }

    // Desvincula este navegador (al cerrar sesión).
    async function salir() {
        try {
            const reg = await navigator.serviceWorker.getRegistration('/');
            if (!reg) return;                              // nunca se registró: nada que hacer
            const c = await obtenerCliente();
            await c.stop();
        } catch (e) { /* secundario */ }
    }

    // ---- Botón "Activar notificaciones" ----
    function descartado() { try { return localStorage.getItem(CLAVE_DESCARTE) === '1'; } catch { return false; } }

    function mostrarChip() {
        if (document.getElementById('push-chip')) return;
        const chip = document.createElement('div');
        chip.id = 'push-chip';
        chip.className = 'push-chip';
        chip.innerHTML = `<button type="button" class="push-chip-si">🔔 Activar notificaciones</button>
            <button type="button" class="push-chip-no" aria-label="Ahora no" title="Ahora no">✕</button>`;
        chip.querySelector('.push-chip-si').addEventListener('click', async () => {
            ocultarChip();
            try { await registrar(); }
            catch (e) { console.warn('[push]', e); }
        });
        chip.querySelector('.push-chip-no').addEventListener('click', () => {
            try { localStorage.setItem(CLAVE_DESCARTE, '1'); } catch {}
            ocultarChip();
        });
        document.body.appendChild(chip);
    }

    function ocultarChip() { document.getElementById('push-chip')?.remove(); }

    window.BritechPush = { sincronizar, salir };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', sincronizar);
    else sincronizar();
})();

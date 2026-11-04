// ===== Auditoría del asistente de voz (panel admin) =====
// Muestra la bitácora de interacciones: qué pidió cada quién y qué respondió el
// asistente. renderVoz() lo llama seleccionar('voz') desde admin.js.

async function renderVoz() {
    const cont = document.getElementById('voz-log-cont');
    const btn = document.getElementById('voz-refrescar');
    if (btn) btn.onclick = renderVoz;

    cont.innerHTML = '<p class="td-mute" style="padding:1rem">Cargando…</p>';

    let r;
    try { r = await api.get('/api/admin/voz/log'); }
    catch (e) { cont.innerHTML = '<div class="tabla-caja"><p class="tabla-vacia">No se pudo cargar la auditoría.</p></div>'; return; }

    const filas = (r && r.data) || [];
    if (!filas.length) {
        cont.innerHTML = '<div class="tabla-caja"><p class="tabla-vacia">Todavía no hay interacciones con el asistente.</p></div>';
        return;
    }

    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const fecha = (s) => {
        try { return new Date(String(s).replace(' ', 'T')).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }); }
        catch (e) { return s; }
    };

    const rows = filas.map((f) => `
        <tr>
            <td class="td-mute tabular" style="white-space:nowrap">${fecha(f.creado_en)}</td>
            <td>${esc(f.usuario)}</td>
            <td>${esc(f.texto)}</td>
            <td class="td-mute">${esc(f.respuesta)}</td>
        </tr>`).join('');

    cont.innerHTML = `
        <div class="tabla-caja">
            <table>
                <thead><tr><th>Fecha</th><th>Usuario</th><th>Dijo</th><th>Respondió</th></tr></thead>
                <tbody>${rows}</tbody>
            </table>
        </div>`;
}

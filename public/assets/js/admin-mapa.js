// ===== Mapa de envíos en tiempo real (seguimiento tipo Uber) =====
// Usa Leaflet + OpenStreetMap. Las motos se actualizan por polling cada 3s.
// Depende de los globales de admin.js: $, api, esc, seleccionar.

let _mapa = null, _marcadores = {}, _destinos = {}, _mapaInterval = null;

// Pines SVG compartidos (mapas.js). "viva" se refleja con opacidad del marcador.
// ¿La posición es reciente? (más de 30s sin emitir = sin señal)
const senal = (e) => (e.hace_seg == null ? false : Number(e.hace_seg) <= 30);
const haceTxt = (e) => e.hace_seg == null ? 'sin datos' : (Number(e.hace_seg) <= 5 ? 'ahora' : 'hace ' + Number(e.hace_seg) + 's');

function renderMapaEnvios() {
    if (!window.L) { setTimeout(renderMapaEnvios, 300); return; }   // Leaflet carga con defer
    if (!_mapa) {
        _mapa = L.map('mapa-envios', { zoomControl: true }).setView([-26.1849, -58.1731], 13);  // Formosa capital
        tileBritech().addTo(_mapa);
    }
    setTimeout(() => _mapa.invalidateSize(), 60);   // el contenedor estaba oculto
    clearInterval(_mapaInterval);
    cargarMapa();
    _mapaInterval = setInterval(() => {
        if ($('vista-mapa').classList.contains('oculto')) { clearInterval(_mapaInterval); return; }
        cargarMapa();
    }, 3000);
}

async function cargarMapa() {
    let r;
    try { r = await api.get('/api/admin/envios/mapa'); } catch { return; }
    const envios = r.envios || [];
    $('mapa-estado').textContent = envios.length
        ? `🛵 ${envios.length} moto(s) en reparto` : 'No hay motos en reparto ahora';

    const vivos = new Set();
    envios.forEach((e) => {
        if (e.ult_lat == null) return;
        const id = 'e' + e.envio_id;
        vivos.add(id);
        const ll = [Number(e.ult_lat), Number(e.ult_lng)];
        const viva = senal(e);
        const pop = `<b>${esc(e.repartidor)}</b><br>${esc(e.referencia)} · ${esc(e.estado)}`
            + (e.eta_min != null && viva ? `<br>ETA ~${e.eta_min} min` : '')
            + (viva ? `<br><small>${haceTxt(e)}</small>` : `<br><small>Sin señal (${haceTxt(e)})</small>`);
        if (_marcadores[id]) { _marcadores[id].setLatLng(ll).setOpacity(viva ? 1 : .55).setPopupContent(pop); }
        else { _marcadores[id] = L.marker(ll, { icon: markerMoto(), opacity: viva ? 1 : .55 }).addTo(_mapa).bindPopup(pop); }
        if (e.dest_lat != null && !_destinos[id]) {
            _destinos[id] = L.marker([Number(e.dest_lat), Number(e.dest_lng)], { icon: markerDropoff() })
                .addTo(_mapa).bindPopup('Destino · ' + esc(e.referencia));
        }
    });
    brkIcons();   // renderiza los iconos SVG de los pines recién agregados
    // Sacar las motos que ya no están en reparto.
    Object.keys(_marcadores).forEach((id) => {
        if (!vivos.has(id)) {
            _mapa.removeLayer(_marcadores[id]); delete _marcadores[id];
            if (_destinos[id]) { _mapa.removeLayer(_destinos[id]); delete _destinos[id]; }
        }
    });

    // Lista lateral (clic = centrar en esa moto).
    $('mapa-lista').innerHTML = envios.length
        ? envios.map((e) => `<button class="mapa-item${senal(e) ? '' : ' sin-senal'}" data-id="e${e.envio_id}">
            <span class="mapa-item-ic"><i data-lucide="bike"></i></span>
            <span class="mapa-item-tx"><strong>${esc(e.repartidor)}</strong>
                <small>${esc(e.referencia)} · ${esc(e.estado)}${senal(e) && e.eta_min != null ? ` · ETA ${e.eta_min}′` : ''}
                ${senal(e) ? '' : '· sin señal'}</small></span>
          </button>`).join('')
        : '<p class="dash-vacio">Sin motos en reparto.</p>';
    $('mapa-lista').querySelectorAll('.mapa-item').forEach((b) => b.addEventListener('click', () => {
        const m = _marcadores[b.dataset.id];
        if (m) { _mapa.panTo(m.getLatLng()); m.openPopup(); }
    }));
    brkIcons();   // iconos de la lista lateral
}

// Botón "‹ Panel" de esta vista.
(function () {
    const b = document.querySelector('#vista-mapa [data-ir-inicio]');
    if (b) b.addEventListener('click', () => seleccionar('inicio'));
})();

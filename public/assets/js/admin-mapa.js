// ===== Mapa de envíos en tiempo real (seguimiento tipo Uber) =====
// Usa Leaflet + OpenStreetMap. Las motos se actualizan por polling cada 3s.
// Depende de los globales de admin.js: $, api, esc, seleccionar.

let _mapa = null, _marcadores = {}, _destinos = {}, _mapaInterval = null;

const motoIcon = () => L.divIcon({ className: 'moto-icon', html: '🛵', iconSize: [34, 34], iconAnchor: [17, 17] });
const destIcon = () => L.divIcon({ className: 'dest-icon', html: '🏁', iconSize: [24, 24], iconAnchor: [6, 24] });

function renderMapaEnvios() {
    if (!window.L) { setTimeout(renderMapaEnvios, 300); return; }   // Leaflet carga con defer
    if (!_mapa) {
        _mapa = L.map('mapa-envios', { zoomControl: true }).setView([-26.1849, -58.1731], 13);  // Formosa capital
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap', maxZoom: 19,
        }).addTo(_mapa);
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
        const pop = `<b>🛵 ${esc(e.repartidor)}</b><br>${esc(e.referencia)} · ${esc(e.estado)}`
            + (e.eta_min != null ? `<br>⏱ ETA ~${e.eta_min} min` : '');
        if (_marcadores[id]) { _marcadores[id].setLatLng(ll).setPopupContent(pop); }
        else { _marcadores[id] = L.marker(ll, { icon: motoIcon() }).addTo(_mapa).bindPopup(pop); }
        if (e.dest_lat != null && !_destinos[id]) {
            _destinos[id] = L.marker([Number(e.dest_lat), Number(e.dest_lng)], { icon: destIcon() })
                .addTo(_mapa).bindPopup('🏁 Destino · ' + esc(e.referencia));
        }
    });
    // Sacar las motos que ya no están en reparto.
    Object.keys(_marcadores).forEach((id) => {
        if (!vivos.has(id)) {
            _mapa.removeLayer(_marcadores[id]); delete _marcadores[id];
            if (_destinos[id]) { _mapa.removeLayer(_destinos[id]); delete _destinos[id]; }
        }
    });

    // Lista lateral (clic = centrar en esa moto).
    $('mapa-lista').innerHTML = envios.length
        ? envios.map((e) => `<button class="mapa-item" data-id="e${e.envio_id}">
            <span class="mapa-item-ic">🛵</span>
            <span class="mapa-item-tx"><strong>${esc(e.repartidor)}</strong>
                <small>${esc(e.referencia)} · ${esc(e.estado)}${e.eta_min != null ? ` · ETA ${e.eta_min}′` : ''}</small></span>
          </button>`).join('')
        : '<p class="dash-vacio">Sin motos en reparto.</p>';
    $('mapa-lista').querySelectorAll('.mapa-item').forEach((b) => b.addEventListener('click', () => {
        const m = _marcadores[b.dataset.id];
        if (m) { _mapa.panTo(m.getLatLng()); m.openPopup(); }
    }));
}

// Botón "‹ Panel" de esta vista.
(function () {
    const b = document.querySelector('#vista-mapa [data-ir-inicio]');
    if (b) b.addEventListener('click', () => seleccionar('inicio'));
})();

// Capa base + marcadores del sistema de mapas (un solo lugar para todos).
// Mapa oscuro Esri (sin key) + pines SVG con iconos Lucide (nada de emojis).

function tileBritech() {
    return L.tileLayer(
        'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}',
        { attribution: '© Esri · © OpenStreetMap', maxZoom: 19 }
    );
}

// Estilos del pin (se inyectan una sola vez).
(function () {
    if (document.getElementById('brk-pin-css')) return;
    const s = document.createElement('style');
    s.id = 'brk-pin-css';
    s.textContent =
        '.brk-pin-inner{display:grid;place-items:center;width:32px;height:32px;' +
        'border-radius:50% 50% 50% 0;transform:rotate(-45deg);border:2px solid #fff;' +
        'box-shadow:0 2px 8px rgba(0,0,0,.45)}' +
        '.brk-pin-inner svg{transform:rotate(45deg);width:16px;height:16px;color:#fff}';
    document.head.appendChild(s);
})();

function _pin(iconName, bg) {
    return L.divIcon({
        className: 'brk-pin',
        html: '<span class="brk-pin-inner" style="background:' + bg + '"><i data-lucide="' + iconName + '"></i></span>',
        iconSize: [32, 40], iconAnchor: [16, 36], popupAnchor: [0, -34],
    });
}

const markerPickup  = () => _pin('package', '#f59e0b');     // retiro (local) — ámbar
const markerDropoff = () => _pin('map-pin', '#22c55e');     // destino (cliente) — verde
const markerMoto    = () => _pin('bike', '#7c3aed');        // moto en vivo — violeta

// Renderiza los <i data-lucide> que ya estén en el DOM (markers, botones, etc.).
function brkIcons() { if (window.lucide) window.lucide.createIcons(); }

// Mueve un marcador suavemente (glide) de donde está a `to` ([lat,lng]) en `ms`.
function moverMarcadorSuave(marker, to, ms) {
    ms = ms || 900;
    const from = marker.getLatLng();
    if (!from || (Math.abs(from.lat - to[0]) < 1e-7 && Math.abs(from.lng - to[1]) < 1e-7)) { marker.setLatLng(to); return; }
    const t0 = performance.now();
    (function step(t) {
        let k = Math.min(1, (t - t0) / ms);
        k = k < 0.5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;   // easeInOutQuad
        marker.setLatLng([from.lat + (to[0] - from.lat) * k, from.lng + (to[1] - from.lng) * k]);
        if (k < 1) requestAnimationFrame(step);
    })(t0);
}

// Recorta la ruta al tramo que FALTA: desde la posición de la moto hasta el final.
// Así lo recorrido desaparece y la línea va de la moto al destino. (`coords` = [[lat,lng],…])
function recortarRuta(coords, moto) {
    if (!coords || coords.length < 2) return coords || [];
    let mejor = 0, md = Infinity;
    for (let i = 0; i < coords.length; i++) {
        const dx = coords[i][0] - moto[0], dy = coords[i][1] - moto[1];
        const d = dx * dx + dy * dy;
        if (d < md) { md = d; mejor = i; }
    }
    return [moto].concat(coords.slice(mejor));
}

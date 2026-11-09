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

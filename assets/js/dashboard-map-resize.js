/**
 * Altura dos mapas Leaflet no dashboard: arrastar a barra inferior, persistir em localStorage.
 * Chaves: localStorage crm_prefeitura_map_h_<data-map-resize-key>
 * Espera: .dashboard-map-resize-wrap[data-map-resize-key] > .dashboard-map-leaflet-host + .dashboard-map-resize-handle
 */
(function () {
  var PREFIX = 'crm_prefeitura_map_h_';
  var MIN = 220;
  var MAX = 960;
  var DEFAULT = 420;

  function clamp(n) {
    n = parseInt(n, 10);
    if (isNaN(n)) return DEFAULT;
    return Math.min(MAX, Math.max(MIN, n));
  }

  function readKey(key) {
    try {
      var v = localStorage.getItem(PREFIX + key);
      if (v !== null && v !== '') return clamp(parseInt(v, 10));
    } catch (e) {}
    return DEFAULT;
  }

  function saveKey(key, h) {
    try {
      localStorage.setItem(PREFIX + key, String(clamp(h)));
    } catch (e) {}
  }

  function invalidateMap(host) {
    if (!host) return;
    if (host._crmGoogleMap && window.CrmGoogleMapsJs && typeof window.CrmGoogleMapsJs.triggerResize === 'function') {
      requestAnimationFrame(function () {
        window.CrmGoogleMapsJs.triggerResize(host._crmGoogleMap);
      });
      return;
    }
    if (!host._crmLeafletMap || typeof host._crmLeafletMap.invalidateSize !== 'function') return;
    requestAnimationFrame(function () {
      try {
        host._crmLeafletMap.invalidateSize({ animate: false });
      } catch (e) {}
    });
  }

  document.querySelectorAll('.dashboard-map-resize-wrap').forEach(function (wrap) {
    var key = wrap.getAttribute('data-map-resize-key');
    var host = wrap.querySelector('.dashboard-map-leaflet-host');
    var handle = wrap.querySelector('.dashboard-map-resize-handle');
    if (!key || !host || !handle) return;

    host.style.height = readKey(key) + 'px';

    var startY;
    var startH;
    function onMove(e) {
      if (e.type === 'touchmove' && e.cancelable) e.preventDefault();
      var y = e.touches ? e.touches[0].clientY : e.clientY;
      var nh = clamp(startH + (y - startY));
      host.style.height = nh + 'px';
      invalidateMap(host);
    }
    function onUp() {
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
      document.removeEventListener('touchmove', onMove);
      document.removeEventListener('touchend', onUp);
      document.body.classList.remove('dashboard-map-resize-dragging');
      saveKey(key, host.offsetHeight);
      invalidateMap(host);
    }
    function onDown(e) {
      if (e.button !== undefined && e.button !== 0) return;
      e.preventDefault();
      startY = e.touches ? e.touches[0].clientY : e.clientY;
      startH = host.offsetHeight;
      document.body.classList.add('dashboard-map-resize-dragging');
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
      document.addEventListener('touchmove', onMove, { passive: false });
      document.addEventListener('touchend', onUp);
    }
    handle.addEventListener('mousedown', onDown);
    handle.addEventListener('touchstart', onDown, { passive: false });
  });
})();

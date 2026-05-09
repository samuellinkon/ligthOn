/**
 * Mapa Leaflet no dashboard: pins a partir de window.CHAMADOS_MAP_PINS.
 */
(function () {
  var pins = window.CHAMADOS_MAP_PINS;
  var el = document.getElementById('chamados-map');
  var statusFilter = document.getElementById('chamados-map-filter-status');
  var clusterToggle = document.getElementById('chamados-map-toggle-cluster');
  var visibleCount = document.getElementById('chamados-map-visible-count');
  if (!el || typeof L === 'undefined') return;
  if (!Array.isArray(pins) || pins.length === 0) {
    el.innerHTML = '<div class="dashboard-map-empty">Nenhum chamado com latitude e longitude no período.</div>';
    return;
  }

  var map = L.map('chamados-map', { scrollWheelZoom: false });
  el._crmLeafletMap = map;
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap',
  }).addTo(map);

  var markers = pins.map(function (p) {
    var m = L.marker([p.lat, p.lng]);
    var addr = p.endereco_completo ? '<br><small>' + escapeHtml(p.endereco_completo) + '</small>' : '';
    m.bindPopup(
      '<strong>#' + p.id + '</strong> ' + escapeHtml(p.titulo) +
        '<br><span class="map-popup-meta">' + escapeHtml(p.cliente) + ' · ' + escapeHtml(p.data) +
        ' · ' + escapeHtml(p.status) + '</span>' + addr +
        '<br><a href="chamado_detalhe.php?id=' + p.id + '">Abrir chamado</a>'
    );
    return { pin: p, marker: m };
  });

  var markerLayer = null;
  renderMarkers();
  requestAnimationFrame(function () {
    try {
      map.invalidateSize({ animate: false });
    } catch (e) {}
  });

  if (statusFilter) {
    statusFilter.addEventListener('change', renderMarkers);
  }
  if (clusterToggle) {
    clusterToggle.addEventListener('change', renderMarkers);
  }

  function renderMarkers() {
    var filtered = markers.filter(function (item) {
      return matchStatus(item.pin);
    });
    var bounds = [];

    if (markerLayer) {
      map.removeLayer(markerLayer);
    }
    markerLayer = createMarkerLayer();
    filtered.forEach(function (item) {
      markerLayer.addLayer(item.marker);
      bounds.push([item.pin.lat, item.pin.lng]);
    });
    map.addLayer(markerLayer);

    if (visibleCount) {
      visibleCount.textContent = filtered.length + ' de ' + markers.length + ' chamado(s) visível(is)';
    }
    if (bounds.length === 1) {
      map.setView(bounds[0], 14);
    } else if (bounds.length > 1) {
      map.fitBounds(bounds, { padding: [28, 28], maxZoom: 15 });
    }
  }

  function createMarkerLayer() {
    if (!clusterToggle || clusterToggle.checked) {
      if (typeof L.markerClusterGroup === 'function') {
        return L.markerClusterGroup({
          showCoverageOnHover: false,
          spiderfyOnMaxZoom: true,
          disableClusteringAtZoom: 18,
        });
      }
    }

    return L.layerGroup();
  }

  function matchStatus(p) {
    if (!statusFilter || !statusFilter.value) return true;
    return String(p.status || '') === statusFilter.value;
  }

  function escapeHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

})();

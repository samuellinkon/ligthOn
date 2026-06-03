/**
 * Mapa dos pontos de iluminacao — viewport via API ou fallback legado.
 */
(function () {
  if (
    window.CRM_DASHBOARD_MAP_PROVIDER === 'google' ||
    window.CRM_PONTOS_MAP_PROVIDER === 'google'
  ) {
    return;
  }

  var useViewport =
    window.CRM_PONTOS_MAPA_VIEWPORT !== false &&
    window.CRM_PONTOS_MAPA_CONFIG &&
    window.CrmPontosMapViewport &&
    typeof window.CrmPontosMapViewport.initLeaflet === 'function';

  if (useViewport) {
    window.CrmPontosMapViewport.initLeaflet({
      mapElementId: 'pontos-iluminacao-map',
      config: window.CRM_PONTOS_MAPA_CONFIG,
    });
    return;
  }

  var pins = window.PONTOS_ILUMINACAO_MAP;
  var el = document.getElementById('pontos-iluminacao-map');
  var areaFilter = document.getElementById('map-filter-area');
  var searchFilter = document.getElementById('map-filter-search');
  var clusterToggle = document.getElementById('map-toggle-cluster');
  var visibleCount = document.getElementById('map-visible-count');
  var FIT_BOUNDS_MAX = 80;

  if (!el || typeof L === 'undefined') return;
  if (!Array.isArray(pins) || pins.length === 0) {
    el.innerHTML = '<div class="pontos-map-empty">Nenhum ponto com latitude e longitude cadastrado.</div>';
    if (window.CrmPontosPageLoader) {
      window.CrmPontosPageLoader.notifyMapReady();
    }
    return;
  }

  var markerClass =
    typeof window.crmPontoMarkerClass === 'function'
      ? window.crmPontoMarkerClass
      : function () {
          return 'ponto-marker ponto-marker--ativo';
        };

  var map = L.map('pontos-iluminacao-map', { scrollWheelZoom: false });
  el._crmLeafletMap = map;
  if (window.CrmLeafletBasemap) {
    window.CrmLeafletBasemap.addTo(map, { maxZoom: 19 });
  }

  applyDefaultCenter(map);

  var markers = pins.map(function (p) {
    var icon = L.divIcon({
      className: '',
      html: '<span class="' + markerClass(p) + '"></span>',
      iconSize: [10, 10],
      iconAnchor: [5, 5],
      popupAnchor: [0, -8],
    });
    var marker = L.marker([p.lat, p.lng], { icon: icon });
    if (
      window.CrmPontoIluminacaoPopup &&
      typeof window.CrmPontoIluminacaoPopup.bindLazyLeaflet === 'function'
    ) {
      window.CrmPontoIluminacaoPopup.bindLazyLeaflet(marker, p, {
        apiUrl: window.CRM_PONTO_MAPA_DETALHE_API,
        popupOptions: { actions: 'full' },
      });
    } else if (
      window.CrmPontoIluminacaoPopup &&
      typeof window.CrmPontoIluminacaoPopup.render === 'function'
    ) {
      marker.bindPopup(window.CrmPontoIluminacaoPopup.render(p, { actions: 'full' }), {
        maxWidth: 360,
        minWidth: 280,
      });
    }
    return { pin: p, marker: marker };
  });

  var markerLayer = null;
  renderMarkers();
  requestAnimationFrame(function () {
    try {
      map.invalidateSize({ animate: false });
    } catch (e) {}
    renderMarkers();
    if (window.CrmPontosPageLoader) {
      window.CrmPontosPageLoader.notifyMapReady();
    }
  });

  if (areaFilter) areaFilter.addEventListener('change', renderMarkers);
  if (searchFilter) searchFilter.addEventListener('input', debounce(renderMarkers, 180));
  if (clusterToggle) clusterToggle.addEventListener('change', renderMarkers);

  function applyDefaultCenter(leafletMap) {
    var c = window.CRM_PONTOS_MAP_CENTER;
    if (c && c.lat != null && c.lng != null) {
      leafletMap.setView([Number(c.lat), Number(c.lng)], typeof c.zoom === 'number' ? c.zoom : 12);
      return;
    }
    leafletMap.setView([-8.398075, -35.063889], 12);
  }

  function shouldFitBounds(count) {
    return count > 0 && count <= FIT_BOUNDS_MAX;
  }

  function renderMarkers() {
    var filtered = markers.filter(function (item) {
      return matchArea(item.pin) && matchSearch(item.pin);
    });
    var bounds = [];

    if (markerLayer) map.removeLayer(markerLayer);
    markerLayer = createMarkerLayer();
    filtered.forEach(function (item) {
      markerLayer.addLayer(item.marker);
      bounds.push([item.pin.lat, item.pin.lng]);
    });
    map.addLayer(markerLayer);

    if (visibleCount) {
      visibleCount.textContent = filtered.length + ' de ' + markers.length + ' ponto(s) visível(is)';
    }
    if (bounds.length === 1) {
      map.setView(bounds[0], 16);
    } else if (shouldFitBounds(bounds.length)) {
      map.fitBounds(bounds, { padding: [28, 28], maxZoom: 16 });
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

  function matchArea(p) {
    if (!areaFilter || !areaFilter.value) return true;
    return String(p.bairro || '') === areaFilter.value;
  }

  function matchSearch(p) {
    if (!searchFilter || !searchFilter.value) return true;
    var q = normalizeText(searchFilter.value);
    return normalizeText([p.codigo_poste, p.bairro, p.status].join(' ')).indexOf(q) !== -1;
  }

  function normalizeText(s) {
    var out = String(s || '').toLowerCase();
    if (typeof out.normalize === 'function') {
      out = out.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    return out;
  }

  function debounce(fn, wait) {
    var t = null;
    return function () {
      clearTimeout(t);
      t = setTimeout(fn, wait);
    };
  }
})();

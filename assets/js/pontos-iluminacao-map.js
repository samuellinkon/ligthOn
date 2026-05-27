/**
 * Mapa dos pontos de iluminacao a partir de window.PONTOS_ILUMINACAO_MAP.
 */
(function () {
  var pins = window.PONTOS_ILUMINACAO_MAP;
  var el = document.getElementById('pontos-iluminacao-map');
  var areaFilter = document.getElementById('map-filter-area');
  var searchFilter = document.getElementById('map-filter-search');
  var clusterToggle = document.getElementById('map-toggle-cluster');
  var visibleCount = document.getElementById('map-visible-count');
  if (!el || typeof L === 'undefined') return;
  if (!Array.isArray(pins) || pins.length === 0) {
    el.innerHTML = '<div class="pontos-map-empty">Nenhum ponto com latitude e longitude cadastrado.</div>';
    return;
  }

  var renderPopup =
    window.CrmPontoIluminacaoPopup && typeof window.CrmPontoIluminacaoPopup.render === 'function'
      ? function (p) {
          return window.CrmPontoIluminacaoPopup.render(p, { actions: 'full' });
        }
      : function () {
          return '<div class="map-popup"><p>Popup indisponível.</p></div>';
        };

  var map = L.map('pontos-iluminacao-map', { scrollWheelZoom: false });
  el._crmLeafletMap = map;
  if (window.CrmLeafletBasemap) {
    window.CrmLeafletBasemap.addTo(map, { maxZoom: 19 });
  }

  var markerClass =
    typeof window.crmPontoMarkerClass === 'function'
      ? window.crmPontoMarkerClass
      : function () {
          return 'ponto-marker ponto-marker--ativo';
        };

  var markers = pins.map(function (p) {
    var icon = L.divIcon({
      className: '',
      html: '<span class="' + markerClass(p) + '"></span>',
      iconSize: [10, 10],
      iconAnchor: [5, 5],
      popupAnchor: [0, -8],
    });
    var marker = L.marker([p.lat, p.lng], { icon: icon });
    marker.bindPopup(renderPopup(p), { maxWidth: 360, minWidth: 280 });
    return { pin: p, marker: marker };
  });

  var markerLayer = null;
  renderMarkers();
  requestAnimationFrame(function () {
    try {
      map.invalidateSize({ animate: false });
    } catch (e) {}
  });

  if (areaFilter) {
    areaFilter.addEventListener('change', renderMarkers);
  }
  if (searchFilter) {
    searchFilter.addEventListener('input', debounce(renderMarkers, 180));
  }
  if (clusterToggle) {
    clusterToggle.addEventListener('change', renderMarkers);
  }

  function renderMarkers() {
    var filtered = markers.filter(function (item) {
      return matchArea(item.pin) && matchSearch(item.pin);
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
      visibleCount.textContent = filtered.length + ' de ' + markers.length + ' ponto(s) visível(is)';
    }
    if (bounds.length === 1) {
      map.setView(bounds[0], 16);
    } else if (bounds.length > 1) {
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
    var haystack = [
      p.codigo_poste,
      p.identificador_externo,
      p.endereco_completo,
      p.bairro,
      p.cliente,
      p.status,
    ].join(' ');
    return normalizeText(haystack).indexOf(q) !== -1;
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

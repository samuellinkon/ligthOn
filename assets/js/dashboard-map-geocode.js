/**
 * Mapa de chamados no dashboard (Leaflet): pins + fila de geocode Nominatim.
 */
(function (global) {
  'use strict';

  var Core = global.CrmDashboardMapGeocodeCore;

  function escapeHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function renderPopup(ch) {
    if (global.CrmChamadoPopup && typeof global.CrmChamadoPopup.render === 'function') {
      return global.CrmChamadoPopup.render(ch, { showStreetView: true });
    }
    return '<div class="call-popup"><p>Popup indisponível.</p></div>';
  }

  function chamadoMarkerClass(pin) {
    if (typeof global.crmChamadoMarkerClass === 'function') {
      return global.crmChamadoMarkerClass(pin);
    }
    return 'chamado-marker chamado-marker--open';
  }

  function createChamadoLeafletIcon(pin) {
    return L.divIcon({
      className: 'crm-chamado-marker-wrap',
      html: '<span class="' + chamadoMarkerClass(pin) + '"></span>',
      iconSize: [12, 12],
      iconAnchor: [6, 6],
    });
  }

  function initChamadosMap(opts) {
    opts = opts || {};
    var el = document.getElementById(opts.mapElementId || 'chamados-map');
    var pins = Array.isArray(opts.pins) ? opts.pins.slice() : [];
    var statusFilter = opts.statusFilter || null;
    var clusterToggle = opts.clusterToggle || null;
    var visibleCountEl = opts.visibleCountEl || null;
    var geocodeApi = opts.geocodeApi || '';
    var persistApi = opts.persistApi || '';
    var loadingBanner = null;

    if (!el || typeof L === 'undefined') {
      return null;
    }

    if (pins.length === 0) {
      var emptyMsg = opts.emptyMsg || 'Nenhum chamado com localização no período.';
      el.innerHTML = '<div class="dashboard-map-empty">' + escapeHtml(emptyMsg) + '</div>';
      return null;
    }

    if (opts.loadingMsg) {
      loadingBanner = document.createElement('div');
      loadingBanner.className = 'dashboard-map-loading';
      loadingBanner.setAttribute('role', 'status');
      loadingBanner.textContent = opts.loadingMsg;
      el.parentNode.insertBefore(loadingBanner, el);
    }

    var map = L.map(el.id, { scrollWheelZoom: false });
    el._crmLeafletMap = map;
    if (global.CrmLeafletBasemap) {
      global.CrmLeafletBasemap.addTo(map, { maxZoom: 19 });
    }

    var markers = [];
    var markerLayer = null;

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

    function addReadyPin(pin) {
      if (pin.lat == null || pin.lng == null) return;
      var m = L.marker([pin.lat, pin.lng], { icon: createChamadoLeafletIcon(pin) });
      m.bindPopup(renderPopup(pin), { maxWidth: 360, minWidth: 280 });
      pin.pin_state = 'ready';
      markers.push({ pin: pin, marker: m });
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

      if (visibleCountEl) {
        visibleCountEl.textContent =
          filtered.length + ' de ' + markers.length + ' visíveis';
      }
      if (bounds.length === 1) {
        map.setView(bounds[0], 14);
      } else if (bounds.length > 1) {
        map.fitBounds(bounds, { padding: [28, 28], maxZoom: 15 });
      }
      if (typeof opts.onMarkersChange === 'function') {
        opts.onMarkersChange(markers.length, filtered.length);
      }
    }

    pins.forEach(function (p) {
      if (p.pin_state === 'ready' || (p.lat != null && p.lng != null)) {
        addReadyPin(p);
      }
    });

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

    function finishLoadingBanner(failed) {
      if (!loadingBanner) return;
      if (failed > 0) {
        loadingBanner.textContent =
          failed + ' chamado(s) não localizados pelo endereço (demais exibidos no mapa).';
        loadingBanner.classList.add('dashboard-map-loading--warn');
      } else {
        loadingBanner.remove();
      }
    }

    if (!Core) {
      finishLoadingBanner(0);
      return map;
    }

    Core.runPendingOnMap(
      pins,
      geocodeApi,
      persistApi,
      function (pin) {
        addReadyPin(pin);
      },
      function (failed) {
        finishLoadingBanner(failed);
        renderMarkers();
      }
    );

    return map;
  }

  function createMarkerForPin(pin) {
    if (pin.lat == null || pin.lng == null) return null;
    var m = L.marker([pin.lat, pin.lng], { icon: createChamadoLeafletIcon(pin) });
    m.bindPopup(renderPopup(pin), { maxWidth: 360, minWidth: 280 });
    return m;
  }

  global.CrmDashboardMapChamados = {
    init: initChamadosMap,
    geocodePendingPins: Core ? Core.geocodePendingPins : function () {},
    createMarkerForPin: createMarkerForPin,
  };
})(typeof window !== 'undefined' ? window : this);

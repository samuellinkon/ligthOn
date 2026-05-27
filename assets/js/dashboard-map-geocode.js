/**
 * Mapa de chamados no dashboard: pins prontos + fila de geocode Nominatim + persistência.
 */
(function (global) {
  'use strict';

  var GEOCODE_DELAY_MS = 1100;

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

  function geocodeAttemptParams(attempt) {
    if (!attempt || typeof attempt !== 'object') return null;
    if (attempt.type === 'structured') {
      var p = new URLSearchParams();
      if (attempt.street) p.set('street', attempt.street);
      if (attempt.city) p.set('city', attempt.city);
      if (attempt.state) p.set('state', attempt.state);
      return p;
    }
    if (attempt.q) {
      var pq = new URLSearchParams();
      pq.set('q', attempt.q);
      return pq;
    }
    return null;
  }

  function fetchGeocodeHit(apiUrl, attempt) {
    var params = geocodeAttemptParams(attempt);
    if (!params) return Promise.resolve(null);
    var url = apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') + params.toString();
    return fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    })
      .then(function (r) {
        if (r.status === 429) return { rateLimited: true };
        return r.json().then(function (data) {
          return { rateLimited: false, data: data };
        });
      })
      .catch(function () {
        return { rateLimited: false, data: null };
      });
  }

  function resolvePinCoords(apiUrl, pin) {
    var attempts = Array.isArray(pin.geocode_attempts) ? pin.geocode_attempts : [];
    var chain = Promise.resolve(null);

    attempts.forEach(function (attempt) {
      chain = chain.then(function (hit) {
        if (hit) return hit;
        return fetchGeocodeHit(apiUrl, attempt).then(function (res) {
          if (res && res.rateLimited) {
            return { rateLimited: true };
          }
          var data = res && res.data;
          if (data && data.ok && data.hit) {
            return data.hit;
          }
          return null;
        });
      });
    });

    return chain;
  }

  function persistCoords(persistApi, pin, lat, lng) {
    if (!persistApi) return Promise.resolve();
    var fd = new FormData();
    fd.append('chamado_id', String(pin.id));
    fd.append('lat', String(lat));
    fd.append('lng', String(lng));
    return fetch(persistApi, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    }).catch(function () {});
  }

  function delay(ms) {
    return new Promise(function (resolve) {
      setTimeout(resolve, ms);
    });
  }

  /**
   * @param {object} opts
   * @param {string} opts.mapElementId
   * @param {Array} opts.pins
   * @param {string} [opts.geocodeApi]
   * @param {string} [opts.persistApi]
   * @param {string} [opts.emptyMsg]
   * @param {string} [opts.loadingMsg]
   * @param {HTMLElement|null} [opts.statusFilter]
   * @param {HTMLElement|null} [opts.clusterToggle]
   * @param {HTMLElement|null} [opts.visibleCountEl]
   * @param {function} [opts.onMarkersChange]
   */
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
      var emptyMsg = opts.emptyMsg
        || 'Nenhum chamado com localização no período.';
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
      var m = L.marker([pin.lat, pin.lng]);
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
          filtered.length + ' de ' + markers.length + ' chamado(s) visível(is)';
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

    var pending = pins.filter(function (p) {
      return p.pin_state === 'pending_geocode' || (p.lat == null && Array.isArray(p.geocode_attempts) && p.geocode_attempts.length);
    });

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

    if (pending.length === 0 || !geocodeApi) {
      finishLoadingBanner(0);
      return map;
    }

    var idx = 0;
    var failed = 0;

    function nextPending() {
      if (idx >= pending.length) {
        finishLoadingBanner(failed);
        renderMarkers();
        return;
      }
      var pin = pending[idx++];
      resolvePinCoords(geocodeApi, pin).then(function (hit) {
        if (hit && hit.rateLimited) {
          idx--;
          return delay(GEOCODE_DELAY_MS * 2).then(nextPending);
        }
        if (hit && hit.lat != null && hit.lon != null) {
          pin.lat = parseFloat(hit.lat);
          pin.lng = parseFloat(hit.lon);
          addReadyPin(pin);
          return persistCoords(persistApi, pin, pin.lat, pin.lng)
            .then(function () {
              return delay(GEOCODE_DELAY_MS);
            })
            .then(nextPending);
        }
        failed++;
        return delay(GEOCODE_DELAY_MS).then(nextPending);
      });
    }

    nextPending();
    return map;
  }

  /**
   * Geocodifica pins pendentes e chama onReady a cada sucesso.
   *
   * @param {Array} pins
   * @param {string} geocodeApi
   * @param {string} persistApi
   * @param {function(object): void} onReady
   * @param {function({failed:number}): void} onDone
   */
  function geocodePendingPins(pins, geocodeApi, persistApi, onReady, onDone) {
    var pending = (pins || []).filter(function (p) {
      return p.pin_state === 'pending_geocode'
        || (p.lat == null && Array.isArray(p.geocode_attempts) && p.geocode_attempts.length);
    });
    if (!geocodeApi || pending.length === 0) {
      if (onDone) onDone({ failed: 0 });
      return;
    }
    var idx = 0;
    var failed = 0;

    function next() {
      if (idx >= pending.length) {
        if (onDone) onDone({ failed: failed });
        return;
      }
      var pin = pending[idx++];
      resolvePinCoords(geocodeApi, pin).then(function (hit) {
        if (hit && hit.rateLimited) {
          idx--;
          return delay(GEOCODE_DELAY_MS * 2).then(next);
        }
        if (hit && hit.lat != null && hit.lon != null) {
          pin.lat = parseFloat(hit.lat);
          pin.lng = parseFloat(hit.lon);
          pin.pin_state = 'ready';
          if (onReady) onReady(pin);
          return persistCoords(persistApi, pin, pin.lat, pin.lng)
            .then(function () { return delay(GEOCODE_DELAY_MS); })
            .then(next);
        }
        failed++;
        return delay(GEOCODE_DELAY_MS).then(next);
      });
    }

    next();
  }

  global.CrmDashboardMapChamados = {
    init: initChamadosMap,
    geocodePendingPins: geocodePendingPins,
    createMarkerForPin: function (pin) {
      if (pin.lat == null || pin.lng == null) return null;
      var m = L.marker([pin.lat, pin.lng]);
      m.bindPopup(renderPopup(pin), { maxWidth: 360, minWidth: 280 });
      return m;
    },
  };
})(typeof window !== 'undefined' ? window : this);

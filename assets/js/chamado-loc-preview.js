/**
 * Street View primeiro; mapa Leaflet (OSM) sob demanda ou após geocode.
 */
(function (global) {
  'use strict';

  function initChamadoLocPreview(opts) {
    var mapEl = document.getElementById(opts.mapId);
    var svWrap = document.getElementById(opts.svWrapId);
    var svFrame = document.getElementById(opts.svFrameId);
    var svTab = document.getElementById(opts.svTabId);
    var svLabel = opts.svLabelId ? document.getElementById(opts.svLabelId) : null;
    var svMapBtn = opts.svMapBtnId ? document.getElementById(opts.svMapBtnId) : null;
    var hint = opts.hintId ? document.getElementById(opts.hintId) : null;
    if (!mapEl || typeof global.L === 'undefined') return;

    var lat = opts.lat;
    var lng = opts.lng;
    var modo = opts.modo || 'streetview';
    var mapaQuery = (opts.mapaQuery || '').trim();
    var attempts = opts.attempts || [];
    var map;
    var svGeneration = 0;
    var lastCoords = { lat: null, lng: null };

    function clearHint() {
      if (hint) hint.remove();
    }

    function showLeafletMap(la, lo) {
      svGeneration++;
      if (svWrap) svWrap.hidden = true;
      mapEl.hidden = false;
      lastCoords.lat = la;
      lastCoords.lng = lo;
      if (map) {
        map.setView([la, lo], 15);
        global.setTimeout(function () { map.invalidateSize(); }, 120);
        clearHint();
        return;
      }
      map = global.L.map(opts.mapId, {
        scrollWheelZoom: opts.scrollWheelZoom !== false,
        zoomControl: opts.zoomControl !== false
      });
      global.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OSM'
      }).addTo(map);
      global.L.marker([la, lo]).addTo(map);
      map.setView([la, lo], 15);
      global.setTimeout(function () { map.invalidateSize(); }, 120);
      clearHint();
    }

    function showStreetView(la, lo) {
      if (!svWrap || !svFrame) {
        showLeafletMap(la, lo);
        return;
      }
      svGeneration++;
      lastCoords.lat = la;
      lastCoords.lng = lo;
      mapEl.hidden = true;
      svWrap.hidden = false;
      if (svLabel) svLabel.textContent = 'Street View';
      if (svTab) {
        svTab.textContent = 'Abrir em nova aba';
        svTab.hidden = false;
      }
      var ll = encodeURIComponent(String(la) + ',' + String(lo));
      svFrame.src = 'https://www.google.com/maps?cbll=' + ll + '&cbp=11,0,0,0,0&layer=c&output=svembed';
      if (svTab) {
        svTab.href = 'https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=' + ll;
      }
      clearHint();
    }

    function showMapaEndereco(q) {
      if (!svWrap || !svFrame) {
        return;
      }
      svGeneration++;
      mapEl.hidden = true;
      svWrap.hidden = false;
      if (svLabel) svLabel.textContent = 'Mapa (endereço)';
      if (svTab) {
        svTab.textContent = 'Abrir no Google Maps';
        svTab.hidden = false;
      }
      var qEnc = encodeURIComponent(q);
      svFrame.src = 'https://www.google.com/maps?q=' + qEnc + '&hl=pt-BR&output=embed';
      if (svTab) {
        svTab.href = 'https://www.google.com/maps/search/?api=1&query=' + qEnc;
      }
      clearHint();
    }

    if (svMapBtn) {
      svMapBtn.addEventListener('click', function () {
        if (lastCoords.lat != null && lastCoords.lng != null) {
          showLeafletMap(lastCoords.lat, lastCoords.lng);
        }
      });
    }

    function nominatimStructured(street, city, state, nomEmail, nomOpts) {
      var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=br&email=' + nomEmail
        + '&street=' + encodeURIComponent(street)
        + '&city=' + encodeURIComponent(city)
        + '&state=' + encodeURIComponent(state)
        + '&country=' + encodeURIComponent('Brasil');
      return fetch(url, nomOpts).then(function (r) { return r.ok ? r.json() : []; });
    }

    function nominatimQ(q, nomEmail, nomOpts) {
      var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=br&email=' + nomEmail
        + '&q=' + encodeURIComponent(q);
      return fetch(url, nomOpts).then(function (r) { return r.ok ? r.json() : []; });
    }

    function photonQ(q, nomOpts) {
      var url = 'https://photon.komoot.io/api/?limit=1&lang=pt&q=' + encodeURIComponent(q);
      return fetch(url, nomOpts).then(function (r) { return r.ok ? r.json() : null; });
    }

    function hitFromNominatim(hits) {
      if (!hits || !hits[0]) return null;
      var la = parseFloat(hits[0].lat);
      var lo = parseFloat(hits[0].lon);
      return isFinite(la) && isFinite(lo) ? { lat: la, lon: lo } : null;
    }

    function hitFromPhoton(data) {
      if (!data || !data.features || !data.features[0] || !data.features[0].geometry) return null;
      var c = data.features[0].geometry.coordinates;
      if (!c || c.length < 2) return null;
      return { lat: c[1], lon: c[0] };
    }

    function tryAttempts(i, nomEmail, nomOpts) {
      if (i >= attempts.length) {
        var lastQ = attempts.length ? (attempts[attempts.length - 1].q || '') : '';
        if (!lastQ) {
          if (hint) {
            hint.textContent = 'Sem endereço para localizar no mapa.';
            hint.style.color = 'var(--danger,#b91c1c)';
          }
          return Promise.resolve(null);
        }
        return photonQ(lastQ, nomOpts).then(function (data) {
          return hitFromPhoton(data);
        });
      }
      var a = attempts[i];
      var p = (a.type === 'structured')
        ? nominatimStructured(a.street, a.city, a.state, nomEmail, nomOpts)
        : nominatimQ(a.q || '', nomEmail, nomOpts);
      return p.then(function (hits) {
        var h = hitFromNominatim(hits);
        if (h) return h;
        return tryAttempts(i + 1, nomEmail, nomOpts);
      });
    }

    if (modo === 'streetview' && lat != null && lng != null) {
      showStreetView(lat, lng);
      return;
    }
    if (modo === 'mapa_endereco' && mapaQuery !== '') {
      showMapaEndereco(mapaQuery);
      return;
    }
    if (modo !== 'geocode' || !attempts.length) {
      clearHint();
      return;
    }

    var nomEmail = 'crm-prefeitura-nominatim%40invalid.local';
    var nomOpts = {
      method: 'GET',
      headers: { 'Accept-Language': 'pt-BR,pt;q=0.9' },
      mode: 'cors',
      credentials: 'omit'
    };

    tryAttempts(0, nomEmail, nomOpts)
      .then(function (hit) {
        if (hit) {
          showStreetView(hit.lat, hit.lon);
          return;
        }
        if (hint) {
          hint.textContent = 'Não foi possível localizar automaticamente. Use o botão Google Maps acima.';
          hint.style.color = 'var(--muted,#64748b)';
        }
      })
      .catch(function () {
        if (hint) {
          hint.textContent = 'Mapa indisponível (verifique a ligação).';
          hint.style.color = 'var(--danger,#b91c1c)';
        }
      });
  }

  global.CrmChamadoLocPreview = { init: initChamadoLocPreview };
})(typeof window !== 'undefined' ? window : this);

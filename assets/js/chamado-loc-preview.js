/**
 * Localização do chamado: Street View (iframe) e/ou mapa Leaflet (OSM).
 */
(function (global) {
  'use strict';

  function initChamadoLocPreview(opts) {
    var mapOnly = !!opts.mapOnly;
    var mapEl = document.getElementById(opts.mapId);
    var svWrap = opts.svWrapId ? document.getElementById(opts.svWrapId) : null;
    var svFrame = opts.svFrameId ? document.getElementById(opts.svFrameId) : null;
    var svTab = opts.svTabId ? document.getElementById(opts.svTabId) : null;
    var svLabel = opts.svLabelId ? document.getElementById(opts.svLabelId) : null;
    var svMapBtn = opts.svMapBtnId ? document.getElementById(opts.svMapBtnId) : null;
    var svStreetBtn = opts.svStreetBtnId ? document.getElementById(opts.svStreetBtnId) : null;
    var hideExternalTab = !!opts.hideExternalTab || mapOnly;
    var dualViewButtons = !!opts.dualViewButtons;
    var defaultView = opts.defaultView === 'leaflet' ? 'leaflet' : 'streetview';
    var hint = opts.hintId ? document.getElementById(opts.hintId) : null;
    if (!mapEl || typeof global.L === 'undefined') return;
    if (!mapOnly && (!svWrap || !svFrame)) return;

    var lat = opts.lat;
    var lng = opts.lng;
    var modo = opts.modo || 'streetview';
    var mapaQuery = (opts.mapaQuery || '').trim();
    var attempts = opts.attempts || [];
    var mapZoom = typeof opts.zoom === 'number' && opts.zoom > 0 ? opts.zoom : 15;
    var map;
    var svGeneration = 0;
    var lastCoords = { lat: null, lng: null };

    function clearHint() {
      if (hint) hint.remove();
    }

    function setViewButtons(active) {
      if (!dualViewButtons) return;
      if (svMapBtn) {
        svMapBtn.classList.toggle('btn-primary', active === 'map');
        svMapBtn.classList.toggle('btn-secondary', active !== 'map');
        svMapBtn.classList.remove('btn-ghost');
      }
      if (svStreetBtn) {
        svStreetBtn.classList.toggle('btn-primary', active === 'street');
        svStreetBtn.classList.toggle('btn-secondary', active !== 'street');
        svStreetBtn.classList.remove('btn-ghost');
        svStreetBtn.hidden = false;
      }
      if (svMapBtn) svMapBtn.hidden = false;
    }

    function showCoordsView(la, lo) {
      if (defaultView === 'leaflet') {
        showLeafletMap(la, lo);
      } else {
        showStreetView(la, lo);
      }
    }

    function showLeafletMap(la, lo) {
      svGeneration++;
      if (svWrap) svWrap.hidden = true;
      mapEl.hidden = false;
      lastCoords.lat = la;
      lastCoords.lng = lo;
      setViewButtons('map');
      if (map) {
        map.setView([la, lo], mapZoom);
        global.setTimeout(function () { map.invalidateSize(); }, 120);
        clearHint();
        return;
      }
      map = global.L.map(opts.mapId, {
        scrollWheelZoom: opts.scrollWheelZoom !== false,
        zoomControl: opts.zoomControl !== false
      });
      if (global.CrmLeafletBasemap) {
        global.CrmLeafletBasemap.addTo(map, { maxZoom: 19 });
      }
      global.L.marker([la, lo]).addTo(map);
      map.setView([la, lo], mapZoom);
      global.setTimeout(function () { map.invalidateSize(); }, 120);
      clearHint();
    }

    function showMapEmbed(la, lo) {
      if (!svWrap || !svFrame) {
        showLeafletMap(la, lo);
        return;
      }
      svGeneration++;
      lastCoords.lat = la;
      lastCoords.lng = lo;
      mapEl.hidden = true;
      svWrap.hidden = false;
      if (svLabel) svLabel.textContent = 'Mapa';
      if (svTab) {
        svTab.textContent = 'Abrir no Google Maps';
        svTab.hidden = hideExternalTab;
      }
      var ll = encodeURIComponent(String(la) + ',' + String(lo));
      svFrame.src = 'https://www.google.com/maps?q=' + ll + '&z=17&hl=pt-BR&output=embed';
      if (svTab && !hideExternalTab) {
        svTab.href = 'https://www.google.com/maps/search/?api=1&query=' + ll;
      }
      if (!dualViewButtons) {
        if (svStreetBtn) svStreetBtn.hidden = false;
        if (svMapBtn) svMapBtn.hidden = false;
      } else {
        setViewButtons('street');
      }
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
        svTab.textContent = 'Abrir no Google Maps';
        svTab.hidden = hideExternalTab;
      }
      var ll = encodeURIComponent(String(la) + ',' + String(lo));
      svFrame.src = 'https://www.google.com/maps?cbll=' + ll + '&cbp=11,0,0,0,0&layer=c&output=svembed';
      if (svTab && !hideExternalTab) {
        svTab.href = 'https://www.google.com/maps/search/?api=1&query=' + ll;
      }
      if (!dualViewButtons) {
        if (svStreetBtn) svStreetBtn.hidden = true;
        if (svMapBtn) svMapBtn.hidden = false;
      } else {
        setViewButtons('street');
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
      svFrame.src = 'https://www.google.com/maps?q=' + qEnc + '&z=17&hl=pt-BR&output=embed';
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
    if (svStreetBtn) {
      svStreetBtn.addEventListener('click', function () {
        if (lastCoords.lat != null && lastCoords.lng != null) {
          showStreetView(lastCoords.lat, lastCoords.lng);
        }
      });
    }

    var geocodeCidade = (opts.geocodeCidade || '').trim();
    var geocodeUf = String(opts.geocodeUf || '').replace(/\./g, '').toUpperCase();

    function hitMatchesContext(hit) {
      if (!hit) return false;
      if (!geocodeCidade && !geocodeUf) return true;
      var dn = String(hit.display_name || '').toLowerCase();
      if (!dn) return false;
      if (geocodeUf && dn.indexOf(geocodeUf.toLowerCase()) < 0) {
        var addr = hit.address;
        if (!addr || !addr.state) return false;
        var st = String(addr.state).toLowerCase();
        if (st.indexOf(geocodeUf.toLowerCase()) < 0) return false;
      }
      if (geocodeCidade && dn.indexOf(geocodeCidade.toLowerCase()) < 0) {
        return false;
      }
      return true;
    }

    function nominatimStructured(attempt, nomEmail, nomOpts) {
      var street = attempt.street || '';
      var city = attempt.city || '';
      var state = attempt.state || '';
      var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=5&countrycodes=br&email=' + nomEmail
        + '&street=' + encodeURIComponent(street)
        + '&city=' + encodeURIComponent(city)
        + '&state=' + encodeURIComponent(state)
        + '&country=' + encodeURIComponent('Brasil');
      if (attempt.postalcode) {
        url += '&postalcode=' + encodeURIComponent(attempt.postalcode);
      }
      if (attempt.county) {
        url += '&county=' + encodeURIComponent(attempt.county);
      }
      return fetch(url, nomOpts).then(function (r) { return r.ok ? r.json() : []; });
    }

    function nominatimQ(q, nomEmail, nomOpts) {
      var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=5&countrycodes=br&email=' + nomEmail
        + '&q=' + encodeURIComponent(q);
      return fetch(url, nomOpts).then(function (r) { return r.ok ? r.json() : []; });
    }

    function photonQ(q, nomOpts) {
      var url = 'https://photon.komoot.io/api/?limit=1&lang=pt&q=' + encodeURIComponent(q);
      return fetch(url, nomOpts).then(function (r) { return r.ok ? r.json() : null; });
    }

    function hitFromNominatim(hits) {
      if (!hits || !hits.length) return null;
      var i;
      for (i = 0; i < hits.length; i++) {
        if (!hitMatchesContext(hits[i])) continue;
        var la = parseFloat(hits[i].lat);
        var lo = parseFloat(hits[i].lon);
        if (isFinite(la) && isFinite(lo)) {
          return { lat: la, lon: lo };
        }
      }
      return null;
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
        ? nominatimStructured(a, nomEmail, nomOpts)
        : nominatimQ(a.q || '', nomEmail, nomOpts);
      return p.then(function (hits) {
        var h = hitFromNominatim(hits);
        if (h) return h;
        return tryAttempts(i + 1, nomEmail, nomOpts);
      });
    }

    if (mapOnly && lat != null && lng != null) {
      showLeafletMap(lat, lng);
      return;
    }
    if (modo === 'map_embed' && lat != null && lng != null) {
      if (mapOnly || defaultView === 'leaflet') showLeafletMap(lat, lng);
      else showMapEmbed(lat, lng);
      return;
    }
    if (modo === 'streetview' && lat != null && lng != null) {
      if (mapOnly) showLeafletMap(lat, lng);
      else showCoordsView(lat, lng);
      return;
    }
    if (modo === 'mapa_endereco' && mapaQuery !== '' && !mapOnly) {
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
          if (mapOnly) showLeafletMap(hit.lat, hit.lon);
          else showCoordsView(hit.lat, hit.lon);
          return;
        }
        if (hint) {
          hint.textContent = mapOnly
            ? 'Não foi possível localizar automaticamente o endereço no mapa.'
            : 'Não foi possível localizar automaticamente. Use o botão Google Maps acima.';
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

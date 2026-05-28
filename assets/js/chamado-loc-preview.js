/**
 * Localização do chamado: Street View (iframe) e/ou mapa Leaflet (OSM).
 */
(function (global) {
  'use strict';

  var Dual = global.CrmChamadoLocDual;
  var GMaps = global.CrmGoogleMaps;

  function initChamadoLocPreview(opts) {
    var mapEl = document.getElementById(opts.mapId);
    var mapEmbedId = (opts.mapEmbedId || opts.mapId + '-embed').trim();
    var mapEmbedEl = mapEmbedId ? document.getElementById(mapEmbedId) : null;
    var mapFallbackId = (opts.mapFallbackId || 'chamado-loc-map-fallback').trim();
    var mapFallbackEl = mapFallbackId ? document.getElementById(mapFallbackId) : null;
    var svWrap = opts.svWrapId ? document.getElementById(opts.svWrapId) : null;
    var svFrame = opts.svFrameId ? document.getElementById(opts.svFrameId) : null;
    var svTab = opts.svTabId ? document.getElementById(opts.svTabId) : null;
    var svLabel = opts.svLabelId ? document.getElementById(opts.svLabelId) : null;
    var svMapBtn = opts.svMapBtnId ? document.getElementById(opts.svMapBtnId) : null;
    var svStreetBtn = opts.svStreetBtnId ? document.getElementById(opts.svStreetBtnId) : null;
    var mapRefreshBtnId = (opts.mapRefreshBtnId || 'chamado-loc-map-refresh-btn').trim();
    var mapRefreshBtn = mapRefreshBtnId ? document.getElementById(mapRefreshBtnId) : null;
    var hideExternalTab = !!opts.hideExternalTab;
    var dualViewButtons = !!opts.dualViewButtons;
    var hint = opts.hintId ? document.getElementById(opts.hintId) : null;
    var geocodeApiUrl = (opts.geocodeApiUrl || '').trim();
    var svExternalId = (opts.svExternalId || 'chamado-loc-sv-external').trim();
    var svFallbackId = (opts.svFallbackId || 'chamado-loc-sv-fallback').trim();
    var previewContainerId = (opts.previewContainerId || '').trim();
    var svExternalEl = svExternalId ? document.getElementById(svExternalId) : null;
    var svFallbackEl = svFallbackId ? document.getElementById(svFallbackId) : null;
    var previewRoot = previewContainerId
      ? document.getElementById(previewContainerId)
      : mapEl && mapEl.closest ? mapEl.closest('[id]') || mapEl.parentElement : null;
    if (!mapEl && !mapEmbedEl) return;
    if (!svWrap || !svFrame) return;

    function useGoogleEmbed() {
      if (GMaps && GMaps.hasApiKey) return GMaps.hasApiKey(previewRoot || svFrame);
      return getGoogleMapsApiKey() !== '';
    }

    function getGoogleMapsApiKey() {
      if (Dual && Dual.resolveGoogleMapsApiKey) {
        return Dual.resolveGoogleMapsApiKey(previewRoot || svFrame);
      }
      return previewRoot ? (previewRoot.getAttribute('data-google-maps-key') || '').trim() : '';
    }

    function setSvIframeSrc(iframe, url) {
      if (Dual && Dual.defaultSetSvIframeSrc) {
        Dual.defaultSetSvIframeSrc(iframe, url);
        return;
      }
      if (!iframe || !url) return;
      if (iframe.getAttribute('src') === url) {
        iframe.removeAttribute('src');
        global.setTimeout(function () {
          iframe.src = url;
        }, 0);
        return;
      }
      iframe.src = url;
    }

    function loadStreetViewIframe(la, lo) {
      if (Dual && Dual.loadSvIframe) {
        Dual.loadSvIframe(svFrame, la, lo, {
          apiKey: getGoogleMapsApiKey(),
          setSvIframeSrc: setSvIframeSrc,
          fallbackEl: svFallbackEl
        });
        return;
      }
      var svUrl =
        'https://www.google.com/maps?cbll=' +
        encodeURIComponent(String(la) + ',' + String(lo)) +
        '&cbp=11,0,0,0,0&layer=c&output=svembed&hl=pt-BR';
      svFrame.setAttribute('data-sv-embed-src', svUrl);
      svFrame.hidden = false;
      setSvIframeSrc(svFrame, svUrl);
    }

    function invalidatePreviewMap() {
      if (!map) return;
      map.invalidateSize();
      global.setTimeout(function () {
        map.invalidateSize();
      }, 80);
    }

    function scheduleStreetViewLoad(la, lo) {
      var frameWrap = svWrap ? svWrap.querySelector('.chamado-ponto-streetview__frame-wrap') : null;
      if (Dual && Dual.scheduleSvIframeAfterLayout) {
        Dual.scheduleSvIframeAfterLayout({
          lat: la,
          lng: lo,
          frameWrapEl: frameWrap,
          mapEl: useGoogleEmbed() ? null : mapEl,
          mapIframe: mapEmbedEl,
          hideMapAfterWarm: true,
          ensureLeaflet: function (plat, plng) {
            if (useGoogleEmbed()) return;
            if (map) {
              map.setView([plat, plng], mapZoom);
              return;
            }
            if (typeof global.L === 'undefined') return;
            map = global.L.map(opts.mapId, {
              scrollWheelZoom: opts.scrollWheelZoom !== false,
              zoomControl: opts.zoomControl !== false
            });
            if (global.CrmLeafletBasemap) {
              global.CrmLeafletBasemap.addTo(map, { maxZoom: 19 });
            }
            global.L.marker([plat, plng]).addTo(map);
            map.setView([plat, plng], mapZoom);
          },
          invalidateMap: invalidatePreviewMap,
          loadIframe: function (plat, plng) {
            loadStreetViewIframe(plat, plng);
          }
        });
        return;
      }
      if (map) {
        map.setView([la, lo], mapZoom);
      }
      mapEl.hidden = false;
      invalidatePreviewMap();
      void mapEl.offsetHeight;
      mapEl.hidden = true;
      global.requestAnimationFrame(function () {
        global.requestAnimationFrame(function () {
          loadStreetViewIframe(la, lo);
        });
      });
    }

    var lat = opts.lat;
    var lng = opts.lng;
    var modo = opts.modo || 'streetview';
    var mapaQuery = (opts.mapaQuery || '').trim();
    var attempts = opts.attempts || [];
    var mapZoom = typeof opts.zoom === 'number' && opts.zoom > 0 ? opts.zoom : 15;
    var map;
    var svCheckGen = 0;
    var embedLayoutGen = 0;
    var userViewChoice = null;
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

    function resolveStreetViewAvailable(la, lo) {
      if (Dual) return Dual.checkStreetView(geocodeApiUrl, la, lo);
      if (!geocodeApiUrl) return Promise.resolve(false);
      return fetch(
        geocodeApiUrl +
          '?action=streetview_check&lat=' +
          encodeURIComponent(String(la)) +
          '&lon=' +
          encodeURIComponent(String(lo)),
        {
          method: 'GET',
          credentials: 'same-origin',
          headers: { Accept: 'application/json' }
        }
      )
        .then(function (r) {
          return r.ok ? r.json() : { ok: false };
        })
        .then(function (res) {
          return !!(res && res.ok && res.available);
        })
        .catch(function () {
          return false;
        });
    }

    function locPreloadBothViews(la, lo) {
      var ensureLeaflet = function () {
        if (useGoogleEmbed()) return;
        if (map) {
          map.setView([la, lo], mapZoom);
        }
      };
      if (Dual) {
        Dual.preloadBothViews(la, lo, {
          ensureLeaflet: ensureLeaflet,
          svFrame: svFrame,
          mapIframe: mapEmbedEl,
          skipSvIframe: true,
          apiKey: getGoogleMapsApiKey(),
          externalLinkEl: svExternalEl
        });
        return;
      }
      ensureLeaflet();
      if (svExternalEl && Dual && Dual.updateStreetViewExternalLink) {
        Dual.updateStreetViewExternalLink(svExternalEl, la, lo);
      }
    }

    function loadGoogleMapEmbedNow(la, lo) {
      var frameWrap = svWrap ? svWrap.querySelector('.chamado-ponto-streetview__frame-wrap') : null;
      if (!mapEmbedEl) return;
      mapEmbedEl.removeAttribute('data-map-needs-reload');
      mapEmbedEl.setAttribute('data-embed-lat', String(la));
      mapEmbedEl.setAttribute('data-embed-lng', String(lo));
      mapEmbedEl.hidden = false;
      if (mapFallbackEl) mapFallbackEl.hidden = true;
      if (Dual && Dual.loadMapEmbedIframe) {
        Dual.loadMapEmbedIframe(mapEmbedEl, la, lo, {
          apiKey: getGoogleMapsApiKey(),
          zoom: mapZoom,
          frameWrapEl: frameWrap,
          fallbackEl: mapFallbackEl
        });
      } else if (GMaps && GMaps.showMapEmbed) {
        GMaps.showMapEmbed(mapEmbedEl, la, lo, {
          apiKey: getGoogleMapsApiKey(),
          zoom: mapZoom,
          frameWrapEl: frameWrap,
          fallbackEl: mapFallbackEl
        });
      }
    }

    function showGoogleMapEmbed(la, lo) {
      lastCoords.lat = la;
      lastCoords.lng = lo;
      if (Dual && Dual.cancelSvEmbedWatch) Dual.cancelSvEmbedWatch(svFrame);
      if (svFrame) {
        svFrame.hidden = true;
        svFrame.removeAttribute('src');
      }
      if (svFallbackEl && Dual && Dual.hideSvEmbedFallback) Dual.hideSvEmbedFallback(svFallbackEl);
      if (mapEl) mapEl.hidden = true;
      if (svWrap) svWrap.hidden = false;
      setViewButtons('map');
      var frameWrap = svWrap ? svWrap.querySelector('.chamado-ponto-streetview__frame-wrap') : null;
      if (!mapEmbedEl || !useGoogleEmbed()) {
        clearHint();
        return;
      }
      embedLayoutGen++;
      var gen = embedLayoutGen;
      if (Dual && Dual.scheduleMapEmbedAfterLayout) {
        Dual.scheduleMapEmbedAfterLayout({
          lat: la,
          lng: lo,
          frameWrapEl: frameWrap,
          mapIframe: mapEmbedEl,
          loadEmbed: function (plat, plng) {
            if (userViewChoice === 'street' || gen !== embedLayoutGen) return;
            loadGoogleMapEmbedNow(plat, plng);
          }
        });
        clearHint();
        return;
      }
      if (frameWrap) {
        frameWrap.hidden = false;
        void frameWrap.offsetHeight;
      }
      global.requestAnimationFrame(function () {
        global.requestAnimationFrame(function () {
          if (userViewChoice === 'street' || gen !== embedLayoutGen) return;
          loadGoogleMapEmbedNow(la, lo);
        });
      });
      clearHint();
    }

    function showLeafletMap(la, lo, forceLeaflet) {
      if (!forceLeaflet && useGoogleEmbed()) {
        showGoogleMapEmbed(la, lo);
        return;
      }
      if (typeof global.L === 'undefined') return;
      locPreloadBothViews(la, lo);
      if (Dual && Dual.cancelSvEmbedWatch) Dual.cancelSvEmbedWatch(svFrame);
      if (svFrame) {
        svFrame.hidden = true;
        svFrame.removeAttribute('src');
      }
      if (mapEmbedEl) {
        if (GMaps && GMaps.cancelEmbedWatch) GMaps.cancelEmbedWatch(mapEmbedEl);
        mapEmbedEl.hidden = true;
        mapEmbedEl.removeAttribute('src');
      }
      if (svFallbackEl && Dual && Dual.hideSvEmbedFallback) Dual.hideSvEmbedFallback(svFallbackEl);
      if (svWrap) svWrap.hidden = true;
      if (mapEl) mapEl.hidden = false;
      lastCoords.lat = la;
      lastCoords.lng = lo;
      setViewButtons('map');
      if (map) {
        map.setView([la, lo], mapZoom);
        global.setTimeout(function () {
          map.invalidateSize();
        }, 120);
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
      global.setTimeout(function () {
        map.invalidateSize();
      }, 120);
      clearHint();
    }

    function showStreetView(la, lo) {
      lastCoords.lat = la;
      lastCoords.lng = lo;
      if (mapEl) mapEl.hidden = true;
      if (mapEmbedEl) {
        if (GMaps && GMaps.cancelEmbedWatch) GMaps.cancelEmbedWatch(mapEmbedEl);
        mapEmbedEl.hidden = true;
        mapEmbedEl.removeAttribute('src');
      }
      svWrap.hidden = false;
      if (svFallbackEl && Dual && Dual.hideSvEmbedFallback) Dual.hideSvEmbedFallback(svFallbackEl);
      if (svLabel) svLabel.textContent = 'Street View';
      if (svTab) {
        svTab.textContent = 'Abrir no Google Maps';
        svTab.hidden = hideExternalTab;
      }
      scheduleStreetViewLoad(la, lo);
      if (svExternalEl && Dual && Dual.updateStreetViewExternalLink) {
        Dual.updateStreetViewExternalLink(svExternalEl, la, lo);
      }
      if (svTab && !hideExternalTab && Dual && Dual.streetViewExternalUrl) {
        svTab.href = Dual.streetViewExternalUrl(la, lo);
      }
      setViewButtons('street');
      clearHint();
    }

    function applyCoordsPreview(la, lo) {
      locPreloadBothViews(la, lo);
      if (userViewChoice === 'map') {
        showLeafletMap(la, lo);
        return;
      }
      if (userViewChoice === 'street') {
        resolveStreetViewAvailable(la, lo).then(function (available) {
          if (available) showStreetView(la, lo);
          else showLeafletMap(la, lo);
        });
        return;
      }
      svCheckGen++;
      var gen = svCheckGen;
      resolveStreetViewAvailable(la, lo).then(function (available) {
        if (gen !== svCheckGen) return;
        if (available) showStreetView(la, lo);
        else showLeafletMap(la, lo);
      });
    }

    if (svMapBtn) {
      svMapBtn.addEventListener('click', function () {
        userViewChoice = 'map';
        if (lastCoords.lat != null && lastCoords.lng != null) {
          showLeafletMap(lastCoords.lat, lastCoords.lng);
        }
      });
    }
    if (svStreetBtn) {
      svStreetBtn.addEventListener('click', function () {
        userViewChoice = 'street';
        if (lastCoords.lat != null && lastCoords.lng != null) {
          resolveStreetViewAvailable(lastCoords.lat, lastCoords.lng).then(function (available) {
            if (available) showStreetView(lastCoords.lat, lastCoords.lng);
            else showLeafletMap(lastCoords.lat, lastCoords.lng);
          });
        }
      });
    }

    function forceRefreshMapEmbed() {
      var la = lat;
      var lo = lng;
      if (lastCoords.lat != null && lastCoords.lng != null) {
        la = lastCoords.lat;
        lo = lastCoords.lng;
      }
      if (la == null || lo == null || !isFinite(la) || !isFinite(lo)) {
        if (hint) {
          hint.textContent = 'Informe latitude e longitude para atualizar o mapa.';
          hint.style.color = 'var(--muted,#64748b)';
        }
        return;
      }
      clearHint();
      userViewChoice = 'map';
      if (mapEmbedEl) {
        if (GMaps && GMaps.cancelEmbedWatch) GMaps.cancelEmbedWatch(mapEmbedEl);
        mapEmbedEl.removeAttribute('src');
        mapEmbedEl.removeAttribute('data-embed-lat');
        mapEmbedEl.removeAttribute('data-embed-lng');
        mapEmbedEl.setAttribute('data-map-needs-reload', '1');
      }
      if (Dual && Dual.cancelSvEmbedWatch) Dual.cancelSvEmbedWatch(svFrame);
      if (svFrame) {
        svFrame.hidden = true;
        svFrame.removeAttribute('src');
      }
      showGoogleMapEmbed(la, lo);
    }

    if (mapRefreshBtn) {
      mapRefreshBtn.hidden = !useGoogleEmbed();
      mapRefreshBtn.addEventListener('click', function () {
        forceRefreshMapEmbed();
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
      return fetch(url, nomOpts).then(function (r) {
        return r.ok ? r.json() : [];
      });
    }

    function nominatimQ(q, nomEmail, nomOpts) {
      var url = 'https://nominatim.openstreetmap.org/search?format=json&limit=5&countrycodes=br&email=' + nomEmail
        + '&q=' + encodeURIComponent(q);
      return fetch(url, nomOpts).then(function (r) {
        return r.ok ? r.json() : [];
      });
    }

    function photonQ(q, nomOpts) {
      var url = 'https://photon.komoot.io/api/?limit=1&lang=pt&q=' + encodeURIComponent(q);
      return fetch(url, nomOpts).then(function (r) {
        return r.ok ? r.json() : null;
      });
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
      var p =
        a.type === 'structured'
          ? nominatimStructured(a, nomEmail, nomOpts)
          : nominatimQ(a.q || '', nomEmail, nomOpts);
      return p.then(function (hits) {
        var h = hitFromNominatim(hits);
        if (h) return h;
        return tryAttempts(i + 1, nomEmail, nomOpts);
      });
    }

    function geocodeQuery(q, nomEmail, nomOpts) {
      if (!q) return Promise.resolve(null);
      return nominatimQ(q, nomEmail, nomOpts).then(function (hits) {
        var h = hitFromNominatim(hits);
        if (h) return h;
        return photonQ(q, nomOpts).then(function (data) {
          return hitFromPhoton(data);
        });
      });
    }

    if (modo === 'map_embed' && lat != null && lng != null) {
      applyCoordsPreview(lat, lng);
      return;
    }
    if (modo === 'streetview' && lat != null && lng != null) {
      applyCoordsPreview(lat, lng);
      return;
    }
    if (modo === 'mapa_endereco' && mapaQuery !== '') {
      var nomEmailMapa = 'crm-prefeitura-nominatim%40invalid.local';
      var nomOptsMapa = {
        method: 'GET',
        headers: { 'Accept-Language': 'pt-BR,pt;q=0.9' },
        mode: 'cors',
        credentials: 'omit'
      };
      geocodeQuery(mapaQuery, nomEmailMapa, nomOptsMapa)
        .then(function (hit) {
          if (hit) {
            applyCoordsPreview(hit.lat, hit.lon);
            return;
          }
          if (hint) {
            hint.textContent = 'Não foi possível localizar automaticamente o endereço no mapa.';
            hint.style.color = 'var(--muted,#64748b)';
          }
        })
        .catch(function () {
          if (hint) {
            hint.textContent = 'Mapa indisponível (verifique a ligação).';
            hint.style.color = 'var(--danger,#b91c1c)';
          }
        });
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
          applyCoordsPreview(hit.lat, hit.lon);
          return;
        }
        if (hint) {
          hint.textContent = 'Não foi possível localizar automaticamente o endereço no mapa.';
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

/**
 * Mapa / Street View dedicado à visualização do chamado (coluna OS no detalhe).
 * Isolado de chamado-loc-preview.js (coluna direita) e do script inline do grid OS.
 */
(function (global) {
  'use strict';

  var Dual = global.CrmChamadoLocDual;
  var GMaps = global.CrmGoogleMaps;

  function parseCoord(raw) {
    if (raw === null || raw === undefined) return null;
    var s = String(raw).trim().replace(',', '.');
    if (s === '') return null;
    var n = parseFloat(s);
    return isFinite(n) ? n : null;
  }

  function prefixFromRootId(rootId) {
    var id = (rootId || 'chamado-viz-loc').trim();
    if (id.slice(-4) === '-loc') return id.slice(0, -4);
    return id;
  }

  function elId(prefix, suffix) {
    return prefix + '-' + suffix;
  }

  function initChamadoVisualizacaoMapa(opts) {
    opts = opts || {};
    var rootId = (opts.rootId || 'chamado-viz-loc').trim();
    var root = document.getElementById(rootId);
    if (!root) return null;

    var prefix = prefixFromRootId(rootId);
    var mapBtn = document.getElementById(elId(prefix, 'map-btn'));
    var svBtn = document.getElementById(elId(prefix, 'sv-btn'));
    var refreshBtn = document.getElementById(elId(prefix, 'map-refresh-btn'));
    var mapEmbed = document.getElementById(elId(prefix, 'map-embed'));
    var mapMini = document.getElementById(elId(prefix, 'map-mini'));
    var svFrame = document.getElementById(elId(prefix, 'sv-frame'));
    var svBlock = document.getElementById(elId(prefix, 'streetview-block'));
    var svFallback = document.getElementById(elId(prefix, 'sv-fallback'));
    var mapFallback = document.getElementById(elId(prefix, 'map-fallback'));
    var hintNoCoord = document.getElementById(elId(prefix, 'sem-coord'));
    var hintGeo = document.getElementById(elId(prefix, 'geocode-hint'));
    var svExternal = document.getElementById(elId(prefix, 'sv-external'));

    var latInputId = (opts.latInputId || root.getAttribute('data-lat-input-id') || 'chamado_latitude').trim();
    var lngInputId = (opts.lngInputId || root.getAttribute('data-lng-input-id') || 'chamado_longitude').trim();
    var geocodeApiUrl = (opts.geocodeApiUrl || root.getAttribute('data-geocode-api') || '').trim();

    var mapLayoutGen = 0;
    var svLayoutGen = 0;
    var userChoice = null;
    var previewView = 'map';
    var leafletMap = null;
    var leafletMarker = null;
    var bootDone = false;

    function getApiKey() {
      if (Dual && Dual.resolveGoogleMapsApiKey) {
        return Dual.resolveGoogleMapsApiKey(root);
      }
      return (root.getAttribute('data-google-maps-key') || global.CRM_GOOGLE_MAPS_API_KEY || '').trim();
    }

    function useGoogleEmbed() {
      if (GMaps && GMaps.hasApiKey) return GMaps.hasApiKey(root);
      return getApiKey() !== '';
    }

    function getDefaultView() {
      var v = (root.getAttribute('data-preview-default-view') || opts.defaultView || 'map').trim();
      return v === 'street' ? 'street' : 'map';
    }

    function getCoords() {
      var laEl = document.getElementById(latInputId);
      var loEl = document.getElementById(lngInputId);
      var la = laEl ? parseCoord(laEl.value) : null;
      var lo = loEl ? parseCoord(loEl.value) : null;
      if (la !== null && lo !== null) {
        return { lat: la, lng: lo };
      }
      return {
        lat: parseCoord(root.getAttribute('data-lat')),
        lng: parseCoord(root.getAttribute('data-lng'))
      };
    }

    function frameWrap() {
      return svBlock ? svBlock.querySelector('.chamado-ponto-streetview__frame-wrap') : null;
    }

    function setViewButtons(active) {
      if (mapBtn) {
        mapBtn.classList.toggle('btn-primary', active === 'map');
        mapBtn.classList.toggle('btn-secondary', active !== 'map');
        mapBtn.classList.remove('btn-ghost');
      }
      if (svBtn) {
        svBtn.classList.toggle('btn-primary', active === 'street');
        svBtn.classList.toggle('btn-secondary', active !== 'street');
        svBtn.classList.remove('btn-ghost');
      }
    }

    function syncVisibility(hasCoords) {
      if (hintNoCoord) hintNoCoord.hidden = hasCoords;
      if (svBlock) svBlock.hidden = !hasCoords;
    }

    function loadMapEmbedNow(lat, lng) {
      if (!mapEmbed || lat === null || lng === null) return;
      mapEmbed.removeAttribute('data-map-needs-reload');
      mapEmbed.setAttribute('data-embed-lat', String(lat));
      mapEmbed.setAttribute('data-embed-lng', String(lng));
      mapEmbed.hidden = false;
      if (mapFallback) mapFallback.hidden = true;
      var wrap = frameWrap();
      if (Dual && Dual.loadMapEmbedIframe) {
        Dual.loadMapEmbedIframe(mapEmbed, lat, lng, {
          apiKey: getApiKey(),
          zoom: 16,
          frameWrapEl: wrap,
          fallbackEl: mapFallback
        });
      } else if (GMaps && GMaps.showMapEmbed) {
        GMaps.showMapEmbed(mapEmbed, lat, lng, {
          apiKey: getApiKey(),
          zoom: 16,
          frameWrapEl: wrap,
          fallbackEl: mapFallback
        });
      }
    }

    function scheduleMapLoad(lat, lng, immediate) {
      if (lat === null || lng === null) return;
      if (userChoice === 'street') return;
      mapLayoutGen++;
      var gen = mapLayoutGen;
      var wrap = frameWrap();
      if (svBlock) svBlock.hidden = false;
      if (wrap) wrap.hidden = false;

      function runLoad() {
        if (gen !== mapLayoutGen || userChoice === 'street') return;
        loadMapEmbedNow(lat, lng);
      }

      if (Dual && Dual.scheduleMapEmbedAfterLayout) {
        Dual.scheduleMapEmbedAfterLayout({
          lat: lat,
          lng: lng,
          frameWrapEl: wrap,
          mapIframe: mapEmbed,
          loadEmbed: function (plat, plng) {
            runLoad();
          }
        });
        return;
      }

      if (wrap) {
        wrap.hidden = false;
        void wrap.offsetHeight;
      }
      if (immediate) {
        runLoad();
        return;
      }
      global.requestAnimationFrame(function () {
        global.requestAnimationFrame(runLoad);
      });
    }

    function loadSvNow(lat, lng) {
      if (!svFrame || lat === null || lng === null) return;
      if (Dual && Dual.loadSvIframe) {
        Dual.loadSvIframe(svFrame, lat, lng, {
          apiKey: getApiKey(),
          fallbackEl: svFallback,
          frameWrapEl: frameWrap()
        });
      }
    }

    function scheduleSvLoad(lat, lng) {
      if (lat === null || lng === null) return;
      svLayoutGen++;
      var gen = svLayoutGen;
      if (Dual && Dual.scheduleSvIframeAfterLayout) {
        Dual.scheduleSvIframeAfterLayout({
          lat: lat,
          lng: lng,
          frameWrapEl: frameWrap(),
          mapIframe: mapEmbed,
          hideMapAfterWarm: true,
          loadIframe: function (plat, plng) {
            if (gen !== svLayoutGen || previewView === 'map') return;
            loadSvNow(plat, plng);
          }
        });
        return;
      }
      loadSvNow(lat, lng);
    }

    function showMap(lat, lng) {
      if (lat === null || lng === null) return;
      userChoice = 'map';
      previewView = 'map';
      if (mapMini) mapMini.hidden = true;
      if (svFrame && Dual && Dual.cancelSvEmbedWatch) {
        Dual.cancelSvEmbedWatch(svFrame);
        svFrame.hidden = true;
        svFrame.removeAttribute('src');
      }
      if (svFallback && Dual && Dual.hideSvEmbedFallback) {
        Dual.hideSvEmbedFallback(svFallback);
      }
      setViewButtons('map');
      scheduleMapLoad(lat, lng, false);
    }

    function showStreet(lat, lng) {
      if (lat === null || lng === null) return;
      userChoice = 'street';
      previewView = 'street';
      if (mapEmbed) {
        if (GMaps && GMaps.cancelEmbedWatch) GMaps.cancelEmbedWatch(mapEmbed);
        mapEmbed.hidden = true;
        mapEmbed.removeAttribute('src');
      }
      if (mapMini) mapMini.hidden = true;
      if (svFallback && Dual && Dual.hideSvEmbedFallback) {
        Dual.hideSvEmbedFallback(svFallback);
      }
      setViewButtons('street');
      scheduleSvLoad(lat, lng);
      if (svExternal && Dual && Dual.updateStreetViewExternalLink) {
        Dual.updateStreetViewExternalLink(svExternal, lat, lng);
      }
    }

    function applyInitialView(lat, lng) {
      syncVisibility(true);
      root.setAttribute('data-lat', String(lat));
      root.setAttribute('data-lng', String(lng));
      if (Dual && Dual.preloadBothViews) {
        Dual.preloadBothViews(lat, lng, {
          svFrame: svFrame,
          mapIframe: mapEmbed,
          skipSvIframe: true,
          apiKey: getApiKey(),
          externalLinkEl: svExternal
        });
      }
      if (userChoice === 'street') {
        showStreet(lat, lng);
        return;
      }
      if (userChoice === 'map' || getDefaultView() === 'map') {
        showMap(lat, lng);
        return;
      }
      if (Dual && Dual.checkStreetView) {
        Dual.checkStreetView(geocodeApiUrl, lat, lng).then(function (available) {
          if (available) showStreet(lat, lng);
          else showMap(lat, lng);
        });
        return;
      }
      showMap(lat, lng);
    }

    function forceRefresh() {
      var c = getCoords();
      if (c.lat === null || c.lng === null) {
        if (hintGeo) {
          hintGeo.hidden = false;
          hintGeo.textContent = 'Informe latitude e longitude para atualizar o mapa.';
        }
        return;
      }
      if (hintGeo) hintGeo.hidden = true;
      if (mapEmbed) {
        if (GMaps && GMaps.cancelEmbedWatch) GMaps.cancelEmbedWatch(mapEmbed);
        mapEmbed.removeAttribute('src');
        mapEmbed.setAttribute('data-map-needs-reload', '1');
      }
      applyInitialView(c.lat, c.lng);
    }

    function resolveGeocodeThen(run) {
      var geo = opts.geocode || null;
      if (!geo || !geo.modo || geo.modo === 'none') return false;
      var Core = global.CrmDashboardMapGeocodeCore;
      if (!Core || !Core.resolvePinCoords || !geocodeApiUrl) return false;
      if (hintGeo) {
        hintGeo.hidden = false;
        hintGeo.textContent = 'A localizar endereço no mapa…';
      }
      var pin = { geocode_attempts: Array.isArray(geo.attempts) ? geo.attempts : [] };
      if (geo.modo === 'mapa_endereco' && geo.mapaQuery) {
        pin = { geocode_attempts: [{ q: String(geo.mapaQuery) }] };
      }
      Core.resolvePinCoords(geocodeApiUrl, pin).then(function (hit) {
        if (hit && hit.rateLimited) {
          if (hintGeo) {
            hintGeo.textContent = 'Aguarde um momento e clique em Atualizar mapa.';
          }
          return;
        }
        if (hit && hit.lat != null && hit.lon != null) {
          if (hintGeo) hintGeo.hidden = true;
          run(parseFloat(hit.lat), parseFloat(hit.lon));
          return;
        }
        if (hintGeo) {
          hintGeo.hidden = false;
          hintGeo.textContent = 'Não foi possível localizar automaticamente o endereço no mapa.';
        }
        syncVisibility(false);
      }).catch(function () {
        if (hintGeo) {
          hintGeo.hidden = false;
          hintGeo.textContent = 'Mapa indisponível (verifique a ligação).';
        }
      });
      return true;
    }

    function boot(force) {
      if (bootDone && !force) return;
      var c = getCoords();
      if (c.lat === null || c.lng === null) {
        if (resolveGeocodeThen(function (la, lo) {
          bootDone = true;
          applyInitialView(la, lo);
        })) {
          return;
        }
        syncVisibility(false);
        return;
      }
      bootDone = true;
      applyInitialView(c.lat, c.lng);
    }

    function scheduleBoot(force) {
      global.requestAnimationFrame(function () {
        global.requestAnimationFrame(function () {
          global.setTimeout(function () {
            boot(!!force);
          }, 120);
        });
      });
    }

    if (mapBtn) {
      mapBtn.addEventListener('click', function () {
        var c = getCoords();
        if (c.lat !== null && c.lng !== null) showMap(c.lat, c.lng);
      });
    }
    if (svBtn) {
      svBtn.addEventListener('click', function () {
        var c = getCoords();
        if (c.lat === null || c.lng === null) return;
        if (Dual && Dual.checkStreetView) {
          Dual.checkStreetView(geocodeApiUrl, c.lat, c.lng).then(function (available) {
            if (available) showStreet(c.lat, c.lng);
            else showMap(c.lat, c.lng);
          });
          return;
        }
        showStreet(c.lat, c.lng);
      });
    }
    if (refreshBtn) {
      refreshBtn.addEventListener('click', forceRefresh);
    }

    [latInputId, lngInputId].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('change', function () {
        bootDone = false;
        scheduleBoot(true);
      });
      el.addEventListener('input', function () {
        bootDone = false;
      });
    });

    scheduleBoot(true);

    return { refresh: forceRefresh, getCoords: getCoords };
  }

  global.CrmChamadoVizMapa = { init: initChamadoVisualizacaoMapa };
})(typeof window !== 'undefined' ? window : this);

/**
 * Núcleo compartilhado: pré-carregar Leaflet + Street View e observer de campos de localização.
 */
(function (global) {
  'use strict';

  var GMaps = global.CrmGoogleMaps;

  function parseCoord(raw) {
    if (raw === null || raw === undefined) return null;
    var s = String(raw).trim().replace(',', '.');
    if (s === '') return null;
    var n = parseFloat(s);
    return isFinite(n) ? n : null;
  }

  function resolveGoogleMapsApiKey(fromEl) {
    if (GMaps && GMaps.getApiKey) return GMaps.getApiKey(fromEl);
    var el = fromEl;
    while (el) {
      var k = (el.getAttribute && el.getAttribute('data-google-maps-key')) || '';
      k = k.trim();
      if (k) return k;
      el = el.parentElement;
    }
    return (global.CRM_GOOGLE_MAPS_API_KEY || '').trim();
  }

  function streetViewEmbedUrl(lat, lng, opts) {
    if (GMaps && GMaps.streetViewEmbedUrl) {
      return GMaps.streetViewEmbedUrl(lat, lng, opts);
    }
    opts = opts || {};
    var apiKey = (opts.apiKey || '').trim();
    var loc = encodeURIComponent(String(lat) + ',' + String(lng));
    if (apiKey) {
      return (
        'https://www.google.com/maps/embed/v1/streetview?key=' +
        encodeURIComponent(apiKey) +
        '&location=' +
        loc +
        '&heading=0&pitch=0&fov=80'
      );
    }
    return (
      'https://www.google.com/maps?cbll=' +
      loc +
      '&cbp=11,0,0,0,0&layer=c&output=svembed&hl=pt-BR'
    );
  }

  function mapViewEmbedUrl(lat, lng, opts) {
    if (GMaps && GMaps.mapViewEmbedUrl) return GMaps.mapViewEmbedUrl(lat, lng, opts);
    return '';
  }

  function mapPlaceEmbedUrl(lat, lng, opts) {
    if (GMaps && GMaps.mapPlaceEmbedUrl) return GMaps.mapPlaceEmbedUrl(lat, lng, opts);
    return mapViewEmbedUrl(lat, lng, opts);
  }

  function streetViewExternalUrl(lat, lng) {
    if (GMaps && GMaps.streetViewExternalUrl) return GMaps.streetViewExternalUrl(lat, lng);
    var ll = encodeURIComponent(String(lat) + ',' + String(lng));
    return 'https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=' + ll;
  }

  function isLocalhostHost() {
    var h = (global.location && global.location.hostname) || '';
    return h === 'localhost' || h === '127.0.0.1';
  }

  function updateStreetViewExternalLink(linkEl, lat, lng) {
    if (!linkEl || lat == null || lng == null || !isFinite(lat) || !isFinite(lng)) return;
    linkEl.href = streetViewExternalUrl(lat, lng);
    linkEl.hidden = false;
    var note = isLocalhostHost()
      ? ' O preview embutido pode não carregar em localhost; use o link abaixo.'
      : '';
    linkEl.textContent = 'Abrir Street View no Google Maps' + note;
  }

  function hideSvEmbedFallback(fallbackEl) {
    if (!fallbackEl) return;
    fallbackEl.hidden = true;
  }

  function showSvEmbedFallback(fallbackEl, lat, lng) {
    if (!fallbackEl) return;
    fallbackEl.hidden = false;
    var btn = fallbackEl.querySelector('.chamado-sv-embed-fallback__btn');
    if (btn && lat != null && lng != null && isFinite(lat) && isFinite(lng)) {
      btn.href = streetViewExternalUrl(lat, lng);
    }
  }

  function cancelSvEmbedWatch(iframe) {
    if (GMaps && GMaps.cancelEmbedWatch) {
      GMaps.cancelEmbedWatch(iframe);
      return;
    }
    if (!iframe) return;
    if (iframe._svWatchTimer) {
      clearTimeout(iframe._svWatchTimer);
      iframe._svWatchTimer = null;
    }
    iframe._svWatchId = (iframe._svWatchId || 0) + 1;
  }

  function watchSvEmbedFailure(iframe, opts) {
    if (GMaps && GMaps.loadEmbedIframe) {
      return;
    }
    cancelSvEmbedWatch(iframe);
    if (!iframe || !opts.fallbackEl) return;
    var watchId = (iframe._svWatchId = (iframe._svWatchId || 0) + 1);
    var timeoutMs =
      typeof opts.timeoutMs === 'number'
        ? opts.timeoutMs
        : opts.apiKey
          ? 8000
          : 12000;

    function fail() {
      if (iframe._svWatchId !== watchId) return;
      iframe.hidden = true;
      showSvEmbedFallback(opts.fallbackEl, opts.lat, opts.lng);
    }

    iframe.addEventListener(
      'load',
      function () {
        if (iframe._svWatchId !== watchId) return;
        cancelSvEmbedWatch(iframe);
        hideSvEmbedFallback(opts.fallbackEl);
        iframe.hidden = false;
      },
      { once: true }
    );

    iframe._svWatchTimer = global.setTimeout(fail, timeoutMs);
  }

  function defaultSetSvIframeSrc(iframe, url) {
    if (GMaps && GMaps.defaultSetIframeSrc) {
      GMaps.defaultSetIframeSrc(iframe, url);
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

  function loadSvIframe(iframe, lat, lng, opts) {
    if (!iframe || lat == null || lng == null || !isFinite(lat) || !isFinite(lng)) return;
    opts = opts || {};
    if (GMaps && GMaps.showStreetViewEmbed) {
      GMaps.showStreetViewEmbed(iframe, lat, lng, {
        apiKey: (opts.apiKey || '').trim() || resolveGoogleMapsApiKey(iframe),
        fallbackEl: opts.fallbackEl,
        setIframeSrc: opts.setSvIframeSrc || defaultSetSvIframeSrc,
        timeoutMs: opts.timeoutMs,
        frameWrapEl: opts.frameWrapEl,
        onFail: function () {
          showSvEmbedFallback(opts.fallbackEl, lat, lng);
        }
      });
      return;
    }
    var apiKey = (opts.apiKey || '').trim() || resolveGoogleMapsApiKey(iframe);
    var url = streetViewEmbedUrl(lat, lng, { apiKey: apiKey });
    var fallbackEl = opts.fallbackEl || null;
    var setSrc = opts.setSvIframeSrc || defaultSetSvIframeSrc;
    var wasHidden = iframe.hidden;
    var prevUrl = iframe.getAttribute('data-sv-embed-src');
    var urlChanged = !!(prevUrl && prevUrl !== url);
    var needsLayoutReload = wasHidden || !iframe.offsetParent || urlChanged;

    iframe.setAttribute('data-sv-embed-src', url);
    if (fallbackEl) hideSvEmbedFallback(fallbackEl);
    iframe.hidden = false;

    function applySrc() {
      if (needsLayoutReload || iframe.getAttribute('src') === url) {
        defaultSetSvIframeSrc(iframe, url);
      } else {
        setSrc(iframe, url);
      }
    }

    if (needsLayoutReload) {
      global.requestAnimationFrame(function () {
        global.requestAnimationFrame(applySrc);
      });
    } else {
      applySrc();
    }

    if (fallbackEl) {
      watchSvEmbedFailure(iframe, {
        fallbackEl: fallbackEl,
        lat: lat,
        lng: lng,
        apiKey: apiKey,
        timeoutMs: opts.timeoutMs
      });
    }
  }

  function loadMapEmbedIframe(iframe, lat, lng, opts) {
    if (!iframe || lat == null || lng == null || !isFinite(lat) || !isFinite(lng)) return false;
    opts = opts || {};
    if (opts.watchFailure === undefined) {
      opts.watchFailure = false;
    }
    if (GMaps && GMaps.showMapEmbed) {
      return GMaps.showMapEmbed(iframe, lat, lng, opts);
    }
    return false;
  }

  /**
   * Aquece layout do frame-wrap antes de atribuir src a um iframe embed (mapa ou SV).
   */
  function scheduleEmbedAfterLayout(opts) {
    opts = opts || {};
    var lat = opts.lat;
    var lng = opts.lng;
    if (lat == null || lng == null || !isFinite(lat) || !isFinite(lng)) return;

    var frameWrap = opts.frameWrapEl;
    var mapEl = opts.mapEl;
    var mapIframe = opts.mapIframe;
    if (frameWrap) {
      frameWrap.hidden = false;
      void frameWrap.offsetHeight;
    }
    if (typeof opts.ensureLeaflet === 'function') {
      opts.ensureLeaflet(lat, lng);
    }
    if (mapIframe && GMaps && GMaps.hasApiKey(mapIframe)) {
      if (opts.hideMapAfterWarm !== false) {
        mapIframe.hidden = true;
      } else {
        mapIframe.hidden = false;
        void mapIframe.offsetHeight;
      }
    } else if (mapEl) {
      mapEl.hidden = false;
      if (typeof opts.invalidateMap === 'function') {
        opts.invalidateMap();
      }
      void mapEl.offsetHeight;
      if (opts.hideMapAfterWarm !== false) {
        mapEl.hidden = true;
      }
    }

    global.requestAnimationFrame(function () {
      global.requestAnimationFrame(function () {
        if (typeof opts.loadEmbed === 'function') {
          opts.loadEmbed(lat, lng);
        } else if (typeof opts.loadIframe === 'function') {
          opts.loadIframe(lat, lng);
        }
      });
    });
  }

  /** Wrapper SV: esconde mini-mapa após aquecer o frame-wrap. */
  function scheduleSvIframeAfterLayout(opts) {
    opts = opts || {};
    if (opts.hideMapAfterWarm === undefined) {
      opts.hideMapAfterWarm = true;
    }
    scheduleEmbedAfterLayout(opts);
  }

  /** Wrapper mapa Google: mantém iframe do mapa visível durante o aquecimento de layout. */
  function scheduleMapEmbedAfterLayout(opts) {
    opts = opts || {};
    opts.hideMapAfterWarm = false;
    scheduleEmbedAfterLayout(opts);
  }

  function checkStreetView(geocodeApiUrl, lat, lng) {
    var url = (geocodeApiUrl || '').trim();
    if (!url) return Promise.resolve(false);
    return fetch(
      url +
        '?action=streetview_check&lat=' +
        encodeURIComponent(String(lat)) +
        '&lon=' +
        encodeURIComponent(String(lng)),
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

  /**
   * Pré-carrega Leaflet; iframe SV só se skipSvIframe for false (padrão: não carregar src).
   */
  function preloadBothViews(lat, lng, opts) {
    if (lat == null || lng == null || !isFinite(lat) || !isFinite(lng)) return;
    opts = opts || {};
    var useGoogleMap = GMaps && GMaps.hasApiKey(opts.mapIframe || opts.svFrame);
    if (!useGoogleMap && typeof opts.ensureLeaflet === 'function') {
      opts.ensureLeaflet(lat, lng);
    }
    var iframe = opts.svFrame;
    if (!iframe && opts.svFrameId) {
      iframe = document.getElementById(opts.svFrameId);
    }
    var apiKey = (opts.apiKey || '').trim();
    if (!apiKey && iframe) {
      apiKey = resolveGoogleMapsApiKey(iframe);
    }
    if (iframe) {
      var svUrl = streetViewEmbedUrl(lat, lng, { apiKey: apiKey });
      iframe.setAttribute('data-sv-embed-src', svUrl);
      var skipSv = opts.skipSvIframe !== false;
      if (!skipSv) {
        loadSvIframe(iframe, lat, lng, {
          apiKey: apiKey,
          setSvIframeSrc: opts.setSvIframeSrc || defaultSetSvIframeSrc,
          fallbackEl: opts.fallbackEl,
          timeoutMs: opts.timeoutMs
        });
      }
    }
    if (opts.externalLinkEl) {
      updateStreetViewExternalLink(opts.externalLinkEl, lat, lng);
    }
  }

  function applyDualPreview(lat, lng, opts) {
    opts = opts || {};
    preloadBothViews(lat, lng, opts);
    var userChoice = opts.userChoice;
    var geocodeApiUrl = (opts.geocodeApiUrl || '').trim();
    var showMap = opts.showMap;
    var showStreet = opts.showStreet;
    if (typeof showMap !== 'function' || typeof showStreet !== 'function') return Promise.resolve();

    if (userChoice === 'map') {
      showMap(lat, lng);
      return Promise.resolve();
    }
    if (userChoice === 'street') {
      return checkStreetView(geocodeApiUrl, lat, lng).then(function (available) {
        if (available) showStreet(lat, lng);
        else showMap(lat, lng);
      });
    }

    var gen = opts.checkGen;
    if (typeof gen === 'object' && gen !== null && 'value' in gen) {
      gen.value++;
    }
    var myGen = typeof gen === 'object' && gen !== null ? gen.value : null;

    return checkStreetView(geocodeApiUrl, lat, lng).then(function (available) {
      if (myGen != null && gen && gen.value !== myGen) return;
      if (available) showStreet(lat, lng);
      else showMap(lat, lng);
    });
  }

  function wireLocationFieldObserver(config) {
    config = config || {};
    var debounceMs = typeof config.debounceMs === 'number' ? config.debounceMs : 400;
    var timer = null;
    var fieldIds = config.fieldIds || [
      'os_cep',
      'os_logradouro',
      'os_numero',
      'os_complemento',
      'os_bairro',
      'os_cidade',
      'os_uf',
      'chamado_latitude',
      'chamado_longitude'
    ];
    var pontoSelectId = config.pontoSelectId || 'ponto_iluminacao_id';
    var onTrigger = config.onTrigger;
    var shouldTrigger = config.shouldTrigger;
    var onBeforeTrigger = config.onBeforeTrigger;

    function schedule() {
      clearTimeout(timer);
      timer = global.setTimeout(function () {
        timer = null;
        if (typeof shouldTrigger === 'function' && !shouldTrigger()) return;
        if (typeof onBeforeTrigger === 'function') onBeforeTrigger();
        if (typeof onTrigger === 'function') onTrigger();
      }, debounceMs);
    }

    fieldIds.forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('input', schedule);
      el.addEventListener('change', schedule);
    });

    var pontoSel = document.getElementById(pontoSelectId);
    if (pontoSel) {
      pontoSel.addEventListener('change', schedule);
      if (typeof MutationObserver !== 'undefined') {
        var mo = new MutationObserver(schedule);
        mo.observe(pontoSel, { childList: true, subtree: true });
      }
    }

    document.addEventListener('crm:os-address-changed', schedule);

    return { schedule: schedule, cancel: function () { clearTimeout(timer); } };
  }

  global.CrmChamadoLocDual = {
    parseCoord: parseCoord,
    resolveGoogleMapsApiKey: resolveGoogleMapsApiKey,
    streetViewEmbedUrl: streetViewEmbedUrl,
    mapViewEmbedUrl: mapViewEmbedUrl,
    mapPlaceEmbedUrl: mapPlaceEmbedUrl,
    streetViewExternalUrl: streetViewExternalUrl,
    updateStreetViewExternalLink: updateStreetViewExternalLink,
    hideSvEmbedFallback: hideSvEmbedFallback,
    showSvEmbedFallback: showSvEmbedFallback,
    cancelSvEmbedWatch: cancelSvEmbedWatch,
    watchSvEmbedFailure: watchSvEmbedFailure,
    loadSvIframe: loadSvIframe,
    loadMapEmbedIframe: loadMapEmbedIframe,
    scheduleEmbedAfterLayout: scheduleEmbedAfterLayout,
    scheduleSvIframeAfterLayout: scheduleSvIframeAfterLayout,
    scheduleMapEmbedAfterLayout: scheduleMapEmbedAfterLayout,
    defaultSetSvIframeSrc: defaultSetSvIframeSrc,
    checkStreetView: checkStreetView,
    preloadBothViews: preloadBothViews,
    applyDualPreview: applyDualPreview,
    wireLocationFieldObserver: wireLocationFieldObserver
  };
})(typeof window !== 'undefined' ? window : this);

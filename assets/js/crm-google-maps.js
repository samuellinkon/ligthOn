/**
 * Google Maps Embed API (view + streetview) — chave em window.CRM_GOOGLE_MAPS_API_KEY.
 */
(function (global) {
  'use strict';

  function getApiKey(fromEl) {
    var k = (global.CRM_GOOGLE_MAPS_API_KEY || '').trim();
    if (k) return k;
    var el = fromEl;
    while (el) {
      var attr = (el.getAttribute && el.getAttribute('data-google-maps-key')) || '';
      attr = attr.trim();
      if (attr) return attr;
      el = el.parentElement;
    }
    return '';
  }

  function hasApiKey(fromEl) {
    return getApiKey(fromEl) !== '';
  }

  function formatLoc(lat, lng) {
    return encodeURIComponent(String(lat) + ',' + String(lng));
  }

  function streetViewEmbedUrl(lat, lng, opts) {
    opts = opts || {};
    var apiKey = (opts.apiKey || '').trim() || getApiKey(opts.fromEl);
    var loc = formatLoc(lat, lng);
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
    opts = opts || {};
    var apiKey = (opts.apiKey || '').trim() || getApiKey(opts.fromEl);
    if (!apiKey) return '';
    var zoom = typeof opts.zoom === 'number' && opts.zoom > 0 ? opts.zoom : 16;
    if (zoom < 1) zoom = 1;
    if (zoom > 21) zoom = 21;
    var loc = formatLoc(lat, lng);
    return (
      'https://www.google.com/maps/embed/v1/view?key=' +
      encodeURIComponent(apiKey) +
      '&center=' +
      loc +
      '&zoom=' +
      zoom
    );
  }

  /** Embed com marcador no ponto (modo place). */
  function mapPlaceEmbedUrl(lat, lng, opts) {
    opts = opts || {};
    var apiKey = (opts.apiKey || '').trim() || getApiKey(opts.fromEl);
    if (!apiKey) return '';
    var zoom = typeof opts.zoom === 'number' && opts.zoom > 0 ? opts.zoom : 16;
    if (zoom < 1) zoom = 1;
    if (zoom > 21) zoom = 21;
    var loc = formatLoc(lat, lng);
    return (
      'https://www.google.com/maps/embed/v1/place?key=' +
      encodeURIComponent(apiKey) +
      '&q=' +
      loc +
      '&zoom=' +
      zoom
    );
  }

  function streetViewExternalUrl(lat, lng) {
    var ll = encodeURIComponent(String(lat) + ',' + String(lng));
    return 'https://www.google.com/maps/@?api=1&map_action=pano&viewpoint=' + ll;
  }

  function mapExternalUrl(lat, lng) {
    var ll = encodeURIComponent(String(lat) + ',' + String(lng));
    return 'https://www.google.com/maps/search/?api=1&query=' + ll;
  }

  function cancelEmbedWatch(iframe) {
    if (!iframe) return;
    if (iframe._crmEmbedWatchTimer) {
      clearTimeout(iframe._crmEmbedWatchTimer);
      iframe._crmEmbedWatchTimer = null;
    }
    iframe._crmEmbedWatchId = (iframe._crmEmbedWatchId || 0) + 1;
  }

  function defaultSetIframeSrc(iframe, url) {
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

  function watchEmbedFailure(iframe, opts) {
    cancelEmbedWatch(iframe);
    if (!iframe || !opts.fallbackEl) return;
    var watchId = (iframe._crmEmbedWatchId = (iframe._crmEmbedWatchId || 0) + 1);
    var timeoutMs =
      typeof opts.timeoutMs === 'number'
        ? opts.timeoutMs
        : opts.apiKey
          ? 8000
          : 12000;

    function fail() {
      if (iframe._crmEmbedWatchId !== watchId) return;
      iframe.hidden = true;
      if (opts.onFail) opts.onFail();
    }

    iframe.addEventListener(
      'load',
      function () {
        if (iframe._crmEmbedWatchId !== watchId) return;
        cancelEmbedWatch(iframe);
        if (opts.fallbackEl) opts.fallbackEl.hidden = true;
        iframe.hidden = false;
      },
      { once: true }
    );

    iframe._crmEmbedWatchTimer = global.setTimeout(fail, timeoutMs);
  }

  function loadEmbedIframe(iframe, url, opts) {
    if (!iframe || !url) return;
    opts = opts || {};
    var wasHidden = iframe.hidden;
    var prevUrl = iframe.getAttribute('data-embed-src');
    var urlChanged = !!(prevUrl && prevUrl !== url);
    var needsLayoutReload = wasHidden || !iframe.offsetParent || urlChanged;
    var setSrc = opts.setIframeSrc || defaultSetIframeSrc;
    var fallbackEl = opts.fallbackEl || null;

    iframe.setAttribute('data-embed-src', url);
    if (fallbackEl) fallbackEl.hidden = true;
    iframe.hidden = false;

    function applySrc() {
      if (needsLayoutReload || iframe.getAttribute('src') === url) {
        defaultSetIframeSrc(iframe, url);
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

    if (fallbackEl && opts.watchFailure !== false) {
      watchEmbedFailure(iframe, {
        fallbackEl: fallbackEl,
        apiKey: opts.apiKey,
        timeoutMs: opts.timeoutMs,
        onFail: function () {
          if (opts.onFail) opts.onFail();
          else if (fallbackEl) fallbackEl.hidden = false;
        }
      });
    }
  }

  function hideSiblingEmbeds(activeIframe, frameWrap) {
    if (!frameWrap) return;
    var list = frameWrap.querySelectorAll('iframe.chamado-map-embed-frame, iframe.chamado-ponto-streetview__frame');
    list.forEach(function (node) {
      if (node !== activeIframe) {
        cancelEmbedWatch(node);
        node.hidden = true;
        node.removeAttribute('src');
      }
    });
    var leafletEl = frameWrap.querySelector('.chamado-map-mini--in-frame, .chamado-map-mini');
    if (leafletEl && leafletEl.tagName !== 'IFRAME') {
      leafletEl.hidden = true;
    }
  }

  function showMapEmbed(mapIframe, lat, lng, opts) {
    if (!mapIframe || lat == null || lng == null || !isFinite(lat) || !isFinite(lng)) return false;
    opts = opts || {};
    var url =
      opts.embedMode === 'view'
        ? mapViewEmbedUrl(lat, lng, {
            apiKey: opts.apiKey,
            zoom: opts.zoom,
            fromEl: mapIframe
          })
        : mapPlaceEmbedUrl(lat, lng, {
            apiKey: opts.apiKey,
            zoom: opts.zoom,
            fromEl: mapIframe
          });
    if (!url) return false;
    var frameWrap =
      opts.frameWrapEl ||
      (mapIframe.closest && mapIframe.closest('.chamado-ponto-streetview__frame-wrap'));
    hideSiblingEmbeds(mapIframe, frameWrap);
    mapIframe.style.zIndex = '2';
    var fallbackEl = opts.fallbackEl || null;
    if (fallbackEl) {
      var mapBtn = fallbackEl.querySelector('.chamado-sv-embed-fallback__btn');
      if (mapBtn) mapBtn.href = mapExternalUrl(lat, lng);
      fallbackEl.hidden = true;
    }
    loadEmbedIframe(mapIframe, url, {
      apiKey: opts.apiKey,
      fallbackEl: fallbackEl,
      timeoutMs: opts.timeoutMs || 10000,
      watchFailure: opts.watchFailure === true,
      onFail: function () {
        mapIframe.hidden = true;
        if (fallbackEl) fallbackEl.hidden = false;
        if (opts.onFail) opts.onFail();
      }
    });
    return true;
  }

  function showStreetViewEmbed(svIframe, lat, lng, opts) {
    if (!svIframe || lat == null || lng == null || !isFinite(lat) || !isFinite(lng)) return;
    opts = opts || {};
    var apiKey = (opts.apiKey || '').trim() || getApiKey(svIframe);
    var url = streetViewEmbedUrl(lat, lng, { apiKey: apiKey, fromEl: svIframe });
    var frameWrap =
      opts.frameWrapEl ||
      (svIframe.closest && svIframe.closest('.chamado-ponto-streetview__frame-wrap'));
    hideSiblingEmbeds(svIframe, frameWrap);
    loadEmbedIframe(svIframe, url, {
      apiKey: apiKey,
      fallbackEl: opts.fallbackEl,
      setIframeSrc: opts.setIframeSrc,
      timeoutMs: opts.timeoutMs,
      onFail: opts.onFail
    });
    svIframe.setAttribute('data-sv-embed-src', url);
  }

  global.CrmGoogleMaps = {
    getApiKey: getApiKey,
    hasApiKey: hasApiKey,
    streetViewEmbedUrl: streetViewEmbedUrl,
    mapViewEmbedUrl: mapViewEmbedUrl,
    mapPlaceEmbedUrl: mapPlaceEmbedUrl,
    streetViewExternalUrl: streetViewExternalUrl,
    mapExternalUrl: mapExternalUrl,
    cancelEmbedWatch: cancelEmbedWatch,
    defaultSetIframeSrc: defaultSetIframeSrc,
    loadEmbedIframe: loadEmbedIframe,
    showMapEmbed: showMapEmbed,
    showStreetViewEmbed: showStreetViewEmbed,
    hideSiblingEmbeds: hideSiblingEmbeds
  };
})(typeof window !== 'undefined' ? window : this);

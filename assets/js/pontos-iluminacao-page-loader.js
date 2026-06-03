/**
 * Overlay de carregamento inicial — pontos de iluminação (todas as roles).
 */
(function (global) {
  'use strict';

  var MIN_VISIBLE_MS = 380;
  var FALLBACK_MS = 28000;
  var hidden = false;
  var mapReady = false;
  var domReady = false;
  var minUntil = Date.now() + MIN_VISIBLE_MS;

  function loaderEl() {
    return document.getElementById('pontos-page-loader');
  }

  function needsMapWait() {
    if (!document.getElementById('pontos-iluminacao-map')) {
      return false;
    }
    if (global.CRM_PONTOS_MAPA_VIEWPORT === false) {
      return false;
    }
    if (global.CRM_PONTOS_MAPA_CONFIG) {
      return true;
    }
    if (Array.isArray(global.PONTOS_ILUMINACAO_MAP) && global.PONTOS_ILUMINACAO_MAP.length > 0) {
      return true;
    }
    return global.CRM_PONTOS_MAP_PROVIDER === 'google' || global.CRM_PONTOS_MAPA_VIEWPORT === true;
  }

  function doHide() {
    var el = loaderEl();
    if (!el || hidden) {
      return;
    }
    hidden = true;
    el.classList.remove('is-visible');
    el.setAttribute('aria-hidden', 'true');
    el.setAttribute('aria-busy', 'false');
    document.body.classList.remove('pontos-page-loading-active');
    global.setTimeout(function () {
      el.hidden = true;
    }, 220);
  }

  function tryHide() {
    if (hidden) {
      return;
    }
    if (!domReady) {
      return;
    }
    if (needsMapWait() && !mapReady) {
      return;
    }
    var wait = Math.max(0, minUntil - Date.now());
    global.setTimeout(doHide, wait);
  }

  function markDomReady() {
    domReady = true;
    tryHide();
  }

  global.CrmPontosPageLoader = {
    notifyMapReady: function () {
      mapReady = true;
      tryHide();
    },
    notifyMapError: function () {
      mapReady = true;
      tryHide();
    },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', markDomReady);
  } else {
    markDomReady();
  }

  global.setTimeout(function () {
    mapReady = true;
    tryHide();
  }, FALLBACK_MS);
})(typeof window !== 'undefined' ? window : this);

/**
 * Basemap Leaflet padrão do CRM — CARTO Positron (sem ícones de lojas/POIs do OSM Carto).
 */
(function (global) {
  'use strict';

  var DEFAULTS = {
    url: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
    options: {
      maxZoom: 20,
      subdomains: 'abcd',
      attribution:
        '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
    },
  };

  function addTo(map, options) {
    var L = global.L;
    if (!map || typeof L === 'undefined' || typeof L.tileLayer !== 'function') {
      return null;
    }
    var opts = Object.assign({}, DEFAULTS.options, options || {});
    return L.tileLayer(DEFAULTS.url, opts).addTo(map);
  }

  global.CrmLeafletBasemap = {
    url: DEFAULTS.url,
    defaultOptions: DEFAULTS.options,
    addTo: addTo,
  };
})(typeof window !== 'undefined' ? window : this);

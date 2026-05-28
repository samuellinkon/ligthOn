/**
 * Carregamento e utilitários da Google Maps JavaScript API (dashboard).
 */
(function (global) {
  'use strict';

  var DEFAULT_CENTER = { lat: -14.235, lng: -51.9253 };
  var DEFAULT_ZOOM = 4;

  function mapsReady() {
    return !!(global.google && global.google.maps && global.google.maps.Map);
  }

  function resolveMapId(opts) {
    opts = opts || {};
    var id = (opts.mapId || '').trim();
    if (id) return id;
    return String(global.CRM_GOOGLE_MAPS_MAP_ID || '').trim();
  }

  function hasAdvancedMarkers() {
    if (!resolveMapId()) return false;
    return !!(
      global.google &&
      global.google.maps &&
      global.google.maps.marker &&
      global.google.maps.marker.AdvancedMarkerElement
    );
  }

  function isAdvancedMarker(marker) {
    if (!marker) return false;
    var AdvancedMarkerElement =
      global.google &&
      global.google.maps &&
      global.google.maps.marker &&
      global.google.maps.marker.AdvancedMarkerElement;
    return !!(AdvancedMarkerElement && marker instanceof AdvancedMarkerElement);
  }

  /**
   * Resolve quando google.maps.Map estiver disponível (callback da API ou já carregada).
   */
  function load() {
    if (mapsReady()) {
      return Promise.resolve(global.google.maps);
    }
    return new Promise(function (resolve, reject) {
      var waited = 0;
      var step = 50;
      var max = 30000;
      var t = setInterval(function () {
        waited += step;
        if (mapsReady()) {
          clearInterval(t);
          resolve(global.google.maps);
          return;
        }
        if (waited >= max) {
          clearInterval(t);
          reject(new Error('Google Maps API não carregou a tempo.'));
        }
      }, step);
    });
  }

  function createMap(el, opts) {
    opts = opts || {};
    if (!el || !mapsReady()) return null;
    var mapOpts = {
      center: opts.center || DEFAULT_CENTER,
      zoom: typeof opts.zoom === 'number' ? opts.zoom : DEFAULT_ZOOM,
      gestureHandling: opts.gestureHandling || 'cooperative',
      mapTypeControl: opts.mapTypeControl !== false,
      streetViewControl: opts.streetViewControl !== false,
      fullscreenControl: opts.fullscreenControl !== false,
    };
    var mapId = resolveMapId(opts);
    if (mapId) {
      mapOpts.mapId = mapId;
    }
    var map = new global.google.maps.Map(el, mapOpts);
    el._crmGoogleMap = map;
    return map;
  }

  function latLng(pos) {
    return new global.google.maps.LatLng(pos.lat, pos.lng);
  }

  /**
   * @param {google.maps.Map} map
   * @param {Array<{lat:number,lng:number}>} positions
   * @param {{padding?:number,maxZoom?:number}} opts
   */
  function fitToPositions(map, positions, opts) {
    opts = opts || {};
    if (!map || !positions || !positions.length) return;
    var padding = typeof opts.padding === 'number' ? opts.padding : 28;
    var maxZoom = typeof opts.maxZoom === 'number' ? opts.maxZoom : 15;

    if (positions.length === 1) {
      map.setCenter(latLng(positions[0]));
      map.setZoom(opts.singleZoom || 14);
      return;
    }

    var bounds = new global.google.maps.LatLngBounds();
    positions.forEach(function (p) {
      if (p && p.lat != null && p.lng != null) {
        bounds.extend(latLng(p));
      }
    });
    map.fitBounds(bounds, padding);
    global.google.maps.event.addListenerOnce(map, 'idle', function () {
      if (map.getZoom() > maxZoom) {
        map.setZoom(maxZoom);
      }
    });
  }

  function triggerResize(map) {
    if (!map || !global.google || !global.google.maps) return;
    try {
      global.google.maps.event.trigger(map, 'resize');
    } catch (e) {}
  }

  function getMarkerClustererClass() {
    if (global.markerClusterer && global.markerClusterer.MarkerClusterer) {
      return global.markerClusterer.MarkerClusterer;
    }
    if (global.MarkerClusterer) {
      return global.MarkerClusterer;
    }
    return null;
  }

  function setMarkerMap(marker, map) {
    if (!marker) return;
    if (isAdvancedMarker(marker)) {
      marker.map = map || null;
      return;
    }
    if (typeof marker.setMap === 'function') {
      marker.setMap(map || null);
    }
  }

  global.CrmGoogleMapsJs = {
    load: load,
    createMap: createMap,
    fitToPositions: fitToPositions,
    triggerResize: triggerResize,
    latLng: latLng,
    getMarkerClustererClass: getMarkerClustererClass,
    mapsReady: mapsReady,
    resolveMapId: resolveMapId,
    hasAdvancedMarkers: hasAdvancedMarkers,
    isAdvancedMarker: isAdvancedMarker,
    setMarkerMap: setMarkerMap,
    DEFAULT_CENTER: DEFAULT_CENTER,
    DEFAULT_ZOOM: DEFAULT_ZOOM,
  };
})(typeof window !== 'undefined' ? window : this);

/**
 * Geocode Nominatim + persistência de coords (sem Leaflet).
 */
(function (global) {
  'use strict';

  var GEOCODE_DELAY_MS = 1100;

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

  function geocodePendingPins(pins, geocodeApi, persistApi, onReady, onDone) {
    var pending = (pins || []).filter(function (p) {
      return (
        p.pin_state === 'pending_geocode' ||
        (p.lat == null && Array.isArray(p.geocode_attempts) && p.geocode_attempts.length)
      );
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
            .then(function () {
              return delay(GEOCODE_DELAY_MS);
            })
            .then(next);
        }
        failed++;
        return delay(GEOCODE_DELAY_MS).then(next);
      });
    }

    next();
  }

  function runPendingOnMap(pins, geocodeApi, persistApi, onPinReady, onFinish) {
    var pending = pins.filter(function (p) {
      return (
        p.pin_state === 'pending_geocode' ||
        (p.lat == null && Array.isArray(p.geocode_attempts) && p.geocode_attempts.length)
      );
    });
    if (pending.length === 0 || !geocodeApi) {
      if (onFinish) onFinish(0);
      return;
    }
    var idx = 0;
    var failed = 0;

    function nextPending() {
      if (idx >= pending.length) {
        if (onFinish) onFinish(failed);
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
          pin.pin_state = 'ready';
          if (onPinReady) onPinReady(pin);
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
  }

  global.CrmDashboardMapGeocodeCore = {
    GEOCODE_DELAY_MS: GEOCODE_DELAY_MS,
    resolvePinCoords: resolvePinCoords,
    persistCoords: persistCoords,
    geocodePendingPins: geocodePendingPins,
    runPendingOnMap: runPendingOnMap,
    delay: delay,
  };
})(typeof window !== 'undefined' ? window : this);

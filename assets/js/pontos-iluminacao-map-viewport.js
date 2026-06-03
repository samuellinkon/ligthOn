/**
 * Carregamento de pontos de iluminação por viewport (API bounding box).
 */
(function (global) {
  'use strict';

  var CACHE_TTL_MS = 300000;
  var SESSION_CACHE_MAX_BYTES = 450000;
  var SESSION_STORAGE_PREFIX = 'crm_pontos_mapa:';
  var DEBOUNCE_MS = 400;
  var FIT_BOUNDS_MAX = 80;
  var GEO_PINPOINT_ZOOM = 19;
  var GEO_PINPOINT_RAIO_M = 15;

  function shouldRenderPointMarkers(mode) {
    return mode === 'points' || mode === 'pinpoint';
  }

  function boundsFromPinpoint(pin) {
    var d = 0.00025;
    return {
      sw_lat: pin.lat - d,
      sw_lng: pin.lng - d,
      ne_lat: pin.lat + d,
      ne_lng: pin.lng + d,
    };
  }

  function debounce(fn, wait) {
    var t = null;
    function debounced() {
      clearTimeout(t);
      var args = arguments;
      var ctx = this;
      t = setTimeout(function () {
        t = null;
        fn.apply(ctx, args);
      }, wait);
    }
    debounced.cancel = function () {
      clearTimeout(t);
      t = null;
    };
    return debounced;
  }

  function parseCoord(raw) {
    var s = String(raw || '').trim().replace(',', '.');
    if (s === '') return null;
    var n = parseFloat(s);
    return isFinite(n) ? n : null;
  }

  function resolveGeoFields(latRaw, lngRaw) {
    var lat = parseCoord(latRaw);
    var lng = parseCoord(lngRaw);
    var hasLat = lat !== null;
    var hasLng = lng !== null;

    if (hasLat && hasLng) {
      if (Math.abs(lat) > 90 || Math.abs(lng) > 180) {
        return Promise.reject(new Error('Latitude ou longitude fora do intervalo válido.'));
      }
      return Promise.resolve({ lat: lat, lng: lng });
    }

    if (hasLat || hasLng) {
      return Promise.reject(new Error('Informe latitude e longitude juntas.'));
    }

    return Promise.reject(new Error('Informe latitude e longitude.'));
  }

  function syncForcePointsToggle(toggle, active) {
    if (!toggle) return;
    toggle.setAttribute('aria-pressed', active ? 'true' : 'false');
    toggle.textContent = active ? 'Ver regiões agregadas' : 'Ver postes individuais';
    toggle.classList.toggle('btn-primary', !!active);
    toggle.classList.toggle('btn-secondary', !active);
  }

  function attachGeoFilter(options) {
    options = options || {};
    var latInput = document.getElementById('map-filter-lat');
    var lngInput = document.getElementById('map-filter-lng');
    var geoBtn = document.getElementById('map-filter-geo-go');
    var map = options.map;
    var provider = options.provider || 'google';
    var statusCtrl = options.statusCtrl;
    var setPinpoint = options.setPinpoint;
    var clearPinpoint = options.clearPinpoint;
    var onGo = options.onGo;
    var onPanStart = options.onPanStart;
    if (!latInput || !lngInput || !geoBtn || !map) return;

    function isGeoEmpty() {
      return !String(latInput.value || '').trim() && !String(lngInput.value || '').trim();
    }

    function panTo(lat, lng) {
      if (typeof onPanStart === 'function') onPanStart();
      if (provider === 'leaflet') {
        map.setView([lat, lng], GEO_PINPOINT_ZOOM);
      } else {
        map.setCenter({ lat: lat, lng: lng });
        map.setZoom(GEO_PINPOINT_ZOOM);
      }
    }

    function applyGeo() {
      if (isGeoEmpty()) {
        if (typeof clearPinpoint === 'function') clearPinpoint();
        if (typeof onGo === 'function') onGo();
        return;
      }

      geoBtn.disabled = true;
      if (statusCtrl && typeof statusCtrl.beginLoad === 'function') {
        statusCtrl.beginLoad();
      }
      resolveGeoFields(latInput.value, lngInput.value)
        .then(function (pos) {
          if (typeof setPinpoint === 'function') {
            setPinpoint({ lat: pos.lat, lng: pos.lng, raio_m: GEO_PINPOINT_RAIO_M });
          }
          panTo(pos.lat, pos.lng);
          if (typeof onGo === 'function') onGo();
        })
        .catch(function (err) {
          if (statusCtrl && typeof statusCtrl.setError === 'function') {
            statusCtrl.setError(err && err.message ? err.message : 'Erro ao localizar.');
          }
        })
        .finally(function () {
          geoBtn.disabled = false;
        });
    }

    geoBtn.addEventListener('click', applyGeo);
    [latInput, lngInput].forEach(function (input) {
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          applyGeo();
        }
      });
    });
  }

  function debugLog(config, msg, data) {
    if (!config || !config.debug) return;
    if (global.console && typeof global.console.debug === 'function') {
      global.console.debug('[CrmPontosMapViewport] ' + msg, data || '');
    }
  }

  function roundBounds(bounds, zoom) {
    var z = typeof zoom === 'number' ? zoom : 12;
    var prec = z <= 11 ? 2 : z <= 14 ? 3 : 4;
    var f = Math.pow(10, prec);
    return [
      Math.round(bounds.sw_lat * f) / f,
      Math.round(bounds.sw_lng * f) / f,
      Math.round(bounds.ne_lat * f) / f,
      Math.round(bounds.ne_lng * f) / f,
    ].join(':');
  }

  /** Mesmo arredondamento do cache/servidor — evita 2 URLs para a mesma área. */
  function normalizeBoundsPayload(swLat, swLng, neLat, neLng, zoom) {
    var parts = roundBounds(
      { sw_lat: swLat, sw_lng: swLng, ne_lat: neLat, ne_lng: neLng },
      zoom
    ).split(':');
    return {
      sw_lat: parseFloat(parts[0]),
      sw_lng: parseFloat(parts[1]),
      ne_lat: parseFloat(parts[2]),
      ne_lng: parseFloat(parts[3]),
    };
  }

  function buildCacheKey(bounds, zoom, filters) {
    return String(zoom) + ':' + roundBounds(bounds, zoom) + ':' + JSON.stringify(filters || {});
  }

  function mapCacheGen(config) {
    var cfg = config || global.CRM_PONTOS_MAPA_CONFIG || {};
    return cfg.cache_gen != null ? String(cfg.cache_gen) : '0';
  }

  function sessionStorageKey(escopoId, cacheGen, cacheKey) {
    return SESSION_STORAGE_PREFIX + escopoId + ':' + cacheGen + ':' + cacheKey;
  }

  function readSessionCache(escopoId, cacheGen, cacheKey) {
    try {
      var raw = sessionStorage.getItem(sessionStorageKey(escopoId, cacheGen, cacheKey));
      if (!raw) return null;
      var parsed = JSON.parse(raw);
      if (!parsed || !parsed.data || Date.now() - (parsed.ts || 0) >= CACHE_TTL_MS) return null;
      return parsed;
    } catch (e) {
      return null;
    }
  }

  function writeSessionCache(escopoId, cacheGen, cacheKey, entry) {
    try {
      var serialized = JSON.stringify(entry);
      if (serialized.length > SESSION_CACHE_MAX_BYTES) return;
      sessionStorage.setItem(sessionStorageKey(escopoId, cacheGen, cacheKey), serialized);
    } catch (e) {}
  }

  function getMemoryCacheEntry(state, cacheKey) {
    var entry = state.cache[cacheKey];
    if (!entry || Date.now() - entry.ts >= CACHE_TTL_MS) return null;
    return entry;
  }

  function setMemoryCacheEntry(state, cacheKey, data, etag) {
    state.cache[cacheKey] = { ts: Date.now(), data: data, etag: etag || '' };
  }

  function syncCacheGenFromResponse(config, data) {
    if (!config || !data || data.cache_gen == null) return;
    config.cache_gen = data.cache_gen;
  }

  function fetchMapData(url, cacheKey, state, config) {
    if (!state.inFlight) state.inFlight = {};
    if (state.inFlight[cacheKey]) {
      return state.inFlight[cacheKey];
    }

    var mem = getMemoryCacheEntry(state, cacheKey);
    var fetchOpts = {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    };
    if (mem && mem.etag) {
      fetchOpts.headers['If-None-Match'] = mem.etag;
    }
    if (state.abortController) {
      fetchOpts.signal = state.abortController.signal;
    }

    var promise = fetch(url, fetchOpts)
      .then(function (res) {
        if (res.status === 304 && mem) {
          return mem.data;
        }
        return res.json().then(function (data) {
          if (!res.ok || !data) {
            throw new Error((data && data.err) || 'Falha ao carregar pontos.');
          }
          var etag = res.headers.get('ETag') || '';
          setMemoryCacheEntry(state, cacheKey, data, etag);
          syncCacheGenFromResponse(config, data);
          var escopoId = (config && config.escopo_id) || 0;
          var gen = data.cache_gen != null ? String(data.cache_gen) : mapCacheGen(config);
          writeSessionCache(escopoId, gen, cacheKey, { ts: Date.now(), data: data, etag: etag });
          return data;
        });
      })
      .finally(function () {
        delete state.inFlight[cacheKey];
      });

    state.inFlight[cacheKey] = promise;
    return promise;
  }

  function resolveCachedMapData(state, cacheKey, config, force) {
    if (force) return null;
    var mem = getMemoryCacheEntry(state, cacheKey);
    if (mem) return mem.data;
    var escopoId = (config && config.escopo_id) || 0;
    var sess = readSessionCache(escopoId, mapCacheGen(config), cacheKey);
    if (!sess) return null;
    setMemoryCacheEntry(state, cacheKey, sess.data, sess.etag || '');
    return sess.data;
  }

  function getFiltersFromDom(areaFilter, searchFilter, baseFilters, geoPinpoint, forcePoints) {
    baseFilters = baseFilters || {};
    var filters = {
      status: baseFilters.status || '',
      somente_chamados_abertos: !!baseFilters.somente_chamados_abertos,
      bairro: areaFilter && areaFilter.value ? String(areaFilter.value) : '',
      busca: searchFilter && searchFilter.value ? String(searchFilter.value).trim() : '',
    };
    if (forcePoints) {
      filters.force_points = true;
    }
    if (geoPinpoint && geoPinpoint.lat != null && geoPinpoint.lng != null) {
      filters.ref_lat = geoPinpoint.lat;
      filters.ref_lng = geoPinpoint.lng;
      filters.ref_raio_m = geoPinpoint.raio_m || GEO_PINPOINT_RAIO_M;
    }
    return filters;
  }

  function buildApiUrl(apiUrl, escopoId, bounds, zoom, filters) {
    var params = new URLSearchParams();
    params.set('cliente_id', String(escopoId));
    params.set('sw_lat', String(bounds.sw_lat));
    params.set('sw_lng', String(bounds.sw_lng));
    params.set('ne_lat', String(bounds.ne_lat));
    params.set('ne_lng', String(bounds.ne_lng));
    params.set('zoom', String(zoom));
    if (filters.status) params.set('status', filters.status);
    if (filters.bairro) params.set('bairro', filters.bairro);
    if (filters.busca) params.set('busca', filters.busca);
    if (filters.somente_chamados_abertos) params.set('somente_chamados_abertos', '1');
    if (filters.force_points) params.set('force_points', '1');
    if (filters.ref_lat != null && filters.ref_lng != null) {
      params.set('ref_lat', String(filters.ref_lat));
      params.set('ref_lng', String(filters.ref_lng));
      params.set('ref_raio_m', String(filters.ref_raio_m || GEO_PINPOINT_RAIO_M));
    }
    return apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') + params.toString();
  }

  function createMapStatusController(visibleCountEl, loadingEl) {
    var PLACEHOLDER = '—';
    var lastCountText = '';

    function isTransientStatus(text) {
      var t = String(text || '').trim();
      return (
        !t ||
        t === PLACEHOLDER ||
        t === 'Carregando mapa…' ||
        t === 'Aguardando mapa…' ||
        t === 'Carregando…' ||
        t === 'Carregando pontos…'
      );
    }

    if (visibleCountEl) {
      lastCountText = isTransientStatus(visibleCountEl.textContent) ? '' : String(visibleCountEl.textContent).trim();
      visibleCountEl.textContent = lastCountText || PLACEHOLDER;
    }
    if (loadingEl) {
      loadingEl.hidden = true;
      loadingEl.textContent = '';
    }

    function setLoading(active) {
      if (!visibleCountEl) return;
      visibleCountEl.classList.toggle('pontos-map-status--loading', !!active);
      visibleCountEl.setAttribute('aria-busy', active ? 'true' : 'false');
    }

    function renderCount(text) {
      if (!visibleCountEl) return;
      lastCountText = String(text || '').trim() || lastCountText || PLACEHOLDER;
      visibleCountEl.textContent = lastCountText;
      visibleCountEl.classList.remove('pontos-map-status--error');
      setLoading(false);
    }

    return {
      beginLoad: function () {
        if (visibleCountEl && !lastCountText) {
          visibleCountEl.textContent = PLACEHOLDER;
        }
        setLoading(true);
      },
      setWaiting: function () {
        if (visibleCountEl && !lastCountText) {
          visibleCountEl.textContent = PLACEHOLDER;
        }
        setLoading(true);
      },
      setCount: renderCount,
      setError: function (text) {
        if (!visibleCountEl) return;
        visibleCountEl.textContent = String(text || 'Erro ao carregar pontos.').trim();
        visibleCountEl.classList.add('pontos-map-status--error');
        setLoading(false);
      },
    };
  }

  function formatCountMessage(data) {
    var n = data.count != null ? data.count : (data.items || []).length;
    if (data.mode === 'pinpoint') {
      if (data.message) return data.message;
      if (n === 0) return 'Nenhum poste a ' + GEO_PINPOINT_RAIO_M + ' m destas coordenadas';
      if (n === 1) return '1 poste neste ponto';
      return n + ' postes a até ' + GEO_PINPOINT_RAIO_M + ' m';
    }
    if (data.mode === 'grid') {
      if (data.message) return data.message;
      return n + ' região(ões) · ' + (data.total_in_bounds || n) + ' poste(s). Aproxime o mapa para ver postes.';
    }
    if (data.limited && data.message) return data.message;
    if (data.limited) {
      return (
        'Exibindo ' +
        n +
        ' de ~' +
        (data.total_in_bounds || n) +
        '. Aproxime o mapa para carregar com mais precisão.'
      );
    }
    return n + ' ponto(s) nesta área';
  }

  function maybeOpenPinpointPopup(state, items, mode, provider) {
    if (mode !== 'pinpoint' || !items || items.length !== 1) return;
    var item = items[0];
    if (item.type === 'cluster') return;
    if (provider === 'google') {
      var key = 'p:' + item.id;
      var entry = state.byKey && state.byKey.get ? state.byKey.get(key) : null;
      if (entry && entry.marker && global.google && global.google.maps) {
        global.google.maps.event.trigger(entry.marker, 'click');
      }
      return;
    }
    if (provider === 'leaflet' && state.layer && typeof state.layer.eachLayer === 'function') {
      state.layer.eachLayer(function (layer) {
        if (typeof layer.openPopup === 'function') {
          layer.openPopup();
        }
      });
    }
  }

  function normalizePoint(item) {
    return {
      id: item.id,
      lat: item.lat,
      lng: item.lng,
      status: item.status,
      codigo_poste: item.codigo_poste,
      bairro: item.bairro,
      chamados_abertos: item.chamados_abertos,
    };
  }

  function notifyPontosPageInitialLoad(isError) {
    var loader = global.CrmPontosPageLoader;
    if (!loader) {
      return;
    }
    if (isError && typeof loader.notifyMapError === 'function') {
      loader.notifyMapError();
      return;
    }
    if (typeof loader.notifyMapReady === 'function') {
      loader.notifyMapReady();
    }
  }

  function buildGoogleMarkerFns(map, infoWindow, options) {
    var createPontoMarker = options.createPontoMarker;
    var openInfoWindow = options.openInfoWindow;

    return {
      point: function (pin) {
        if (typeof createPontoMarker === 'function') {
          return createPontoMarker(pin, map, infoWindow);
        }
        return new global.google.maps.Marker({
          position: { lat: pin.lat, lng: pin.lng },
          map: null,
        });
      },
      cluster: function (item) {
        var comChamado = item.status_summary && Number(item.status_summary.com_chamado) > 0;
        var scale = Math.min(24, 12 + Math.log10(Math.max(item.count, 1)) * 3);
        var marker = new global.google.maps.Marker({
          position: { lat: item.lat, lng: item.lng },
          map: null,
          icon: {
            path: global.google.maps.SymbolPath.CIRCLE,
            fillColor: comChamado ? '#3b82f6' : '#475569',
            fillOpacity: 0.9,
            strokeColor: '#ffffff',
            strokeWeight: 2,
            scale: scale,
          },
          label: {
            text: String(item.count),
            color: '#ffffff',
            fontSize: '11px',
            fontWeight: '700',
          },
          zIndex: Number(item.count) || 1,
          title: item.count + ' poste(s) nesta região',
        });
        marker.addListener('click', function () {
          map.setCenter({ lat: item.lat, lng: item.lng });
          map.setZoom(Math.min((map.getZoom() || 12) + 2, 18));
        });
        return marker;
      },
    };
  }

  function attachGoogleViewport(options) {
    options = options || {};
    var GJs = global.CrmGoogleMapsJs;
    var map = options.map;
    var config = options.config || global.CRM_PONTOS_MAPA_CONFIG || {};
    var el = options.el || (map && map.getDiv ? map.getDiv() : null);
    var areaFilter =
      options.areaFilter ||
      document.getElementById('map-filter-area') ||
      document.getElementById('combo-pontos-filter-area');
    var searchFilter =
      options.searchFilter ||
      document.getElementById('map-filter-search') ||
      document.getElementById('combo-pontos-filter-search');
    var clusterToggle =
      options.clusterToggle ||
      document.getElementById('map-toggle-cluster') ||
      document.getElementById('combo-map-toggle-cluster');
    var forcePointsToggle = document.getElementById('map-toggle-force-points');
    var visibleCount =
      options.visibleCountEl ||
      document.getElementById('map-visible-count') ||
      document.getElementById('combo-map-visible-pontos');
    var loadingEl = options.loadingEl || document.getElementById('map-loading-status');
    var statusCtrl = createMapStatusController(visibleCount, loadingEl);

    if (!map || !GJs) return null;

    var infoWindow = new global.google.maps.InfoWindow({ maxWidth: 360 });
    var state = {
      byKey: new Map(),
      clusterer: null,
      mode: 'points',
      abortController: null,
      requestSeq: 0,
      cache: {},
      inFlight: {},
      pendingCacheKey: '',
      mapDataLoaded: false,
      geoPinpoint: null,
      geoPanning: false,
      forcePoints: false,
    };
    var skipNextIdle = false;
    var pendingGeoLoad = false;
    var createFns = buildGoogleMarkerFns(map, infoWindow, options);
    var initialPageLoadDone = false;

    function markInitialPageLoadDone(isError) {
      if (initialPageLoadDone) {
        return;
      }
      initialPageLoadDone = true;
      notifyPontosPageInitialLoad(!!isError);
    }

    function useCluster() {
      return state.mode === 'points' && (!clusterToggle || clusterToggle.checked);
    }

    function setMarkerMap(marker, mapRef) {
      if (GJs && typeof GJs.setMarkerMap === 'function') {
        GJs.setMarkerMap(marker, mapRef);
      } else if (marker && typeof marker.setMap === 'function') {
        marker.setMap(mapRef);
      }
    }

    function clearAllMarkers() {
      if (state.clusterer && typeof state.clusterer.clearMarkers === 'function') {
        state.clusterer.clearMarkers();
      }
      state.clusterer = null;
      state.byKey.forEach(function (entry) {
        setMarkerMap(entry.marker, null);
      });
      state.byKey.clear();
    }

    function applyItemsToMap(items, mode) {
      if (options.enabled === false) {
        clearAllMarkers();
        return;
      }
      state.mode = mode || 'points';
      var nextKeys = new Set();
      var pointMarkers = [];

      (items || []).forEach(function (item) {
        if (!item || item.lat == null || item.lng == null) return;
        var key =
          item.type === 'cluster'
            ? 'c:' + Number(item.lat).toFixed(5) + ':' + Number(item.lng).toFixed(5)
            : 'p:' + item.id;
        nextKeys.add(key);
        if (!state.byKey.has(key)) {
          var marker =
            item.type === 'cluster' ? createFns.cluster(item) : createFns.point(normalizePoint(item));
          state.byKey.set(key, { key: key, type: item.type, marker: marker, item: item });
        }
      });

      state.byKey.forEach(function (entry, key) {
        if (!nextKeys.has(key)) {
          setMarkerMap(entry.marker, null);
          state.byKey.delete(key);
        } else if (entry.type === 'point') {
          pointMarkers.push(entry.marker);
        } else {
          setMarkerMap(entry.marker, map);
        }
      });

      if (state.clusterer && typeof state.clusterer.clearMarkers === 'function') {
        state.clusterer.clearMarkers();
      }
      state.clusterer = null;

      if (shouldRenderPointMarkers(state.mode) && pointMarkers.length) {
        var Clusterer = GJs.getMarkerClustererClass();
        if (useCluster() && Clusterer) {
          state.clusterer = new Clusterer({ map: map, markers: pointMarkers });
        } else {
          pointMarkers.forEach(function (m) {
            setMarkerMap(m, map);
          });
        }
      }
    }

    function updateCount(data) {
      statusCtrl.setCount(formatCountMessage(data));
      markInitialPageLoadDone(false);
    }

    function maybeFitSearchResults(items) {
      var points = (items || []).filter(function (i) {
        return i && (i.type === 'point' || !i.type) && i.lat != null;
      });
      if (points.length === 0 || points.length > FIT_BOUNDS_MAX) return;
      GJs.fitToPositions(
        map,
        points.map(function (p) {
          return { lat: p.lat, lng: p.lng };
        }),
        { maxZoom: 16, singleZoom: 16 }
      );
    }

    function loadVisiblePoints(force) {
      if (options.enabled === false) return;

      var zoom = map.getZoom() || 12;
      var bounds;
      var boundsObj = map.getBounds();
      if (boundsObj) {
        var ne = boundsObj.getNorthEast();
        var sw = boundsObj.getSouthWest();
        if (!ne || !sw) return;
        bounds = normalizeBoundsPayload(sw.lat(), sw.lng(), ne.lat(), ne.lng(), zoom);
      } else if (state.geoPinpoint && state.geoPinpoint.lat != null && state.geoPinpoint.lng != null) {
        var pinB = boundsFromPinpoint(state.geoPinpoint);
        bounds = normalizeBoundsPayload(pinB.sw_lat, pinB.sw_lng, pinB.ne_lat, pinB.ne_lng, zoom);
      } else {
        statusCtrl.setWaiting();
        return;
      }
      var filters = getFiltersFromDom(
        areaFilter,
        searchFilter,
        config.filtros || {},
        state.geoPinpoint,
        state.forcePoints
      );
      var cacheKey = buildCacheKey(bounds, zoom, filters);

      if (force) {
        skipNextIdle = true;
        debouncedLoad.cancel();
      }

      var cachedData = resolveCachedMapData(state, cacheKey, config, force);
      if (cachedData) {
        applyItemsToMap(cachedData.items, cachedData.mode);
        updateCount(cachedData);
        state.mapDataLoaded = true;
        return;
      }

      if (state.inFlight[cacheKey]) {
        var seqJoin = ++state.requestSeq;
        statusCtrl.beginLoad();
        state.inFlight[cacheKey]
          .then(function (data) {
            if (seqJoin !== state.requestSeq) return;
            state.mapDataLoaded = true;
            applyItemsToMap(data.items || [], data.mode || 'points');
            updateCount(data);
            state.geoPanning = false;
          })
          .catch(function (err) {
            if (err && err.name === 'AbortError') return;
            if (seqJoin !== state.requestSeq) return;
            statusCtrl.setError(err && err.message ? err.message : 'Erro ao carregar pontos.');
            markInitialPageLoadDone(true);
          });
        return;
      }

      if (state.abortController && state.pendingCacheKey !== cacheKey) {
        state.abortController.abort();
      }
      state.pendingCacheKey = cacheKey;
      state.abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var seq = ++state.requestSeq;

      statusCtrl.beginLoad();

      var url = buildApiUrl(config.api_url || global.CRM_PONTOS_MAPA_API, config.escopo_id, bounds, zoom, filters);
      var t0 = typeof performance !== 'undefined' && performance.now ? performance.now() : Date.now();

      fetchMapData(url, cacheKey, state, config)
        .then(function (data) {
          if (seq !== state.requestSeq) return;
          debugLog(config, 'loaded', {
            ms: Math.round((performance && performance.now ? performance.now() : Date.now()) - t0),
            api_ms: data.debug_ms,
            cached: !!data.cached,
            count: data.count,
            mode: data.mode,
            limited: data.limited,
            zoom: zoom,
          });
          state.mapDataLoaded = true;
          applyItemsToMap(data.items || [], data.mode || 'points');
          updateCount(data);
          state.geoPanning = false;
          maybeOpenPinpointPopup(state, data.items || [], data.mode, 'google');
          if (filters.busca && (data.items || []).length > 0 && (data.items || []).length <= FIT_BOUNDS_MAX) {
            maybeFitSearchResults(data.items);
          }
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          if (seq !== state.requestSeq) return;
          statusCtrl.setError(err && err.message ? err.message : 'Erro ao carregar pontos.');
          markInitialPageLoadDone(true);
        });
    }

    function onFilterChange() {
      state.cache = {};
      debouncedLoad.cancel();
      clearAllMarkers();
      loadVisiblePoints(true);
    }

    var debouncedLoad = debounce(function () {
      loadVisiblePoints(false);
    }, DEBOUNCE_MS);

    map.addListener('dragstart', function () {
      if (state.geoPanning || !state.geoPinpoint) return;
      state.geoPinpoint = null;
      state.cache = {};
    });
    map.addListener('idle', function () {
      if (pendingGeoLoad) {
        pendingGeoLoad = false;
        skipNextIdle = true;
        loadVisiblePoints(true);
        return;
      }
      if (skipNextIdle) {
        skipNextIdle = false;
        return;
      }
      if (!state.mapDataLoaded) {
        loadVisiblePoints(true);
        return;
      }
      debouncedLoad();
    });
    if (areaFilter) areaFilter.addEventListener('change', onFilterChange);
    if (searchFilter) searchFilter.addEventListener('input', debounce(onFilterChange, 300));
    if (clusterToggle) {
      clusterToggle.addEventListener('change', function () {
        if (shouldRenderPointMarkers(state.mode)) {
          var items = [];
          state.byKey.forEach(function (entry) {
            if (entry.type === 'point') items.push(entry.item);
          });
          applyItemsToMap(items, state.mode);
        }
      });
    }
    if (forcePointsToggle) {
      syncForcePointsToggle(forcePointsToggle, state.forcePoints);
      forcePointsToggle.addEventListener('click', function () {
        state.forcePoints = !state.forcePoints;
        syncForcePointsToggle(forcePointsToggle, state.forcePoints);
        state.cache = {};
        clearAllMarkers();
        loadVisiblePoints(true);
      });
    }

    if (options.autoLoad !== false) {
      requestAnimationFrame(function () {
        if (!options.skipResize) GJs.triggerResize(map);
      });
    }

    attachGeoFilter({
      map: map,
      provider: 'google',
      config: config,
      statusCtrl: statusCtrl,
      setPinpoint: function (ref) {
        state.geoPinpoint = ref;
        state.cache = {};
      },
      clearPinpoint: function () {
        state.geoPinpoint = null;
        state.cache = {};
      },
      onPanStart: function () {
        state.geoPanning = true;
      },
      onGo: function () {
        state.cache = {};
        clearAllMarkers();
        if (state.geoPinpoint) {
          pendingGeoLoad = true;
        } else {
          loadVisiblePoints(true);
        }
      },
    });

    return {
      map: map,
      reload: function () {
        loadVisiblePoints(true);
      },
      setEnabled: function (on) {
        options.enabled = on !== false;
        if (!options.enabled) clearAllMarkers();
        else loadVisiblePoints(true);
      },
      destroy: function () {
        clearAllMarkers();
        if (state.abortController) state.abortController.abort();
      },
    };
  }

  function initGoogle(options) {
    options = options || {};
    var GJs = global.CrmGoogleMapsJs;
    var el = options.el || document.getElementById(options.mapElementId || 'pontos-iluminacao-map');
    var config = options.config || global.CRM_PONTOS_MAPA_CONFIG || {};
    if (!el || !GJs || !GJs.mapsReady()) return null;

    var mapOpts = {};
    if (config.center && !options.map) {
      mapOpts.center = { lat: Number(config.center.lat), lng: Number(config.center.lng) };
      mapOpts.zoom = typeof config.center.zoom === 'number' ? config.center.zoom : 12;
    }

    var map = options.map || GJs.createMap(el, mapOpts);
    var controller = attachGoogleViewport(Object.assign({}, options, { map: map, config: config }));
    return controller ? controller.map : null;
  }

  function initLeaflet(options) {
    options = options || {};
    var el = document.getElementById(options.mapElementId || 'pontos-iluminacao-map');
    var config = options.config || global.CRM_PONTOS_MAPA_CONFIG || {};
    var areaFilter = options.areaFilter || document.getElementById('map-filter-area');
    var searchFilter = options.searchFilter || document.getElementById('map-filter-search');
    var clusterToggle = options.clusterToggle || document.getElementById('map-toggle-cluster');
    var forcePointsToggle = document.getElementById('map-toggle-force-points');
    var visibleCount = options.visibleCountEl || document.getElementById('map-visible-count');
    var loadingEl = options.loadingEl || document.getElementById('map-loading-status');
    var statusCtrl = createMapStatusController(visibleCount, loadingEl);

    if (!el || typeof L === 'undefined') return null;

    var map = L.map(el.id, { scrollWheelZoom: false });
    el._crmLeafletMap = map;
    if (global.CrmLeafletBasemap) global.CrmLeafletBasemap.addTo(map, { maxZoom: 19 });
    if (config.center) {
      map.setView(
        [Number(config.center.lat), Number(config.center.lng)],
        typeof config.center.zoom === 'number' ? config.center.zoom : 12
      );
    }

    var state = {
      layer: null,
      abortController: null,
      requestSeq: 0,
      cache: {},
      inFlight: {},
      pendingCacheKey: '',
      mapDataLoaded: false,
      mode: 'points',
      geoPinpoint: null,
      geoPanning: false,
      forcePoints: false,
    };
    var skipNextIdle = false;
    var pendingGeoLoad = false;
    var initialPageLoadDone = false;

    function markInitialPageLoadDone(isError) {
      if (initialPageLoadDone) {
        return;
      }
      initialPageLoadDone = true;
      notifyPontosPageInitialLoad(!!isError);
    }

    function markerClass(pin) {
      return typeof global.crmPontoMarkerClass === 'function'
        ? global.crmPontoMarkerClass(pin)
        : 'ponto-marker ponto-marker--ativo';
    }

    function clearLayer() {
      if (state.layer) {
        map.removeLayer(state.layer);
        state.layer = null;
      }
    }

    function createLayer() {
      if (
        state.mode === 'points' &&
        (!clusterToggle || clusterToggle.checked) &&
        typeof L.markerClusterGroup === 'function'
      ) {
        return L.markerClusterGroup({
          showCoverageOnHover: false,
          spiderfyOnMaxZoom: true,
          disableClusteringAtZoom: 18,
        });
      }
      return L.layerGroup();
    }

    function applyItems(items, mode) {
      clearLayer();
      state.mode = mode || 'points';
      state.layer = createLayer();
      (items || []).forEach(function (item) {
        if (!item || item.lat == null) return;
        if (item.type === 'cluster') {
          var comChamado = item.status_summary && Number(item.status_summary.com_chamado) > 0;
          var cm = L.marker([item.lat, item.lng], {
            icon: L.divIcon({
              className: '',
              html:
                '<span class="ponto-map-cluster-marker' +
                (comChamado ? ' ponto-map-cluster-marker--alert' : '') +
                '">' +
                String(item.count) +
                '</span>',
              iconSize: [36, 36],
              iconAnchor: [18, 18],
            }),
          });
          cm.on('click', function () {
            map.setView([item.lat, item.lng], Math.min(map.getZoom() + 2, 18));
          });
          state.layer.addLayer(cm);
        } else {
          var pin = normalizePoint(item);
          var m = L.marker([pin.lat, pin.lng], {
            icon: L.divIcon({
              className: '',
              html: '<span class="' + markerClass(pin) + '"></span>',
              iconSize: [10, 10],
              iconAnchor: [5, 5],
              popupAnchor: [0, -8],
            }),
          });
          if (global.CrmPontoIluminacaoPopup && global.CrmPontoIluminacaoPopup.bindLazyLeaflet) {
            global.CrmPontoIluminacaoPopup.bindLazyLeaflet(m, pin, {
              apiUrl: config.detalhe_api_url || global.CRM_PONTO_MAPA_DETALHE_API,
              popupOptions: { actions: 'full' },
            });
          }
          state.layer.addLayer(m);
        }
      });
      map.addLayer(state.layer);
    }

    function loadVisiblePoints(force) {
      var zoom = map.getZoom() || 12;
      var boundsPayload;
      var b = map.getBounds();
      if (b) {
        var sw = b.getSouthWest();
        var ne = b.getNorthEast();
        boundsPayload = normalizeBoundsPayload(sw.lat, sw.lng, ne.lat, ne.lng, zoom);
      } else if (state.geoPinpoint && state.geoPinpoint.lat != null && state.geoPinpoint.lng != null) {
        var pinBL = boundsFromPinpoint(state.geoPinpoint);
        boundsPayload = normalizeBoundsPayload(pinBL.sw_lat, pinBL.sw_lng, pinBL.ne_lat, pinBL.ne_lng, zoom);
      } else {
        return;
      }
      var filters = getFiltersFromDom(
        areaFilter,
        searchFilter,
        config.filtros || {},
        state.geoPinpoint,
        state.forcePoints
      );
      var cacheKey = buildCacheKey(boundsPayload, zoom, filters);

      if (force) {
        skipNextIdle = true;
        debouncedMoveEnd.cancel();
      }

      var cachedData = resolveCachedMapData(state, cacheKey, config, force);
      if (cachedData) {
        applyItems(cachedData.items, cachedData.mode);
        statusCtrl.setCount(formatCountMessage(cachedData));
        state.mapDataLoaded = true;
        markInitialPageLoadDone(false);
        return;
      }

      if (state.inFlight[cacheKey]) {
        var seqJoin = ++state.requestSeq;
        statusCtrl.beginLoad();
        state.inFlight[cacheKey]
          .then(function (data) {
            if (seqJoin !== state.requestSeq) return;
            state.mapDataLoaded = true;
            applyItems(data.items || [], data.mode || 'points');
            statusCtrl.setCount(formatCountMessage(data));
            state.geoPanning = false;
            markInitialPageLoadDone(false);
          })
          .catch(function (err) {
            if (err && err.name === 'AbortError') return;
            if (seqJoin !== state.requestSeq) return;
            statusCtrl.setError(err && err.message ? err.message : 'Erro ao carregar pontos.');
            markInitialPageLoadDone(true);
          });
        return;
      }

      if (state.abortController && state.pendingCacheKey !== cacheKey) {
        state.abortController.abort();
      }
      state.pendingCacheKey = cacheKey;
      state.abortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
      var seq = ++state.requestSeq;
      statusCtrl.beginLoad();

      var url = buildApiUrl(config.api_url || global.CRM_PONTOS_MAPA_API, config.escopo_id, boundsPayload, zoom, filters);

      fetchMapData(url, cacheKey, state, config)
        .then(function (data) {
          if (seq !== state.requestSeq) return;
          state.mapDataLoaded = true;
          applyItems(data.items || [], data.mode || 'points');
          statusCtrl.setCount(formatCountMessage(data));
          state.geoPanning = false;
          maybeOpenPinpointPopup(state, data.items || [], data.mode, 'leaflet');
          markInitialPageLoadDone(false);
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          if (seq !== state.requestSeq) return;
          statusCtrl.setError(err && err.message ? err.message : 'Erro ao carregar pontos.');
          markInitialPageLoadDone(true);
        });
    }

    function onFilterChange() {
      state.cache = {};
      debouncedMoveEnd.cancel();
      loadVisiblePoints(true);
    }

    var debouncedMoveEnd = debounce(function () {
      loadVisiblePoints(false);
    }, DEBOUNCE_MS);

    map.on('dragstart', function () {
      if (state.geoPanning || !state.geoPinpoint) return;
      state.geoPinpoint = null;
      state.cache = {};
    });
    map.on('moveend', function () {
      if (pendingGeoLoad) {
        pendingGeoLoad = false;
        skipNextIdle = true;
        loadVisiblePoints(true);
        return;
      }
      if (skipNextIdle) {
        skipNextIdle = false;
        return;
      }
      if (!state.mapDataLoaded) {
        loadVisiblePoints(true);
        return;
      }
      debouncedMoveEnd();
    });
    if (areaFilter) areaFilter.addEventListener('change', onFilterChange);
    if (searchFilter) searchFilter.addEventListener('input', debounce(onFilterChange, 300));
    if (clusterToggle) clusterToggle.addEventListener('change', function () {
      loadVisiblePoints(true);
    });
    if (forcePointsToggle) {
      syncForcePointsToggle(forcePointsToggle, state.forcePoints);
      forcePointsToggle.addEventListener('click', function () {
        state.forcePoints = !state.forcePoints;
        syncForcePointsToggle(forcePointsToggle, state.forcePoints);
        state.cache = {};
        clearLayer();
        loadVisiblePoints(true);
      });
    }

    requestAnimationFrame(function () {
      try {
        map.invalidateSize({ animate: false });
      } catch (e) {}
    });

    attachGeoFilter({
      map: map,
      provider: 'leaflet',
      config: config,
      statusCtrl: statusCtrl,
      setPinpoint: function (ref) {
        state.geoPinpoint = ref;
        state.cache = {};
      },
      clearPinpoint: function () {
        state.geoPinpoint = null;
        state.cache = {};
      },
      onPanStart: function () {
        state.geoPanning = true;
      },
      onGo: function () {
        state.cache = {};
        if (state.geoPinpoint) {
          pendingGeoLoad = true;
        } else {
          loadVisiblePoints(true);
        }
      },
    });

    return map;
  }

  global.CrmPontosMapViewport = {
    initGoogle: initGoogle,
    attachGoogle: attachGoogleViewport,
    initLeaflet: initLeaflet,
    CACHE_TTL_MS: CACHE_TTL_MS,
    DEBOUNCE_MS: DEBOUNCE_MS,
  };
})(typeof window !== 'undefined' ? window : this);

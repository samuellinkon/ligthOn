/**
 * Mapas do dashboard com Google Maps JavaScript API.
 */
(function (global) {
  'use strict';

  var GJs = global.CrmGoogleMapsJs;
  var Core = global.CrmDashboardMapGeocodeCore;

  function escapeHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function normalizeText(s) {
    var out = String(s || '').toLowerCase();
    if (typeof out.normalize === 'function') {
      out = out.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    return out;
  }

  function debounce(fn, wait) {
    var t = null;
    return function () {
      clearTimeout(t);
      t = setTimeout(fn, wait);
    };
  }

  function renderChamadoPopup(ch) {
    if (global.CrmChamadoPopup && typeof global.CrmChamadoPopup.render === 'function') {
      return global.CrmChamadoPopup.render(ch, { showStreetView: true });
    }
    return '<div class="call-popup"><p>Popup indisponível.</p></div>';
  }

  function renderPontoPopup(p) {
    if (global.CrmPontoIluminacaoPopup && typeof global.CrmPontoIluminacaoPopup.render === 'function') {
      return global.CrmPontoIluminacaoPopup.render(p, { actions: 'full' });
    }
    return '<div class="map-popup"><p>Popup indisponível.</p></div>';
  }

  function pontoMarkerClass(pin) {
    if (typeof global.crmPontoMarkerClass === 'function') {
      return global.crmPontoMarkerClass(pin);
    }
    return 'ponto-marker ponto-marker--ativo';
  }

  function canUseAdvancedMarkers() {
    return !!(GJs && typeof GJs.hasAdvancedMarkers === 'function' && GJs.hasAdvancedMarkers());
  }

  function bindMarkerClick(marker, fn) {
    if (!marker || !fn) return;
    var eventName = GJs && GJs.isAdvancedMarker && GJs.isAdvancedMarker(marker) ? 'gmp-click' : 'click';
    marker.addListener(eventName, fn);
  }

  function openInfoWindow(infoWindow, map, marker, html) {
    infoWindow.setContent(html);
    infoWindow.open({ map: map, anchor: marker });
  }

  function pontoCircleIcon(pin) {
    var cls = pontoMarkerClass(pin);
    var fill = '#22c55e';
    var stroke = '#15803d';
    if (cls.indexOf('ponto-marker--inativo') !== -1) {
      fill = '#94a3b8';
      stroke = '#64748b';
    } else if (cls.indexOf('ponto-marker--alert') !== -1) {
      fill = '#dc2626';
      stroke = '#7f1d1d';
    }
    return {
      path: global.google.maps.SymbolPath.CIRCLE,
      fillColor: fill,
      fillOpacity: 1,
      strokeColor: stroke,
      strokeWeight: 1,
      scale: 6,
    };
  }

  function createChamadoMarker(pin, map, infoWindow) {
    var position = { lat: pin.lat, lng: pin.lng };
    var marker;
    var AdvancedMarkerElement =
      global.google.maps.marker && global.google.maps.marker.AdvancedMarkerElement;

    if (canUseAdvancedMarkers() && AdvancedMarkerElement) {
      marker = new AdvancedMarkerElement({
        position: position,
        map: null,
        title: pin.titulo ? String(pin.titulo) : '',
      });
    } else {
      marker = new global.google.maps.Marker({
        position: position,
        map: null,
        title: pin.titulo ? String(pin.titulo) : '',
      });
    }

    bindMarkerClick(marker, function () {
      openInfoWindow(infoWindow, map, marker, renderChamadoPopup(pin));
    });
    return marker;
  }

  function createPontoMarker(pin, map, infoWindow) {
    var position = { lat: pin.lat, lng: pin.lng };
    var marker;
    var AdvancedMarkerElement =
      global.google.maps.marker && global.google.maps.marker.AdvancedMarkerElement;

    if (canUseAdvancedMarkers() && AdvancedMarkerElement) {
      var span = document.createElement('span');
      span.className = pontoMarkerClass(pin);
      marker = new AdvancedMarkerElement({
        position: position,
        map: null,
        content: span,
      });
    } else {
      marker = new global.google.maps.Marker({
        position: position,
        map: null,
        icon: pontoCircleIcon(pin),
      });
    }

    bindMarkerClick(marker, function () {
      openInfoWindow(infoWindow, map, marker, renderPontoPopup(pin));
    });
    return marker;
  }

  function clearMapMarkers(clusterer, markers) {
    if (clusterer && typeof clusterer.clearMarkers === 'function') {
      clusterer.clearMarkers();
    }
    (markers || []).forEach(function (m) {
      if (GJs && typeof GJs.setMarkerMap === 'function') {
        GJs.setMarkerMap(m, null);
      } else if (m && typeof m.setMap === 'function') {
        m.setMap(null);
      }
    });
  }

  function displayMarkers(map, markers, useCluster) {
    if (!markers.length) return null;
    var Clusterer = GJs && GJs.getMarkerClustererClass();
    if (useCluster && Clusterer) {
      return new Clusterer({ map: map, markers: markers });
    }
    markers.forEach(function (m) {
      if (GJs && typeof GJs.setMarkerMap === 'function') {
        GJs.setMarkerMap(m, map);
      } else if (m && typeof m.setMap === 'function') {
        m.setMap(map);
      }
    });
    return null;
  }

  function initChamados(opts) {
    opts = opts || {};
    var el = document.getElementById(opts.mapElementId || 'chamados-map');
    var pins = Array.isArray(opts.pins) ? opts.pins.slice() : [];
    var statusFilter = opts.statusFilter || null;
    var clusterToggle = opts.clusterToggle || null;
    var visibleCountEl = opts.visibleCountEl || null;
    var geocodeApi = opts.geocodeApi || '';
    var persistApi = opts.persistApi || '';
    var loadingBanner = null;

    if (!el || !GJs || !GJs.mapsReady()) return null;

    if (pins.length === 0) {
      var emptyMsg = opts.emptyMsg || 'Nenhum chamado com localização no período.';
      el.innerHTML = '<div class="dashboard-map-empty">' + escapeHtml(emptyMsg) + '</div>';
      return null;
    }

    if (opts.loadingMsg) {
      loadingBanner = document.createElement('div');
      loadingBanner.className = 'dashboard-map-loading';
      loadingBanner.setAttribute('role', 'status');
      loadingBanner.textContent = opts.loadingMsg;
      el.parentNode.insertBefore(loadingBanner, el);
    }

    var map = GJs.createMap(el);
    var infoWindow = new global.google.maps.InfoWindow({ maxWidth: 360 });
    var items = [];
    var clusterer = null;

    function useCluster() {
      return !clusterToggle || clusterToggle.checked;
    }

    function matchStatus(p) {
      if (!statusFilter || !statusFilter.value) return true;
      return String(p.status || '') === statusFilter.value;
    }

    function addReadyPin(pin) {
      if (pin.lat == null || pin.lng == null) return;
      pin.pin_state = 'ready';
      items.push({
        pin: pin,
        marker: createChamadoMarker(pin, map, infoWindow),
      });
    }

    function renderMarkers() {
      var filtered = items.filter(function (item) {
        return matchStatus(item.pin);
      });
      var positions = filtered.map(function (item) {
        return { lat: item.pin.lat, lng: item.pin.lng };
      });

      clearMapMarkers(
        clusterer,
        items.map(function (i) {
          return i.marker;
        })
      );
      clusterer = displayMarkers(
        map,
        filtered.map(function (i) {
          return i.marker;
        }),
        useCluster()
      );

      if (visibleCountEl) {
        visibleCountEl.textContent =
          filtered.length + ' de ' + items.length + ' chamado(s) visível(is)';
      }
      if (positions.length) {
        GJs.fitToPositions(map, positions, { maxZoom: 15, singleZoom: 14 });
      }
      if (typeof opts.onMarkersChange === 'function') {
        opts.onMarkersChange(items.length, filtered.length);
      }
    }

    pins.forEach(function (p) {
      if (p.pin_state === 'ready' || (p.lat != null && p.lng != null)) {
        addReadyPin(p);
      }
    });

    renderMarkers();
    requestAnimationFrame(function () {
      GJs.triggerResize(map);
    });

    if (statusFilter) statusFilter.addEventListener('change', renderMarkers);
    if (clusterToggle) clusterToggle.addEventListener('change', renderMarkers);

    function finishLoadingBanner(failed) {
      if (!loadingBanner) return;
      if (failed > 0) {
        loadingBanner.textContent =
          failed + ' chamado(s) não localizados pelo endereço (demais exibidos no mapa).';
        loadingBanner.classList.add('dashboard-map-loading--warn');
      } else {
        loadingBanner.remove();
      }
    }

    if (!Core) {
      finishLoadingBanner(0);
      return map;
    }

    Core.runPendingOnMap(
      pins,
      geocodeApi,
      persistApi,
      function (pin) {
        addReadyPin(pin);
      },
      function (failed) {
        finishLoadingBanner(failed);
        renderMarkers();
      }
    );

    return map;
  }

  function initPontos(opts) {
    opts = opts || {};
    var el = document.getElementById(opts.mapElementId || 'pontos-iluminacao-map');
    var pins = opts.pins != null ? opts.pins : global.PONTOS_ILUMINACAO_MAP;
    var areaFilter = opts.areaFilter || document.getElementById('map-filter-area');
    var searchFilter = opts.searchFilter || document.getElementById('map-filter-search');
    var clusterToggle = opts.clusterToggle || document.getElementById('map-toggle-cluster');
    var visibleCount = opts.visibleCountEl || document.getElementById('map-visible-count');

    if (!el || !GJs || !GJs.mapsReady()) return null;
    if (!Array.isArray(pins) || pins.length === 0) {
      el.innerHTML =
        '<div class="pontos-map-empty">Nenhum ponto com latitude e longitude cadastrado.</div>';
      return null;
    }

    var map = GJs.createMap(el);
    var infoWindow = new global.google.maps.InfoWindow({ maxWidth: 360 });
    var items = pins.map(function (p) {
      return { pin: p, marker: createPontoMarker(p, map, infoWindow) };
    });
    var clusterer = null;

    function useCluster() {
      return !clusterToggle || clusterToggle.checked;
    }

    function matchArea(p) {
      if (!areaFilter || !areaFilter.value) return true;
      return String(p.bairro || '') === areaFilter.value;
    }

    function matchSearch(p) {
      if (!searchFilter || !searchFilter.value) return true;
      var q = normalizeText(searchFilter.value);
      var haystack = [
        p.codigo_poste,
        p.identificador_externo,
        p.endereco_completo,
        p.bairro,
        p.cliente,
        p.status,
      ].join(' ');
      return normalizeText(haystack).indexOf(q) !== -1;
    }

    function renderMarkers() {
      var filtered = items.filter(function (item) {
        return matchArea(item.pin) && matchSearch(item.pin);
      });
      var positions = filtered.map(function (item) {
        return { lat: item.pin.lat, lng: item.pin.lng };
      });

      clearMapMarkers(
        clusterer,
        items.map(function (i) {
          return i.marker;
        })
      );
      clusterer = displayMarkers(
        map,
        filtered.map(function (i) {
          return i.marker;
        }),
        useCluster()
      );

      if (visibleCount) {
        visibleCount.textContent = filtered.length + ' de ' + items.length + ' ponto(s) visível(is)';
      }
      if (positions.length) {
        GJs.fitToPositions(map, positions, { maxZoom: 16, singleZoom: 16 });
      }
    }

    renderMarkers();
    requestAnimationFrame(function () {
      GJs.triggerResize(map);
    });

    if (areaFilter) areaFilter.addEventListener('change', renderMarkers);
    if (searchFilter) searchFilter.addEventListener('input', debounce(renderMarkers, 180));
    if (clusterToggle) clusterToggle.addEventListener('change', renderMarkers);

    return map;
  }

  function initCombinado(opts) {
    opts = opts || {};
    var data = opts.data || global.DASHBOARD_MAP_COMBINED;
    var el = document.getElementById(opts.mapElementId || 'dashboard-mapa-combinado');
    var layerCh = document.getElementById('combo-layer-chamados');
    var layerPt = document.getElementById('combo-layer-pontos');
    var statusFilter = document.getElementById('combo-chamados-filter-status');
    var searchCh = document.getElementById('combo-chamados-filter-search');
    var areaFilter = document.getElementById('combo-pontos-filter-area');
    var searchPt = document.getElementById('combo-pontos-filter-search');
    var clusterToggle = document.getElementById('combo-map-toggle-cluster');
    var visibleCh = document.getElementById('combo-map-visible-chamados');
    var visiblePt = document.getElementById('combo-map-visible-pontos');

    if (!el || !GJs || !GJs.mapsReady() || !data || typeof data !== 'object') return null;

    var pinsCh = Array.isArray(data.chamados) ? data.chamados : [];
    var pinsPt = Array.isArray(data.pontos) ? data.pontos : [];
    var geocodeApi = global.CHAMADOS_MAP_GEOCODE_API || '';
    var persistApi = global.CHAMADOS_MAP_PERSIST_API || '';
    var hasChamadosData = pinsCh.length > 0;

    if (!hasChamadosData && pinsPt.length === 0) {
      var comboEmpty =
        global.CHAMADOS_MAP_EMPTY_MSG ||
        'Não há chamados no período nem postes com coordenadas para exibir.';
      el.innerHTML = '<div class="dashboard-map-empty">' + escapeHtml(comboEmpty) + '</div>';
      return null;
    }

    var map = GJs.createMap(el);
    var infoWindowCh = new global.google.maps.InfoWindow({ maxWidth: 360 });
    var infoWindowPt = new global.google.maps.InfoWindow({ maxWidth: 360 });
    var itemsCh = [];
    var itemsPt = pinsPt.map(function (p) {
      return { pin: p, marker: createPontoMarker(p, map, infoWindowPt) };
    });
    var clustererCh = null;
    var clustererPt = null;
    var loadingBanner = null;

    function useCluster() {
      return !clusterToggle || clusterToggle.checked;
    }

    function addChamadoItem(pin) {
      if (pin.lat == null || pin.lng == null) return;
      itemsCh.push({
        pin: pin,
        marker: createChamadoMarker(pin, map, infoWindowCh),
      });
    }

    pinsCh.forEach(function (p) {
      if (p.pin_state === 'ready' || (p.lat != null && p.lng != null)) {
        addChamadoItem(p);
      }
    });

    if (global.CHAMADOS_MAP_LOADING_MSG && pinsCh.length > 0) {
      loadingBanner = document.createElement('div');
      loadingBanner.className = 'dashboard-map-loading';
      loadingBanner.setAttribute('role', 'status');
      loadingBanner.textContent = global.CHAMADOS_MAP_LOADING_MSG;
      el.parentNode.insertBefore(loadingBanner, el);
      var finishBanner = function (failed) {
        if (!loadingBanner || !loadingBanner.parentNode) return;
        if (failed > 0) {
          loadingBanner.textContent =
            failed + ' chamado(s) não localizados pelo endereço (demais exibidos no mapa).';
          loadingBanner.classList.add('dashboard-map-loading--warn');
        } else {
          loadingBanner.remove();
        }
      };
      if (Core) {
        Core.geocodePendingPins(pinsCh, geocodeApi, persistApi, function (pin) {
          addChamadoItem(pin);
          rebuildLayers();
        }, function (res) {
          finishBanner(res && res.failed ? res.failed : 0);
          rebuildLayers();
        });
      }
    }

    function matchStatus(p) {
      if (!statusFilter || !statusFilter.value) return true;
      return String(p.status || '') === statusFilter.value;
    }

    function matchSearchCh(p) {
      if (!searchCh || !searchCh.value) return true;
      var q = normalizeText(searchCh.value);
      var haystack = [p.id, p.titulo, p.cliente, p.status, p.data, p.endereco_completo].join(' ');
      return normalizeText(haystack).indexOf(q) !== -1;
    }

    function matchArea(p) {
      if (!areaFilter || !areaFilter.value) return true;
      return String(p.bairro || '') === areaFilter.value;
    }

    function matchSearchPt(p) {
      if (!searchPt || !searchPt.value) return true;
      var q = normalizeText(searchPt.value);
      var haystack = [
        p.codigo_poste,
        p.identificador_externo,
        p.endereco_completo,
        p.bairro,
        p.cliente,
        p.status,
      ].join(' ');
      return normalizeText(haystack).indexOf(q) !== -1;
    }

    function filteredCh() {
      return itemsCh.filter(function (item) {
        return matchStatus(item.pin) && matchSearchCh(item.pin);
      });
    }

    function filteredPt() {
      return itemsPt.filter(function (item) {
        return matchArea(item.pin) && matchSearchPt(item.pin);
      });
    }

    function showChamadosLayer() {
      return layerCh && layerCh.checked;
    }

    function showPontosLayer() {
      return layerPt && layerPt.checked;
    }

    function rebuildLayers() {
      var positions = [];
      var chFiltered = showChamadosLayer() ? filteredCh() : [];
      var ptFiltered = showPontosLayer() ? filteredPt() : [];

      clearMapMarkers(
        clustererCh,
        itemsCh.map(function (i) {
          return i.marker;
        })
      );
      clearMapMarkers(
        clustererPt,
        itemsPt.map(function (i) {
          return i.marker;
        })
      );

      if (showChamadosLayer()) {
        var chMarkers = chFiltered.map(function (i) {
          return i.marker;
        });
        clustererCh = displayMarkers(map, chMarkers, useCluster());
        chFiltered.forEach(function (item) {
          positions.push({ lat: item.pin.lat, lng: item.pin.lng });
        });
      } else {
        clustererCh = null;
      }

      if (showPontosLayer()) {
        var ptMarkers = ptFiltered.map(function (i) {
          return i.marker;
        });
        clustererPt = displayMarkers(map, ptMarkers, useCluster());
        ptFiltered.forEach(function (item) {
          positions.push({ lat: item.pin.lat, lng: item.pin.lng });
        });
      } else {
        clustererPt = null;
      }

      if (visibleCh) {
        visibleCh.textContent =
          chFiltered.length + ' de ' + itemsCh.length + ' chamado(s) visível(is)';
      }
      if (visiblePt) {
        visiblePt.textContent =
          ptFiltered.length + ' de ' + itemsPt.length + ' ponto(s) visível(is)';
      }

      if (positions.length === 0) {
        map.setCenter(GJs.DEFAULT_CENTER);
        map.setZoom(GJs.DEFAULT_ZOOM);
      } else {
        GJs.fitToPositions(map, positions, { maxZoom: 15, singleZoom: 14 });
      }
    }

    if (layerCh) layerCh.addEventListener('change', rebuildLayers);
    if (layerPt) layerPt.addEventListener('change', rebuildLayers);
    if (statusFilter) statusFilter.addEventListener('change', rebuildLayers);
    if (searchCh) searchCh.addEventListener('input', debounce(rebuildLayers, 180));
    if (areaFilter) areaFilter.addEventListener('change', rebuildLayers);
    if (searchPt) searchPt.addEventListener('input', debounce(rebuildLayers, 180));
    if (clusterToggle) clusterToggle.addEventListener('change', rebuildLayers);

    if (layerCh && layerPt) {
      if (pinsCh.length === 0) {
        layerCh.checked = false;
        layerPt.checked = true;
      } else if (itemsPt.length === 0) {
        layerCh.checked = true;
        layerPt.checked = false;
      } else {
        layerCh.checked = true;
        layerPt.checked = true;
      }
      if (!layerCh.checked && !layerPt.checked) {
        layerCh.checked = pinsCh.length > 0;
        layerPt.checked = itemsPt.length > 0;
      }
    }

    rebuildLayers();
    requestAnimationFrame(function () {
      GJs.triggerResize(map);
    });

    return map;
  }

  function boot() {
    if (!GJs || !GJs.mapsReady()) return;
    if (document.getElementById('chamados-map') && global.CHAMADOS_MAP_PINS) {
      initChamados({
        mapElementId: 'chamados-map',
        pins: global.CHAMADOS_MAP_PINS,
        geocodeApi: global.CHAMADOS_MAP_GEOCODE_API || '',
        persistApi: global.CHAMADOS_MAP_PERSIST_API || '',
        emptyMsg: global.CHAMADOS_MAP_EMPTY_MSG || '',
        loadingMsg: global.CHAMADOS_MAP_LOADING_MSG || '',
        statusFilter: document.getElementById('chamados-map-filter-status'),
        clusterToggle: document.getElementById('chamados-map-toggle-cluster'),
        visibleCountEl: document.getElementById('chamados-map-visible-count'),
      });
    }
    if (document.getElementById('pontos-iluminacao-map') && global.PONTOS_ILUMINACAO_MAP) {
      initPontos({ mapElementId: 'pontos-iluminacao-map' });
    }
    if (document.getElementById('dashboard-mapa-combinado') && global.DASHBOARD_MAP_COMBINED) {
      initCombinado({ mapElementId: 'dashboard-mapa-combinado' });
    }
  }

  global.CrmDashboardMapGoogle = {
    initChamados: initChamados,
    initPontos: initPontos,
    initCombinado: initCombinado,
    boot: boot,
  };

  global.CrmDashboardMapsBoot = boot;
})(typeof window !== 'undefined' ? window : this);

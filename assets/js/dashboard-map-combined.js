/**
 * Mapa único: chamados + postes (camadas ligáveis por checkbox).
 * Dados: window.DASHBOARD_MAP_COMBINED = { chamados: [], pontos: [] }
 */
(function () {
  var data = window.DASHBOARD_MAP_COMBINED;
  var el = document.getElementById('dashboard-mapa-combinado');
  var layerCh = document.getElementById('combo-layer-chamados');
  var layerPt = document.getElementById('combo-layer-pontos');
  var statusFilter = document.getElementById('combo-chamados-filter-status');
  var searchCh = document.getElementById('combo-chamados-filter-search');
  var areaFilter = document.getElementById('combo-pontos-filter-area');
  var searchPt = document.getElementById('combo-pontos-filter-search');
  var clusterToggle = document.getElementById('combo-map-toggle-cluster');
  var visibleCh = document.getElementById('combo-map-visible-chamados');
  var visiblePt = document.getElementById('combo-map-visible-pontos');

  if (!el || typeof L === 'undefined') return;
  if (!data || typeof data !== 'object') return;

  var pinsCh = Array.isArray(data.chamados) ? data.chamados : [];
  var pinsPt = Array.isArray(data.pontos) ? data.pontos : [];

  var map = L.map('dashboard-mapa-combinado', { scrollWheelZoom: false });
  el._crmLeafletMap = map;
  if (window.CrmLeafletBasemap) {
    window.CrmLeafletBasemap.addTo(map, { maxZoom: 19 });
  }

  var geocodeApi = window.CHAMADOS_MAP_GEOCODE_API || '';
  var persistApi = window.CHAMADOS_MAP_PERSIST_API || '';
  var mapGeo = window.CrmDashboardMapChamados;

  function addChamadoItem(pin) {
    if (!mapGeo || typeof mapGeo.createMarkerForPin !== 'function') return;
    var m = mapGeo.createMarkerForPin(pin);
    if (!m) return;
    itemsCh.push({ pin: pin, marker: m });
  }

  var itemsCh = [];
  pinsCh.forEach(function (p) {
    if (p.pin_state === 'ready' || (p.lat != null && p.lng != null)) {
      addChamadoItem(p);
    }
  });

  if (window.CHAMADOS_MAP_LOADING_MSG && pinsCh.length > 0) {
    var loadingBanner = document.createElement('div');
    loadingBanner.className = 'dashboard-map-loading';
    loadingBanner.setAttribute('role', 'status');
    loadingBanner.textContent = window.CHAMADOS_MAP_LOADING_MSG;
    el.parentNode.insertBefore(loadingBanner, el);
    var finishBanner = function (failed) {
      if (!loadingBanner.parentNode) return;
      if (failed > 0) {
        loadingBanner.textContent =
          failed + ' chamado(s) não localizados pelo endereço (demais exibidos no mapa).';
        loadingBanner.classList.add('dashboard-map-loading--warn');
      } else {
        loadingBanner.remove();
      }
    };
    if (mapGeo && typeof mapGeo.geocodePendingPins === 'function') {
      mapGeo.geocodePendingPins(pinsCh, geocodeApi, persistApi, function (pin) {
        addChamadoItem(pin);
        rebuildLayers();
      }, function (res) {
        finishBanner(res && res.failed ? res.failed : 0);
        rebuildLayers();
      });
    }
  }

  function renderPopupPonto(p) {
    if (window.CrmPontoIluminacaoPopup && typeof window.CrmPontoIluminacaoPopup.render === 'function') {
      return window.CrmPontoIluminacaoPopup.render(p, { actions: 'full' });
    }
    return '<div class="map-popup"><p>Popup indisponível.</p></div>';
  }

  var markerClassPt = typeof window.crmPontoMarkerClass === 'function'
    ? window.crmPontoMarkerClass
    : function (pin) {
        return 'ponto-marker ponto-marker--ativo';
      };

  var itemsPt = pinsPt.map(function (p) {
    var icon = L.divIcon({
      className: '',
      html: '<span class="' + markerClassPt(p) + '"></span>',
      iconSize: [10, 10],
      iconAnchor: [5, 5],
      popupAnchor: [0, -8],
    });
    var marker = L.marker([p.lat, p.lng], { icon: icon });
    marker.bindPopup(renderPopupPonto(p), { maxWidth: 360, minWidth: 280 });
    return { pin: p, marker: marker };
  });

  var layerChamados = null;
  var layerPontos = null;

  function createClusterLayer() {
    if (!clusterToggle || clusterToggle.checked) {
      if (typeof L.markerClusterGroup === 'function') {
        return L.markerClusterGroup({
          showCoverageOnHover: false,
          spiderfyOnMaxZoom: true,
          disableClusteringAtZoom: 18,
        });
      }
    }
    return L.layerGroup();
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
    if (layerChamados) {
      map.removeLayer(layerChamados);
      layerChamados = null;
    }
    if (layerPontos) {
      map.removeLayer(layerPontos);
      layerPontos = null;
    }

    var bounds = [];

    if (showChamadosLayer()) {
      layerChamados = createClusterLayer();
      filteredCh().forEach(function (item) {
        layerChamados.addLayer(item.marker);
        bounds.push([item.pin.lat, item.pin.lng]);
      });
      map.addLayer(layerChamados);
    }

    if (showPontosLayer()) {
      layerPontos = createClusterLayer();
      filteredPt().forEach(function (item) {
        layerPontos.addLayer(item.marker);
        bounds.push([item.pin.lat, item.pin.lng]);
      });
      map.addLayer(layerPontos);
    }

    if (visibleCh) {
      var n = filteredCh().length;
      visibleCh.textContent = n + ' de ' + itemsCh.length + ' chamado(s) visível(is)';
    }
    if (visiblePt) {
      var m = filteredPt().length;
      visiblePt.textContent = m + ' de ' + itemsPt.length + ' ponto(s) visível(is)';
    }

    if (bounds.length === 0) {
      map.setView([-14.235, -51.9253], 4);
      return;
    }
    if (bounds.length === 1) {
      map.setView(bounds[0], 14);
    } else {
      map.fitBounds(bounds, { padding: [28, 28], maxZoom: 15 });
    }
  }

  if (layerCh) layerCh.addEventListener('change', rebuildLayers);
  if (layerPt) layerPt.addEventListener('change', rebuildLayers);
  if (statusFilter) statusFilter.addEventListener('change', rebuildLayers);
  if (searchCh) searchCh.addEventListener('input', debounce(rebuildLayers, 180));
  if (areaFilter) areaFilter.addEventListener('change', rebuildLayers);
  if (searchPt) searchPt.addEventListener('input', debounce(rebuildLayers, 180));
  if (clusterToggle) clusterToggle.addEventListener('change', rebuildLayers);

  var hasChamadosData = pinsCh.length > 0;
  if (!hasChamadosData && itemsPt.length === 0) {
    var comboEmpty = window.CHAMADOS_MAP_EMPTY_MSG
      || 'Não há chamados no período nem postes com coordenadas para exibir.';
    el.innerHTML = '<div class="dashboard-map-empty">' + comboEmpty + '</div>';
    return;
  }

  if (!layerCh || !layerPt) {
    rebuildLayers();
    requestAnimationFrame(function () {
      try {
        map.invalidateSize({ animate: false });
      } catch (e) {}
    });
    return;
  }

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

  rebuildLayers();
  requestAnimationFrame(function () {
    try {
      map.invalidateSize({ animate: false });
    } catch (e) {}
  });

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

})();

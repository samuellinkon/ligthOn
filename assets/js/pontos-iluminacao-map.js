/**
 * Mapa dos pontos de iluminacao a partir de window.PONTOS_ILUMINACAO_MAP.
 */
(function () {
  var pins = window.PONTOS_ILUMINACAO_MAP;
  var el = document.getElementById('pontos-iluminacao-map');
  var areaFilter = document.getElementById('map-filter-area');
  var searchFilter = document.getElementById('map-filter-search');
  var clusterToggle = document.getElementById('map-toggle-cluster');
  var visibleCount = document.getElementById('map-visible-count');
  if (!el || typeof L === 'undefined') return;
  if (!Array.isArray(pins) || pins.length === 0) {
    el.innerHTML = '<div class="pontos-map-empty">Nenhum ponto com latitude e longitude cadastrado.</div>';
    return;
  }

  var map = L.map('pontos-iluminacao-map', { scrollWheelZoom: false });
  el._crmLeafletMap = map;
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap',
  }).addTo(map);

  var markers = pins.map(function (p) {
    var alert = Number(p.chamados_abertos || 0) > 0;
    var icon = L.divIcon({
      className: '',
      html: '<span class="ponto-marker' + (alert ? ' ponto-marker--alert' : '') + '"></span>',
      iconSize: [10, 10],
      iconAnchor: [5, 5],
      popupAnchor: [0, -8],
    });
    var marker = L.marker([p.lat, p.lng], { icon: icon });
    marker.bindPopup(buildPopup(p), { maxWidth: 340, minWidth: 280 });
    return { pin: p, marker: marker };
  });

  var markerLayer = null;
  renderMarkers();
  requestAnimationFrame(function () {
    try {
      map.invalidateSize({ animate: false });
    } catch (e) {}
  });

  if (areaFilter) {
    areaFilter.addEventListener('change', renderMarkers);
  }
  if (searchFilter) {
    searchFilter.addEventListener('input', debounce(renderMarkers, 180));
  }
  if (clusterToggle) {
    clusterToggle.addEventListener('change', renderMarkers);
  }

  function renderMarkers() {
    var filtered = markers.filter(function (item) {
      return matchArea(item.pin) && matchSearch(item.pin);
    });
    var bounds = [];

    if (markerLayer) {
      map.removeLayer(markerLayer);
    }
    markerLayer = createMarkerLayer();
    filtered.forEach(function (item) {
      markerLayer.addLayer(item.marker);
      bounds.push([item.pin.lat, item.pin.lng]);
    });
    map.addLayer(markerLayer);

    if (visibleCount) {
      visibleCount.textContent = filtered.length + ' de ' + markers.length + ' ponto(s) visível(is)';
    }
    if (bounds.length === 1) {
      map.setView(bounds[0], 16);
    } else if (bounds.length > 1) {
      map.fitBounds(bounds, { padding: [28, 28], maxZoom: 16 });
    }
  }

  function createMarkerLayer() {
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

  function escapeHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function buildPopup(p) {
    var codigo = p.codigo_poste || '';
    var streetViewUrl = buildStreetViewUrl(p);
    var html =
      '<div class="ponto-popup">' +
      '<strong>Poste ' + escapeHtml(codigo) + '</strong>' +
      '<br><span class="ponto-popup-meta">' + escapeHtml(p.cliente || '') +
      (p.bairro ? ' · ' + escapeHtml(p.bairro) : '') + '</span>';

    if (p.foto_url) {
      html += '<img class="ponto-popup-photo" src="' + escapeAttr(p.foto_url) + '" alt="Foto do poste ' + escapeAttr(codigo) + '">';
    } else {
      html += '<div class="ponto-popup-photo ponto-popup-photo-empty">Sem foto cadastrada</div>';
    }

    if (p.endereco_completo) {
      html += '<small class="ponto-popup-address">' + escapeHtml(p.endereco_completo) + '</small>';
    }

    html +=
      '<div style="margin-top:8px;"><strong>' + Number(p.chamados_abertos || 0) +
      '</strong> chamado(s) aberto(s)</div>' +
      buildHistorico(p.chamados_historico || []) +
      '<div class="ponto-popup-actions">' +
      '<a class="ponto-popup-link ponto-popup-link--primary" href="chamado_novo.php?ponto_iluminacao_id=' + encodeURIComponent(String(p.id || '')) + '">Abrir chamado</a>' +
      '<a class="ponto-popup-link" href="chamados.php?q=' + encodeURIComponent(codigo) + '">Ver chamados</a>' +
      '<a class="ponto-popup-btn-streetview" href="' + escapeAttr(streetViewUrl) + '" target="_blank" rel="noopener noreferrer">Abrir Street View</a>' +
      '</div>' +
      '</div>';

    return html;
  }

  function buildStreetViewUrl(p) {
    var lat = Number(p.lat);
    var lng = Number(p.lng);
    var endereco = String(p.endereco_completo || '').trim();
    var base = 'https://www.google.com/maps/@?api=1&map_action=pano';
    if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
      return base + '&viewpoint=' + encodeURIComponent(lat + ',' + lng) + (endereco ? '&query=' + encodeURIComponent(endereco) : '');
    }
    if (endereco) {
      return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(endereco);
    }
    return 'https://www.google.com/maps';
  }

  function buildHistorico(chamados) {
    if (!Array.isArray(chamados) || chamados.length === 0) {
      return '<div class="ponto-popup-history"><div class="ponto-popup-history-title">Histórico de chamados</div><small class="ponto-popup-meta">Nenhum chamado vinculado a este poste.</small></div>';
    }

    var html = '<div class="ponto-popup-history"><div class="ponto-popup-history-title">Histórico de chamados</div>';
    chamados.forEach(function (ch) {
      html +=
        '<a class="ponto-popup-call" href="chamado_detalhe.php?id=' + encodeURIComponent(ch.id || '') + '">' +
        '<strong>#' + escapeHtml(String(ch.id || '')) + ' · ' + escapeHtml(ch.status || '') + '</strong>' +
        '<small>' + escapeHtml(ch.data || '') + (ch.prioridade ? ' · ' + escapeHtml(ch.prioridade) : '') + '</small>' +
        (ch.titulo ? '<span style="display:block;margin-top:2px;">' + escapeHtml(ch.titulo) + '</span>' : '') +
        '</a>';
    });
    html += '</div>';

    return html;
  }

  function escapeAttr(s) {
    return escapeHtml(String(s || '')).replace(/"/g, '&quot;');
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

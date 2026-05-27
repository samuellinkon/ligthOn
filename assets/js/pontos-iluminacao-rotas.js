/**
 * Rota dos chamados de iluminacao a partir da Prefeitura do Ipojuca.
 */
(function () {
  var data = window.PONTOS_ILUMINACAO_ROUTE || {};
  var origem = data.origem || null;
  var pins = Array.isArray(data.pins) ? data.pins : [];
  var el = document.getElementById('pontos-iluminacao-route-map');
  var distanceChip = document.getElementById('route-distance-chip');

  if (!el || typeof L === 'undefined') return;
  if (!origem || !Number(origem.lat) || !Number(origem.lng) || pins.length === 0) {
    el.innerHTML = '<div class="route-empty">Nenhum chamado com coordenadas para montar rota.</div>';
    if (distanceChip) distanceChip.textContent = 'Distância: sem rota';
    return;
  }

  var map = L.map('pontos-iluminacao-route-map', { scrollWheelZoom: false });
  if (window.CrmLeafletBasemap) {
    window.CrmLeafletBasemap.addTo(map, { maxZoom: 19 });
  }

  var orderedPins = ordenarPorVizinhoMaisProximo([Number(origem.lat), Number(origem.lng)], pins);
  var coords = [[Number(origem.lat), Number(origem.lng)]];
  var bounds = [[Number(origem.lat), Number(origem.lng)]];

  L.marker([Number(origem.lat), Number(origem.lng)], {
    icon: L.divIcon({
      className: '',
      html: '<span class="route-origin-marker">P</span>',
      iconSize: [24, 24],
      iconAnchor: [12, 12],
      popupAnchor: [0, -12],
    }),
  }).addTo(map).bindPopup('<strong>' + escapeHtml(origem.label || 'Prefeitura') + '</strong><br><small>Origem da rota</small>');

  orderedPins.forEach(function (p, idx) {
    var lat = Number(p.lat);
    var lng = Number(p.lng);
    if (!lat || !lng) return;
    coords.push([lat, lng]);
    bounds.push([lat, lng]);

    L.marker([lat, lng], {
      icon: L.divIcon({
        className: '',
        html: '<span class="route-stop-marker">' + (idx + 1) + '</span>',
        iconSize: [22, 22],
        iconAnchor: [11, 11],
        popupAnchor: [0, -11],
      }),
    }).addTo(map).bindPopup(buildPopup(p, idx + 1));
  });

  if (bounds.length === 1) {
    map.setView(bounds[0], 16);
  } else {
    map.fitBounds(bounds, { padding: [28, 28], maxZoom: 15 });
  }

  desenharRotaOsrm(coords);

  function desenharRotaOsrm(coordsLatLng) {
    var fallbackDistance = distanciaTotalKm(coordsLatLng);
    var osrmCoords = coordsLatLng.map(function (c) {
      return c[1] + ',' + c[0];
    }).join(';');
    var url = 'https://router.project-osrm.org/route/v1/driving/' + osrmCoords + '?overview=full&geometries=geojson';

    fetch(url)
      .then(function (res) {
        if (!res.ok) throw new Error('OSRM indisponivel');
        return res.json();
      })
      .then(function (json) {
        var route = json && json.routes && json.routes[0];
        if (!route || !route.geometry) throw new Error('Rota nao encontrada');
        var latLngs = route.geometry.coordinates.map(function (c) {
          return [c[1], c[0]];
        });
        L.polyline(latLngs, { color: '#2563eb', weight: 5, opacity: 0.88 }).addTo(map);
        if (distanceChip) {
          distanceChip.textContent = 'Distância: ' + (Number(route.distance || 0) / 1000).toFixed(1).replace('.', ',') + ' km';
        }
      })
      .catch(function () {
        L.polyline(coordsLatLng, { color: '#2563eb', weight: 4, opacity: 0.7, dashArray: '8 8' }).addTo(map);
        if (distanceChip) {
          distanceChip.textContent = 'Distância aprox.: ' + fallbackDistance.toFixed(1).replace('.', ',') + ' km';
        }
      });
  }

  function ordenarPorVizinhoMaisProximo(start, list) {
    var rest = list.slice();
    var current = start;
    var out = [];

    while (rest.length > 0) {
      var bestIndex = 0;
      var bestDistance = Infinity;
      rest.forEach(function (p, idx) {
        var d = distanciaKm(current[0], current[1], Number(p.lat), Number(p.lng));
        if (d < bestDistance) {
          bestDistance = d;
          bestIndex = idx;
        }
      });
      var next = rest.splice(bestIndex, 1)[0];
      out.push(next);
      current = [Number(next.lat), Number(next.lng)];
    }

    return out;
  }

  function distanciaTotalKm(coordsLatLng) {
    var total = 0;
    for (var i = 1; i < coordsLatLng.length; i++) {
      total += distanciaKm(coordsLatLng[i - 1][0], coordsLatLng[i - 1][1], coordsLatLng[i][0], coordsLatLng[i][1]);
    }
    return total;
  }

  function distanciaKm(lat1, lng1, lat2, lng2) {
    var earth = 6371;
    var dLat = toRad(lat2 - lat1);
    var dLng = toRad(lng2 - lng1);
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
      Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return earth * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
  }

  function toRad(n) {
    return n * Math.PI / 180;
  }

  function buildPopup(p, ordem) {
    return '<strong>' + ordem + '. Poste ' + escapeHtml(p.codigo_poste || '') + '</strong>' +
      (p.bairro ? '<br><small>' + escapeHtml(p.bairro) + '</small>' : '') +
      (p.endereco_completo ? '<br><small>' + escapeHtml(p.endereco_completo) + '</small>' : '') +
      '<br><strong>' + Number(p.chamados_abertos || 0) + '</strong> chamado(s) aberto(s)' +
      '<br><a href="chamados.php?q=' + encodeURIComponent(p.codigo_poste || '') + '">Ver chamados</a>';
  }

  function escapeHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }
})();

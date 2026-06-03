/**
 * HTML do popup Leaflet para pontos de iluminação pública.
 */
(function (global) {
  'use strict';

  var OPEN_STATUSES = ['Aberto', 'Em andamento', 'Aguardando Aprovação'];
  var PRIORITY_RANK = { Urgente: 4, Alta: 3, Normal: 2, Baixa: 1 };

  function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    var d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  function escapeAttr(s) {
    return escapeHtml(s).replace(/"/g, '&quot;');
  }

  function isChamadoAberto(status) {
    return OPEN_STATUSES.indexOf(String(status || '')) !== -1;
  }

  function prioridadeRank(prioridade) {
    return PRIORITY_RANK[String(prioridade || '')] || 0;
  }

  function limitHistorico(chamados, max) {
    max = max || 3;
    if (!Array.isArray(chamados)) return [];
    return chamados.slice(0, max);
  }

  /**
   * @param {object} ponto
   * @returns {{ kind: string, label: string, className: string, prioridadeCritica: string }}
   */
  function calcularStatusVisual(ponto) {
    var abertos = Number(ponto && ponto.chamados_abertos) || 0;
    var historico = limitHistorico(ponto && ponto.chamados_historico, 10);
    var maxPri = '';
    var maxRank = 0;

    historico.forEach(function (ch) {
      if (!isChamadoAberto(ch.status)) return;
      var rank = prioridadeRank(ch.prioridade);
      if (rank > maxRank) {
        maxRank = rank;
        maxPri = String(ch.prioridade || '');
      }
    });

    if (abertos <= 0) {
      return {
        kind: 'ok',
        label: 'Sem pendências',
        className: 'map-popup__status--ok',
        prioridadeCritica: '',
      };
    }
    if (maxRank >= prioridadeRank('Alta')) {
      return {
        kind: 'danger',
        label: 'Atenção prioritária',
        className: 'map-popup__status--danger',
        prioridadeCritica: maxPri,
      };
    }
    return {
      kind: 'warning',
      label: 'Em atenção',
      className: 'map-popup__status--warning',
      prioridadeCritica: maxPri,
    };
  }

  function prioridadeCardClass(prioridade) {
    var p = String(prioridade || '');
    if (p === 'Urgente') return 'map-popup__summary-card--danger';
    if (p === 'Alta') return 'map-popup__summary-card--warning';
    return '';
  }

  function buildStreetViewUrl(p) {
    var lat = Number(p && p.lat);
    var lng = Number(p && p.lng);
    var endereco = String((p && p.endereco_completo) || '').trim();
    var base = 'https://www.google.com/maps/@?api=1&map_action=pano';
    if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
      return (
        base +
        '&viewpoint=' +
        encodeURIComponent(lat + ',' + lng) +
        (endereco ? '&query=' + encodeURIComponent(endereco) : '')
      );
    }
    if (endereco) {
      return 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(endereco);
    }
    return 'https://www.google.com/maps';
  }

  function renderHistorico(chamados) {
    var list = limitHistorico(chamados, 3);
    if (!list.length) {
      return (
        '<div class="map-popup__history">' +
        '<div class="map-popup__section-title">Últimos chamados</div>' +
        '<p class="map-popup__empty">Nenhum chamado vinculado a este poste.</p>' +
        '</div>'
      );
    }

    var html = '<div class="map-popup__history"><div class="map-popup__section-title">Últimos chamados</div>';
    list.forEach(function (ch) {
      var pri = String(ch.prioridade || '');
      var dotClass = 'map-popup__call-dot';
      if (pri === 'Urgente' || pri === 'Alta') {
        dotClass += ' map-popup__call-dot--danger';
      } else if (isChamadoAberto(ch.status)) {
        dotClass += ' map-popup__call-dot--active';
      }

      html +=
        '<a class="map-popup__call" href="chamado_detalhe.php?id=' +
        encodeURIComponent(String(ch.id || '')) +
        '">' +
        '<span class="' +
        dotClass +
        '" aria-hidden="true"></span>' +
        '<div class="map-popup__call-content">' +
        '<strong>#' +
        escapeHtml(String(ch.id || '')) +
        ' · ' +
        escapeHtml(ch.status || '') +
        '</strong>' +
        '<small>' +
        escapeHtml(ch.data || '') +
        (pri ? ' · ' + escapeHtml(pri) : '') +
        '</small>';
      if (ch.titulo) {
        html += '<span class="map-popup__call-detail">' + escapeHtml(ch.titulo) + '</span>';
      }
      html += '</div></a>';
    });
    html += '</div>';
    return html;
  }

  /**
   * @param {object} ponto
   * @param {{ actions?: 'full'|'minimal'|'none' }} [options]
   */
  function renderPontoIluminacaoPopup(ponto, options) {
    options = options || {};
    var actionsMode = options.actions || 'full';
    var codigo = String((ponto && ponto.codigo_poste) || '');
    var statusVis = calcularStatusVisual(ponto);
    var abertos = Number((ponto && ponto.chamados_abertos) || 0);
    var cliente = escapeHtml((ponto && ponto.cliente) || '');
    var bairro = (ponto && ponto.bairro) ? escapeHtml(ponto.bairro) : '';
    var meta = cliente + (cliente && bairro ? ' · ' : '') + bairro;
    var streetViewUrl = buildStreetViewUrl(ponto);
    var plural = abertos === 1 ? 'Chamado aberto' : 'Chamados abertos';

    var html = '<div class="map-popup map-popup--' + escapeHtml(statusVis.kind) + '">';
    html +=
      '<div class="map-popup__status ' +
      statusVis.className +
      '">' +
      escapeHtml(statusVis.label) +
      '</div>';

    html += '<div class="map-popup__body">';

    html += '<div class="map-popup__header"><div class="map-popup__title-area">';
    html += '<h3 class="map-popup__title">Poste ' + escapeHtml(codigo) + '</h3>';
    if (meta) {
      html += '<p class="map-popup__meta">' + meta + '</p>';
    }
    html += '</div>';

    if (ponto && ponto.foto_url) {
      html +=
        '<img class="map-popup__photo" src="' +
        escapeAttr(ponto.foto_url) +
        '" alt="Foto do poste ' +
        escapeAttr(codigo) +
        '">';
    } else {
      html += '<div class="map-popup__photo map-popup__photo--empty" role="img" aria-label="Sem foto cadastrada">Sem foto</div>';
    }
    html += '</div>';

    if (ponto && ponto.endereco_completo) {
      html +=
        '<div class="map-popup__address"><span class="map-popup__address-icon" aria-hidden="true"></span><span>' +
        escapeHtml(ponto.endereco_completo) +
        '</span></div>';
    }

    html += '<div class="map-popup__summary">';
    html +=
      '<div class="map-popup__summary-card"><strong>' +
      abertos +
      '</strong><span>' +
      escapeHtml(plural) +
      '</span></div>';
    if (statusVis.prioridadeCritica && abertos > 0) {
      html +=
        '<div class="map-popup__summary-card ' +
        prioridadeCardClass(statusVis.prioridadeCritica) +
        '"><strong>' +
        escapeHtml(statusVis.prioridadeCritica) +
        '</strong><span>Prioridade</span></div>';
    }
    html += '</div>';

    html += renderHistorico(ponto && ponto.chamados_historico);

    html += '</div>';

    if (actionsMode !== 'none') {
      html += '<div class="map-popup__actions">';
      if (actionsMode === 'full') {
        html +=
          '<a class="map-popup__button map-popup__button--primary" href="chamado_novo.php?ponto_iluminacao_id=' +
          encodeURIComponent(String((ponto && ponto.id) || '')) +
          '">Abrir chamado</a>';
      }
      html +=
        '<a class="map-popup__button map-popup__button--secondary" href="chamados.php?q=' +
        encodeURIComponent(codigo) +
        '">Ver chamados</a>';
      if (actionsMode === 'full') {
        html +=
          '<a class="map-popup__button map-popup__button--ghost" href="' +
          escapeAttr(streetViewUrl) +
          '" target="_blank" rel="noopener noreferrer">Street View</a>';
      }
      html += '</div>';
    }

    html += '</div>';
    return html;
  }

  global.CrmPontoIluminacaoPopup = {
    render: renderPontoIluminacaoPopup,
    calcularStatusVisual: calcularStatusVisual,
    limitHistorico: limitHistorico,
    buildStreetViewUrl: buildStreetViewUrl,
    escapeHtml: escapeHtml,
    escapeAttr: escapeAttr,
  };
  global.renderPontoIluminacaoPopup = renderPontoIluminacaoPopup;
})(typeof window !== 'undefined' ? window : this);

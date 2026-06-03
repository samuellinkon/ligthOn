/**
 * HTML do popup Leaflet para pins de chamado no mapa.
 */
(function (global) {
  'use strict';

  var SUCCESS_STATUSES = ['Resolvido', 'Fechado', 'Validado'];
  var WARNING_STATUSES = ['Em andamento', 'Aguardando Aprovação'];

  function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    var d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  function escapeAttr(s) {
    return escapeHtml(s).replace(/"/g, '&quot;');
  }

  function formatDataBr(data) {
    var raw = String(data || '').trim();
    if (!raw) return '—';
    var m = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2}))?/);
    if (!m) return raw;
    var out = m[3] + '/' + m[2] + '/' + m[1];
    if (m[4] && m[5]) {
      out += ' ' + m[4] + ':' + m[5];
    }
    return out;
  }

  function getChamadoSubtitle(ch) {
    var problema = String((ch && ch.problema_os) || '').trim();
    var origem = String((ch && ch.origem_os) || '').trim();
    if (problema && origem) return problema + ' · ' + origem;
    if (problema) return problema;
    if (origem) return origem;
    return String((ch && ch.titulo) || '').trim();
  }

  function getChamadoStatusLabel(ch) {
    var st = String((ch && ch.status) || '').trim();
    return st || '—';
  }

  function getChamadoPriorityLabel(ch) {
    var pri = String((ch && ch.prioridade) || '').trim();
    if (pri === 'Urgente' || pri === 'Alta' || pri === 'Normal' || pri === 'Baixa') {
      return pri;
    }
    return 'Atenção';
  }

  function priorityPillClass(ch) {
    var pri = String((ch && ch.prioridade) || '').trim();
    if (pri === 'Urgente') return 'call-popup__priority--danger';
    if (pri === 'Alta') return 'call-popup__priority--warning';
    if (pri === 'Normal') return 'call-popup__priority--neutral';
    if (pri === 'Baixa') return 'call-popup__priority--ok';
    return 'call-popup__priority--neutral';
  }

  function getChamadoPopupVariant(ch) {
    var pri = String((ch && ch.prioridade) || '').trim();
    var st = String((ch && ch.status) || '').trim();
    if (st === 'Cancelado' || st === 'Cancelada') return 'cancelled';
    if (SUCCESS_STATUSES.indexOf(st) !== -1) return 'success';
    if (pri === 'Urgente' || pri === 'Alta') return 'danger';
    if (WARNING_STATUSES.indexOf(st) !== -1) return 'warning';
    return 'neutral';
  }

  function buildStreetViewUrl(ch) {
    var lat = Number(ch && ch.lat);
    var lng = Number(ch && ch.lng);
    var endereco = String((ch && ch.endereco_completo) || '').trim();
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

  function servicoLabel(ch) {
    var problema = String((ch && ch.problema_os) || '').trim();
    if (problema) return problema;
    return String((ch && ch.titulo) || '').trim();
  }

  /**
   * @param {object} ch
   * @param {{ showStreetView?: boolean }} [options]
   */
  function renderChamadoPopup(ch, options) {
    options = options || {};
    var showStreetView = options.showStreetView !== false;
    var variant = getChamadoPopupVariant(ch);
    var id = String((ch && ch.id) || '');
    var subtitle = getChamadoSubtitle(ch);
    var statusLabel = getChamadoStatusLabel(ch);
    var priorityLabel = getChamadoPriorityLabel(ch);
    var priPill = priorityPillClass(ch);
    var dataFmt = formatDataBr(ch && ch.data);
    var prioridade = String((ch && ch.prioridade) || '').trim();
    var servico = servicoLabel(ch);
    var endereco = String((ch && ch.endereco_completo) || '').trim();
    var fotoUrl = String((ch && ch.foto_url) || '').trim();
    var streetViewUrl = buildStreetViewUrl(ch);

    var html = '<div class="call-popup call-popup--' + variant + '">';
    html +=
      '<div class="call-popup__status">' + escapeHtml(statusLabel) + '</div>';
    html += '<div class="call-popup__body">';

    html += '<div class="call-popup__header">';
    html += '<div class="call-popup__header-main">';
    html += '<h3 class="call-popup__title">#' + escapeHtml(id) + '</h3>';
    if (subtitle) {
      html += '<p class="call-popup__subtitle">' + escapeHtml(subtitle) + '</p>';
    }
    html += '</div>';
    html +=
      '<span class="call-popup__priority ' +
      priPill +
      '">' +
      escapeHtml(priorityLabel) +
      '</span>';
    html += '</div>';
    if (fotoUrl) {
      html +=
        '<img class="call-popup__photo" src="' +
        escapeAttr(fotoUrl) +
        '" alt="Anexo do chamado #' +
        escapeAttr(id) +
        '">';
    }

    html += '<div class="call-popup__info">';
    html +=
      '<div class="call-popup__info-item"><span>Abertura</span><strong>' +
      escapeHtml(dataFmt) +
      '</strong></div>';
    if (prioridade) {
      html +=
        '<div class="call-popup__info-item"><span>Prioridade</span><strong>' +
        escapeHtml(prioridade) +
        '</strong></div>';
    }
    if (servico) {
      html +=
        '<div class="call-popup__info-item call-popup__info-item--full"><span>Serviço</span><strong>' +
        escapeHtml(servico) +
        '</strong></div>';
    }
    html += '</div>';

    if (endereco) {
      html +=
        '<div class="call-popup__address"><span class="call-popup__address-icon" aria-hidden="true"></span><span>' +
        escapeHtml(endereco) +
        '</span></div>';
    }

    html += '</div>';

    html += '<div class="call-popup__actions">';
    html +=
      '<a class="call-popup__button call-popup__button--primary" href="chamado_detalhe.php?id=' +
      encodeURIComponent(id) +
      '">Abrir chamado</a>';
    if (showStreetView && (endereco || (!Number.isNaN(Number(ch && ch.lat)) && !Number.isNaN(Number(ch && ch.lng))))) {
      html +=
        '<a class="call-popup__button call-popup__button--ghost" href="' +
        escapeAttr(streetViewUrl) +
        '" target="_blank" rel="noopener noreferrer">Street View</a>';
    }
    html += '</div></div>';

    return html;
  }

  global.CrmChamadoPopup = {
    render: renderChamadoPopup,
    getChamadoPopupVariant: getChamadoPopupVariant,
    getChamadoStatusLabel: getChamadoStatusLabel,
    getChamadoPriorityLabel: getChamadoPriorityLabel,
    getChamadoSubtitle: getChamadoSubtitle,
    formatDataBr: formatDataBr,
    buildStreetViewUrl: buildStreetViewUrl,
    escapeHtml: escapeHtml,
    escapeAttr: escapeAttr,
  };
  global.renderChamadoPopup = renderChamadoPopup;
})(typeof window !== 'undefined' ? window : this);

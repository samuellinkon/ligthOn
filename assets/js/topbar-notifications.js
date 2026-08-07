/**
 * Topbar — notificações agrupadas, textos claros e filtros no dropdown.
 */
(function (global) {
  'use strict';

  var DROPDOWN_LIMIT = 10;
  var RAW_FETCH_LIMIT = 40;

  function escapeHtml(s) {
    if (s === null || s === undefined) return '';
    var d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  function extractChamadoId(n) {
    if (n && n.chamado_id) return parseInt(n.chamado_id, 10) || 0;
    var link = String((n && n.link) || '');
    var m = link.match(/[?&]id=(\d+)/);
    if (m) return parseInt(m[1], 10) || 0;
    var t = String((n && n.titulo) || '');
    m = t.match(/#(\d+)/);
    return m ? parseInt(m[1], 10) || 0 : 0;
  }

  function getNotificationType(n) {
    var tipo = String((n && n.tipo) || '').toLowerCase();
    var titulo = String((n && n.titulo) || '').toLowerCase();

    if (tipo === 'chamado_criado' || /^novo chamado\s*#/.test(titulo)) {
      return 'created';
    }
    if (
      tipo === 'chamado_tecnico_atribuido' ||
      titulo.indexOf('atribuído a você') !== -1 ||
      titulo.indexOf('atribuido a você') !== -1 ||
      titulo.indexOf('atribuído a voce') !== -1 ||
      titulo.indexOf('atribuido a voce') !== -1
    ) {
      return 'assigned';
    }
    if (
      tipo === 'chamado_finalizado_tecnico' ||
      tipo === 'chamado_em_atendimento' ||
      titulo.indexOf('finalizado pelo técnico') !== -1 ||
      titulo.indexOf('finalizado pelo tecnico') !== -1 ||
      titulo.indexOf('em atendimento') !== -1 ||
      titulo.indexOf('técnico designado') !== -1 ||
      titulo.indexOf('tecnico designado') !== -1
    ) {
      return 'finalized';
    }
    if (
      tipo === 'chamado_aprovado_gestor' ||
      titulo.indexOf('aprovado pelo gestor') !== -1 ||
      (titulo.indexOf('foi resolvido') !== -1 && titulo.indexOf('validado') === -1)
    ) {
      return 'approved';
    }
    if (
      tipo === 'chamado_validado_cliente' ||
      titulo.indexOf('validado pelo cliente') !== -1 ||
      titulo.indexOf('aprovado pelo cliente') !== -1 ||
      titulo.indexOf('validado') !== -1
    ) {
      return 'validated';
    }
    if (tipo === 'chamado_reaberto' || titulo.indexOf('reaberto') !== -1) {
      return 'reopened';
    }
    if (tipo === 'chamado_mensagem' || titulo.indexOf('mensagem') !== -1) {
      return 'message';
    }
    if (tipo === 'medicao_bm_importado' || titulo.indexOf('importação bm') !== -1 || titulo.indexOf('importacao bm') !== -1) {
      return 'bm';
    }
    if (tipo === 'medicao_custo_pendente' || titulo.indexOf('custo adicional') !== -1 || titulo.indexOf('custo pendente') !== -1) {
      return 'custo';
    }
    if (tipo === 'chamado_status' || tipo === 'chamado_item_devolutivo') {
      return 'info';
    }
    return 'info';
  }

  function getNotificationIcon(type) {
    var icons = {
      created: '✚',
      assigned: '👤',
      finalized: '⏳',
      approved: '✓',
      validated: '★',
      message: '💬',
      reopened: '↺',
      bm: '📊',
      custo: '💰',
      info: 'ℹ',
    };
    return icons[type] || icons.info;
  }

  function extractStatusFromTitle(titulo) {
    var m = String(titulo || '').match(/Chamado\s*#\d+\s*:\s*(.+)/i);
    if (m) return m[1].trim();
    m = String(titulo || '').match(/alterado para\s+(.+)/i);
    if (m) return m[1].trim();
    return '';
  }

  function formatNotificationTitle(n) {
    var titulo = String((n && n.titulo) || '').trim();
    var cid = extractChamadoId(n);
    var idPart = cid > 0 ? '#' + cid : '';
    var type = getNotificationType(n);

    if (type === 'created') return 'Novo chamado ' + idPart;
    if (type === 'assigned') return 'Chamado ' + idPart + ' atribuído a você';
    if (type === 'finalized') {
      var tipoRaw = String((n && n.tipo) || '').toLowerCase();
      if (titulo.toLowerCase().indexOf('em atendimento') !== -1 || tipoRaw === 'chamado_em_atendimento') {
        return 'Chamado ' + idPart + ' em atendimento';
      }
      return 'Chamado ' + idPart + ' finalizado pelo técnico';
    }
    if (type === 'approved') return 'Chamado ' + idPart + ' aprovado pelo gestor';
    if (type === 'validated') return 'Chamado ' + idPart + ' validado pelo cliente';
    if (type === 'reopened') return 'Chamado ' + idPart + ' reaberto pelo cliente';
    if (type === 'message') return 'Nova mensagem no chamado ' + idPart;
    if (/nova importação bm/i.test(titulo) || /nova importacao bm/i.test(titulo)) {
      return titulo;
    }
    return titulo || 'Notificação';
  }

  function formatNotificationDescription(n) {
    var desc = String((n && n.descricao) || '').trim();
    if (desc) return desc;

    var type = getNotificationType(n);
    if (type === 'created') {
      return 'Um novo chamado foi aberto. Abra para analisar e encaminhar.';
    }
    if (type === 'assigned') {
      return 'Um chamado foi atribuído a você. Abra para iniciar o atendimento.';
    }
    if (type === 'finalized') {
      return 'O técnico finalizou o atendimento. O chamado aguarda aprovação do gestor.';
    }
    if (type === 'approved') {
      return 'O gestor aprovou o atendimento. O chamado aguarda validação do cliente.';
    }
    if (type === 'validated') {
      return 'O cliente validou o chamado.';
    }
    if (type === 'reopened') {
      return 'O chamado voltou ao status Aberto para novo atendimento.';
    }
    if (type === 'message') {
      return 'Há uma nova mensagem neste chamado. Abra para ler e responder.';
    }
    if (type === 'bm') {
      return 'Uma nova medição BM foi importada. Abra o mês para conferir os dados.';
    }
    if (type === 'custo') {
      return 'Há um custo pendente de aprovação na medição.';
    }
    return 'Atualização relacionada a este chamado.';
  }

  function getEventLabel(n) {
    var type = getNotificationType(n);
    if (type === 'created') return 'Criação';
    if (type === 'assigned') return 'Atribuído';
    if (type === 'finalized') return 'Finalizado pelo técnico';
    if (type === 'approved') return 'Aprovado pelo gestor';
    if (type === 'validated') return 'Validado pelo cliente';
    if (type === 'reopened') return 'Reaberto';
    if (type === 'message') return 'Mensagem';
    if (type === 'bm') return 'Importação BM';
    if (type === 'custo') return 'Custo pendente';
    return 'Atualização';
  }

  function parseDateInput(raw) {
    var s = String(raw || '').trim();
    if (!s) return null;
    var m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?/);
    if (m) {
      return new Date(
        parseInt(m[1], 10),
        parseInt(m[2], 10) - 1,
        parseInt(m[3], 10),
        parseInt(m[4] || '0', 10),
        parseInt(m[5] || '0', 10),
        parseInt(m[6] || '0', 10)
      );
    }
    var d = new Date(s);
    return Number.isNaN(d.getTime()) ? null : d;
  }

  function formatRelativeDate(raw) {
    var d = parseDateInput(raw);
    if (!d) return String(raw || '');
    var now = new Date();
    var diffMs = now.getTime() - d.getTime();
    if (diffMs < 0) diffMs = 0;
    var sec = Math.floor(diffMs / 1000);
    if (sec < 45) return 'agora há pouco';
    if (sec < 90) return 'há 1 minuto';
    var min = Math.floor(sec / 60);
    if (min < 60) return 'há ' + min + (min === 1 ? ' minuto' : ' minutos');
    var hrs = Math.floor(min / 60);
    if (hrs < 24) return 'há ' + hrs + (hrs === 1 ? ' hora' : ' horas');
    var pad = function (n) {
      return (n < 10 ? '0' : '') + n;
    };
    var timeStr = pad(d.getHours()) + ':' + pad(d.getMinutes());
    var startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var startThat = new Date(d.getFullYear(), d.getMonth(), d.getDate());
    var dayDiff = Math.round((startToday - startThat) / 86400000);
    if (dayDiff === 0) return 'hoje às ' + timeStr;
    if (dayDiff === 1) return 'ontem às ' + timeStr;
    if (dayDiff < 7) return 'há ' + dayDiff + ' dias';
    return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear() + ' às ' + timeStr;
  }

  function notificationSortKey(n) {
    var d = parseDateInput(n.data_criacao);
    return d ? d.getTime() : 0;
  }

  function resolveNotificationHref(n) {
    var link = n && n.link ? String(n.link).trim() : '';
    if (link && link !== '#') return link;
    var cid = extractChamadoId(n);
    return cid > 0 ? 'chamado_detalhe.php?id=' + cid : '#';
  }

  function filterTypeKey(filter) {
    if (filter === 'aberto') return 'created';
    if (filter === 'atendido_tecnico') return 'finalized';
    if (filter === 'validado') return 'validated';
    if (filter === 'aprovado') return 'approved';
    return '';
  }

  function filterNotifications(items, filter) {
    if (!filter || filter === 'all') return items;
    var typeKey = filterTypeKey(filter);
    if (!typeKey) return items;
    return items.filter(function (n) {
      return getNotificationType(n) === typeKey;
    });
  }

  /**
   * Cada notificação vira um item separado (sem agrupar por chamado),
   * para exibir criação, resolvido, aprovação etc. individualmente.
   */
  function groupNotificationsByChamado(notifications) {
    return notifications
      .slice()
      .sort(function (a, b) {
        return notificationSortKey(b) - notificationSortKey(a);
      })
      .map(function (n) {
        return { kind: 'single', item: n };
      });
  }

  function buildGroupedTitle(g) {
    return 'Chamado #' + g.chamado_id;
  }

  function buildGroupedEventsLine(items) {
    var seen = {};
    var labels = [];
    items.forEach(function (n) {
      var lbl = getEventLabel(n);
      if (!seen[lbl]) {
        seen[lbl] = true;
        labels.push(lbl);
      }
    });
    // Validado sucede Resolvido — não mostrar os dois no mesmo agrupamento.
    if (seen['Validado'] && seen['Resolvido']) {
      labels = labels.filter(function (lbl) {
        return lbl !== 'Resolvido';
      });
    }
    return labels.join(' · ');
  }

  function buildGroupedDescription(items) {
    var latest = items[0];
    return formatNotificationDescription(latest);
  }

  function buildStatusMeta(items) {
    for (var i = 0; i < items.length; i++) {
      var st = extractStatusFromTitle(items[i].titulo);
      if (st) return st;
    }
    return '';
  }

  function isUnread(n) {
    return parseInt(n && n.lida, 10) === 0;
  }

  function anyUnreadInGroup(g) {
    if (g.kind === 'single') return isUnread(g.item);
    return g.items.some(isUnread);
  }

  function collectIds(g) {
    var ids =
      g.kind === 'single'
        ? [g.item.id]
        : g.items.map(function (n) {
            return n.id;
          });
    if (g.suppressed_ids && g.suppressed_ids.length) {
      ids = ids.concat(g.suppressed_ids);
    }
    return ids;
  }

  function renderEntry(g, filterActive) {
    var isGroup = g.kind === 'group';
    var latest = isGroup ? g.items[0] : g.item;
    var type = isGroup ? getNotificationType(latest) : getNotificationType(g.item);
    var unread = anyUnreadInGroup(g);
    var href = isGroup
      ? 'chamado_detalhe.php?id=' + g.chamado_id
      : resolveNotificationHref(g.item);
    var ids = collectIds(g);

    var title = isGroup ? buildGroupedTitle(g) : formatNotificationTitle(g.item);
    var desc = isGroup ? buildGroupedDescription(g.items) : formatNotificationDescription(g.item);
    var relDate = formatRelativeDate(latest.data_criacao);
    var statusMeta = isGroup ? buildStatusMeta(g.items) : extractStatusFromTitle(g.item.titulo);
    var eventsLine = isGroup ? buildGroupedEventsLine(g.items) : '';

    var li = document.createElement('li');
    li.className =
      'topbar-notif-item notification--' +
      type +
      (isGroup ? ' notification--grouped' : '');

    var a = document.createElement('a');
    a.className = 'topbar-notif-link' + (unread ? ' is-unread' : '');
    a.href = href;
    a.setAttribute('role', 'menuitem');
    a.dataset.nids = ids.join(',');

    var iconSpan = document.createElement('span');
    iconSpan.className = 'topbar-notif-type-icon';
    iconSpan.setAttribute('aria-hidden', 'true');
    iconSpan.textContent = getNotificationIcon(type);

    var content = document.createElement('div');
    content.className = 'topbar-notif-content';

    var row = document.createElement('div');
    row.className = 'topbar-notif-row';

    var strong = document.createElement('strong');
    strong.className = 'topbar-notif-title';
    strong.textContent = title;
    row.appendChild(strong);

    if (unread) {
      var badge = document.createElement('span');
      badge.className = 'topbar-notif-unread-badge';
      var unreadN = isGroup
        ? g.items.filter(isUnread).length
        : isUnread(g.item)
          ? 1
          : 0;
      badge.textContent =
        isGroup && unreadN > 1 ? unreadN + ' novas' : 'Não lida';
      row.appendChild(badge);
    }

    content.appendChild(row);

    if (isGroup && eventsLine) {
      var events = document.createElement('p');
      events.className = 'topbar-notif-events';
      events.textContent = eventsLine;
      content.appendChild(events);
    }

    var pDesc = document.createElement('p');
    pDesc.className = 'topbar-notif-desc';
    pDesc.textContent = desc;
    content.appendChild(pDesc);

    var metaParts = [relDate];
    if (statusMeta) metaParts.push(statusMeta);
    var meta = document.createElement('div');
    meta.className = 'topbar-notif-meta';
    meta.textContent = metaParts.join(' · ');
    content.appendChild(meta);

    a.appendChild(iconSpan);
    a.appendChild(content);
    li.appendChild(a);
    return li;
  }

  function init(opts) {
    opts = opts || {};
    var wrap = document.querySelector('.topbar-notif-wrap[data-notif-api]');
    if (!wrap) return;

    var api = opts.api || wrap.getAttribute('data-notif-api');
    var limit = opts.dropdownLimit || DROPDOWN_LIMIT;
    var btn = document.getElementById('topbarNotifBtn');
    var dd = document.getElementById('topbarNotifDropdown');
    var list = document.getElementById('topbarNotifList');
    var badge = document.getElementById('topbarNotifBadge');
    var markAll = document.getElementById('topbarNotifMarkAll');
    var headCount = document.getElementById('topbarNotifHeadCount');
    var tabs = dd ? dd.querySelectorAll('.topbar-notif-tabs [data-notif-filter]') : [];

    var allItems = [];
    var activeFilter = 'all';

    function setBadge(n) {
      if (!badge) return;
      if (n < 1) {
        badge.hidden = true;
        badge.textContent = '0';
        return;
      }
      badge.hidden = false;
      badge.textContent = n > 99 ? '99+' : String(n);
    }

    function updateHeadCount(unread) {
      if (!headCount) return;
      if (unread < 1) {
        headCount.textContent = 'Nenhuma não lida';
        return;
      }
      headCount.textContent =
        unread + (unread === 1 ? ' não lida' : ' não lidas');
    }

    function readInitialUnreadFromBadge() {
      if (!badge || badge.hidden) return 0;
      var t = String(badge.textContent || '').trim();
      if (t === '99+') return 99;
      var n = parseInt(t, 10);
      return Number.isNaN(n) ? 0 : n;
    }

    function fetchJson(url, optsFetch) {
      return fetch(url, Object.assign({ credentials: 'same-origin' }, optsFetch || {})).then(function (r) {
        return r.json();
      });
    }

    function refreshCount() {
      fetchJson(api + '?action=count')
        .then(function (d) {
          if (d && d.ok) {
            var n = parseInt(d.unread, 10) || 0;
            setBadge(n);
            updateHeadCount(n);
          }
        })
        .catch(function () {});
    }

    function markIdsRead(ids) {
      ids.forEach(function (id) {
        if (!id) return;
        var fd = new FormData();
        fd.append('action', 'read_one');
        fd.append('id', String(id));
        fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
      });
    }

    function renderList() {
      if (!list) return;

      var filtered = filterNotifications(allItems, activeFilter);
      var grouped = groupNotificationsByChamado(filtered);
      var visible = grouped.slice(0, limit);

      list.innerHTML = '';
      if (!visible.length) {
        if (markAll) markAll.hidden = true;
        return;
      }

      var anyUnread = false;
      visible.forEach(function (g) {
        if (anyUnreadInGroup(g)) anyUnread = true;
        list.appendChild(renderEntry(g, activeFilter));
      });

      if (markAll) {
        markAll.hidden = allItems.filter(isUnread).length === 0;
      }

      list.querySelectorAll('.topbar-notif-link').forEach(function (a) {
        a.addEventListener('click', function () {
          var raw = a.dataset.nids || '';
          var ids = raw
            .split(',')
            .map(function (x) {
              return parseInt(x, 10);
            })
            .filter(function (x) {
              return x > 0;
            });
          var unreadIds = ids.filter(function (id) {
            var found = allItems.find(function (it) {
              return it.id === id && isUnread(it);
            });
            return !!found;
          });
          if (unreadIds.length) {
            markIdsRead(unreadIds);
            setTimeout(refreshCount, 400);
          }
        });
      });
    }

    function notifDropdownNarrow() {
      return window.matchMedia('(max-width: 900px)').matches;
    }

    function syncNotifDropdownGeom() {
      if (!dd || !btn || dd.hidden) return;
      if (!notifDropdownNarrow()) {
        dd.style.removeProperty('top');
        dd.style.removeProperty('max-height');
        return;
      }
      var r = btn.getBoundingClientRect();
      var gap = 8;
      var topPx = Math.round(r.bottom + gap);
      dd.style.top = topPx + 'px';
      var bottomPad = 16;
      var room = window.innerHeight - topPx - bottomPad;
      dd.style.maxHeight = Math.max(200, Math.min(480, room)) + 'px';
    }

    function openList() {
      if (!dd) return;
      dd.hidden = false;
      if (btn) btn.setAttribute('aria-expanded', 'true');
      syncNotifDropdownGeom();
      requestAnimationFrame(syncNotifDropdownGeom);
      fetchJson(api + '?action=list&limit=' + RAW_FETCH_LIMIT)
        .then(function (d) {
          if (d && d.ok) {
            allItems = d.items || [];
            renderList();
            refreshCount();
          }
          requestAnimationFrame(syncNotifDropdownGeom);
        })
        .catch(function () {});
    }

    function closeList() {
      if (!dd) return;
      dd.hidden = true;
      if (btn) btn.setAttribute('aria-expanded', 'false');
      dd.style.removeProperty('top');
      dd.style.removeProperty('max-height');
    }

    if (btn && dd) {
      btn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (dd.hidden) openList();
        else closeList();
      });
    }

    document.addEventListener('click', function () {
      closeList();
    });
    wrap.addEventListener('click', function (e) {
      e.stopPropagation();
    });

    tabs.forEach(function (tab) {
      tab.addEventListener('click', function (e) {
        e.preventDefault();
        activeFilter = tab.getAttribute('data-notif-filter') || 'all';
        tabs.forEach(function (t) {
          var on = t === tab;
          t.classList.toggle('is-active', on);
          t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        renderList();
      });
    });

    if (markAll) {
      markAll.addEventListener('click', function (e) {
        e.preventDefault();
        var fd = new FormData();
        fd.append('action', 'read_all');
        fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) {
            return r.json();
          })
          .then(function () {
            allItems.forEach(function (it) {
              it.lida = 1;
            });
            setBadge(0);
            updateHeadCount(0);
            renderList();
          })
          .catch(function () {});
      });
    }

    setInterval(refreshCount, 60000);
    window.addEventListener('resize', syncNotifDropdownGeom);
    updateHeadCount(readInitialUnreadFromBadge());
  }

  global.CrmTopbarNotifications = {
    init: init,
    escapeHtml: escapeHtml,
    extractChamadoId: extractChamadoId,
    getNotificationType: getNotificationType,
    getNotificationIcon: getNotificationIcon,
    formatNotificationTitle: formatNotificationTitle,
    formatNotificationDescription: formatNotificationDescription,
    formatRelativeDate: formatRelativeDate,
    groupNotificationsByChamado: groupNotificationsByChamado,
    filterNotifications: filterNotifications,
    getEventLabel: getEventLabel,
  };
})(typeof window !== 'undefined' ? window : this);

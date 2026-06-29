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
    if (tipo === 'chamado_tecnico_atribuido' || titulo.indexOf('atribuído') !== -1 || titulo.indexOf('atribuido') !== -1) {
      return 'assigned';
    }
    if (titulo.indexOf('validado') !== -1) return 'validated';
    if (titulo.indexOf('resolvido') !== -1) return 'resolved';
    if (titulo.indexOf('urgente') !== -1) return 'urgent';
    if (tipo === 'chamado_mensagem' || titulo.indexOf('mensagem') !== -1) return 'message';
    if (tipo === 'chamado_status' || /chamado\s*#\d+\s*:/.test(titulo)) return 'status';
    if (tipo === 'medicao_bm_importado' || titulo.indexOf('importação bm') !== -1 || titulo.indexOf('importacao bm') !== -1) {
      return 'info';
    }
    return 'info';
  }

  function getNotificationIcon(type) {
    var icons = {
      assigned: '👤',
      resolved: '✓',
      validated: '✓',
      urgent: '!',
      message: '💬',
      status: '↻',
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
    var low = titulo.toLowerCase();

    if (/foi atribuído um chamado ao técnico/i.test(titulo) || /atribuído um chamado/i.test(titulo)) {
      return 'Chamado ' + idPart + ' atribuído a você';
    }
    if (/chamado\s*#\d+\s*:\s*resolvido/i.test(titulo)) {
      return 'Chamado ' + idPart + ' foi resolvido';
    }
    if (/chamado\s*#\d+\s*:\s*validado/i.test(titulo)) {
      return 'Chamado ' + idPart + ' validado';
    }
    if (/chamado\s*#\d+\s*:\s*/i.test(titulo)) {
      var st = extractStatusFromTitle(titulo);
      return st ? 'Chamado ' + idPart + ': ' + st : titulo;
    }
    if (/nova mensagem no chamado/i.test(titulo)) {
      return 'Nova mensagem no chamado ' + idPart;
    }
    if (/nova importação bm/i.test(titulo) || /nova importacao bm/i.test(titulo)) {
      return titulo;
    }
    if (low.indexOf('urgente') !== -1 && cid > 0) {
      return 'Chamado ' + idPart + ' — atenção urgente';
    }
    return titulo || 'Notificação';
  }

  function formatNotificationDescription(n) {
    var desc = String((n && n.descricao) || '').trim();
    if (desc) return desc;

    var type = getNotificationType(n);
    var titulo = String((n && n.titulo) || '').toLowerCase();

    if (type === 'assigned') {
      return 'Você foi definido como responsável técnico por este chamado.';
    }
    if (type === 'resolved') {
      return 'O atendimento foi marcado como resolvido e aguarda validação, se aplicável.';
    }
    if (type === 'validated') {
      return 'O chamado foi conferido e validado pela gestão.';
    }
    if (type === 'message') {
      return 'Há uma nova mensagem neste chamado. Abra para ler e responder.';
    }
    if (String((n && n.tipo) || '') === 'medicao_bm_importado') {
      return 'Uma nova medição BM foi importada. Abra o mês para conferir os dados.';
    }
    if (type === 'urgent') {
      return 'Este chamado requer atenção prioritária.';
    }
    if (type === 'status') {
      var st = extractStatusFromTitle(n.titulo);
      if (st === 'Em andamento') {
        return 'O chamado entrou em atendimento. Acompanhe o progresso.';
      }
      if (st === 'Aguardando Aprovação') {
        return 'O chamado aguarda aprovação da gestão.';
      }
      if (st) {
        return 'O status do chamado foi atualizado para «' + st + '».';
      }
    }
    if (titulo.indexOf('fechado') !== -1) {
      return 'O chamado foi encerrado.';
    }
    return 'Atualização relacionada a este chamado.';
  }

  function getEventLabel(n) {
    var type = getNotificationType(n);
    if (type === 'assigned') return 'Atribuído';
    if (type === 'resolved') return 'Resolvido';
    if (type === 'validated') return 'Validado';
    if (type === 'message') return 'Mensagem';
    if (type === 'urgent') return 'Urgente';
    if (type === 'status') {
      var st = extractStatusFromTitle(n.titulo);
      return st || 'Status';
    }
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

  function isMedicaoNotification(n) {
    var tipo = String((n && n.tipo) || '').toLowerCase();
    return tipo === 'medicao_custo_pendente' || tipo === 'medicao_bm_importado';
  }

  function resolveNotificationHref(n) {
    var link = n && n.link ? String(n.link).trim() : '';
    if (link && link !== '#') return link;
    var cid = extractChamadoId(n);
    return cid > 0 ? 'chamado_detalhe.php?id=' + cid : '#';
  }

  function isChamadoNotification(n) {
    if (isMedicaoNotification(n)) return false;
    var tipo = String((n && n.tipo) || '').toLowerCase();
    if (tipo.indexOf('chamado') === 0) return true;
    return extractChamadoId(n) > 0;
  }

  function isSystemNotification(n) {
    return !isChamadoNotification(n);
  }

  function filterNotifications(items, filter) {
    if (!filter || filter === 'all') return items;
    if (filter === 'unread') return items.filter(isUnread);
    if (filter === 'chamados') {
      return items.filter(isChamadoNotification);
    }
    if (filter === 'sistema') {
      return items.filter(isSystemNotification);
    }
    return items;
  }

  function groupNotificationsByChamado(notifications) {
    var byChamado = {};
    var singles = [];

    notifications.forEach(function (n) {
      if (isMedicaoNotification(n)) {
        singles.push({ kind: 'single', item: n });
        return;
      }
      var cid = extractChamadoId(n);
      if (cid <= 0) {
        singles.push({ kind: 'single', item: n });
        return;
      }
      if (!byChamado[cid]) byChamado[cid] = [];
      byChamado[cid].push(n);
    });

    var groups = [];
    Object.keys(byChamado).forEach(function (key) {
      var cid = parseInt(key, 10);
      var items = byChamado[cid].slice().sort(function (a, b) {
        return notificationSortKey(b) - notificationSortKey(a);
      });
      if (items.length === 1) {
        groups.push({ kind: 'single', item: items[0] });
      } else {
        groups.push({ kind: 'group', chamado_id: cid, items: items });
      }
    });

    var all = groups.concat(singles);
    all.sort(function (a, b) {
      var ta = a.kind === 'group' ? notificationSortKey(a.items[0]) : notificationSortKey(a.item);
      var tb = b.kind === 'group' ? notificationSortKey(b.items[0]) : notificationSortKey(b.item);
      return tb - ta;
    });
    return all;
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
    if (g.kind === 'single') return [g.item.id];
    return g.items.map(function (n) {
      return n.id;
    });
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
        var unreadFiltered = filterNotifications(allItems, 'unread');
        markAll.hidden = unreadFiltered.length === 0;
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
          t.classList.toggle('is-active', t === tab);
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

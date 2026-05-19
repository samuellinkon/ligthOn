<?php
/**
 * Topbar reutilizável.
 * Espera:
 *   $topTitle    - título da tela
 *   $topSubtitle - subtítulo curto
 *   $topAction   - ['label' => '...', 'href' => '...', 'icon' => '+'] (opcional)
 *   $topActions  - lista de ações extras no mesmo grupo (opcional)
 */

if (!isset($topTitle))    $topTitle    = 'Dashboard';
if (!isset($topSubtitle)) $topSubtitle = '';
if (!isset($topAction))   $topAction   = null;
if (!isset($topActions))  $topActions  = [];
/** Se true, não renderiza o bloco .topbar-title (ex.: página com cabeçalho próprio). */
if (!isset($topbarHideTitle)) {
    $topbarHideTitle = false;
}
if (!isset($topbarActionsFirst)) {
    $topbarActionsFirst = false;
}
if (!isset($topbarCompact)) {
    $topbarCompact = false;
}

$painelNotifOk = false;
$notifUnreadInitial = 0;
$notifApiHref       = '';
$notifPaginaHref    = '';
if (function_exists('db_ok') && db_ok()) {
    if (!function_exists('repo_notificacoes_table_exists')) {
        require_once __DIR__ . '/repository.php';
    }
    if (repo_notificacoes_table_exists()) {
        $cu   = function_exists('current_user') ? current_user() : null;
        $perf = (string) ($cu['perfil'] ?? '');
        $uidN = (int) ($cu['id'] ?? 0);
        $bp   = rtrim($basePath ?? '', '/');
        if ($uidN > 0 && (function_exists('current_user_painel_interno') && current_user_painel_interno())) {
            $painelNotifOk = true;
            if (function_exists('repo_notificacoes_count_unread')) {
                $notifUnreadInitial = repo_notificacoes_count_unread($uidN);
            }
            $notifApiHref    = $bp . '/admin/notificacoes_api.php';
            $notifPaginaHref = $bp . '/admin/notificacoes.php';
        } elseif ($uidN > 0 && $perf === 'operador') {
            $painelNotifOk = true;
            if (function_exists('repo_notificacoes_count_unread')) {
                $notifUnreadInitial = repo_notificacoes_count_unread($uidN);
            }
            $notifApiHref    = $bp . '/operador/notificacoes_api.php';
            $notifPaginaHref = $bp . '/operador/notificacoes.php';
        } elseif ($uidN > 0 && $perf === 'cliente') {
            $painelNotifOk = true;
            if (function_exists('repo_notificacoes_count_unread')) {
                $notifUnreadInitial = repo_notificacoes_count_unread($uidN);
            }
            $notifApiHref    = $bp . '/cliente/notificacoes_api.php';
            $notifPaginaHref = $bp . '/cliente/notificacoes.php';
        }
    }
}
?>
<header class="topbar<?= $topbarActionsFirst ? ' topbar--actions-first' : '' ?><?= $topbarCompact ? ' topbar--compact' : '' ?>">
  <div class="topbar-start">
    <button class="hamburger" aria-label="Abrir menu"><span></span></button>
    <?php if (!$topbarHideTitle): ?>
    <div class="topbar-title">
      <h2><?= htmlspecialchars($topTitle) ?></h2>
      <?php if ($topSubtitle): ?>
        <p><?= htmlspecialchars($topSubtitle) ?></p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php if ($topAction || !empty($topActions) || !empty($topbarMinhaContaHref) || $painelNotifOk): ?>
  <div class="top-actions">
    <div class="top-actions-trail">
      <?php if ($painelNotifOk && $notifApiHref !== ''): ?>
      <div class="topbar-notif-wrap" data-notif-api="<?= htmlspecialchars($notifApiHref, ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" class="topbar-notif-btn" aria-expanded="false" aria-haspopup="true" aria-label="Notificações" id="topbarNotifBtn">
          <svg class="topbar-notif-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
          </svg>
          <span class="topbar-notif-badge" id="topbarNotifBadge" <?= $notifUnreadInitial > 0 ? '' : 'hidden' ?>><?= $notifUnreadInitial > 99 ? '99+' : (int) $notifUnreadInitial ?></span>
        </button>
        <div class="topbar-notif-dropdown" id="topbarNotifDropdown" hidden role="menu">
          <div class="topbar-notif-head">
            <span>Notificações</span>
            <button type="button" id="topbarNotifMarkAll" hidden>Marcar todas como lidas</button>
          </div>
          <ul class="topbar-notif-list" id="topbarNotifList"></ul>
          <div class="topbar-notif-empty" id="topbarNotifEmpty" hidden>Nenhuma notificação.</div>
          <?php if ($notifPaginaHref !== ''): ?>
          <div class="topbar-notif-foot">
            <a href="<?= htmlspecialchars($notifPaginaHref) ?>" class="topbar-notif-ver-todas">Ver todas</a>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <script>
      (function () {
        var wrap = document.querySelector('.topbar-notif-wrap[data-notif-api]');
        if (!wrap) return;
        var api = wrap.getAttribute('data-notif-api');
        var btn = document.getElementById('topbarNotifBtn');
        var dd = document.getElementById('topbarNotifDropdown');
        var list = document.getElementById('topbarNotifList');
        var empty = document.getElementById('topbarNotifEmpty');
        var badge = document.getElementById('topbarNotifBadge');
        var markAll = document.getElementById('topbarNotifMarkAll');

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

        function fetchJson(url, opts) {
          return fetch(url, Object.assign({ credentials: 'same-origin' }, opts || {})).then(function (r) {
            return r.json();
          });
        }

        function refreshCount() {
          fetchJson(api + '?action=count').then(function (d) {
            if (d && d.ok) setBadge(parseInt(d.unread, 10) || 0);
          }).catch(function () {});
        }

        function renderItems(items) {
          if (!list || !empty) return;
          list.innerHTML = '';
          if (!items || !items.length) {
            empty.hidden = false;
            if (markAll) markAll.hidden = true;
            return;
          }
          empty.hidden = true;
          var anyUnread = false;
          items.forEach(function (it) {
            if (!it.lida) anyUnread = true;
            var li = document.createElement('li');
            li.className = 'topbar-notif-item';
            var a = document.createElement('a');
            a.className = 'topbar-notif-link' + (it.lida ? '' : ' is-unread');
            a.href = it.link || '#';
            a.setAttribute('role', 'menuitem');
            a.dataset.nid = String(it.id);
            var t = document.createElement('div');
            t.className = 'topbar-notif-title';
            t.textContent = it.titulo || '';
            a.appendChild(t);
            var m = document.createElement('div');
            m.className = 'topbar-notif-meta';
            m.textContent = (it.data_criacao || '') + (it.lida ? '' : ' · não lida');
            a.appendChild(m);
            a.addEventListener('click', function (e) {
              if (!it.lida && it.id) {
                var fd = new FormData();
                fd.append('action', 'read_one');
                fd.append('id', String(it.id));
                fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function () {
                  refreshCount();
                }).catch(function () {});
              }
            });
            li.appendChild(a);
            list.appendChild(li);
          });
          if (markAll) markAll.hidden = !anyUnread;
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
          dd.style.maxHeight = Math.max(160, Math.min(420, room)) + 'px';
        }

        function openList() {
          if (!dd) return;
          dd.hidden = false;
          if (btn) btn.setAttribute('aria-expanded', 'true');
          syncNotifDropdownGeom();
          requestAnimationFrame(syncNotifDropdownGeom);
          fetchJson(api + '?action=list').then(function (d) {
            if (d && d.ok) {
              loaded = true;
              renderItems(d.items || []);
              refreshCount();
            }
            requestAnimationFrame(syncNotifDropdownGeom);
          }).catch(function () {});
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
            if (dd.hidden) {
              openList();
            } else {
              closeList();
            }
          });
        }

        document.addEventListener('click', function () { closeList(); });
        if (wrap) {
          wrap.addEventListener('click', function (e) { e.stopPropagation(); });
        }

        if (markAll) {
          markAll.addEventListener('click', function (e) {
            e.preventDefault();
            var fd = new FormData();
            fd.append('action', 'read_all');
            fetch(api, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
              return r.json();
            }).then(function (d) {
              setBadge(0);
              openList();
            }).catch(function () {});
          });
        }

        setInterval(refreshCount, 60000);
        window.addEventListener('resize', function () {
          syncNotifDropdownGeom();
        });
      })();
      </script>
      <?php endif; ?>
      <?php if ($topAction): ?>
        <a href="<?= htmlspecialchars($topAction['href']) ?>" class="btn btn-primary">
          <?= htmlspecialchars($topAction['icon'] ?? '+') ?> <?= htmlspecialchars($topAction['label']) ?>
        </a>
      <?php endif; ?>
      <?php foreach ((array) $topActions as $action): ?>
        <a href="<?= htmlspecialchars((string) ($action['href'] ?? '#')) ?>" class="btn <?= htmlspecialchars((string) ($action['class'] ?? 'btn-secondary')) ?>">
          <?= htmlspecialchars((string) ($action['icon'] ?? '')) ?> <?= htmlspecialchars((string) ($action['label'] ?? 'Ação')) ?>
        </a>
      <?php endforeach; ?>
      <?php if (!empty($topbarMinhaContaHref)): ?>
        <a href="<?= htmlspecialchars($topbarMinhaContaHref) ?>"
           class="btn btn-secondary topbar-minha-conta<?= !empty($topbarMinhaContaActive) ? ' is-active' : '' ?>"
           title="Minha conta"
           aria-label="Minha conta — nome, e-mail e senha">
          <svg class="topbar-minha-conta__icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
        </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</header>
<?php
if (function_exists('render_flash')) {
    render_flash();
}
?>

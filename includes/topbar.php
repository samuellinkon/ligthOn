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
            <div class="topbar-notif-head-text">
              <strong>Notificações</strong>
              <span class="topbar-notif-head-count" id="topbarNotifHeadCount"></span>
            </div>
            <button type="button" class="topbar-notif-mark-all" id="topbarNotifMarkAll" hidden>Marcar todas como lidas</button>
          </div>
          <div class="topbar-notif-tabs" role="tablist" aria-label="Filtrar notificações">
            <button type="button" class="is-active" data-notif-filter="all" role="tab" aria-selected="true">Todas</button>
            <button type="button" data-notif-filter="unread" role="tab" aria-selected="false">Não lidas</button>
            <button type="button" data-notif-filter="chamados" role="tab" aria-selected="false">Chamados</button>
            <button type="button" data-notif-filter="sistema" role="tab" aria-selected="false">Sistema</button>
          </div>
          <ul class="topbar-notif-list" id="topbarNotifList"></ul>
          <div class="topbar-notif-empty" id="topbarNotifEmpty" hidden>
            <strong>Você está em dia</strong>
            <span>Nenhuma nova notificação no momento.</span>
          </div>
          <?php if ($notifPaginaHref !== ''): ?>
          <div class="topbar-notif-foot">
            <a href="<?= htmlspecialchars($notifPaginaHref) ?>" class="topbar-notif-ver-todas">Ver todas as notificações</a>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php
      $topbarNotifJs = dirname(__DIR__) . '/assets/js/topbar-notifications.js';
      ?>
      <script src="<?= htmlspecialchars($basePath ?? '') ?>assets/js/topbar-notifications.js?v=<?= (int) @filemtime($topbarNotifJs) ?>"></script>
      <script>
      if (window.CrmTopbarNotifications && typeof window.CrmTopbarNotifications.init === 'function') {
        window.CrmTopbarNotifications.init({ dropdownLimit: 10 });
      }
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
if (empty($topbarSkipFlash) && function_exists('render_flash')) {
    render_flash();
}
?>

<?php
/**
 * Listagem completa de notificações (admin, operador, cliente).
 * Espera $NOTIF_PAINEL: uid, sidebar, voltar, self, pageTitle, activePage.
 */
declare(strict_types=1);

if (!isset($NOTIF_PAINEL) || !is_array($NOTIF_PAINEL)) {
    http_response_code(500);
    echo 'Configuração de notificações ausente.';
    exit;
}

require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/flash.php';

$uid        = (int) ($NOTIF_PAINEL['uid'] ?? 0);
$selfFile   = (string) ($NOTIF_PAINEL['self'] ?? 'notificacoes.php');
$voltar     = (string) ($NOTIF_PAINEL['voltar'] ?? 'index.php');
$sidebar    = (string) ($NOTIF_PAINEL['sidebar'] ?? 'sidebar-admin.php');
$pageTitle  = (string) ($NOTIF_PAINEL['pageTitle'] ?? 'Notificações');
$activePage = (string) ($NOTIF_PAINEL['activePage'] ?? '');

if ($uid <= 0) {
    http_response_code(403);
    echo 'Sessão inválida.';
    exit;
}

$filtroRaw = strtolower(trim((string) ($_GET['filtro'] ?? '')));
$filtro    = $filtroRaw === 'unread' ? 'unread' : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = strtolower(trim((string) ($_POST['acao'] ?? '')));
    if ($acao === 'read_all' && db_ok() && repo_notificacoes_table_exists()) {
        repo_notificacoes_marcar_todas_lidas($uid);
        flash_set('ok', 'Todas as notificações foram marcadas como lidas.');
    }
    $postFiltro = strtolower(trim((string) ($_POST['filtro'] ?? '')));
    $redirFiltro = $postFiltro === 'unread' ? 'unread' : null;
    $redir = notif_painel_url($selfFile, max(1, (int) ($_POST['page'] ?? 1)), $redirFiltro);
    header('Location: ' . $redir);
    exit;
}

$abrir = (int) ($_GET['abrir'] ?? 0);
if ($abrir > 0 && db_ok() && repo_notificacoes_table_exists()) {
    $pdo = db();
    $cid = 0;
    if ($pdo) {
        try {
            $st = $pdo->prepare(
                'SELECT chamado_id FROM notificacoes WHERE id = ? AND usuario_id = ? LIMIT 1'
            );
            $st->execute([$abrir, $uid]);
            $cid = (int) ($st->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            $cid = 0;
        }
    }
    repo_notificacao_marcar_lida($abrir, $uid);
    if ($cid > 0) {
        header('Location: chamado_detalhe.php?id=' . $cid);
        exit;
    }
    header('Location: ' . $selfFile);
    exit;
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$total       = 0;
$rows        = [];
$unreadTotal = 0;
$tableOk     = db_ok() && repo_notificacoes_table_exists();

if ($tableOk) {
    $res         = repo_notificacoes_list_paginated($uid, $page, $perPage, $filtro);
    $rows        = $res['rows'];
    $total       = (int) $res['total'];
    $unreadTotal = repo_notificacoes_count_unread($uid);
}

$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    if ($tableOk) {
        $res   = repo_notificacoes_list_paginated($uid, $page, $perPage, $filtro);
        $rows  = $res['rows'];
        $total = (int) $res['total'];
    }
}

$offset = ($page - 1) * $perPage;
$fromN  = $total === 0 ? 0 : $offset + 1;
$toN    = $total === 0 ? 0 : min($offset + count($rows), $total);

function notif_painel_url(string $self, int $p, ?string $filtro): string
{
    $qs = [];
    if ($filtro === 'unread') {
        $qs['filtro'] = 'unread';
    }
    if ($p > 1) {
        $qs['page'] = $p;
    }

    return $self . ($qs ? '?' . http_build_query($qs) : '');
}

$basePath    = '../';
$topTitle    = 'Notificações';
$topSubtitle = $unreadTotal > 0
    ? $unreadTotal . ($unreadTotal === 1 ? ' não lida' : ' não lidas')
    : 'Todas lidas';
$topSearch   = '';
$topAction   = ['label' => 'Voltar', 'href' => $voltar, 'icon' => '←'];

include __DIR__ . '/head.php';
?>
<div class="app">
<?php include __DIR__ . '/' . $sidebar; ?>
<main class="main">
<?php include __DIR__ . '/topbar.php'; ?>

<section class="content notif-page">
  <div class="card">
    <div class="panel-head notif-page__head">
      <div>
        <h4>Todas as notificações</h4>
        <span class="panel-sub">Mensagens e avisos dos seus chamados</span>
      </div>
      <?php if ($tableOk && $unreadTotal > 0): ?>
      <form method="post" action="<?= htmlspecialchars($selfFile) ?>" class="notif-page__mark-all-form">
        <input type="hidden" name="acao" value="read_all">
        <?php if ($filtro === 'unread'): ?><input type="hidden" name="filtro" value="unread"><?php endif; ?>
        <?php if ($page > 1): ?><input type="hidden" name="page" value="<?= (int) $page ?>"><?php endif; ?>
        <button type="submit" class="btn btn-secondary btn-sm">Marcar todas como lidas</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="panel-body">
      <div class="notif-page__filters" role="tablist" aria-label="Filtrar notificações">
        <a href="<?= htmlspecialchars(notif_painel_url($selfFile, 1, null)) ?>"
           class="notif-page__filter<?= $filtro === null ? ' is-active' : '' ?>">Todas</a>
        <a href="<?= htmlspecialchars(notif_painel_url($selfFile, 1, 'unread')) ?>"
           class="notif-page__filter<?= $filtro === 'unread' ? ' is-active' : '' ?>">
          Não lidas<?= $unreadTotal > 0 ? ' (' . (int) $unreadTotal . ')' : '' ?>
        </a>
      </div>

      <?php if (!$tableOk): ?>
        <div class="empty">Notificações indisponíveis (banco ou tabela ausente).</div>
      <?php elseif ($rows === []): ?>
        <div class="empty">
          <strong>Nenhuma notificação</strong>
          <?= $filtro === 'unread' ? ' por ler no momento.' : ' no seu histórico.' ?>
        </div>
      <?php else: ?>
        <p class="notif-page__meta">A mostrar <?= (int) $fromN ?>–<?= (int) $toN ?> de <?= (int) $total ?>.</p>
        <ul class="notif-page__list">
          <?php foreach ($rows as $n):
              $lida = (int) ($n['lida'] ?? 0) === 1;
              $nid  = (int) ($n['id'] ?? 0);
              $href = $nid > 0
                  ? htmlspecialchars($selfFile . '?abrir=' . $nid)
                  : htmlspecialchars((string) ($n['link'] ?? '#'));
              ?>
          <li class="notif-page__item<?= $lida ? '' : ' is-unread' ?>">
            <a class="notif-page__link" href="<?= $href ?>">
              <div class="notif-page__link-main">
                <span class="notif-page__title"><?= htmlspecialchars((string) ($n['titulo'] ?? '')) ?></span>
                <?php if (!$lida): ?>
                  <span class="badge notif-page__badge">Não lida</span>
                <?php endif; ?>
              </div>
              <?php if (!empty($n['descricao'])): ?>
                <p class="notif-page__desc"><?= htmlspecialchars((string) $n['descricao']) ?></p>
              <?php endif; ?>
              <div class="notif-page__meta-line">
                <?= htmlspecialchars((string) ($n['data_criacao'] ?? '')) ?>
                <?php if ((int) ($n['chamado_id'] ?? 0) > 0): ?>
                  · Chamado #<?= (int) $n['chamado_id'] ?>
                <?php endif; ?>
              </div>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>

        <?php if ($totalPages > 1): ?>
        <nav class="pagination notif-page__pagination">
          <?php if ($page > 1): ?>
          <a class="pag-btn" href="<?= htmlspecialchars(notif_painel_url($selfFile, $page - 1, $filtro)) ?>">Anterior</a>
          <?php endif; ?>
          <span class="muted" style="padding:0 12px;">Página <?= (int) $page ?> / <?= (int) $totalPages ?></span>
          <?php if ($page < $totalPages): ?>
          <a class="pag-btn" href="<?= htmlspecialchars(notif_painel_url($selfFile, $page + 1, $filtro)) ?>">Seguinte</a>
          <?php endif; ?>
        </nav>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php include __DIR__ . '/footer.php'; ?>

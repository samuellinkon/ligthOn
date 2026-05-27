<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('suporte');

$escopoSup = gestao_scope_cliente_id($me);

$f = strtolower(trim((string) ($_GET['f'] ?? '')));
if (!in_array($f, ['', 'aberta', 'respondendo', 'pendente', 'respondida'], true)) {
    $f = '';
}
$q = trim((string) ($_GET['q'] ?? ''));
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

$allSuporte = db_ok() ? repo_suporte($escopoSup) : ($MOCK_SUPORTE ?? []);

$cntAberta      = count(array_filter($allSuporte, fn ($s) => ($s['status'] ?? '') === 'Aberta'));
$cntRespondendo = count(array_filter($allSuporte, fn ($s) => ($s['status'] ?? '') === 'Respondendo'));
$cntPendente    = count(array_filter($allSuporte, fn ($s) => ($s['status'] ?? '') === 'Pendente'));
$cntRespondida  = count(array_filter($allSuporte, fn ($s) => ($s['status'] ?? '') === 'Respondida'));

$lista = $allSuporte;
if ($f === 'aberta') {
    $lista = array_values(array_filter($lista, fn ($s) => ($s['status'] ?? '') === 'Aberta'));
} elseif ($f === 'respondendo') {
    $lista = array_values(array_filter($lista, fn ($s) => ($s['status'] ?? '') === 'Respondendo'));
} elseif ($f === 'pendente') {
    $lista = array_values(array_filter($lista, fn ($s) => ($s['status'] ?? '') === 'Pendente'));
} elseif ($f === 'respondida') {
    $lista = array_values(array_filter($lista, fn ($s) => ($s['status'] ?? '') === 'Respondida'));
}

if ($q !== '') {
    if (ctype_digit($q)) {
        $lista = array_values(array_filter($lista, fn ($s) => (int) ($s['id'] ?? 0) === (int) $q));
    } else {
        $ql = mb_strtolower($q);
        $lista = array_values(array_filter($lista, function ($s) use ($ql) {
            $hay = mb_strtolower(
                ($s['pergunta'] ?? '') . ' ' . ($s['detalhe'] ?? '') . ' ' . ($s['cliente'] ?? '')
            );

            return mb_strpos($hay, $ql) !== false;
        }));
    }
}

$totalRows  = count($lista);
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;
$lista      = array_slice($lista, $offset, $perPage);

function adm_suporte_url(int $p, string $filtro, string $busca): string
{
    $qs = [];
    if ($filtro !== '') {
        $qs['f'] = $filtro;
    }
    if ($busca !== '') {
        $qs['q'] = $busca;
    }
    if ($p > 1) {
        $qs['page'] = $p;
    }

    return 'suporte.php' . ($qs ? '?' . http_build_query($qs) : '');
}

$fromN = $totalRows === 0 ? 0 : $offset + 1;
$toN   = $totalRows === 0 ? 0 : min($offset + count($lista), $totalRows);

$pageTitle  = 'Suporte';
$basePath   = '../';
$activePage = 'suporte';

$topTitle    = 'Central de Suporte';
$topSubtitle = 'Dúvidas dos clientes — filtros, busca e resposta na ficha.';
$topSearch   = 'Buscar por ID, pergunta ou órgão...';
$topAction   = null;

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="cards-metrics">
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Abertas</div><div class="metric-value"><?= (int) $cntAberta ?></div></div>
        <div class="icon-box purple">?</div>
      </div>
      <div class="metric-change warning">Aguardando primeira resposta</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Respondendo</div><div class="metric-value"><?= (int) $cntRespondendo ?></div></div>
        <div class="icon-box blue">»</div>
      </div>
      <div class="metric-change info">Em atendimento</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Pendentes</div><div class="metric-value"><?= (int) $cntPendente ?></div></div>
        <div class="icon-box orange">!</div>
      </div>
      <div class="metric-change warning">Precisam de retorno</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Respondidas</div><div class="metric-value"><?= (int) $cntRespondida ?></div></div>
        <div class="icon-box green">OK</div>
      </div>
      <div class="metric-change success">Concluídas</div>
    </div>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Todas as dúvidas</h4>
      <span class="panel-sub"><?= (int) $totalRows ?> registro(s) no filtro</span>
    </div>

    <form method="get" action="suporte.php" class="filters" style="padding:12px 20px;border-bottom:1px solid var(--border);display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
      <?php if ($f !== ''): ?><input type="hidden" name="f" value="<?= htmlspecialchars($f) ?>"><?php endif; ?>
      <div class="form-group" style="margin:0;flex:1;min-width:200px;">
        <label for="q" style="font-size:12px;">Buscar</label>
        <input type="search" id="q" name="q" class="input" value="<?= htmlspecialchars($q) ?>" placeholder="ID, pergunta ou órgão">
      </div>
      <button type="submit" class="btn btn-primary">Buscar</button>
      <?php if ($q !== ''): ?>
      <a href="<?= htmlspecialchars(adm_suporte_url(1, $f, '')) ?>" class="btn">Limpar</a>
      <?php endif; ?>
    </form>

    <div class="filters" style="padding:12px 20px;display:flex;flex-wrap:wrap;gap:8px;">
      <a href="<?= htmlspecialchars(adm_suporte_url(1, '', $q)) ?>" class="chip <?= $f === '' ? 'active' : '' ?>">Todas</a>
      <a href="<?= htmlspecialchars(adm_suporte_url(1, 'aberta', $q)) ?>" class="chip <?= $f === 'aberta' ? 'active' : '' ?>">Abertas</a>
      <a href="<?= htmlspecialchars(adm_suporte_url(1, 'respondendo', $q)) ?>" class="chip <?= $f === 'respondendo' ? 'active' : '' ?>">Respondendo</a>
      <a href="<?= htmlspecialchars(adm_suporte_url(1, 'pendente', $q)) ?>" class="chip <?= $f === 'pendente' ? 'active' : '' ?>">Pendentes</a>
      <a href="<?= htmlspecialchars(adm_suporte_url(1, 'respondida', $q)) ?>" class="chip <?= $f === 'respondida' ? 'active' : '' ?>">Respondidas</a>
    </div>

    <div class="table-wrap">
      <table data-crm-sortable>
        <thead>
          <tr class="crm-table-head-sort">
            <?php crm_sort_th('ID', 'id', ['type' => 'number']); ?>
            <?php crm_sort_th('Pergunta', 'pergunta'); ?>
            <?php crm_sort_th('Prefeitura', 'prefeitura'); ?>
            <?php crm_sort_th('Status', 'status'); ?>
            <?php crm_sort_th('Data', 'data', ['type' => 'date']); ?>
            <?php crm_sort_th('Ações', null, ['class' => 'crm-table-col-acoes', 'right' => true]); ?>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($lista)): ?>
          <tr><td colspan="6" class="muted" style="padding:24px;text-align:center;">Nenhuma dúvida encontrada.</td></tr>
          <?php else: ?>
          <?php foreach ($lista as $s): ?>
          <?php
            $dataSort = (string) ($s['data'] ?? '');
            $dataIso  = $dataSort !== '' ? date('Y-m-d H:i:s', strtotime($dataSort)) : '';
          ?>
          <tr <?= crm_sort_row_attr([
              'id'         => (string) (int) ($s['id'] ?? 0),
              'pergunta'   => (string) ($s['pergunta'] ?? ''),
              'prefeitura' => (string) ($s['cliente'] ?? ''),
              'status'     => (string) ($s['status'] ?? ''),
              'data'       => $dataIso,
          ]) ?>>
            <td class="td-id">#<?= (int) $s['id'] ?></td>
            <td>
              <div class="td-title"><?= htmlspecialchars((string) ($s['pergunta'] ?? '')) ?></div>
              <small class="muted"><?= htmlspecialchars(mb_substr((string) ($s['detalhe'] ?? ''), 0, 72)) ?><?= mb_strlen((string) ($s['detalhe'] ?? '')) > 72 ? '…' : '' ?></small>
            </td>
            <td class="td-mute"><?= htmlspecialchars((string) ($s['cliente'] ?? '')) ?></td>
            <td><span class="badge <?= status_class((string) ($s['status'] ?? '')) ?>"><?= htmlspecialchars((string) ($s['status'] ?? '')) ?></span></td>
            <td class="td-mute"><?= date('d/m/Y H:i', strtotime((string) ($s['data'] ?? 'now'))) ?></td>
            <td class="td-actions">
              <a class="action primary" href="suporte_detalhe.php?id=<?= (int) $s['id'] ?>">Ver / responder</a>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <span class="pag-info">Mostrando <?= (int) $fromN ?>–<?= (int) $toN ?> de <?= (int) $totalRows ?></span>
      <div class="pag-controls">
        <?php if ($page <= 1): ?>
          <span class="pag-btn" style="opacity:.4;">‹</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars(adm_suporte_url($page - 1, $f, $q)) ?>">‹</a>
        <?php endif; ?>
        <?php
        $window = 5;
        $start = max(1, $page - (int) floor($window / 2));
        $end   = min($totalPages, $start + $window - 1);
        $start = max(1, $end - $window + 1);
        for ($pi = $start; $pi <= $end; $pi++):
        ?>
          <?php if ($pi === $page): ?>
            <span class="pag-btn active"><?= $pi ?></span>
          <?php else: ?>
            <a class="pag-btn" href="<?= htmlspecialchars(adm_suporte_url($pi, $f, $q)) ?>"><?= $pi ?></a>
          <?php endif; ?>
        <?php endfor; ?>
        <?php if ($page >= $totalPages): ?>
          <span class="pag-btn" style="opacity:.4;">›</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars(adm_suporte_url($page + 1, $f, $q)) ?>">›</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>

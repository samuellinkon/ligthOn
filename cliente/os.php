<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/medicao_helpers.php';

$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('os');

$pageTitle  = 'Minhas OS';
$basePath   = '../';
$activePage = 'os';

$clienteId = (int) ($user['cliente_id'] ?? 0);
$raiz      = $clienteId > 0 ? repo_cliente_matriz_raiz_id($clienteId) : 0;
if ($raiz <= 0) {
    $raiz = $clienteId;
}

$mesFiltro = trim((string) ($_GET['mes'] ?? ''));
if ($mesFiltro !== '' && !preg_match('/^\d{4}-\d{2}$/', $mesFiltro)) {
    $mesFiltro = '';
}

$dataDe = trim((string) ($_GET['data_de'] ?? ''));
$dataAte = trim((string) ($_GET['data_ate'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataDe) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataAte) || $dataDe > $dataAte) {
    $dataDe = '';
    $dataAte = '';
}
if ($dataDe !== '' && $dataAte !== '') {
    $mesFiltro = '';
}

$mesAtual = date('Y-m');
$mesAnterior = date('Y-m', strtotime('first day of previous month'));

$q     = trim((string) ($_GET['q'] ?? ''));
$lista = db_ok() && $raiz > 0
    ? repo_os_pedido_cliente_lista(
        $raiz,
        $mesFiltro !== '' ? $mesFiltro : null,
        $q !== '' ? $q : null,
        $dataDe !== '' ? $dataDe : null,
        $dataAte !== '' ? $dataAte : null
    )
    : [];

$totalOsPeriodo = count($lista);
$osAprovadasPeriodo = 0;
$osReprovadasPeriodo = 0;
foreach ($lista as $o) {
    $statusOs = (string) ($o['status'] ?? '');
    if (in_array($statusOs, ['Aprovada', 'Concluida'], true)) {
        $osAprovadasPeriodo++;
    } elseif ($statusOs === 'Rejeitada') {
        $osReprovadasPeriodo++;
    }
}
$totalOsGeral = db_ok() && $raiz > 0
    ? count(repo_os_pedido_cliente_lista($raiz, null, null))
    : 0;

$periodoLabel = 'Todas as OS';
if ($mesFiltro !== '') {
    $periodoLabel = medicao_mes_label_pt($mesFiltro);
} elseif ($dataDe !== '' && $dataAte !== '') {
    $periodoLabel = date('d/m/Y', strtotime($dataDe)) . ' a ' . date('d/m/Y', strtotime($dataAte));
}

$topTitle    = 'Ordens de serviço';
$topSubtitle = 'Aprovação após o envio da sua empresa · ' . $periodoLabel;
$topAction   = null;

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <style>
    .os-client-kpis {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 20px;
      margin-bottom: 18px;
    }

    .os-client-filters {
      margin-bottom: 18px;
    }

    .os-client-filters__body {
      border-bottom: none;
      justify-content: space-between;
      align-items: flex-end;
      flex-wrap: nowrap;
    }

    .os-client-filter-block {
      min-width: 0;
      flex: 1 1 auto;
    }

    .os-client-filter-title {
      display: block;
      margin-bottom: 8px;
      color: var(--muted);
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .04em;
      text-transform: uppercase;
    }

    .os-client-quick-filters {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      align-items: center;
    }

    .os-client-filter-form {
      display: flex;
      flex-wrap: nowrap;
      gap: 10px;
      align-items: flex-end;
      justify-content: flex-end;
      flex: 0 0 auto;
      margin: 0;
    }

    .os-client-filter-form .form-group--mes {
      flex: 0 0 155px;
      min-width: 155px;
    }

    @media (max-width: 1280px) {
      .os-client-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }

    @media (max-width: 980px) {
      .os-client-filters__body,
      .os-client-filter-form {
        flex-wrap: wrap;
      }

      .os-client-filter-form {
        justify-content: flex-start;
      }
    }

    @media (max-width: 640px) {
      .os-client-kpis { grid-template-columns: 1fr; }

      .os-client-quick-filters,
      .os-client-filter-form,
      .os-client-filter-form .form-group,
      .os-client-filter-form .input,
      .os-client-filter-form .btn {
        width: 100%;
      }

      .os-client-quick-filters .chip {
        justify-content: center;
        flex: 1 1 100%;
      }
    }
  </style>

  <div class="os-client-kpis">
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">OS do período</div>
          <div class="metric-value metric-value--compact"><?= (int) $totalOsPeriodo ?></div>
        </div>
        <div class="icon-box purple">OS</div>
      </div>
      <div class="metric-change muted"><?= htmlspecialchars($periodoLabel) ?></div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">OS aprovadas</div>
          <div class="metric-value metric-value--compact"><?= (int) $osAprovadasPeriodo ?></div>
        </div>
        <div class="icon-box green">OK</div>
      </div>
      <div class="metric-change success">Aprovada ou concluída</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">OS reprovadas</div>
          <div class="metric-value metric-value--compact"><?= (int) $osReprovadasPeriodo ?></div>
        </div>
        <div class="icon-box red">RP</div>
      </div>
      <div class="metric-change danger">Status rejeitada</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Total de OS</div>
          <div class="metric-value metric-value--compact"><?= (int) $totalOsGeral ?></div>
        </div>
        <div class="icon-box blue">Σ</div>
      </div>
      <div class="metric-change muted">Histórico completo</div>
    </div>
  </div>

  <div class="card os-client-filters">
    <div class="filters filters--form os-client-filters__body">
      <div class="os-client-filter-block">
        <span class="os-client-filter-title">Filtros rápidos</span>
        <div class="os-client-quick-filters">
          <a class="chip <?= $mesFiltro === $mesAtual ? 'active' : '' ?>" href="<?= htmlspecialchars('os.php?' . http_build_query(array_filter(['mes' => $mesAtual, 'q' => $q !== '' ? $q : null]))) ?>">Mês atual</a>
          <a class="chip <?= $mesFiltro === $mesAnterior ? 'active' : '' ?>" href="<?= htmlspecialchars('os.php?' . http_build_query(array_filter(['mes' => $mesAnterior, 'q' => $q !== '' ? $q : null]))) ?>">Mês anterior</a>
          <a class="chip <?= $mesFiltro === '' && $dataDe === '' && $dataAte === '' ? 'active' : '' ?>" href="<?= htmlspecialchars($q !== '' ? 'os.php?' . http_build_query(['q' => $q]) : 'os.php') ?>">Todas</a>
        </div>
      </div>
      <form method="get" class="os-client-filter-form">
        <div class="form-group form-group--mes">
          <label for="data_de">De</label>
          <input type="date" id="data_de" name="data_de" class="input" value="<?= htmlspecialchars($dataDe) ?>">
        </div>
        <div class="form-group form-group--mes">
          <label for="data_ate">Até</label>
          <input type="date" id="data_ate" name="data_ate" class="input" value="<?= htmlspecialchars($dataAte) ?>">
        </div>
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>"><?php endif; ?>
        <button type="submit" class="btn btn-secondary btn-sm">Filtrar data</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="panel-body">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>OS</th>
              <th>Mês</th>
              <th>Título</th>
              <th>Status</th>
              <th>Aberta em</th>
              <th class="text-right">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($lista)): ?>
              <tr><td colspan="6" class="muted" style="text-align:center;padding:24px;">Nenhuma OS.</td></tr>
            <?php else: foreach ($lista as $o): ?>
              <tr>
                <td class="td-strong">#<?= (int) $o['id'] ?></td>
                <td><?= htmlspecialchars(medicao_mes_label_pt((string) ($o['ref_ym'] ?? ''))) ?></td>
                <td><a href="os_detalhe.php?id=<?= (int) $o['id'] ?>"><?= htmlspecialchars((string) ($o['titulo'] ?? '')) ?></a></td>
                <td><span class="badge <?= status_class($o['status'] ?? '') ?>"><?= htmlspecialchars((string) ($o['status'] ?? '')) ?></span></td>
                <td class="td-mute"><?= !empty($o['aberto_em']) ? htmlspecialchars(date('d/m/Y H:i', strtotime((string) $o['aberto_em']))) : '—' ?></td>
                <td class="td-actions">
                  <a class="action primary" href="os_detalhe.php?id=<?= (int) $o['id'] ?>">Ver OS</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <p class="muted" style="margin:16px 20px;font-size:13px;">Quando o status for <strong>Enviada ao cliente</strong>, abra a OS para aprovar ou rejeitar.</p>
    </div>
  </div>
</section>
</main>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>

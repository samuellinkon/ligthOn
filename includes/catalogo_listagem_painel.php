<?php
/**
 * Listagem do catálogo (métricas, filtros, chips, tabela).
 *
 * Espera antes do include:
 * - $catalogoPainelClienteId (int) dono do catálogo (empresa raiz)
 * - $catalogoPainelFormAction (string) ex.: catalogo.php (relativo à pasta da página)
 * - $catalogoPainelHiddenQuery (array<string, int|string>) params fixos no GET (ex.: cliente_id no admin)
 * - $catalogoPainelSomenteLeitura (bool) se true: sem botões de edição, modal e coluna Ações
 * - $catalogoPainelAdminEditaSaldo (bool) se true: saldo editável no modal (painel admin/gestão)
 * - $catalogoPainelHrefImportar (string, opcional) URL do botão Importar (default: admin cliente_itens_importar)
 * - $catalogoPainelHrefAplicadoChamados (string, opcional) URL do relatório aplicado em chamados (default: admin + cliente_id)
 */

declare(strict_types=1);

$catalogoPainelClienteId = (int) ($catalogoPainelClienteId ?? 0);
$catalogoPainelFormAction = (string) ($catalogoPainelFormAction ?? 'catalogo.php');
$catalogoPainelHiddenQuery = is_array($catalogoPainelHiddenQuery ?? null) ? $catalogoPainelHiddenQuery : [];
$catalogoPainelSomenteLeitura  = !empty($catalogoPainelSomenteLeitura);
$catalogoPainelAdminEditaSaldo = !empty($catalogoPainelAdminEditaSaldo);

if ($catalogoPainelClienteId <= 0) {
    echo '<section class="content"><p class="muted">Catálogo indisponível.</p></section>';

    return;
}

$catalogoPainelHrefImportar = isset($catalogoPainelHrefImportar) && (string) $catalogoPainelHrefImportar !== ''
    ? (string) $catalogoPainelHrefImportar
    : ('cliente_itens_importar.php?cliente_id=' . $catalogoPainelClienteId);
$catalogoPainelHrefAplicadoChamados = isset($catalogoPainelHrefAplicadoChamados) && (string) $catalogoPainelHrefAplicadoChamados !== ''
    ? (string) $catalogoPainelHrefAplicadoChamados
    : ('catalogo_chamados_materiais.php?cliente_id=' . $catalogoPainelClienteId);

$tipoFiltro = strtolower(trim((string) ($_GET['tipo'] ?? '')));
if (!in_array($tipoFiltro, ['', 'produto', 'servico'], true)) {
    $tipoFiltro = '';
}
$statusFiltro = strtolower(trim((string) ($_GET['status'] ?? '')));
if (!in_array($statusFiltro, ['', 'ativo', 'inativo'], true)) {
    $statusFiltro = '';
}
$q = trim((string) ($_GET['q'] ?? ''));
$codFiltro = trim((string) ($_GET['cod'] ?? ''));
$estoqueBaixoFiltro = isset($_GET['estoque_baixo']) && (string) $_GET['estoque_baixo'] === '1';

$catalogoTemEstoque     = repo_cliente_itens_estoque_saldo_column_exists();
$catalogoTemCapacidade  = repo_cliente_itens_estoque_capacidade_column_exists();
$catalogoEstoqueFmt = static function (float $n): string {
    $t = rtrim(rtrim(number_format($n, 4, ',', '.'), '0'), ',');

    return $t === '' ? '0' : $t;
};
$catalogoValorRound = static function (float $n): float {
    return round($n, 2, PHP_ROUND_HALF_UP);
};
$catalogoValorFmt = static function (float $n) use ($catalogoValorRound): string {
    return number_format($catalogoValorRound($n), 2, ',', '.');
};

$todosItens = repo_cliente_itens_list($catalogoPainelClienteId, false);
$totalProdutos = count(array_filter($todosItens, static fn ($it) => ($it['tipo'] ?? '') === 'produto'));
$totalServicos = count(array_filter($todosItens, static fn ($it) => ($it['tipo'] ?? '') === 'servico'));
$totalAtivos   = count(array_filter($todosItens, static fn ($it) => !empty($it['ativo'])));
$totalEstoqueBaixo = $catalogoTemEstoque
    ? count(array_filter($todosItens, 'catalogo_item_estoque_baixo'))
    : 0;

$itens = array_values(array_filter($todosItens, static function (array $it) use ($tipoFiltro, $statusFiltro, $q, $codFiltro, $estoqueBaixoFiltro, $catalogoTemEstoque): bool {
    if ($estoqueBaixoFiltro && $catalogoTemEstoque) {
        if (!catalogo_item_estoque_baixo($it)) {
            return false;
        }
    }
    if ($tipoFiltro !== '' && ($it['tipo'] ?? '') !== $tipoFiltro) {
        return false;
    }
    if ($statusFiltro === 'ativo' && empty($it['ativo'])) {
        return false;
    }
    if ($statusFiltro === 'inativo' && !empty($it['ativo'])) {
        return false;
    }
    if ($q !== '') {
        $hay = mb_strtolower(
            (string) ($it['nome'] ?? '') . ' ' .
            (string) ($it['codigo'] ?? '') . ' ' .
            (string) ($it['descricao'] ?? '')
        );
        if (mb_strpos($hay, mb_strtolower($q)) === false) {
            return false;
        }
    }
    if ($codFiltro !== '') {
        $codCell = mb_strtolower(trim((string) ($it['codigo'] ?? '')));
        if ($codCell === '' || mb_strpos($codCell, mb_strtolower($codFiltro)) === false) {
            return false;
        }
    }

    return true;
}));

$catalogoPainelUrl = static function (
    array $base,
    string $script,
    string $tipo,
    string $status,
    string $busca,
    string $cod = '',
    bool $estoqueBaixo = false
): string {
    $qs = $base;
    if ($tipo !== '') {
        $qs['tipo'] = $tipo;
    }
    if ($status !== '') {
        $qs['status'] = $status;
    }
    if ($busca !== '') {
        $qs['q'] = $busca;
    }
    if ($cod !== '') {
        $qs['cod'] = $cod;
    }
    if ($estoqueBaixo) {
        $qs['estoque_baixo'] = '1';
    }
    $built = http_build_query($qs);

    return $built !== '' ? ($script . '?' . $built) : $script;
};
$catalogoHrefAplicadoItem = static function (string $baseHref, int $itemId): string {
    $sep = strpos($baseHref, '?') === false ? '?' : '&';

    return $baseHref . $sep . 'item_id=' . $itemId;
};

$filtroMetricAtivos   = $statusFiltro === 'ativo' && $tipoFiltro === '' && !$estoqueBaixoFiltro;
$filtroMetricProdutos = $tipoFiltro === 'produto' && !$estoqueBaixoFiltro;
$filtroMetricServicos = $tipoFiltro === 'servico' && !$estoqueBaixoFiltro;

$hrefMetricAtivos = $catalogoPainelUrl(
    $catalogoPainelHiddenQuery,
    $catalogoPainelFormAction,
    '',
    $filtroMetricAtivos ? '' : 'ativo',
    '',
    ''
);
$hrefMetricProdutos = $catalogoPainelUrl(
    $catalogoPainelHiddenQuery,
    $catalogoPainelFormAction,
    $filtroMetricProdutos ? '' : 'produto',
    '',
    '',
    ''
);
$hrefMetricServicos = $catalogoPainelUrl(
    $catalogoPainelHiddenQuery,
    $catalogoPainelFormAction,
    $filtroMetricServicos ? '' : 'servico',
    '',
    '',
    ''
);
$hrefMetricEstoqueBaixo = $catalogoPainelUrl(
    $catalogoPainelHiddenQuery,
    $catalogoPainelFormAction,
    '',
    '',
    '',
    '',
    !$estoqueBaixoFiltro
);

$metricCardClass = static function (bool $active): string {
    return 'card metric metric-card--link' . ($active ? ' metric-card--active' : '');
};

?>
<section class="content">

  <?php if (!$catalogoTemEstoque): ?>
  <div class="flash flash-warn" style="margin-bottom:16px;">
    Execute a migração <code>database/migrations/045_cliente_itens_estoque_saldo.sql</code> para habilitar o controle de estoque e saldo.
  </div>
  <?php elseif (!$catalogoTemCapacidade): ?>
  <div class="flash flash-warn" style="margin-bottom:16px;">
    Execute a migração <code>database/migrations/052_estoque_capacidade.sql</code> para separar <strong>Estoque</strong> (referência no cadastro) e <strong>Saldo</strong> (atual, movimentado pelos chamados).
  </div>
  <?php endif; ?>

  <div class="cards-metrics" style="grid-template-columns:repeat(<?= $catalogoTemEstoque ? 4 : 3 ?>,1fr);">
    <a class="<?= htmlspecialchars($metricCardClass($filtroMetricAtivos), ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($hrefMetricAtivos, ENT_QUOTES, 'UTF-8') ?>">
      <div class="metric-top">
        <div><div class="metric-label">Ativos</div><div class="metric-value"><?= (int) $totalAtivos ?></div></div>
        <div class="icon-box green">OK</div>
      </div>
      <div class="metric-change success">Disponíveis para chamados — clique para filtrar</div>
    </a>
    <a class="<?= htmlspecialchars($metricCardClass($filtroMetricProdutos), ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($hrefMetricProdutos, ENT_QUOTES, 'UTF-8') ?>">
      <div class="metric-top">
        <div><div class="metric-label">Produtos</div><div class="metric-value"><?= (int) $totalProdutos ?></div></div>
        <div class="icon-box purple">PR</div>
      </div>
      <div class="metric-change muted">Materiais e itens físicos — clique para filtrar</div>
    </a>
    <a class="<?= htmlspecialchars($metricCardClass($filtroMetricServicos), ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($hrefMetricServicos, ENT_QUOTES, 'UTF-8') ?>">
      <div class="metric-top">
        <div><div class="metric-label">Serviços</div><div class="metric-value"><?= (int) $totalServicos ?></div></div>
        <div class="icon-box orange">SV</div>
      </div>
      <div class="metric-change muted">Atividades executadas — clique para filtrar</div>
    </a>
    <?php if ($catalogoTemEstoque): ?>
    <a class="<?= htmlspecialchars($metricCardClass($estoqueBaixoFiltro), ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($hrefMetricEstoqueBaixo, ENT_QUOTES, 'UTF-8') ?>">
      <div class="metric-top">
        <div><div class="metric-label">Estoque baixo</div><div class="metric-value"><?= (int) $totalEstoqueBaixo ?></div></div>
        <div class="icon-box red">!</div>
      </div>
      <div class="metric-change muted"<?= $totalEstoqueBaixo > 0 ? ' style="color:#dc2626;"' : '' ?>>Saldo abaixo de 10% do estoque — clique para filtrar</div>
    </a>
    <?php endif; ?>
  </div>

  <div class="card" style="margin-top:20px;">
    <div class="panel-head" style="flex-wrap:wrap;gap:12px;">
      <div>
        <h4 style="margin:0;">Listagem do catálogo</h4>
        <span class="panel-sub"><span id="catalogo-itens-count"><?= count($itens) ?></span> item(ns) exibido(s)</span>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php if (!$catalogoPainelSomenteLeitura): ?>
        <button class="btn btn-primary btn-sm" type="button" data-open-item-modal data-tipo="produto">+ Produto</button>
        <button class="btn btn-secondary btn-sm" type="button" data-open-item-modal data-tipo="servico">+ Serviço</button>
        <a class="btn btn-secondary btn-sm" href="catalogo_export_xlsx.php?cliente_id=<?= (int) $catalogoPainelClienteId ?>">Exportar XLS</a>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($catalogoPainelHrefImportar) ?>">Importar planilha</a>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($catalogoPainelHrefAplicadoChamados) ?>" title="Lançamentos de itens do catálogo em chamados">Catálogo aplicado em chamados</a>
        <?php if ($catalogoTemEstoque): ?>
        <form method="post" action="<?= htmlspecialchars($catalogoPainelFormAction) ?>" style="display:inline;margin:0;" onsubmit="return confirm('Recalcular o saldo de todos os itens com base nos materiais dos chamados?');">
          <?php foreach ($catalogoPainelHiddenQuery as $hk => $hv): ?>
          <input type="hidden" name="<?= htmlspecialchars((string) $hk) ?>" value="<?= htmlspecialchars((string) $hv) ?>">
          <?php endforeach; ?>
          <input type="hidden" name="acao" value="recalcular_saldos">
          <button type="submit" class="btn btn-secondary btn-sm" title="Ajusta a coluna Saldo: estoque de referência menos utilizado nos chamados, mais devolvido">Recalcular saldos</button>
        </form>
        <?php endif; ?>
        <?php else: ?>
        <span class="btn btn-primary btn-sm" style="opacity:.5;cursor:default;pointer-events:none;" title="Edição disponível no painel do gestor">+ Produto</span>
        <span class="btn btn-secondary btn-sm" style="opacity:.5;cursor:default;pointer-events:none;">+ Serviço</span>
        <span class="btn btn-secondary btn-sm" style="opacity:.5;cursor:default;pointer-events:none;">Importar planilha</span>
        <span class="btn btn-secondary btn-sm" style="opacity:.5;cursor:default;pointer-events:none;">Catálogo aplicado em chamados</span>
        <?php endif; ?>
      </div>
    </div>

    <form class="filters filters--form" method="get" action="<?= htmlspecialchars($catalogoPainelFormAction) ?>">
      <?php foreach ($catalogoPainelHiddenQuery as $hk => $hv): ?>
      <input type="hidden" name="<?= htmlspecialchars((string) $hk) ?>" value="<?= htmlspecialchars((string) $hv) ?>">
      <?php endforeach; ?>
      <div class="form-group">
        <label for="tipo_f">Tipo</label>
        <select id="tipo_f" name="tipo" class="select">
          <option value="">Todos</option>
          <option value="produto" <?= $tipoFiltro === 'produto' ? 'selected' : '' ?>>Produtos</option>
          <option value="servico" <?= $tipoFiltro === 'servico' ? 'selected' : '' ?>>Serviços</option>
        </select>
      </div>
      <div class="form-group">
        <label for="status_f">Status</label>
        <select id="status_f" name="status" class="select">
          <option value="">Todos</option>
          <option value="ativo" <?= $statusFiltro === 'ativo' ? 'selected' : '' ?>>Ativos</option>
          <option value="inativo" <?= $statusFiltro === 'inativo' ? 'selected' : '' ?>>Inativos</option>
        </select>
      </div>
      <div class="form-group">
        <label for="cod">Código</label>
        <input id="cod" name="cod" class="input" type="search" value="<?= htmlspecialchars($codFiltro) ?>" placeholder="SKU, interno…">
      </div>
      <div class="form-group form-group--grow">
        <label for="q">Buscar</label>
        <input id="q" name="q" class="input" type="search" value="<?= htmlspecialchars($q) ?>" placeholder="Nome ou descrição">
      </div>
      <div class="filters-form-actions">
        <button type="submit" class="btn btn-primary">Filtrar</button>
        <?php if ($tipoFiltro !== '' || $statusFiltro !== '' || $q !== '' || $codFiltro !== '' || $estoqueBaixoFiltro): ?>
        <a class="btn btn-secondary" href="<?= htmlspecialchars($catalogoPainelUrl($catalogoPainelHiddenQuery, $catalogoPainelFormAction, '', '', '', '')) ?>">Limpar</a>
        <?php endif; ?>
      </div>
    </form>

    <div class="filters" style="padding:12px 20px;display:flex;flex-wrap:wrap;gap:8px;">
      <a href="<?= htmlspecialchars($catalogoPainelUrl($catalogoPainelHiddenQuery, $catalogoPainelFormAction, '', $statusFiltro, $q, $codFiltro)) ?>" class="chip <?= $tipoFiltro === '' ? 'active' : '' ?>">Todos</a>
      <a href="<?= htmlspecialchars($catalogoPainelUrl($catalogoPainelHiddenQuery, $catalogoPainelFormAction, 'produto', $statusFiltro, $q, $codFiltro)) ?>" class="chip <?= $tipoFiltro === 'produto' ? 'active' : '' ?>">Produtos</a>
      <a href="<?= htmlspecialchars($catalogoPainelUrl($catalogoPainelHiddenQuery, $catalogoPainelFormAction, 'servico', $statusFiltro, $q, $codFiltro)) ?>" class="chip <?= $tipoFiltro === 'servico' ? 'active' : '' ?>">Serviços</a>
    </div>


    <div class="table-wrap">
      <table id="catalogo-itens-table" class="catalogo-excel-table" data-crm-sortable>
        <thead>
          <tr class="catalogo-excel-table__head-sort crm-table-head-sort">
            <?php crm_sort_th('Tipo', 'tipo'); ?>
            <?php crm_sort_th('Nome', 'nome'); ?>
            <?php crm_sort_th('Código', 'codigo'); ?>
            <?php crm_sort_th('Un.', 'unidade'); ?>
            <?php crm_sort_th('Valor unit.', 'valor', ['type' => 'number', 'right' => true]); ?>
            <?php if ($catalogoTemEstoque && $catalogoTemCapacidade): ?>
            <?php crm_sort_th('Estoque', 'estoque', ['type' => 'number', 'right' => true, 'title' => 'Quantidade de referência definida na importação ou edição do catálogo.']); ?>
            <?php crm_sort_th('Saldo', 'saldo', ['type' => 'number', 'right' => true, 'title' => 'Disponível após consumo (BM para itens de contrato X.Y; chamados para materiais numéricos).']); ?>
            <?php elseif ($catalogoTemEstoque): ?>
            <?php crm_sort_th('Saldo', 'saldo', ['type' => 'number', 'right' => true]); ?>
            <?php endif; ?>
            <?php crm_sort_th('Status', 'status', ['type' => 'number']); ?>
            <?php crm_sort_th('Ações', null, ['class' => 'catalogo-excel-col-acoes crm-table-col-acoes']); ?>
          </tr>
        </thead>
        <tbody>
          <?php
            $colspan = 7 + ($catalogoTemEstoque ? ($catalogoTemCapacidade ? 2 : 1) : 0);
          if (empty($itens)): ?>
          <tr><td colspan="<?= $colspan ?>" class="muted" style="padding:24px;text-align:center;">Nenhum item encontrado.</td></tr>
          <?php else: foreach ($itens as $it):
            $tipoRaw   = (string) ($it['tipo'] ?? 'produto');
            $codRaw    = trim((string) ($it['codigo'] ?? ''));
            $estSaldo  = (float) ($it['estoque_saldo'] ?? 0);
            $estCap    = isset($it['estoque_capacidade']) && $it['estoque_capacidade'] !== null
                ? (float) $it['estoque_capacidade']
                : $estSaldo;
            $estBaixo  = $catalogoTemEstoque && catalogo_item_estoque_baixo($it);
            $estNeg    = $catalogoTemEstoque && $estSaldo < 0;
            $rowClass  = trim(($estBaixo ? 'catalogo-row--estoque-baixo ' : '') . 'catalogo-row--aplicado-link');
            $estClasse = $estNeg ? 'catalogo-estoque--negativo' : ($estBaixo ? 'catalogo-estoque--baixo' : '');
            $valorNum = $catalogoValorRound((float) ($it['valor_unitario'] ?? 0));
            $itemId = (int) ($it['id'] ?? 0);
            $itemAplicadoHref = $catalogoHrefAplicadoItem($catalogoPainelHrefAplicadoChamados, $itemId);
          ?>
          <tr
            class="<?= htmlspecialchars($rowClass) ?>"
            data-catalogo-aplicado-href="<?= htmlspecialchars($itemAplicadoHref, ENT_QUOTES) ?>"
            role="link"
            tabindex="0"
            title="Ver aplicado em chamados"
            <?= crm_sort_row_attr([
                'tipo'    => $tipoRaw,
                'nome'    => (string) ($it['nome'] ?? ''),
                'codigo'  => $codRaw,
                'unidade' => (string) ($it['unidade'] ?? ''),
                'valor'   => (string) $valorNum,
                'estoque' => (string) $estCap,
                'saldo'   => (string) $estSaldo,
                'status'  => !empty($it['ativo']) ? '1' : '0',
            ]) ?>
          >
            <td><span class="badge badge-plain"><?= (($it['tipo'] ?? '') === 'produto') ? 'Produto' : 'Serviço' ?></span></td>
            <td>
              <div class="catalogo-item-nome">
                <strong><?= htmlspecialchars((string) ($it['nome'] ?? '')) ?></strong>
                <?php if ($estBaixo): ?>
                  <?php
                    $limiarBaixoUi = $catalogoTemCapacidade ? catalogo_estoque_limiar_baixo($it) : 0.0;
                    $tituloBaixo   = $limiarBaixoUi > 0
                        ? sprintf('Saldo %.4g ≤ 10%% do estoque (limiar %.4g %s)', $estSaldo, $limiarBaixoUi, (string) ($it['unidade'] ?? ''))
                        : 'Saldo abaixo de 10% do estoque';
                  ?>
                  <span class="badge catalogo-badge-estoque-baixo" title="<?= htmlspecialchars($tituloBaixo, ENT_QUOTES) ?>">Estoque baixo</span>
                <?php endif; ?>
              </div>
            </td>
            <td class="td-mute"><?= htmlspecialchars((string) ($it['codigo'] ?? '—')) ?></td>
            <td class="td-mute"><?= htmlspecialchars((string) ($it['unidade'] ?? '')) ?></td>
            <td class="text-right">R$ <?= $catalogoValorFmt((float) ($it['valor_unitario'] ?? 0)) ?></td>
            <?php if ($catalogoTemEstoque && $catalogoTemCapacidade): ?>
            <td class="text-right">
              <?= $catalogoEstoqueFmt($estCap) ?>
              <span class="muted" style="font-size:12px;"> <?= htmlspecialchars((string) ($it['unidade'] ?? '')) ?></span>
            </td>
            <td class="text-right<?= $estClasse !== '' ? ' ' . $estClasse : '' ?>">
              <?= $catalogoEstoqueFmt($estSaldo) ?>
              <span class="muted" style="font-size:12px;"> <?= htmlspecialchars((string) ($it['unidade'] ?? '')) ?></span>
            </td>
            <?php elseif ($catalogoTemEstoque): ?>
            <td class="text-right<?= $estClasse !== '' ? ' ' . $estClasse : '' ?>">
              <?= $catalogoEstoqueFmt($estSaldo) ?>
              <span class="muted" style="font-size:12px;"> <?= htmlspecialchars((string) ($it['unidade'] ?? '')) ?></span>
            </td>
            <?php endif; ?>
            <td><?php
              $fluxoSt = trim((string) ($it['catalogo_fluxo_status'] ?? ''));
              if ($fluxoSt === 'Criado') {
                  echo '<span class="badge plain" title="Criado pelo gestor no chamado">Criado</span>';
              } elseif ($fluxoSt !== '') {
                  echo '<span class="badge plain">' . htmlspecialchars($fluxoSt) . '</span>';
              } elseif (!empty($it['ativo'])) {
                  echo '<span class="badge done">Ativo</span>';
              } else {
                  echo '<span class="badge muted">Inativo</span>';
              }
            ?></td>
            <td class="td-actions">
              <?php if (!$catalogoPainelSomenteLeitura): ?>
              <button
                type="button"
                class="action primary"
                data-open-item-modal
                data-id="<?= $itemId ?>"
                data-tipo="<?= htmlspecialchars((string) ($it['tipo'] ?? 'produto')) ?>"
                data-nome="<?= htmlspecialchars((string) ($it['nome'] ?? ''), ENT_QUOTES) ?>"
                data-codigo="<?= htmlspecialchars((string) ($it['codigo'] ?? ''), ENT_QUOTES) ?>"
                data-unidade="<?= htmlspecialchars((string) ($it['unidade'] ?? 'UN'), ENT_QUOTES) ?>"
                data-valor="<?= htmlspecialchars($catalogoValorFmt((float) ($it['valor_unitario'] ?? 0)), ENT_QUOTES) ?>"
                data-estoque-capacidade="<?= htmlspecialchars($catalogoEstoqueFmt($estCap), ENT_QUOTES) ?>"
                data-estoque-saldo="<?= htmlspecialchars($catalogoEstoqueFmt($estSaldo), ENT_QUOTES) ?>"
                data-descricao="<?= htmlspecialchars((string) ($it['descricao'] ?? ''), ENT_QUOTES) ?>"
                data-ativo="<?= !empty($it['ativo']) ? '1' : '0' ?>"
              >Editar</button>
              <form method="post" style="display:inline;" data-confirm="Excluir este item do catálogo?" data-confirm-danger>
                <input type="hidden" name="acao" value="item_excluir">
                <input type="hidden" name="item_id" value="<?= $itemId ?>">
                <button type="submit" class="action danger">Excluir</button>
              </form>
              <?php else: ?>
              <span class="muted" style="font-size:13px;" title="Edição no painel interno do gestor">—</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</section>

<style>
.catalogo-row--aplicado-link { cursor: pointer; }
.catalogo-row--aplicado-link:hover { background: var(--surface-hover, rgba(0, 0, 0, 0.04)); }
</style>
<script>
(function () {
  function goToAplicado(row) {
    var href = row.getAttribute('data-catalogo-aplicado-href');
    if (href) window.location.href = href;
  }

  document.querySelectorAll('.catalogo-row--aplicado-link').forEach(function (row) {
    row.addEventListener('click', function () { goToAplicado(row); });
    row.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' || ev.key === ' ') {
        ev.preventDefault();
        goToAplicado(row);
      }
    });
  });

  document.querySelectorAll('.catalogo-row--aplicado-link .td-actions, .catalogo-row--aplicado-link .td-actions *').forEach(function (el) {
    el.addEventListener('click', function (ev) { ev.stopPropagation(); });
    el.addEventListener('keydown', function (ev) { ev.stopPropagation(); });
  });
})();
</script>

<?php if (!$catalogoPainelSomenteLeitura): ?>
<div id="item-modal" class="kb-modal" role="dialog" aria-modal="true" aria-labelledby="item-modal-title" aria-hidden="true">
  <form method="post" action="<?= htmlspecialchars($catalogoPainelFormAction) ?>" class="kb-modal-card" style="max-width:680px;">
    <input type="hidden" name="acao" value="item_salvar">
    <input type="hidden" name="item_id" id="modal_item_id" value="0">
    <header class="kb-modal-head">
      <h4 id="item-modal-title">Novo produto</h4>
      <button type="button" class="kb-close" data-close-item-modal>×</button>
    </header>
    <div class="kb-modal-body form form-grid">
      <div class="form-group">
        <label for="modal_tipo">Tipo</label>
        <select id="modal_tipo" name="tipo" class="select">
          <option value="produto">Produto</option>
          <option value="servico">Serviço</option>
        </select>
      </div>
      <div class="form-group">
        <label for="modal_nome">Nome</label>
        <input type="text" id="modal_nome" name="nome" class="input" required maxlength="160" placeholder="Nome do item ou serviço">
      </div>
      <div class="form-group">
        <label for="modal_codigo">Código</label>
        <input type="text" id="modal_codigo" name="codigo" class="input" maxlength="64" placeholder="SKU / interno">
      </div>
      <div class="form-group">
        <label for="modal_valor">Valor unitário (R$)</label>
        <input type="text" id="modal_valor" name="valor_unitario" class="input" inputmode="decimal" value="0" placeholder="0,00">
      </div>
      <div class="form-group">
        <label for="modal_unidade">Unidade</label>
        <input type="text" id="modal_unidade" name="unidade" class="input" value="UN" maxlength="20" placeholder="UN, m, kg, h">
      </div>
      <?php if ($catalogoTemEstoque): ?>
      <div class="form-group">
        <label for="modal_estoque">Estoque</label>
        <input type="text" id="modal_estoque" name="estoque_capacidade" class="input" inputmode="decimal" value="0" placeholder="0" title="Quantidade de referência definida no cadastro">
        <span class="muted" style="font-size:12px;" id="modal_estoque_hint">Referência fixa para alerta de estoque baixo (10% do estoque).</span>
      </div>
      <?php if ($catalogoPainelAdminEditaSaldo): ?>
      <div class="form-group" id="modal_saldo_wrap">
        <label for="modal_saldo_display">Saldo atual</label>
        <input type="text"
               id="modal_saldo_display"
               name="estoque_saldo"
               class="input"
               inputmode="decimal"
               value="0"
               placeholder="0"
               title="Saldo atual em estoque (ajuste manual pelo administrador)">
        <span class="muted" style="font-size:12px;" id="modal_saldo_hint">Editável pelo administrador; alterações ficam registradas na Auditoria.</span>
      </div>
      <?php endif; ?>
      <?php endif; ?>
      <div class="form-group full">
        <label for="modal_descricao">Descrição</label>
        <textarea id="modal_descricao" name="descricao" class="textarea" rows="3" maxlength="500" placeholder="Descrição opcional para o catálogo"></textarea>
      </div>
    </div>
    <footer class="kb-modal-foot">
      <button type="button" class="btn btn-secondary" data-close-item-modal>Cancelar</button>
      <button type="submit" class="btn btn-primary">Salvar item</button>
    </footer>
  </form>
</div>

<script>
(function () {
  var modal = document.getElementById('item-modal');
  if (!modal) return;
  var title = document.getElementById('item-modal-title');
  var id = document.getElementById('modal_item_id');
  var tipo = document.getElementById('modal_tipo');
  var nome = document.getElementById('modal_nome');
  var codigo = document.getElementById('modal_codigo');
  var unidade = document.getElementById('modal_unidade');
  var valor = document.getElementById('modal_valor');
  var estoque = document.getElementById('modal_estoque');
  var saldoDisplay = document.getElementById('modal_saldo_display');
  var estoqueHint = document.getElementById('modal_estoque_hint');
  var desc = document.getElementById('modal_descricao');
  var adminEditaSaldo = <?= $catalogoPainelAdminEditaSaldo ? 'true' : 'false' ?>;

  function open(btn) {
    var itemId = btn.getAttribute('data-id');
    var editing = itemId !== null && itemId !== '' && itemId !== '0';
    id.value = editing ? itemId : '0';
    var tipoNovo = btn.getAttribute('data-tipo') || 'produto';
    tipo.value = tipoNovo;
    tipo.dispatchEvent(new Event('change', { bubbles: true }));
    nome.value = btn.getAttribute('data-nome') || '';
    codigo.value = btn.getAttribute('data-codigo') || '';
    unidade.value = btn.getAttribute('data-unidade') || 'UN';
    valor.value = btn.getAttribute('data-valor') || '0,00';
    if (estoque) {
      estoque.value = btn.getAttribute('data-estoque-capacidade') || btn.getAttribute('data-estoque') || '0';
    }
    if (adminEditaSaldo && saldoDisplay) {
      saldoDisplay.value = editing
        ? (btn.getAttribute('data-estoque-saldo') || '0')
        : (estoque ? estoque.value || '0' : '0');
    }
    if (estoqueHint) {
      estoqueHint.textContent = editing
        ? 'Referência fixa; alterações ficam registradas na Auditoria.'
        : 'Referência fixa para alerta de estoque baixo (10% do estoque). No cadastro, o saldo inicial será igual ao estoque.';
    }
    if (desc) {
      desc.value = btn.getAttribute('data-descricao') || '';
    }
    title.textContent = editing ? 'Editar item' : (tipo.value === 'servico' ? 'Novo serviço' : 'Novo produto');
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    setTimeout(function () { nome.focus(); }, 50);
  }
  function close() {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }

  document.querySelectorAll('[data-open-item-modal]').forEach(function (btn) {
    btn.addEventListener('click', function () { open(btn); });
  });
  document.querySelectorAll('[data-close-item-modal]').forEach(function (btn) {
    btn.addEventListener('click', close);
  });
  modal.addEventListener('click', function (ev) {
    if (ev.target === modal) close();
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && modal.classList.contains('is-open')) close();
  });
})();
</script>
<?php endif; ?>

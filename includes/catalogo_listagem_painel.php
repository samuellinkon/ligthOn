<?php
/**
 * Listagem do catálogo (métricas, filtros, chips, tabela).
 *
 * Espera antes do include:
 * - $catalogoPainelClienteId (int) dono do catálogo (empresa raiz)
 * - $catalogoPainelFormAction (string) ex.: catalogo.php (relativo à pasta da página)
 * - $catalogoPainelHiddenQuery (array<string, int|string>) params fixos no GET (ex.: cliente_id no admin)
 * - $catalogoPainelSomenteLeitura (bool) se true: sem botões de edição, modal e coluna Ações
 * - $catalogoPainelHrefImportar (string, opcional) URL do botão Importar (default: admin cliente_itens_importar)
 * - $catalogoPainelHrefAplicadoChamados (string, opcional) URL do relatório aplicado em chamados (default: admin + cliente_id)
 */

declare(strict_types=1);

$catalogoPainelClienteId = (int) ($catalogoPainelClienteId ?? 0);
$catalogoPainelFormAction = (string) ($catalogoPainelFormAction ?? 'catalogo.php');
$catalogoPainelHiddenQuery = is_array($catalogoPainelHiddenQuery ?? null) ? $catalogoPainelHiddenQuery : [];
$catalogoPainelSomenteLeitura = !empty($catalogoPainelSomenteLeitura);

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

$catalogoTemEstoque = repo_cliente_itens_estoque_saldo_column_exists();
$catalogoEstoqueBaixoLimiar = 10.0;
$catalogoEstoqueFmt = static function (float $n): string {
    $t = rtrim(rtrim(number_format($n, 4, ',', '.'), '0'), ',');

    return $t === '' ? '0' : $t;
};

$todosItens = repo_cliente_itens_list($catalogoPainelClienteId, false);
$totalProdutos = count(array_filter($todosItens, static fn ($it) => ($it['tipo'] ?? '') === 'produto'));
$totalServicos = count(array_filter($todosItens, static fn ($it) => ($it['tipo'] ?? '') === 'servico'));
$totalAtivos   = count(array_filter($todosItens, static fn ($it) => !empty($it['ativo'])));
$totalEstoqueBaixo = $catalogoTemEstoque
    ? count(array_filter(
        $todosItens,
        static fn ($it) => !empty($it['ativo']) && (float) ($it['estoque_saldo'] ?? 0) < $catalogoEstoqueBaixoLimiar
    ))
    : 0;

$itens = array_values(array_filter($todosItens, static function (array $it) use ($tipoFiltro, $statusFiltro, $q, $codFiltro): bool {
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

$catalogoPainelUrl = static function (array $base, string $script, string $tipo, string $status, string $busca, string $cod = ''): string {
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
    $built = http_build_query($qs);

    return $built !== '' ? ($script . '?' . $built) : $script;
};

?>
<section class="content">

  <?php if (!$catalogoTemEstoque): ?>
  <div class="flash flash-warn" style="margin-bottom:16px;">
    Execute a migração <code>database/migrations/045_cliente_itens_estoque_saldo.sql</code> para habilitar o controle de estoque saldo.
  </div>
  <?php endif; ?>

  <div class="cards-metrics" style="grid-template-columns:repeat(<?= $catalogoTemEstoque ? 4 : 3 ?>,1fr);">
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Ativos</div><div class="metric-value"><?= (int) $totalAtivos ?></div></div>
        <div class="icon-box green">OK</div>
      </div>
      <div class="metric-change success">Disponíveis para chamados</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Produtos</div><div class="metric-value"><?= (int) $totalProdutos ?></div></div>
        <div class="icon-box purple">PR</div>
      </div>
      <div class="metric-change muted">Materiais e itens físicos</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Serviços</div><div class="metric-value"><?= (int) $totalServicos ?></div></div>
        <div class="icon-box orange">SV</div>
      </div>
      <div class="metric-change muted">Atividades executadas</div>
    </div>
    <?php if ($catalogoTemEstoque): ?>
    <div class="card metric">
      <div class="metric-top">
        <div><div class="metric-label">Estoque baixo</div><div class="metric-value"><?= (int) $totalEstoqueBaixo ?></div></div>
        <div class="icon-box red">!</div>
      </div>
      <div class="metric-change muted"<?= $totalEstoqueBaixo > 0 ? ' style="color:#dc2626;"' : '' ?>>Saldo &lt; <?= (int) $catalogoEstoqueBaixoLimiar ?> (ativos)</div>
    </div>
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
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($catalogoPainelHrefImportar) ?>">Importar planilha</a>
        <a class="btn btn-secondary btn-sm" href="<?= htmlspecialchars($catalogoPainelHrefAplicadoChamados) ?>" title="Lançamentos de itens do catálogo em chamados">Catálogo aplicado em chamados</a>
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
        <?php if ($tipoFiltro !== '' || $statusFiltro !== '' || $q !== '' || $codFiltro !== ''): ?>
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
      <table id="catalogo-itens-table" class="catalogo-excel-table">
        <thead>
          <tr class="catalogo-excel-table__head-sort">
            <th><button type="button" class="catalogo-excel-sort" data-sort-key="tipo"><span>Tipo</span><span class="catalogo-excel-sort__icon" aria-hidden="true">↕</span></button></th>
            <th><button type="button" class="catalogo-excel-sort" data-sort-key="nome"><span>Nome</span><span class="catalogo-excel-sort__icon" aria-hidden="true">↕</span></button></th>
            <th><button type="button" class="catalogo-excel-sort" data-sort-key="codigo"><span>Código</span><span class="catalogo-excel-sort__icon" aria-hidden="true">↕</span></button></th>
            <th><button type="button" class="catalogo-excel-sort" data-sort-key="unidade"><span>Un.</span><span class="catalogo-excel-sort__icon" aria-hidden="true">↕</span></button></th>
            <th class="text-right"><button type="button" class="catalogo-excel-sort text-right" data-sort-key="valor"><span>Valor unit.</span><span class="catalogo-excel-sort__icon" aria-hidden="true">↕</span></button></th>
            <?php if ($catalogoTemEstoque): ?>
            <th class="text-right"><button type="button" class="catalogo-excel-sort text-right" data-sort-key="estoque"><span>Estoque saldo</span><span class="catalogo-excel-sort__icon" aria-hidden="true">↕</span></button></th>
            <?php endif; ?>
            <th><button type="button" class="catalogo-excel-sort" data-sort-key="status"><span>Status</span><span class="catalogo-excel-sort__icon" aria-hidden="true">↕</span></button></th>
            <th class="text-right catalogo-excel-filter-actions"><span class="muted" style="font-size:11px;font-weight:600;text-transform:uppercase;">Ações</span></th>
          </tr>
          <tr class="catalogo-excel-table__head-filters">
            <th>
              <select class="catalogo-excel-filter" data-filter-key="tipo" aria-label="Filtrar tipo">
                <option value="">Todos</option>
                <option value="produto">Produto</option>
                <option value="servico">Serviço</option>
              </select>
            </th>
            <th><input type="search" class="catalogo-excel-filter" data-filter-key="nome" placeholder="Filtrar nome" aria-label="Filtrar nome"></th>
            <th><input type="search" class="catalogo-excel-filter" data-filter-key="codigo" placeholder="Filtrar código" aria-label="Filtrar código" value="<?= htmlspecialchars($codFiltro) ?>"></th>
            <th><input type="search" class="catalogo-excel-filter" data-filter-key="unidade" placeholder="Un." aria-label="Filtrar unidade"></th>
            <th><input type="search" class="catalogo-excel-filter" data-filter-key="valor" placeholder="Valor" aria-label="Filtrar valor unitário"></th>
            <?php if ($catalogoTemEstoque): ?>
            <th><input type="search" class="catalogo-excel-filter" data-filter-key="estoque" placeholder="Saldo" aria-label="Filtrar estoque"></th>
            <?php endif; ?>
            <th>
              <select class="catalogo-excel-filter" data-filter-key="status" aria-label="Filtrar status">
                <option value="">Todos</option>
                <option value="ativo">Ativo</option>
                <option value="inativo">Inativo</option>
              </select>
            </th>
            <th class="catalogo-excel-filter-actions">
              <button type="button" class="btn-catalogo-excel-clear" id="catalogo-excel-clear-filters" title="Limpar filtros das colunas">Limpar colunas</button>
            </th>
          </tr>
        </thead>
        <tbody>
          <?php
            $colspan = 7 + ($catalogoTemEstoque ? 1 : 0);
          if (empty($itens)): ?>
          <tr><td colspan="<?= $colspan ?>" class="muted" style="padding:24px;text-align:center;">Nenhum item encontrado.</td></tr>
          <?php else: foreach ($itens as $it):
            $tipoRaw   = (string) ($it['tipo'] ?? 'produto');
            $codRaw    = trim((string) ($it['codigo'] ?? ''));
            $estSaldo  = (float) ($it['estoque_saldo'] ?? 0);
            $estBaixo  = $catalogoTemEstoque && !empty($it['ativo']) && $estSaldo < $catalogoEstoqueBaixoLimiar;
            $estNeg    = $catalogoTemEstoque && $estSaldo < 0;
            $rowClass  = $estBaixo ? 'catalogo-row--estoque-baixo' : '';
            $estClasse = $estNeg ? 'catalogo-estoque--negativo' : ($estBaixo ? 'catalogo-estoque--baixo' : '');
            $statusKey = !empty($it['ativo']) ? 'ativo' : 'inativo';
          ?>
          <tr
            data-catalogo-row="1"
            class="<?= htmlspecialchars($rowClass) ?>"
            data-sort-tipo="<?= htmlspecialchars($tipoRaw) ?>"
            data-sort-nome="<?= htmlspecialchars((string) ($it['nome'] ?? '')) ?>"
            data-sort-codigo="<?= htmlspecialchars($codRaw) ?>"
            data-sort-unidade="<?= htmlspecialchars((string) ($it['unidade'] ?? '')) ?>"
            data-sort-valor="<?= htmlspecialchars((string) ($it['valor_unitario'] ?? '0')) ?>"
            data-sort-estoque="<?= htmlspecialchars((string) $estSaldo) ?>"
            data-sort-status="<?= !empty($it['ativo']) ? '1' : '0' ?>"
            data-filter-tipo="<?= htmlspecialchars($tipoRaw) ?>"
            data-filter-nome="<?= htmlspecialchars((string) ($it['nome'] ?? '')) ?>"
            data-filter-codigo="<?= htmlspecialchars($codRaw) ?>"
            data-filter-unidade="<?= htmlspecialchars((string) ($it['unidade'] ?? '')) ?>"
            data-filter-valor="<?= htmlspecialchars(number_format((float) ($it['valor_unitario'] ?? 0), 4, ',', '.')) ?>"
            data-filter-estoque="<?= htmlspecialchars($catalogoEstoqueFmt($estSaldo)) ?>"
            data-filter-status="<?= htmlspecialchars($statusKey) ?>"
          >
            <td><span class="badge badge-plain"><?= (($it['tipo'] ?? '') === 'produto') ? 'Produto' : 'Serviço' ?></span></td>
            <td>
              <strong><?= htmlspecialchars((string) ($it['nome'] ?? '')) ?></strong>
              <?php if ($estBaixo): ?>
                <div style="margin-top:4px;"><span class="badge" style="background:rgba(220,38,38,.12);color:#dc2626;">Estoque baixo</span></div>
              <?php endif; ?>
              <?php if (!empty($it['descricao'])): ?>
                <div class="muted" style="font-size:12px;margin-top:4px;"><?= htmlspecialchars((string) $it['descricao']) ?></div>
              <?php endif; ?>
            </td>
            <td class="td-mute"><?= htmlspecialchars((string) ($it['codigo'] ?? '—')) ?></td>
            <td class="td-mute"><?= htmlspecialchars((string) ($it['unidade'] ?? '')) ?></td>
            <td class="text-right">R$ <?= number_format((float) ($it['valor_unitario'] ?? 0), 4, ',', '.') ?></td>
            <?php if ($catalogoTemEstoque): ?>
            <td class="text-right<?= $estClasse !== '' ? ' ' . $estClasse : '' ?>">
              <?= $catalogoEstoqueFmt($estSaldo) ?>
              <span class="muted" style="font-size:12px;"> <?= htmlspecialchars((string) ($it['unidade'] ?? '')) ?></span>
            </td>
            <?php endif; ?>
            <td><?= !empty($it['ativo']) ? '<span class="badge done">Ativo</span>' : '<span class="badge muted">Inativo</span>' ?></td>
            <td class="td-actions">
              <?php if (!$catalogoPainelSomenteLeitura): ?>
              <button
                type="button"
                class="action primary"
                data-open-item-modal
                data-id="<?= (int) ($it['id'] ?? 0) ?>"
                data-tipo="<?= htmlspecialchars((string) ($it['tipo'] ?? 'produto')) ?>"
                data-nome="<?= htmlspecialchars((string) ($it['nome'] ?? ''), ENT_QUOTES) ?>"
                data-codigo="<?= htmlspecialchars((string) ($it['codigo'] ?? ''), ENT_QUOTES) ?>"
                data-unidade="<?= htmlspecialchars((string) ($it['unidade'] ?? 'UN'), ENT_QUOTES) ?>"
                data-valor="<?= htmlspecialchars((string) ($it['valor_unitario'] ?? '0'), ENT_QUOTES) ?>"
                data-estoque="<?= htmlspecialchars((string) ($it['estoque_saldo'] ?? '0'), ENT_QUOTES) ?>"
                data-descricao="<?= htmlspecialchars((string) ($it['descricao'] ?? ''), ENT_QUOTES) ?>"
                data-ativo="<?= !empty($it['ativo']) ? '1' : '0' ?>"
              >Editar</button>
              <form method="post" style="display:inline;" data-confirm="Excluir este item do catálogo?" data-confirm-danger>
                <input type="hidden" name="acao" value="item_excluir">
                <input type="hidden" name="item_id" value="<?= (int) ($it['id'] ?? 0) ?>">
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

<?php
$catalogoPainelBasePath = isset($basePath) ? (string) $basePath : '../';
?>
<script src="<?= htmlspecialchars($catalogoPainelBasePath) ?>assets/js/catalogo-tabela.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/catalogo-tabela.js') ?>"></script>

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
        <label for="modal_unidade">Unidade</label>
        <input type="text" id="modal_unidade" name="unidade" class="input" value="UN" maxlength="20" placeholder="UN, m, kg, h">
      </div>
      <div class="form-group">
        <label for="modal_valor">Valor unitário (R$)</label>
        <input type="text" id="modal_valor" name="valor_unitario" class="input" inputmode="decimal" value="0" placeholder="0,00">
      </div>
      <?php if ($catalogoTemEstoque): ?>
      <div class="form-group">
        <label for="modal_estoque">Estoque saldo</label>
        <input type="text" id="modal_estoque" name="estoque_saldo" class="input" inputmode="decimal" value="0" placeholder="0" title="Quantidade total disponível em estoque">
        <span class="muted" style="font-size:12px;">Aceita saldo negativo. Alerta quando &lt; <?= (int) $catalogoEstoqueBaixoLimiar ?></span>
      </div>
      <?php endif; ?>
      <div class="form-group" style="display:flex;align-items:flex-end;">
        <label class="checkbox" style="margin-bottom:10px;">
          <input type="checkbox" name="ativo" id="modal_ativo" value="1" checked> Ativo no catálogo
        </label>
      </div>
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
  var desc = document.getElementById('modal_descricao');
  var ativo = document.getElementById('modal_ativo');

  function open(btn) {
    var editing = !!btn.getAttribute('data-id');
    id.value = btn.getAttribute('data-id') || '0';
    tipo.value = btn.getAttribute('data-tipo') || 'produto';
    nome.value = btn.getAttribute('data-nome') || '';
    codigo.value = btn.getAttribute('data-codigo') || '';
    unidade.value = btn.getAttribute('data-unidade') || 'UN';
    valor.value = btn.getAttribute('data-valor') || '0';
    if (estoque) estoque.value = btn.getAttribute('data-estoque') || '0';
    desc.value = btn.getAttribute('data-descricao') || '';
    ativo.checked = (btn.getAttribute('data-ativo') || '1') === '1';
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

<?php
/**
 * Listagem + mapa de pontos de iluminação (paridade admin/cliente).
 *
 * Antes do include:
 * - $pontosPainelEscopoId (int) empresa raiz (matriz)
 * - $pontosPainelFormAction (string) ex.: pontos_iluminacao.php
 * - $pontosPainelHiddenQuery (array<string, scalar>) params fixos no GET (ex.: cliente_id no admin)
 * - $pontosPainelHrefNovoPoste (string) URL do botão novo poste / adicionar
 * - $pontosPainelMostrarRotas (bool) + $pontosPainelHrefRotas (string) link “Rotas” (opcional)
 */

$pontosPainelEscopoId = (int) ($pontosPainelEscopoId ?? 0);
$pontosPainelFormAction = (string) ($pontosPainelFormAction ?? 'pontos_iluminacao.php');
$pontosPainelHiddenQuery = is_array($pontosPainelHiddenQuery ?? null) ? $pontosPainelHiddenQuery : [];
$pontosPainelHrefNovoPoste = (string) ($pontosPainelHrefNovoPoste ?? 'ponto_iluminacao_novo.php');
$pontosPainelMostrarRotas = !empty($pontosPainelMostrarRotas);
$pontosPainelHrefRotas = (string) ($pontosPainelHrefRotas ?? '');

if ($pontosPainelEscopoId <= 0) {
    echo '<section class="content"><p class="muted">Escopo de iluminação indisponível.</p></section>';

    return;
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
if (!in_array($status, ['', 'Ativo', 'Inativo'], true)) {
    $status = '';
}

$filtroMapa = strtolower(trim((string) ($_GET['filtro'] ?? '')));
if (!in_array($filtroMapa, ['', 'chamados'], true)) {
    $filtroMapa = '';
}

$pontosTodosEscopo = repo_pontos_iluminacao_estatisticas_escopo($pontosPainelEscopoId, true);
$totalPontosStats  = (int) ($pontosTodosEscopo['total'] ?? 0);
$comChamadoStats   = (int) ($pontosTodosEscopo['com_chamados_abertos'] ?? 0);
$totalAtivosStats  = (int) ($pontosTodosEscopo['ativos'] ?? 0);
$totalInativosStats = (int) ($pontosTodosEscopo['inativos'] ?? 0);

$pontosPerPage = 50;
$pontosPage = max(1, (int) ($_GET['page'] ?? 1));
$pontosListagem = repo_pontos_iluminacao_list_paginated(
    $pontosPainelEscopoId,
    true,
    $q,
    $status,
    $filtroMapa === 'chamados',
    $pontosPage,
    $pontosPerPage
);
$pontos = $pontosListagem['rows'];
$pontosTotalFiltrado = (int) ($pontosListagem['total'] ?? 0);
$pontosTotalPages = max(1, (int) ceil($pontosTotalFiltrado / $pontosPerPage));
$pontosPage = min($pontosPage, $pontosTotalPages);
$pontosOffset = ($pontosPage - 1) * $pontosPerPage;
if ($pontosTotalFiltrado === 0) {
    $pontosMostrarDe = $pontosMostrarAte = 0;
} else {
    $pontosMostrarDe = $pontosOffset + 1;
    $pontosMostrarAte = $pontosOffset + count($pontos);
}

/**
 * @return list<int>
 */
$pontosPaginationVisiblePages = static function (int $current, int $lastPage): array {
    if ($lastPage <= 1) {
        return [];
    }
    if ($lastPage <= 9) {
        return range(1, $lastPage);
    }
    $set = [1, $lastPage];
    for ($i = max(2, $current - 2); $i <= min($lastPage - 1, $current + 2); $i++) {
        $set[] = $i;
    }
    $set = array_values(array_unique($set));
    sort($set, SORT_NUMERIC);
    $out = [];
    $prev = 0;
    foreach ($set as $p) {
        if ($prev > 0 && $p - $prev > 1) {
            $out[] = 0;
        }
        $out[] = $p;
        $prev = $p;
    }

    return $out;
};
$pontosPaginasVisiveis = $pontosPaginationVisiblePages($pontosPage, $pontosTotalPages);

$bairrosMapa = repo_pontos_iluminacao_bairros_distintos($pontosPainelEscopoId, true);

$pontosListaQs = static function (array $override = []) use ($pontosPainelHiddenQuery, $q, $status, $filtroMapa): string {
    $p = [];
    foreach ($pontosPainelHiddenQuery as $k => $v) {
        $p[(string) $k] = $v;
    }
    if ($q !== '') {
        $p['q'] = $q;
    }
    if ($status !== '') {
        $p['status'] = $status;
    }
    if ($filtroMapa !== '') {
        $p['filtro'] = $filtroMapa;
    }
    foreach ($override as $k => $v) {
        if ($v === null || $v === '') {
            unset($p[$k]);
        } else {
            $p[$k] = $v;
        }
    }

    return http_build_query($p);
};

$pontosListaPageUrl = static function (int $pageNum) use ($pontosListaQs): string {
    return $pontosListaQs($pageNum > 1 ? ['page' => (string) $pageNum] : ['page' => null]);
};

$metricaAtiva = 'total';
if ($filtroMapa === 'chamados') {
    $metricaAtiva = 'chamados';
} elseif ($status === 'Ativo') {
    $metricaAtiva = 'ativo';
} elseif ($status === 'Inativo') {
    $metricaAtiva = 'inativo';
}

$pontosMetricHref = static function (string $chave) use ($pontosPainelFormAction, $pontosListaQs, $metricaAtiva): string {
    if ($metricaAtiva === $chave) {
        $qs = $pontosListaQs(['filtro' => null, 'status' => null]);
    } elseif ($chave === 'total') {
        $qs = $pontosListaQs(['filtro' => null, 'status' => null]);
    } elseif ($chave === 'chamados') {
        $qs = $pontosListaQs(['filtro' => 'chamados', 'status' => null]);
    } elseif ($chave === 'ativo') {
        $qs = $pontosListaQs(['filtro' => null, 'status' => 'Ativo']);
    } elseif ($chave === 'inativo') {
        $qs = $pontosListaQs(['filtro' => null, 'status' => 'Inativo']);
    } else {
        $qs = $pontosListaQs([]);
    }

    return $pontosPainelFormAction . ($qs !== '' ? '?' . $qs : '');
};

$pontosMetricCardClass = static function (bool $active): string {
    return 'card metric metric-card--link' . ($active ? ' metric-card--active' : '');
};

$mapaSubtituloFiltro = 'Todos os pontos com coordenadas';
if ($metricaAtiva === 'chamados') {
    $mapaSubtituloFiltro = 'Somente postes com chamado em aberto';
} elseif ($metricaAtiva === 'ativo') {
    $mapaSubtituloFiltro = 'Somente postes ativos';
} elseif ($metricaAtiva === 'inativo') {
    $mapaSubtituloFiltro = 'Somente postes inativos';
}

?>
<section class="content pontos-iluminacao-lista-layout">
  <div class="cards-metrics" style="margin-bottom:20px;">
    <a class="<?= htmlspecialchars($pontosMetricCardClass($metricaAtiva === 'total'), ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($pontosMetricHref('total'), ENT_QUOTES, 'UTF-8') ?>">
      <div class="metric-top">
        <div>
          <div class="metric-label">Total de pontos</div>
          <div class="metric-value"><?= (int) $totalPontosStats ?></div>
        </div>
        <div class="icon-box purple">PT</div>
      </div>
      <div class="metric-change info">Parque no escopo da empresa — clique para filtrar</div>
    </a>
    <a class="<?= htmlspecialchars($pontosMetricCardClass($metricaAtiva === 'chamados'), ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($pontosMetricHref('chamados'), ENT_QUOTES, 'UTF-8') ?>">
      <div class="metric-top">
        <div>
          <div class="metric-label">Em chamados abertos</div>
          <div class="metric-value"><?= (int) $comChamadoStats ?></div>
        </div>
        <div class="icon-box red">!</div>
      </div>
      <div class="metric-change warning">Postes com chamado em aberto — clique para filtrar</div>
    </a>
    <a class="<?= htmlspecialchars($pontosMetricCardClass($metricaAtiva === 'ativo'), ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($pontosMetricHref('ativo'), ENT_QUOTES, 'UTF-8') ?>">
      <div class="metric-top">
        <div>
          <div class="metric-label">Ativos</div>
          <div class="metric-value"><?= (int) $totalAtivosStats ?></div>
        </div>
        <div class="icon-box green">✓</div>
      </div>
      <div class="metric-change success">Em operação — clique para filtrar</div>
    </a>
    <a class="<?= htmlspecialchars($pontosMetricCardClass($metricaAtiva === 'inativo'), ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($pontosMetricHref('inativo'), ENT_QUOTES, 'UTF-8') ?>">
      <div class="metric-top">
        <div>
          <div class="metric-label">Inativos</div>
          <div class="metric-value"><?= (int) $totalInativosStats ?></div>
        </div>
        <div class="icon-box neutral">—</div>
      </div>
      <div class="metric-change">Fora de uso / desativados — clique para filtrar</div>
    </a>
  </div>

  <div class="card" id="mapa-pontos">
    <div class="panel-head">
      <h4>Mapa de acompanhamento</h4>
      <span class="panel-sub"><?= (int) $totalPontosStats ?> ponto(s) no parque · <?= htmlspecialchars($mapaSubtituloFiltro) ?> · carregamento progressivo por zoom</span>
    </div>
    <div class="panel-body">
      <div class="pontos-mapa-toolbar" aria-label="Vistas do mapa">
        <a href="<?= htmlspecialchars($pontosPainelFormAction) ?>?<?= htmlspecialchars($pontosListaQs(['filtro' => null])) ?>" class="btn btn-sm <?= $filtroMapa === '' ? 'btn-primary' : 'btn-secondary' ?>">Todos no mapa</a>
        <a href="<?= htmlspecialchars($pontosPainelFormAction) ?>?<?= htmlspecialchars($pontosListaQs(['filtro' => 'chamados'])) ?>" class="btn btn-sm <?= $filtroMapa === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Chamados (<?= (int) $comChamadoStats ?>)</a>
        <label class="pontos-mapa-cluster-toggle" for="map-toggle-cluster">
          <input type="checkbox" id="map-toggle-cluster" checked>
          <span>Agrupar em clusters</span>
        </label>
        <button type="button" id="map-toggle-force-points" class="btn btn-sm btn-secondary" aria-pressed="false">Ver postes individuais</button>
        <span class="pontos-mapa-toolbar-spacer" aria-hidden="true"></span>
        <?php if ($pontosPainelMostrarRotas && $pontosPainelHrefRotas !== ''): ?>
        <a href="<?= htmlspecialchars($pontosPainelHrefRotas) ?>" class="btn btn-sm btn-primary">Rotas</a>
        <?php endif; ?>
      </div>
      <?php require __DIR__ . '/partials/pontos_iluminacao_mapa_legenda.php'; ?>
      <div class="dashboard-pontos-tools" aria-label="Ferramentas do mapa">
        <div>
          <label for="map-filter-area">Área / bairro</label>
          <select id="map-filter-area" class="input">
            <option value="">Todas as áreas</option>
            <?php foreach ($bairrosMapa as $bairro): ?>
            <option value="<?= htmlspecialchars($bairro) ?>"><?= htmlspecialchars($bairro) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="map-filter-search">Buscar no mapa</label>
          <input id="map-filter-search" class="input" type="search" placeholder="Poste, endereço, bairro ou referência">
        </div>
        <div class="pontos-mapa-geo-filter-row" aria-label="Localizar no mapa por coordenadas">
          <div>
            <label for="map-filter-lat">Latitude</label>
            <input id="map-filter-lat" class="input" type="text" inputmode="decimal" placeholder="Ex.: -8.398075" autocomplete="off">
          </div>
          <div>
            <label for="map-filter-lng">Longitude</label>
            <input id="map-filter-lng" class="input" type="text" inputmode="decimal" placeholder="Ex.: -35.063889" autocomplete="off">
          </div>
          <button type="button" id="map-filter-geo-go" class="btn btn-sm btn-secondary">Ir</button>
        </div>
        <p class="pontos-mapa-geo-filter-hint">Por padrão, regiões agregadas. Aproxime o mapa ou use a busca para localizar postes. Busca por coordenadas: raio de 15 m.</p>
        <div class="dashboard-pontos-tools-status" id="map-visible-count" aria-live="polite" aria-busy="true">—</div>
        <div class="pontos-mapa-loading" id="map-loading-status" hidden aria-live="polite"></div>
      </div>
      <div id="pontos-iluminacao-map" role="region" aria-label="Mapa de pontos de iluminação"></div>
    </div>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Listagem de pontos</h4>
      <span class="panel-sub"><?= (int) $pontosTotalFiltrado ?> ponto(s) no filtro atual<?php if ($pontosTotalPages > 1): ?> · página <?= (int) $pontosPage ?> de <?= (int) $pontosTotalPages ?><?php endif; ?></span>
    </div>

    <form class="filters filters--form" method="get" action="<?= htmlspecialchars($pontosPainelFormAction) ?>">
      <?php foreach ($pontosPainelHiddenQuery as $hk => $hv): ?>
      <input type="hidden" name="<?= htmlspecialchars((string) $hk) ?>" value="<?= htmlspecialchars((string) $hv) ?>">
      <?php endforeach; ?>
      <?php if ($filtroMapa !== ''): ?>
      <input type="hidden" name="filtro" value="<?= htmlspecialchars($filtroMapa) ?>">
      <?php endif; ?>
      <div class="form-group form-group--grow">
        <label for="q">Buscar</label>
        <input type="search" id="q" name="q" class="input" value="<?= htmlspecialchars($q) ?>" placeholder="ID do poste, bairro, endereço ou referência">
      </div>
      <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status" class="select">
          <option value="" <?= $status === '' ? 'selected' : '' ?>>Todos<?= $filtroMapa === 'chamados' ? ' (com chamado aberto)' : '' ?></option>
          <option value="Ativo" <?= $status === 'Ativo' ? 'selected' : '' ?>>Ativos<?= $filtroMapa === 'chamados' ? ' (com chamado aberto)' : '' ?></option>
          <option value="Inativo" <?= $status === 'Inativo' ? 'selected' : '' ?>>Inativos<?= $filtroMapa === 'chamados' ? ' (com chamado aberto)' : '' ?></option>
        </select>
      </div>
      <div class="filters-form-actions">
        <button type="submit" class="btn btn-primary">Filtrar</button>
        <a href="#mapa-pontos" class="btn btn-secondary">Ir ao mapa</a>
        <a href="<?= htmlspecialchars($pontosPainelHrefNovoPoste) ?>" class="btn btn-secondary">Adicionar poste</a>
      </div>
    </form>

    <div class="table-wrap">
      <table data-crm-sortable>
        <thead>
          <tr class="crm-table-head-sort">
            <?php crm_sort_th('Poste', 'poste'); ?>
            <?php crm_sort_th('Prefeitura', 'prefeitura'); ?>
            <?php crm_sort_th('Local', 'local'); ?>
            <?php crm_sort_th('Chamados', 'chamados', ['type' => 'number']); ?>
            <?php crm_sort_th('Status', 'status'); ?>
            <?php crm_sort_th('Ações', null, ['class' => 'crm-table-col-acoes', 'right' => true]); ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$pontos): ?>
          <tr><td colspan="6" class="muted" style="padding:24px;text-align:center;">Nenhum ponto encontrado.</td></tr>
          <?php else: foreach ($pontos as $p): ?>
          <?php
            $localSort = trim((string) ($p['bairro'] ?? '') . ' ' . (string) ($p['endereco_completo'] ?? ''));
          ?>
          <tr <?= crm_sort_row_attr([
              'poste'      => (string) ($p['codigo_poste'] ?? ''),
              'prefeitura' => (string) ($p['cliente_empresa'] ?? ''),
              'local'      => $localSort,
              'chamados'   => (string) (int) ($p['chamados_abertos'] ?? 0),
              'status'     => (string) ($p['status'] ?? ''),
          ]) ?>>
            <td><strong><?= htmlspecialchars((string) ($p['codigo_poste'] ?? '')) ?></strong></td>
            <td><?= htmlspecialchars((string) ($p['cliente_empresa'] ?? '')) ?></td>
            <td class="td-mute"><?= htmlspecialchars((string) ($p['bairro'] ?? '')) ?><?php if (!empty($p['endereco_completo'])): ?><br><small><?= htmlspecialchars((string) $p['endereco_completo']) ?></small><?php endif; ?></td>
            <td>
              <span class="badge <?= ((int) ($p['chamados_abertos'] ?? 0)) > 0 ? 'urgent' : 'done' ?>">
                <?= (int) ($p['chamados_abertos'] ?? 0) ?> aberto(s)
              </span>
            </td>
            <td><span class="badge <?= ($p['status'] ?? '') === 'Ativo' ? 'done' : 'plain' ?>"><?= htmlspecialchars((string) ($p['status'] ?? '')) ?></span></td>
            <td class="td-actions">
              <?php
                $cidLinha = (int) ($p['cliente_id'] ?? $pontosPainelEscopoId);
              $qsEdit = 'id=' . (int) ($p['id'] ?? 0) . '&cliente_id=' . $cidLinha;
              ?>
              <a class="action primary" href="ponto_iluminacao_novo.php?<?= htmlspecialchars($qsEdit) ?>">Editar</a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="pagination">
      <span class="pag-info">Mostrando <?= (int) $pontosMostrarDe ?>–<?= (int) $pontosMostrarAte ?> de <?= (int) $pontosTotalFiltrado ?></span>
      <?php if ($pontosTotalPages > 1): ?>
      <div class="pag-controls">
        <?php if ($pontosPage <= 1): ?>
          <span class="pag-btn pag-btn--static" aria-hidden="true">‹</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars($pontosPainelFormAction . '?' . $pontosListaPageUrl($pontosPage - 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Página anterior">‹</a>
        <?php endif; ?>

        <?php foreach ($pontosPaginasVisiveis as $pv): ?>
          <?php if ($pv === 0): ?>
            <span class="pag-ellipsis" aria-hidden="true">…</span>
          <?php elseif ($pv === $pontosPage): ?>
            <span class="pag-btn active" aria-current="page"><?= (int) $pv ?></span>
          <?php else: ?>
            <a class="pag-btn" href="<?= htmlspecialchars($pontosPainelFormAction . '?' . $pontosListaPageUrl((int) $pv), ENT_QUOTES, 'UTF-8') ?>"><?= (int) $pv ?></a>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($pontosPage >= $pontosTotalPages): ?>
          <span class="pag-btn pag-btn--static" aria-hidden="true">›</span>
        <?php else: ?>
          <a class="pag-btn" href="<?= htmlspecialchars($pontosPainelFormAction . '?' . $pontosListaPageUrl($pontosPage + 1), ENT_QUOTES, 'UTF-8') ?>" aria-label="Próxima página">›</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php
$pontosMapPins = [];
$crmPontosMapaViewport = true;
$crmPontosMapaConfig = crm_pontos_mapa_js_config(
    $pontosPainelEscopoId,
    [
        'status' => $status,
        'somente_chamados_abertos' => $filtroMapa === 'chamados',
    ],
    $basePath ?? '../'
);
$loadPontosMapGoogle = !empty($loadPontosMapGoogle);
$loadLeaflet = !empty($loadLeaflet) || !$loadPontosMapGoogle;
require __DIR__ . '/partials/pontos_iluminacao_map_scripts.php';
?>

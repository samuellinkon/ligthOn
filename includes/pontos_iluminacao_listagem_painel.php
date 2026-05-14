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

$pontosTodosEscopo = repo_pontos_iluminacao_list($pontosPainelEscopoId, true, '', '');
$totalPontosStats  = count($pontosTodosEscopo);
$comChamadoStats   = count(array_filter($pontosTodosEscopo, static function (array $p): bool {
    return ((int) ($p['chamados_abertos'] ?? 0)) > 0;
}));
$totalAtivosStats = count(array_filter($pontosTodosEscopo, static function (array $p): bool {
    return ($p['status'] ?? '') === 'Ativo';
}));
$totalInativosStats = count(array_filter($pontosTodosEscopo, static function (array $p): bool {
    return ($p['status'] ?? '') === 'Inativo';
}));

$pontos = repo_pontos_iluminacao_list($pontosPainelEscopoId, true, $q, $status);

$pinsTodos = repo_pontos_iluminacao_mapa($pontosPainelEscopoId, true);
$pinsMapa  = $pinsTodos;
if ($filtroMapa === 'chamados') {
    $pinsMapa = array_values(array_filter($pinsTodos, static function (array $p): bool {
        return ((int) ($p['chamados_abertos'] ?? 0)) > 0;
    }));
}
$bairrosMapa = [];
foreach ($pinsMapa as $p) {
    $bairro = trim((string) ($p['bairro'] ?? ''));
    if ($bairro !== '') {
        $bairrosMapa[$bairro] = true;
    }
}
$bairrosMapa = array_keys($bairrosMapa);
natcasesort($bairrosMapa);
$bairrosMapa = array_values($bairrosMapa);

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

?>
<section class="content pontos-iluminacao-lista-layout">
  <div class="cards-metrics" style="margin-bottom:20px;">
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Total de pontos</div>
          <div class="metric-value"><?= (int) $totalPontosStats ?></div>
        </div>
        <div class="icon-box purple">PT</div>
      </div>
      <div class="metric-change info">Parque no escopo da empresa</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Em chamados abertos</div>
          <div class="metric-value"><?= (int) $comChamadoStats ?></div>
        </div>
        <div class="icon-box red">!</div>
      </div>
      <div class="metric-change warning">Postes com chamado em aberto</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Ativos</div>
          <div class="metric-value"><?= (int) $totalAtivosStats ?></div>
        </div>
        <div class="icon-box green">✓</div>
      </div>
      <div class="metric-change success">Em operação</div>
    </div>
    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Inativos</div>
          <div class="metric-value"><?= (int) $totalInativosStats ?></div>
        </div>
        <div class="icon-box neutral">—</div>
      </div>
      <div class="metric-change">Fora de uso / desativados</div>
    </div>
  </div>

  <div class="card" id="mapa-pontos">
    <div class="panel-head">
      <h4>Mapa de acompanhamento</h4>
      <span class="panel-sub"><?= count($pinsMapa) ?> de <?= count($pinsTodos) ?> ponto(s) ativo(s) com coordenadas · vermelho = chamados abertos</span>
    </div>
    <div class="panel-body">
      <div class="pontos-mapa-toolbar" aria-label="Vistas do mapa">
        <a href="<?= htmlspecialchars($pontosPainelFormAction) ?>?<?= htmlspecialchars($pontosListaQs(['filtro' => null])) ?>" class="btn btn-sm <?= $filtroMapa === '' ? 'btn-primary' : 'btn-secondary' ?>">Todos no mapa</a>
        <a href="<?= htmlspecialchars($pontosPainelFormAction) ?>?<?= htmlspecialchars($pontosListaQs(['filtro' => 'chamados'])) ?>" class="btn btn-sm <?= $filtroMapa === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Chamados (<?= (int) $comChamadoStats ?>)</a>
        <label class="pontos-mapa-cluster-toggle" for="map-toggle-cluster">
          <input type="checkbox" id="map-toggle-cluster" checked>
          <span>Agrupar em clusters</span>
        </label>
        <span class="pontos-mapa-toolbar-spacer" aria-hidden="true"></span>
        <?php if ($pontosPainelMostrarRotas && $pontosPainelHrefRotas !== ''): ?>
        <a href="<?= htmlspecialchars($pontosPainelHrefRotas) ?>" class="btn btn-sm btn-primary">Rotas</a>
        <?php endif; ?>
      </div>
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
        <div class="dashboard-pontos-tools-status" id="map-visible-count"><?= count($pinsMapa) ?> ponto(s) visível(is)</div>
      </div>
      <div id="pontos-iluminacao-map" role="region" aria-label="Mapa de pontos de iluminação"></div>
    </div>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Listagem de pontos</h4>
      <span class="panel-sub"><?= count($pontos) ?> ponto(s) no filtro atual</span>
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
          <option value="">Todos</option>
          <option value="Ativo" <?= $status === 'Ativo' ? 'selected' : '' ?>>Ativos</option>
          <option value="Inativo" <?= $status === 'Inativo' ? 'selected' : '' ?>>Inativos</option>
        </select>
      </div>
      <div class="filters-form-actions">
        <button type="submit" class="btn btn-primary">Filtrar</button>
        <a href="#mapa-pontos" class="btn btn-secondary">Ir ao mapa</a>
        <a href="<?= htmlspecialchars($pontosPainelHrefNovoPoste) ?>" class="btn btn-secondary">Adicionar poste</a>
      </div>
    </form>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Poste</th>
            <th>Prefeitura</th>
            <th>Local</th>
            <th>Chamados</th>
            <th>Status</th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$pontos): ?>
          <tr><td colspan="6" class="muted" style="padding:24px;text-align:center;">Nenhum ponto encontrado.</td></tr>
          <?php else: foreach ($pontos as $p): ?>
          <tr>
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
  </div>
</section>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js" crossorigin=""></script>
<script>
  window.PONTOS_ILUMINACAO_MAP = <?= json_encode($pinsMapa, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;
</script>
<script src="<?= htmlspecialchars($basePath ?? '../') ?>assets/js/pontos-iluminacao-map.js?v=<?= (int) @filemtime(dirname(__DIR__) . '/assets/js/pontos-iluminacao-map.js') ?>"></script>

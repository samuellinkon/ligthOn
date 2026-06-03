  <div class="dashboard-admin-metrics">
  <div class="cards-metrics">
    <a class="card metric metric--link" href="<?= htmlspecialchars($dashHrefChamados ?? 'chamados.php', ENT_QUOTES, 'UTF-8') ?>" title="Ver todos os chamados">
      <div class="metric-top">
        <div>
          <div class="metric-label">Total de Chamados</div>
          <div class="metric-value"><?= number_format($dash ? (int) ($dash['ch_total'] ?? 0) : count($MOCK_CHAMADOS), 0, ',', '.') ?></div>
        </div>
        <div class="icon-box">CH</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? 'Clique para abrir a listagem' : 'Sem conexão ao banco' ?></div>
    </a>

    <a class="card metric metric--link" href="<?= htmlspecialchars($dashHrefChamadosAbertos ?? 'chamados.php?f=Aberto', ENT_QUOTES, 'UTF-8') ?>" title="Filtrar chamados abertos">
      <div class="metric-top">
        <div>
          <div class="metric-label">Total de Chamados Abertos</div>
          <div class="metric-value"><?= number_format($dash ? (int) $dash['ch_abertos'] : count(array_filter($MOCK_CHAMADOS, fn ($c) => ($c['status'] ?? '') === 'Aberto')), 0, ',', '.') ?></div>
        </div>
        <div class="icon-box">AB</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? 'Filtra a listagem por Aberto' : 'Sem conexão ao banco' ?></div>
    </a>

    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Total de pontos</div>
          <div class="metric-value"><?= number_format($dash ? (int) ($dash['pontos_total'] ?? 0) : 0, 0, ',', '.') ?></div>
        </div>
        <div class="icon-box">PT</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? ($dashPainel === 'cliente' ? 'Pontos de iluminação na matriz' : ($escopoDash !== null ? 'Iluminação no escopo da gestão' : 'Pontos de iluminação cadastrados')) : 'Sem conexão ao banco' ?></div>
    </div>

    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Medição do mês (BM)</div>
          <div class="metric-value" style="font-size:1.25rem;">R$ <?= $dash ? number_format((float) ($dash['medicao_mes_valor'] ?? 0), 2, ',', '.') : '0,00' ?></div>
        </div>
        <div class="icon-box">BM</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? htmlspecialchars(medicao_mes_label_pt($refYmDashboard)) . ' · chamados com status Validado' : 'Sem conexão ao banco' ?></div>
    </div>
  </div>
  </div>

  <?php if ($moduleChamadosMap || $modulePontosMap): ?>
  <div class="dashboard-maps-row" style="grid-template-columns:1fr;">
    <?php if ($loadLeafletChamados): ?>
    <div class="card dashboard-map-card dashboard-map-card--chamados">
      <div class="panel-head dashboard-map-panel-head--compact dashboard-map-header">
        <div class="dashboard-map-header__content dashboard-map-panel-head-main">
          <h4>Mapa de chamados</h4>
          <span class="panel-sub">Geolocalização pela data de abertura · <?= htmlspecialchars($mapPeriodoLabel) ?></span>
        </div>
        <div class="dashboard-map-header__badges">
          <div class="dashboard-pontos-tools-status dashboard-map-filters-chamados-meta dashboard-map-head-badge dashboard-map-head-badge--primary" id="chamados-map-visible-count"><?php $nCh = count($mapPins); ?><?= (int) $nCh ?> de <?= (int) $nCh ?> chamado(s) visível(is)</div>
          <span class="dashboard-map-head-badge dashboard-map-head-badge--secondary"><?= htmlspecialchars($mapPeriodoLabel) ?></span>
        </div>
      </div>
      <div class="panel-body dashboard-map-panel-body--compact dashboard-map-chamados-layout">
        <div class="dashboard-map-main-row">
          <?php require __DIR__ . '/dashboard_map_type_tabs.php'; ?>
        </div>
        <div class="dashboard-map-filter-row" aria-label="Filtros do mapa de chamados">
          <?php require __DIR__ . '/dashboard_map_periodo_filtro.php'; ?>
          <form class="dashboard-map-filters dashboard-map-filters--chamados dashboard-map-filters--chamados-controls" method="get" action="index.php">
            <input type="hidden" name="dash_mapa" value="chamados">
            <input type="hidden" name="map_periodo" value="<?= htmlspecialchars($mapPeriodo) ?>">
            <?php if ($mapPeriodo === 'mes' && ($mapMes ?? '') !== ''): ?>
            <input type="hidden" name="map_mes" value="<?= htmlspecialchars((string) $mapMes) ?>">
            <?php endif; ?>
            <?php if ($dashPainel === 'admin' && $escopoDash === null && $scopeIdPontos > 0 && !$modoClienteUnicoAdmin): ?>
            <input type="hidden" name="dash_cliente_id" value="<?= (int) $scopeIdPontos ?>">
            <?php endif; ?>
            <?php if ($pontoMapFiltro !== ''): ?>
            <input type="hidden" name="ponto_filtro" value="<?= htmlspecialchars($pontoMapFiltro) ?>">
            <?php endif; ?>
            <div class="form-group form-group--chamados-map-status">
              <label for="chamados-map-filter-status" class="dashboard-map-sr-label">Status</label>
              <select id="chamados-map-filter-status" class="input dashboard-map-status-select" aria-label="Filtrar por status">
                <option value="">Todos os status</option>
                <?php foreach ($statusChamadosMap as $statusMap): ?>
                <option value="<?= htmlspecialchars($statusMap) ?>"><?= htmlspecialchars($statusMap) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-secondary btn-sm dashboard-map-btn-refresh">Atualizar</button>
          </form>
        </div>
        <div class="dashboard-map-tools-row map-toolbar-secondary dashboard-map-toolbar-secondary" aria-label="Ferramentas do mapa de chamados">
          <label class="dashboard-map-cluster-toggle dashboard-pontos-tools-toggle" for="chamados-map-toggle-cluster">
            <input type="checkbox" id="chamados-map-toggle-cluster" checked>
            Clusters
          </label>
          <?php require __DIR__ . '/chamados_mapa_legenda.php'; ?>
        </div>
        <div class="dashboard-map-resize-wrap" data-map-resize-key="<?= htmlspecialchars($dashMapResizePrefix) ?>_chamados">
          <div id="chamados-map" class="dashboard-map-leaflet-host" role="region" aria-label="Mapa de chamados"></div>
          <button type="button" class="dashboard-map-resize-handle" aria-label="Redimensionar altura do mapa" title="Arraste para ajustar a altura (salvo neste navegador)"></button>
        </div>
        <p class="muted dashboard-map-footnote"><?= count($mapPins) ?> chamado(s) com coordenadas · período: <?= htmlspecialchars($mapPeriodoLabel) ?>.</p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($loadPontosMap): ?>
    <div class="card dashboard-map-card dashboard-map-card--pontos">
      <div class="panel-head dashboard-map-panel-head--compact dashboard-map-header">
        <div class="dashboard-map-header__content dashboard-map-panel-head-main">
          <h4>Mapa de iluminação</h4>
          <span class="panel-sub"><?= number_format((int) $totalPontosComGeo, 0, ',', '.') ?> ponto(s) no parque · carregamento por área visível</span>
        </div>
        <div class="dashboard-map-header__badges">
          <div class="dashboard-pontos-tools-status dashboard-map-filters-pontos-meta dashboard-map-head-badge dashboard-map-head-badge--primary" id="map-visible-count" aria-live="polite" aria-busy="true">—</div>
          <span class="dashboard-map-head-badge dashboard-map-head-badge--secondary"><?= htmlspecialchars($dashPontosMapSubtitulo) ?></span>
        </div>
      </div>
      <div class="panel-body dashboard-map-panel-body--compact dashboard-map-pontos-layout">
        <div class="dashboard-map-main-row">
          <?php require __DIR__ . '/dashboard_map_type_tabs.php'; ?>
          <div class="dashboard-map-summary-chips" role="group" aria-label="Visão do parque de postes">
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos', 'ponto_filtro' => null])) ?>" class="dashboard-map-summary-chip<?= $pontoMapFiltro === '' ? ' is-active' : '' ?>">
              <span class="dashboard-map-summary-chip__label">Todos</span>
              <span class="dashboard-map-summary-chip__value"><?= number_format((int) $totalPontosComGeo, 0, ',', '.') ?></span>
            </a>
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos', 'ponto_filtro' => 'ativo'])) ?>" class="dashboard-map-summary-chip<?= $pontoMapFiltro === 'ativo' ? ' is-active' : '' ?>">
              <span class="dashboard-map-summary-chip__label">Ativos</span>
              <span class="dashboard-map-summary-chip__value"><?= number_format((int) $totalAtivosPontosDash, 0, ',', '.') ?></span>
            </a>
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos', 'ponto_filtro' => 'chamados'])) ?>" class="dashboard-map-summary-chip<?= $pontoMapFiltro === 'chamados' ? ' is-active' : '' ?>">
              <span class="dashboard-map-summary-chip__label">Com chamado</span>
              <span class="dashboard-map-summary-chip__value"><?= number_format((int) $totalPontosComChamados, 0, ',', '.') ?></span>
            </a>
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos', 'ponto_filtro' => 'inativo'])) ?>" class="dashboard-map-summary-chip<?= $pontoMapFiltro === 'inativo' ? ' is-active' : '' ?>">
              <span class="dashboard-map-summary-chip__label">Inativos</span>
              <span class="dashboard-map-summary-chip__value"><?= number_format((int) $totalInativosPontosDash, 0, ',', '.') ?></span>
            </a>
          </div>
        </div>
        <div class="dashboard-map-filter-row" aria-label="Filtros operacionais do mapa de postes">
          <?php $dashboardMapPeriodoAba = 'pontos'; require __DIR__ . '/dashboard_map_periodo_filtro.php'; unset($dashboardMapPeriodoAba); ?>
          <?php if ($dashPainel === 'admin' && $escopoDash === null && !empty($empresasDashOptions) && !$modoClienteUnicoAdmin): ?>
          <form class="dashboard-map-filters dashboard-map-filters--pontos-empresa dashboard-map-filters--pontos-controls" method="get" action="index.php">
            <input type="hidden" name="dash_mapa" value="pontos">
            <input type="hidden" name="map_periodo" value="<?= htmlspecialchars($mapPeriodo) ?>">
            <?php if ($mapPeriodo === 'mes' && ($mapMes ?? '') !== ''): ?>
            <input type="hidden" name="map_mes" value="<?= htmlspecialchars((string) $mapMes) ?>">
            <?php endif; ?>
            <?php if ($pontoMapFiltro !== ''): ?>
            <input type="hidden" name="ponto_filtro" value="<?= htmlspecialchars($pontoMapFiltro) ?>">
            <?php endif; ?>
            <div class="form-group form-group--pontos-map-empresa">
              <label for="dash_cliente_id" class="dashboard-map-filter-label">Empresa</label>
              <select id="dash_cliente_id" name="dash_cliente_id" class="select dashboard-map-empresa-select" aria-label="Empresa do mapa" onchange="this.form.submit()">
                <?php foreach ($empresasDashOptions as $empOp): ?>
                <option value="<?= (int) $empOp['id'] ?>" <?= (int) $scopeIdPontos === (int) $empOp['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) ($empOp['empresa'] ?? $empOp['nome'] ?? 'Cadastro')) ?> #<?= (int) $empOp['id'] ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>
          <?php endif; ?>
          <div class="dashboard-map-filters dashboard-map-filters--pontos-controls">
            <div class="form-group form-group--pontos-map-area">
              <label for="map-filter-area" class="dashboard-map-filter-label">Área</label>
              <select id="map-filter-area" class="input dashboard-map-area-select" aria-label="Filtrar por área ou bairro">
                <option value="">Todas as áreas</option>
                <?php foreach ($bairrosPontosDash as $bairro): ?>
                <option value="<?= htmlspecialchars($bairro) ?>"><?= htmlspecialchars($bairro) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group form-group--pontos-map-search">
              <label for="map-filter-search" class="dashboard-map-filter-label">Buscar</label>
              <input id="map-filter-search" class="input dashboard-map-search-input" type="search" placeholder="Poste, endereço, bairro ou referência" aria-label="Buscar no mapa">
            </div>
          </div>
        </div>
        <div class="dashboard-map-tools-row map-toolbar-secondary dashboard-map-toolbar-secondary" aria-label="Ferramentas do mapa de postes">
          <label class="dashboard-map-cluster-toggle dashboard-pontos-tools-toggle" for="map-toggle-cluster">
            <input type="checkbox" id="map-toggle-cluster" checked>
            Clusters
          </label>
          <?php require __DIR__ . '/pontos_iluminacao_mapa_legenda.php'; ?>
          <?php if ($dashPontosHrefRotas !== ''): ?>
          <a href="<?= htmlspecialchars($dashPontosHrefRotas) ?>" class="btn btn-sm btn-secondary dashboard-map-btn-rotas">Rotas</a>
          <?php endif; ?>
        </div>
        <div class="dashboard-map-resize-wrap" data-map-resize-key="<?= htmlspecialchars($dashMapResizePrefix) ?>_pontos">
          <div id="pontos-iluminacao-map" class="dashboard-map-leaflet-host" role="region" aria-label="Mapa de pontos de iluminação"></div>
          <button type="button" class="dashboard-map-resize-handle" aria-label="Redimensionar altura do mapa" title="Arraste para ajustar a altura (salvo neste navegador)"></button>
        </div>
        <p class="muted dashboard-map-footnote"><?= number_format((int) $totalPontosComGeo, 0, ',', '.') ?> ponto(s) com coordenadas no escopo · <?= htmlspecialchars($dashPontosMapSubtitulo) ?>.</p>
      </div>
    </div>
    <?php elseif ($modulePontosMap && $scopeIdPontos <= 0): ?>
    <div class="card dashboard-map-card dashboard-map-card--half">
      <div class="panel-head"><h4>Mapa de iluminação</h4></div>
      <div class="panel-body">
        <p class="muted" style="margin:0;"><?= $dashPainel === 'admin' ? 'Cadastre a prefeitura (matriz) para ver postes no mapa.' : 'Não foi possível carregar os postes no mapa.' ?></p>
        <?php if ($dashPainel === 'admin'): ?>
        <a href="clientes.php" class="btn btn-secondary btn-sm" style="margin-top:12px;">Ir à prefeitura</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($loadMapaCombinado): ?>
    <div class="card dashboard-map-card">
      <div class="panel-head">
        <h4>Mapa combinado</h4>
        <span class="panel-sub">Chamados no período e postes no mesmo mapa — use as camadas e os filtros abaixo.</span>
      </div>
      <div class="panel-body">
        <div class="dashboard-map-filters" style="margin-bottom:12px;">
          <?php $dashboardMapPeriodoAba = 'ambos'; require __DIR__ . '/dashboard_map_periodo_filtro.php'; unset($dashboardMapPeriodoAba); ?>
        </div>

        <?php if ($dashPainel === 'admin' && $escopoDash === null && !empty($empresasDashOptions) && !$modoClienteUnicoAdmin): ?>
        <form class="dashboard-map-filters" method="get" action="index.php" style="margin-bottom:12px;">
          <input type="hidden" name="dash_mapa" value="ambos">
          <input type="hidden" name="map_periodo" value="<?= htmlspecialchars($mapPeriodo) ?>">
          <?php if ($pontoMapFiltro !== ''): ?>
          <input type="hidden" name="ponto_filtro" value="<?= htmlspecialchars($pontoMapFiltro) ?>">
          <?php endif; ?>
          <div class="form-group form-group--grow">
            <label for="combo_dash_cliente_id">Empresa (postes no mapa)</label>
            <select id="combo_dash_cliente_id" name="dash_cliente_id" class="select" onchange="this.form.submit()">
              <?php foreach ($empresasDashOptions as $empOp): ?>
              <option value="<?= (int) $empOp['id'] ?>" <?= (int) $scopeIdPontos === (int) $empOp['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) ($empOp['empresa'] ?? $empOp['nome'] ?? 'Cadastro')) ?> #<?= (int) $empOp['id'] ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
        <?php endif; ?>

        <div style="display:flex;flex-wrap:wrap;gap:12px 20px;align-items:center;margin-bottom:14px;padding:10px 12px;background:var(--card-inner-bg, rgba(0,0,0,.04));border-radius:8px;">
          <span class="muted" style="font-size:13px;margin:0;">Exibir no mapa:</span>
          <label class="dashboard-pontos-tools-toggle" style="margin:0;">
            <input type="checkbox" id="combo-layer-chamados" checked>
            Chamados
          </label>
          <label class="dashboard-pontos-tools-toggle" style="margin:0;">
            <input type="checkbox" id="combo-layer-pontos" checked>
            Postes
          </label>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
          <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'ponto_filtro' => null])) ?>" class="btn btn-sm <?= $pontoMapFiltro === '' ? 'btn-primary' : 'btn-secondary' ?>">Todos os pontos</a>
          <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'ponto_filtro' => 'chamados'])) ?>" class="btn btn-sm <?= $pontoMapFiltro === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Postes com chamados (<?= (int) $totalPontosComChamados ?>)</a>
          <?php if ($dashPainel === 'admin'): ?>
          <a href="pontos_iluminacao_mapa.php?cliente_id=<?= (int) $scopeIdPontos ?>" class="btn btn-sm btn-secondary">Mapa completo de postes</a>
          <?php else: ?>
          <a href="pontos_iluminacao.php" class="btn btn-sm btn-secondary">Lista de postes</a>
          <?php endif; ?>
        </div>

        <p class="muted" style="font-size:12px;margin:0 0 10px;">
          <?= count($mapPins) ?> chamado(s) com coordenadas no período · <?= (int) $totalPontosComGeo ?> poste(s) no parque (por área visível)
        </p>

        <div class="dashboard-pontos-tools dashboard-chamados-tools" aria-label="Filtros dos chamados no mapa combinado" style="margin-bottom:10px;">
          <div>
            <label for="combo-chamados-filter-status">Chamados — status</label>
            <select id="combo-chamados-filter-status" class="input">
              <option value="">Todos os status</option>
              <?php foreach ($statusChamadosMap as $statusMap): ?>
              <option value="<?= htmlspecialchars($statusMap) ?>"><?= htmlspecialchars($statusMap) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="combo-chamados-filter-search">Chamados — buscar</label>
            <input id="combo-chamados-filter-search" class="input" type="search" placeholder="Chamado, status ou endereço">
          </div>
        </div>

        <div class="dashboard-pontos-tools" aria-label="Filtros dos postes no mapa combinado" style="margin-bottom:10px;">
          <div>
            <label for="combo-pontos-filter-area">Postes — área / bairro</label>
            <select id="combo-pontos-filter-area" class="input">
              <option value="">Todas as áreas</option>
              <?php foreach ($bairrosPontosDash as $bairro): ?>
              <option value="<?= htmlspecialchars($bairro) ?>"><?= htmlspecialchars($bairro) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="combo-pontos-filter-search">Postes — buscar</label>
            <input id="combo-pontos-filter-search" class="input" type="search" placeholder="Poste, endereço, bairro ou referência">
          </div>
          <label class="dashboard-pontos-tools-toggle" for="combo-map-toggle-cluster">
            <input type="checkbox" id="combo-map-toggle-cluster" checked>
            Agrupar clusters
          </label>
          <div class="dashboard-pontos-tools-status" id="combo-map-visible-chamados">—</div>
          <div class="dashboard-pontos-tools-status" id="combo-map-visible-pontos">—</div>
        </div>

        <?php require __DIR__ . '/chamados_mapa_legenda.php'; ?>

        <div class="dashboard-map-resize-wrap" data-map-resize-key="<?= htmlspecialchars($dashMapResizePrefix) ?>_combinado">
          <div id="dashboard-mapa-combinado" class="dashboard-map-leaflet-host" role="region" aria-label="Mapa combinado: chamados e postes"></div>
          <button type="button" class="dashboard-map-resize-handle" aria-label="Redimensionar altura do mapa" title="Arraste para ajustar a altura (salvo neste navegador)"></button>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

    <div class="grid-main" style="grid-template-columns:1fr;">
    <div class="card">
      <div class="panel-head">
        <h4>Últimos chamados</h4>
        <a href="chamados.php">Ver todos</a>
      </div>
      <div class="table-wrap">
        <table data-crm-sortable>
          <thead>
            <tr class="crm-table-head-sort">
              <?php crm_sort_th('ID', 'id', ['type' => 'number']); ?>
              <?php crm_sort_th('Título', 'titulo'); ?>
              <?php if ($dashMostrarColunaOrgao): ?>
              <?php crm_sort_th('Órgão', 'orgao'); ?>
              <?php endif; ?>
              <?php crm_sort_th('Status', 'status'); ?>
              <?php crm_sort_th('Prioridade', 'prioridade', ['type' => 'number']); ?>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($ultimosCh)): ?>
            <tr>
              <td colspan="<?= $dashMostrarColunaOrgao ? 5 : 4 ?>" class="muted" style="padding:24px;text-align:center;">Nenhum chamado encontrado.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($ultimosCh as $c): ?>
              <?php
                $dashSort = [
                    'id'         => (string) (int) ($c['id'] ?? 0),
                    'titulo'     => (string) ($c['titulo'] ?? ''),
                    'status'     => (string) ($c['status'] ?? ''),
                    'prioridade' => (string) crm_sort_prioridade_rank((string) ($c['prioridade'] ?? '')),
                ];
                if ($dashMostrarColunaOrgao) {
                    $dashSort['orgao'] = (string) ($c['cliente'] ?? '');
                }
              ?>
              <tr onclick="location.href='chamado_detalhe.php?id=<?= (int) $c['id'] ?>'" style="cursor:pointer;" <?= crm_sort_row_attr($dashSort) ?>>
                <td class="td-id">#<?= (int) $c['id'] ?></td>
                <td class="td-title"><?= htmlspecialchars($c['titulo']) ?></td>
                <?php if ($dashMostrarColunaOrgao): ?>
                <td class="td-mute"><?= htmlspecialchars($c['cliente'] ?? '') ?></td>
                <?php endif; ?>
                <td><span class="badge <?= status_class($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                <td><span class="badge <?= status_class($c['prioridade']) ?>"><?= htmlspecialchars($c['prioridade']) ?></span></td>
              </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

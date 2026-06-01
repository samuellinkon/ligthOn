  <div class="dashboard-admin-metrics">
  <div class="cards-metrics">
    <a class="card metric metric--link" href="<?= htmlspecialchars($dashHrefChamados ?? 'chamados.php', ENT_QUOTES, 'UTF-8') ?>" title="Ver todos os chamados">
      <div class="metric-top">
        <div>
          <div class="metric-label">Total de Chamados</div>
          <div class="metric-value"><?= $dash ? (int) ($dash['ch_total'] ?? 0) : count($MOCK_CHAMADOS) ?></div>
        </div>
        <div class="icon-box">CH</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? 'Clique para abrir a listagem' : 'Sem conexão ao banco' ?></div>
    </a>

    <a class="card metric metric--link" href="<?= htmlspecialchars($dashHrefChamadosAbertos ?? 'chamados.php?f=Aberto', ENT_QUOTES, 'UTF-8') ?>" title="Filtrar chamados abertos">
      <div class="metric-top">
        <div>
          <div class="metric-label">Total de Chamados Abertos</div>
          <div class="metric-value"><?= $dash ? (int) $dash['ch_abertos'] : count(array_filter($MOCK_CHAMADOS, fn ($c) => ($c['status'] ?? '') === 'Aberto')) ?></div>
        </div>
        <div class="icon-box">AB</div>
      </div>
      <div class="metric-change metric-change--admin"><?= $dash ? 'Filtra a listagem por Aberto' : 'Sem conexão ao banco' ?></div>
    </a>

    <div class="card metric">
      <div class="metric-top">
        <div>
          <div class="metric-label">Total de pontos</div>
          <div class="metric-value"><?= $dash ? (int) ($dash['pontos_total'] ?? 0) : 0 ?></div>
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
    <div class="card dashboard-map-card">
      <div class="panel-head">
        <h4>Mapa de chamados</h4>
        <span class="panel-sub">Geolocalização dos chamados pela data de abertura</span>
      </div>
      <div class="panel-body">
        <form class="dashboard-map-filters dashboard-map-filters--chamados" method="get" action="index.php" aria-label="Filtros e ferramentas do mapa de chamados">
          <input type="hidden" name="dash_mapa" value="chamados">
          <input type="hidden" name="map_periodo" value="<?= htmlspecialchars($mapPeriodo) ?>">
          <?php if ($dashPainel === 'admin' && $escopoDash === null && $scopeIdPontos > 0 && !$modoClienteUnicoAdmin): ?>
          <input type="hidden" name="dash_cliente_id" value="<?= (int) $scopeIdPontos ?>">
          <?php endif; ?>
          <?php if ($pontoMapFiltro !== ''): ?>
          <input type="hidden" name="ponto_filtro" value="<?= htmlspecialchars($pontoMapFiltro) ?>">
          <?php endif; ?>
          <div class="dashboard-map-chamados-toolbar">
            <div class="dashboard-map-type-tabs" role="group" aria-label="Tipo de mapa">
              <?php if ($moduleChamadosMap): ?>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Chamados</a>
              <?php endif; ?>
              <?php if ($modulePontosMap): ?>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'pontos' ? 'btn-primary' : 'btn-secondary' ?>">Pontos</a>
              <?php endif; ?>
              <?php if ($dashMapaCombinadoHabilitado && $moduleChamadosMap && $modulePontosMap && $scopeIdPontos > 0): ?>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'ambos' ? 'btn-primary' : 'btn-secondary' ?>">Mapa combinado</a>
              <?php endif; ?>
            </div>
            <div class="dashboard-map-chamados-period" role="group" aria-label="Período do mapa">
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados', 'map_periodo' => 'hoje'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'hoje' ? 'btn-primary' : 'btn-secondary' ?>">Dia atual</a>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados', 'map_periodo' => 'ontem'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'ontem' ? 'btn-primary' : 'btn-secondary' ?>">Dia anterior</a>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados', 'map_periodo' => 'semana'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'semana' ? 'btn-primary' : 'btn-secondary' ?>">Semana atual</a>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados', 'map_periodo' => 'mes'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'mes' ? 'btn-primary' : 'btn-secondary' ?>">Mês atual</a>
            </div>
          </div>
          <div class="form-group form-group--chamados-map-status">
            <label for="chamados-map-filter-status">Status</label>
            <select id="chamados-map-filter-status" class="input">
              <option value="">Todos os status</option>
              <?php foreach ($statusChamadosMap as $statusMap): ?>
              <option value="<?= htmlspecialchars($statusMap) ?>"><?= htmlspecialchars($statusMap) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary">Atualizar mapa</button>
          <label class="dashboard-pontos-tools-toggle" for="chamados-map-toggle-cluster">
            <input type="checkbox" id="chamados-map-toggle-cluster" checked>
            Agrupar clusters
          </label>
          <div class="dashboard-pontos-tools-status dashboard-map-filters-chamados-meta" id="chamados-map-visible-count"><?php $nCh = count($mapPins); ?><?= (int) $nCh ?> de <?= (int) $nCh ?> chamado(s) visível(is)</div>
        </form>
        <div class="dashboard-map-resize-wrap" data-map-resize-key="<?= htmlspecialchars($dashMapResizePrefix) ?>_chamados">
          <div id="chamados-map" class="dashboard-map-leaflet-host" role="region" aria-label="Mapa de chamados"></div>
          <button type="button" class="dashboard-map-resize-handle" aria-label="Redimensionar altura do mapa" title="Arraste para ajustar a altura (salvo neste navegador)"></button>
        </div>
        <p class="muted" style="font-size:12px;margin-top:10px;margin-bottom:0;">
          <?= count($mapPins) ?> chamado(s) com coordenadas no período.
        </p>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($loadPontosMap): ?>
    <div class="card dashboard-map-card">
      <div class="panel-head">
        <h4>Mapa de iluminação</h4>
        <span class="panel-sub"><?= count($pontosPinsPass) ?> de <?= count($pontosPinsTodos) ?> ponto(s) · postes em azul com chamados abertos</span>
      </div>
      <div class="panel-body">
        <div class="dashboard-map-filters dashboard-map-filters--pontos" aria-label="Filtros e ferramentas do mapa de postes">
          <div class="dashboard-map-pontos-toolbar">
            <div class="dashboard-map-type-tabs" role="group" aria-label="Tipo de mapa">
              <?php if ($moduleChamadosMap): ?>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'chamados'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Chamados</a>
              <?php endif; ?>
              <?php if ($modulePontosMap): ?>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'pontos' ? 'btn-primary' : 'btn-secondary' ?>">Pontos</a>
              <?php endif; ?>
              <?php if ($dashMapaCombinadoHabilitado && $moduleChamadosMap && $modulePontosMap && $scopeIdPontos > 0): ?>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos'])) ?>" class="btn btn-sm <?= $dashMapaAba === 'ambos' ? 'btn-primary' : 'btn-secondary' ?>">Mapa combinado</a>
              <?php endif; ?>
            </div>
            <div class="dashboard-map-pontos-presets" role="group" aria-label="Exibição dos postes">
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos', 'ponto_filtro' => null])) ?>" class="btn btn-sm <?= $pontoMapFiltro === '' ? 'btn-primary' : 'btn-secondary' ?>">Todos os pontos</a>
              <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'pontos', 'ponto_filtro' => 'chamados'])) ?>" class="btn btn-sm <?= $pontoMapFiltro === 'chamados' ? 'btn-primary' : 'btn-secondary' ?>">Com chamados (<?= (int) $totalPontosComChamados ?>)</a>
            </div>
          </div>
          <?php if ($dashPainel === 'admin' && $escopoDash === null && !empty($empresasDashOptions) && !$modoClienteUnicoAdmin): ?>
          <form class="dashboard-map-filters--pontos-empresa" method="get" action="index.php">
            <input type="hidden" name="dash_mapa" value="pontos">
            <input type="hidden" name="map_periodo" value="<?= htmlspecialchars($mapPeriodo) ?>">
            <?php if ($pontoMapFiltro !== ''): ?>
            <input type="hidden" name="ponto_filtro" value="<?= htmlspecialchars($pontoMapFiltro) ?>">
            <?php endif; ?>
            <div class="form-group form-group--grow">
              <label for="dash_cliente_id">Empresa (mapa)</label>
              <select id="dash_cliente_id" name="dash_cliente_id" class="select" onchange="this.form.submit()">
                <?php foreach ($empresasDashOptions as $empOp): ?>
                <option value="<?= (int) $empOp['id'] ?>" <?= (int) $scopeIdPontos === (int) $empOp['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars((string) ($empOp['empresa'] ?? $empOp['nome'] ?? 'Cadastro')) ?> #<?= (int) $empOp['id'] ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>
          <?php endif; ?>

          <div class="form-group">
            <label for="map-filter-area">Área / bairro</label>
            <select id="map-filter-area" class="input">
              <option value="">Todas as áreas</option>
              <?php foreach ($bairrosPontosDash as $bairro): ?>
              <option value="<?= htmlspecialchars($bairro) ?>"><?= htmlspecialchars($bairro) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="map-filter-search">Buscar no mapa</label>
            <input id="map-filter-search" class="input" type="search" placeholder="Poste, endereço, bairro ou referência">
          </div>
          <label class="dashboard-pontos-tools-toggle" for="map-toggle-cluster">
            <input type="checkbox" id="map-toggle-cluster" checked>
            Agrupar clusters
          </label>
          <div class="dashboard-pontos-tools-status dashboard-map-filters-pontos-meta" id="map-visible-count"><?= count($pontosPinsPass) ?> ponto(s) visível(is)</div>
        </div>

        <div class="dashboard-map-resize-wrap" data-map-resize-key="<?= htmlspecialchars($dashMapResizePrefix) ?>_pontos">
          <div id="pontos-iluminacao-map" class="dashboard-map-leaflet-host" role="region" aria-label="Mapa de pontos de iluminação"></div>
          <button type="button" class="dashboard-map-resize-handle" aria-label="Redimensionar altura do mapa" title="Arraste para ajustar a altura (salvo neste navegador)"></button>
        </div>
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
          <div class="dashboard-map-chamados-period" role="group" aria-label="Período dos chamados no mapa">
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'map_periodo' => 'hoje'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'hoje' ? 'btn-primary' : 'btn-secondary' ?>">Dia atual</a>
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'map_periodo' => 'ontem'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'ontem' ? 'btn-primary' : 'btn-secondary' ?>">Dia anterior</a>
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'map_periodo' => 'semana'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'semana' ? 'btn-primary' : 'btn-secondary' ?>">Semana atual</a>
            <a href="index.php?<?= htmlspecialchars($dashQsPreserve(['dash_mapa' => 'ambos', 'map_periodo' => 'mes'])) ?>" class="btn btn-sm <?= $mapPeriodo === 'mes' ? 'btn-primary' : 'btn-secondary' ?>">Mês atual</a>
          </div>
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
          <?= count($mapPins) ?> chamado(s) com coordenadas no período · <?= count($pontosPinsPass) ?> de <?= count($pontosPinsTodos) ?> poste(s) carregado(s)
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

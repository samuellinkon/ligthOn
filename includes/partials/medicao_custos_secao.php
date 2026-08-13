<?php
/**
 * Custos adicionais da medição — listagem e modal (gestor).
 *
 * @var int $clienteId
 * @var string $mesRef
 * @var string $dataDe
 * @var string $dataAte
 * @var bool $medicaoCustosPodeCriar
 * @var bool $medicaoCustosPodeEditar
 * @var bool $medicaoCustosPodeAprovar
 * @var string $medicaoCustosApiUrl
 */
declare(strict_types=1);

$custosPkg       = repo_medicao_custos_list((int) $clienteId, $mesRef, null);
$custosLinhas    = $custosPkg['rows'];
$custosTotais    = $custosPkg['totais'];
$custosTemLinhas = $custosLinhas !== [];

$medicaoCustosPodeCriar    = !empty($medicaoCustosPodeCriar);
$medicaoCustosPodeEditar   = !empty($medicaoCustosPodeEditar);
$medicaoCustosPodeAprovar  = !empty($medicaoCustosPodeAprovar);
$medicaoCustosApiUrl       = (string) ($medicaoCustosApiUrl ?? 'medicao_custos_api.php');
$tabelaDisponivel          = repo_medicao_custos_table_exists();

$valorAprovado  = (float) ($custosTotais['valor_aprovado'] ?? 0);
$valorPendente  = (float) ($custosTotais['valor_pendente'] ?? 0);
$valorRejeitado = (float) ($custosTotais['valor_rejeitado'] ?? 0);
$nPendente = (int) ($custosTotais['n_pendente'] ?? 0);
?>
  <section class="card medicao-view-section medicao-custos-compact medicao-custos-secao" id="medicao-custos-secao"
       data-api-url="<?= htmlspecialchars($medicaoCustosApiUrl, ENT_QUOTES, 'UTF-8') ?>"
       data-cliente-id="<?= (int) $clienteId ?>"
       data-ref-ym="<?= htmlspecialchars($mesRef, ENT_QUOTES, 'UTF-8') ?>"
       data-pode-criar="<?= $medicaoCustosPodeCriar ? '1' : '0' ?>"
       data-pode-editar="<?= $medicaoCustosPodeEditar ? '1' : '0' ?>"
       data-pode-aprovar="<?= $medicaoCustosPodeAprovar ? '1' : '0' ?>">
    <header class="medicao-custos-header">
      <div class="medicao-custos-header__text">
        <h4 class="medicao-custos-title">Custos adicionais</h4>
        <p class="medicao-custos-subtitle">Extras da medição</p>
      </div>
      <?php if ($medicaoCustosPodeCriar && $tabelaDisponivel): ?>
      <div class="medicao-custos-actions">
        <button type="button" class="btn btn-secondary btn-sm medicao-custo-btn-add" id="medicao-custo-btn-add" title="Adicionar custo adicional" aria-label="Adicionar custo adicional">
          <span class="medicao-custo-btn-add__icon" aria-hidden="true">+</span>
          <span class="medicao-custo-btn-add__label">Custo</span>
        </button>
      </div>
      <?php endif; ?>
    </header>

    <div class="medicao-custos-body">
      <?php if (!$tabelaDisponivel): ?>
      <p class="medicao-custos-inline-msg medicao-custos-inline-msg--warn" role="status">
        Indisponível — execute a migration <code>054_medicao_custos.sql</code>.
      </p>
      <?php else: ?>

      <div class="medicao-custos-resumo-row">
        <p class="medicao-custos-resumo-line" aria-label="Resumo dos custos adicionais">
          <span>Aprovados <span class="medicao-custos-resumo-line__val">R$ <?= number_format($valorAprovado, 2, ',', '.') ?></span></span>
          <span class="medicao-custos-resumo-line__sep" aria-hidden="true">·</span>
          <span>Pendentes <span class="medicao-custos-resumo-line__val">R$ <?= number_format($valorPendente, 2, ',', '.') ?></span><?= $nPendente > 0 ? ' (' . $nPendente . ')' : '' ?></span>
          <span class="medicao-custos-resumo-line__sep" aria-hidden="true">·</span>
          <span>Rejeitados <span class="medicao-custos-resumo-line__val">R$ <?= number_format($valorRejeitado, 2, ',', '.') ?></span></span>
        </p>
      </div>

      <div class="medicao-custos-table-wrap table-wrap">
        <table class="medicao-custos-table" data-crm-sortable id="medicao-custos-tabela">
          <thead>
            <tr class="crm-table-head-sort">
              <?php crm_sort_th('Data', 'data', ['type' => 'date']); ?>
              <?php crm_sort_th('Descrição', 'descricao'); ?>
              <?php crm_sort_th('Qtd', 'qtd', ['type' => 'number', 'right' => true]); ?>
              <?php crm_sort_th('V. unit.', 'vunit', ['type' => 'number', 'right' => true]); ?>
              <?php crm_sort_th('Total', 'total', ['type' => 'number', 'right' => true]); ?>
              <?php crm_sort_th('Status', 'status'); ?>
              <?php crm_sort_th('Ações', null, ['class' => 'crm-table-col-acoes', 'right' => true]); ?>
            </tr>
          </thead>
          <tbody>
            <?php if (!$custosTemLinhas): ?>
            <tr class="medicao-custos-empty-row">
              <td colspan="7" class="medicao-custos-empty-cell">
                <span class="medicao-custos-empty-text">Nenhum custo adicional lançado.</span>
              </td>
            </tr>
            <?php else: foreach ($custosLinhas as $c): ?>
            <?php
              $cId       = (int) ($c['id'] ?? 0);
              $cSt       = (string) ($c['status'] ?? '');
              $criado    = (string) ($c['criado_em'] ?? '');
              $criadoIso = $criado !== '' ? date('Y-m-d H:i:s', strtotime($criado)) : '';
              $codEx     = medicao_custo_bm_codigo_exibir($c);
              $criador   = trim((string) ($c['criado_por_nome'] ?? ''));
              $criadorAttr = $criador !== '' ? $criador : '';
            ?>
            <tr data-custo-id="<?= $cId ?>" <?= crm_sort_row_attr([
                'data'      => $criadoIso,
                'codigo'    => $codEx,
                'descricao' => (string) ($c['descricao'] ?? ''),
                'unidade'   => (string) ($c['unidade'] ?? ''),
                'qtd'       => (string) (float) ($c['quantidade'] ?? 0),
                'vunit'     => (string) (float) ($c['valor_unitario'] ?? 0),
                'total'     => (string) (float) ($c['valor_total'] ?? 0),
                'status'    => $cSt,
                'criador'   => $criadorAttr,
            ]) ?>>
              <td class="td-mute medicao-custos-table__data"><?= htmlspecialchars((string) ($c['criado_em_br'] ?? '')) ?></td>
              <td class="medicao-custos-table__desc"<?= $criadorAttr !== '' ? ' title="Criado por: ' . htmlspecialchars($criadorAttr, ENT_QUOTES, 'UTF-8') . '"' : '' ?>>
                <div class="medicao-custos-table__desc-main"><?= htmlspecialchars((string) ($c['descricao'] ?? '')) ?></div>
                <?php if ($codEx !== '' && strtoupper($codEx) !== 'CUSTO'): ?>
                <span class="medicao-custos-table__code-hint"><?= htmlspecialchars($codEx) ?></span>
                <?php endif; ?>
                <?php if ($cSt === 'Rejeitado' && !empty($c['rejeitado_motivo'])): ?>
                <span class="medicao-custos-table__motivo" title="<?= htmlspecialchars((string) $c['rejeitado_motivo'], ENT_QUOTES, 'UTF-8') ?>">Rejeitado</span>
                <?php endif; ?>
              </td>
              <td class="text-right tabular-nums"><?= htmlspecialchars(medicao_custo_fmt_quantidade((float) ($c['quantidade'] ?? 0))) ?></td>
              <td class="text-right tabular-nums">R$ <?= number_format((float) ($c['valor_unitario'] ?? 0), 2, ',', '.') ?></td>
              <td class="text-right tabular-nums"><strong>R$ <?= number_format((float) ($c['valor_total'] ?? 0), 2, ',', '.') ?></strong></td>
              <td><span class="badge <?= htmlspecialchars(medicao_custo_status_badge_class($cSt)) ?>"><?= htmlspecialchars($cSt) ?></span></td>
              <td class="td-actions">
                <?php $custoJsonAttr = htmlspecialchars(json_encode(medicao_custo_para_json($c), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="action js-medicao-custo-ver" data-custo="<?= $custoJsonAttr ?>">Ver</button>
                <?php if ($medicaoCustosPodeAprovar && $cSt === 'Pendente'): ?>
                <button type="button" class="action primary js-medicao-custo-aprovar" data-id="<?= $cId ?>">Aprovar</button>
                <button type="button" class="action js-medicao-custo-rejeitar" data-id="<?= $cId ?>">Rejeitar</button>
                <?php endif; ?>
                <?php if ($medicaoCustosPodeEditar && in_array($cSt, ['Pendente', 'Rejeitado'], true)): ?>
                <button type="button" class="action<?= ($medicaoCustosPodeAprovar && $cSt === 'Pendente') ? '' : ' primary' ?> js-medicao-custo-editar"
                        data-custo="<?= $custoJsonAttr ?>">Editar</button>
                <button type="button" class="action danger js-medicao-custo-excluir" data-id="<?= $cId ?>">Excluir</button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($tabelaDisponivel): ?>
  <div id="medicao-custo-ver-modal" class="chamado-mat-modal medicao-custo-modal medicao-custo-ver-modal" hidden aria-hidden="true">
    <button type="button" class="chamado-mat-modal__scrim medicao-custo-modal__scrim" data-medicao-custo-ver-close tabindex="-1" aria-label="Fechar"></button>
    <div class="chamado-mat-modal__box medicao-custo-modal__container medicao-custo-ver-modal__container" role="dialog" aria-modal="true" aria-labelledby="medicao-custo-ver-modal-title">
      <header class="medicao-custo-modal__header">
        <div class="medicao-custo-modal__header-text">
          <p class="medicao-custo-modal__eyebrow">Medição · <?= htmlspecialchars($mesRef) ?></p>
          <h3 id="medicao-custo-ver-modal-title" class="medicao-custo-modal__title">Detalhe do custo</h3>
          <p class="medicao-custo-modal__subtitle">Lançamento completo — item, valores e observação.</p>
        </div>
        <button type="button" class="medicao-custo-modal__close" data-medicao-custo-ver-close aria-label="Fechar">
          <span aria-hidden="true">×</span>
        </button>
      </header>
      <div class="medicao-custo-modal__body medicao-custo-ver-modal__body">
        <section class="medicao-custo-modal__section medicao-custo-ver-modal__section">
          <div class="medicao-custo-ver-modal__top">
            <div class="medicao-custo-ver-modal__top-main">
              <span class="medicao-custo-ver-modal__code-label">Código</span>
              <strong id="medicao-custo-ver-codigo" class="medicao-custo-ver-modal__code">—</strong>
            </div>
            <div id="medicao-custo-ver-status" class="medicao-custo-ver-modal__status">
              <span class="badge plain">—</span>
            </div>
          </div>
          <div class="medicao-custo-ver-modal__desc-card">
            <span class="medicao-custo-ver-modal__field-label">Descrição</span>
            <p id="medicao-custo-ver-descricao" class="medicao-custo-ver-modal__desc">—</p>
          </div>
        </section>

        <section class="medicao-custo-modal__section medicao-custo-ver-modal__section" aria-labelledby="medicao-custo-ver-sec-valores">
          <h4 id="medicao-custo-ver-sec-valores" class="medicao-custo-modal__section-title">Valores</h4>
          <div class="medicao-custo-ver-modal__values">
            <div class="medicao-custo-ver-modal__metric">
              <span class="medicao-custo-ver-modal__field-label">Unidade</span>
              <strong id="medicao-custo-ver-unidade" class="medicao-custo-ver-modal__metric-val">—</strong>
            </div>
            <div class="medicao-custo-ver-modal__metric">
              <span class="medicao-custo-ver-modal__field-label">Quantidade</span>
              <strong id="medicao-custo-ver-qtd" class="medicao-custo-ver-modal__metric-val">—</strong>
            </div>
            <div class="medicao-custo-ver-modal__metric">
              <span class="medicao-custo-ver-modal__field-label">Valor unitário</span>
              <strong id="medicao-custo-ver-vunit" class="medicao-custo-ver-modal__metric-val">—</strong>
            </div>
            <div class="medicao-custo-modal__total-card medicao-custo-ver-modal__total-card" aria-live="polite">
              <span class="medicao-custo-modal__total-label">Total</span>
              <strong id="medicao-custo-ver-total" class="medicao-custo-modal__total-value">R$ 0,00</strong>
            </div>
          </div>
        </section>

        <section class="medicao-custo-modal__section medicao-custo-ver-modal__section" aria-labelledby="medicao-custo-ver-sec-obs">
          <h4 id="medicao-custo-ver-sec-obs" class="medicao-custo-modal__section-title">Observação</h4>
          <div class="medicao-custo-ver-modal__obs-card">
            <p id="medicao-custo-ver-obs" class="medicao-custo-ver-modal__obs">—</p>
          </div>
        </section>

        <section class="medicao-custo-modal__section medicao-custo-ver-modal__section medicao-custo-ver-modal__section--last" aria-labelledby="medicao-custo-ver-sec-meta">
          <h4 id="medicao-custo-ver-sec-meta" class="medicao-custo-modal__section-title">Registo</h4>
          <dl class="medicao-custo-ver-modal__meta">
            <div class="medicao-custo-ver-modal__meta-row">
              <dt>Criado em</dt>
              <dd id="medicao-custo-ver-criado-em">—</dd>
            </div>
            <div class="medicao-custo-ver-modal__meta-row">
              <dt>Criado por</dt>
              <dd id="medicao-custo-ver-criado-por">—</dd>
            </div>
            <div class="medicao-custo-ver-modal__meta-row" id="medicao-custo-ver-aprovado-wrap" hidden>
              <dt>Aprovado em</dt>
              <dd id="medicao-custo-ver-aprovado-em">—</dd>
            </div>
            <div class="medicao-custo-ver-modal__meta-row medicao-custo-ver-modal__meta-row--block" id="medicao-custo-ver-rejeitado-wrap" hidden>
              <dt>Rejeição</dt>
              <dd id="medicao-custo-ver-rejeitado">—</dd>
            </div>
          </dl>
        </section>
      </div>
      <footer class="medicao-custo-modal__footer">
        <button type="button" class="btn btn-secondary medicao-custo-modal__btn-cancel" data-medicao-custo-ver-close>Fechar</button>
      </footer>
    </div>
  </div>
  <?php endif; ?>

  <?php if (($medicaoCustosPodeCriar || $medicaoCustosPodeEditar) && $tabelaDisponivel): ?>
  <div id="medicao-custo-modal" class="chamado-mat-modal medicao-custo-modal" hidden aria-hidden="true">
    <button type="button" class="chamado-mat-modal__scrim medicao-custo-modal__scrim" data-medicao-custo-close tabindex="-1" aria-label="Fechar"></button>
    <div class="chamado-mat-modal__box medicao-custo-modal__container" role="dialog" aria-modal="true" aria-labelledby="medicao-custo-modal-title" aria-describedby="medicao-custo-modal-desc">
      <header class="medicao-custo-modal__header">
        <div class="medicao-custo-modal__header-text">
          <p class="medicao-custo-modal__eyebrow">Medição · <?= htmlspecialchars($mesRef) ?></p>
          <h3 id="medicao-custo-modal-title" class="medicao-custo-modal__title">Adicionar custo adicional</h3>
          <p id="medicao-custo-modal-desc" class="medicao-custo-modal__subtitle">Selecione um item do catálogo (produto ou serviço) para este custo extra.</p>
        </div>
        <button type="button" class="medicao-custo-modal__close" data-medicao-custo-close aria-label="Fechar">
          <span aria-hidden="true">×</span>
        </button>
      </header>

      <form id="medicao-custo-form" class="medicao-custo-modal__body">
        <input type="hidden" name="id" id="medicao-custo-id" value="">

        <section id="medicao-custo-catalogo-wrap" class="medicao-custo-modal__section medicao-custo-modal__section--catalog" aria-labelledby="medicao-custo-sec-catalogo">
          <h4 id="medicao-custo-sec-catalogo" class="medicao-custo-modal__section-title">Item do catálogo</h4>
          <div class="medicao-custo-modal__field">
            <label class="medicao-custo-modal__label" for="medicao-custo-busca">Buscar item <span class="medicao-custo-modal__required">*</span></label>
            <input type="search" id="medicao-custo-busca" class="input medicao-custo-modal__input" placeholder="Digite nome ou código do produto ou serviço..." autocomplete="off">
          </div>
          <div id="medicao-custo-catalogo-results" class="medicao-custo-modal__catalog-results chamado-mat-results" role="listbox" aria-label="Itens do catálogo"></div>
          <input type="hidden" name="item_id" id="medicao-custo-item-id" value="">
          <input type="hidden" name="item_codigo" id="medicao-custo-codigo" value="">
          <input type="hidden" name="unidade" id="medicao-custo-unidade" value="UN">
          <input type="hidden" name="descricao" id="medicao-custo-descricao" value="">
          <p id="medicao-custo-item-selecionado" class="medicao-custo-item-selecionado muted" hidden></p>
        </section>

        <section class="medicao-custo-modal__section" aria-labelledby="medicao-custo-sec-valores">
          <h4 id="medicao-custo-sec-valores" class="medicao-custo-modal__section-title">Valores</h4>
          <div class="medicao-custo-modal__grid medicao-custo-modal__grid--values">
            <div class="medicao-custo-modal__field">
              <label class="medicao-custo-modal__label" for="medicao-custo-qtd">Quantidade</label>
              <input type="text" id="medicao-custo-qtd" name="quantidade" class="input medicao-custo-modal__input" inputmode="decimal" value="1" placeholder="1">
            </div>
            <div class="medicao-custo-modal__field">
              <label class="medicao-custo-modal__label" for="medicao-custo-vunit">Valor unitário (R$)</label>
              <input type="text" id="medicao-custo-vunit" name="valor_unitario" class="input medicao-custo-modal__input medicao-custo-modal__input--readonly" inputmode="decimal" value="0" readonly placeholder="0,00">
            </div>
            <div class="medicao-custo-modal__total-card" aria-live="polite">
              <span class="medicao-custo-modal__total-label">Total</span>
              <strong id="medicao-custo-total-preview" class="medicao-custo-modal__total-value">R$ 0,00</strong>
              <span class="medicao-custo-modal__total-hint">Calculado automaticamente</span>
            </div>
          </div>
        </section>

        <section class="medicao-custo-modal__section" aria-labelledby="medicao-custo-sec-obs">
          <h4 id="medicao-custo-sec-obs" class="medicao-custo-modal__section-title">Observação</h4>
          <div class="medicao-custo-modal__field">
            <label class="medicao-custo-modal__label" for="medicao-custo-obs">Observação <span class="medicao-custo-modal__optional">(opcional)</span></label>
            <textarea id="medicao-custo-obs" name="observacao" class="input medicao-custo-modal__textarea" rows="3" maxlength="2000" placeholder="Detalhes adicionais para o cliente ou para auditoria interna..."></textarea>
          </div>
        </section>

        <p id="medicao-custo-form-err" class="medicao-custo-modal__err" role="alert" hidden></p>
      </form>

      <footer class="medicao-custo-modal__footer">
        <button type="button" class="btn btn-secondary medicao-custo-modal__btn-cancel" data-medicao-custo-close>Cancelar</button>
        <button type="submit" form="medicao-custo-form" class="btn btn-primary medicao-custo-modal__btn-save" id="medicao-custo-submit">
          <span class="medicao-custo-modal__btn-save-icon" aria-hidden="true">✓</span>
          <span>Salvar custo</span>
        </button>
      </footer>
    </div>
  </div>
  <?php endif; ?>

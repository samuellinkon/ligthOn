<?php
/**
 * Composição do valor medido (chamados + custos adicionais aprovados).
 *
 * @var array{n_chamados:int,valor_materiais:float,valor_servicos:float,valor_total:float,valor_custos_adicionais?:float} $totExibicao
 */
declare(strict_types=1);

$valorCustosBm = (float) ($totExibicao['valor_custos_adicionais'] ?? 0);
?>
  <div class="card medicao-view-section medicao-chamados-resumo">
    <div class="panel-head">
      <h4>Medição dos chamados</h4>
    </div>
    <div class="panel-body">
      <div class="medicao-view-grid">
        <div>
          <div class="metric-label">Materiais aplicados</div>
          <div class="td-strong">R$ <?= number_format((float) ($totExibicao['valor_materiais'] ?? 0), 2, ',', '.') ?></div>
        </div>
        <div>
          <div class="metric-label">Serviços / itens</div>
          <div class="td-strong">R$ <?= number_format((float) ($totExibicao['valor_servicos'] ?? 0), 2, ',', '.') ?></div>
        </div>
        <div>
          <div class="metric-label">Custos adicionais (aprovados)</div>
          <div class="td-strong">R$ <?= number_format($valorCustosBm, 2, ',', '.') ?></div>
        </div>
        <div>
          <div class="metric-label">Total BM</div>
          <div class="td-strong">R$ <?= number_format((float) ($totExibicao['valor_total'] ?? 0), 2, ',', '.') ?></div>
        </div>
      </div>
    </div>
  </div>

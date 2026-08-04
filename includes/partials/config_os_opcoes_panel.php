<?php
declare(strict_types=1);

/**
 * Painel Avançado: gestão das opções Origem da OS e Problema.
 *
 * @var list<array{id:int,tipo:string,nome:string,ativo:int,ordem:int}> $osOpcoesOrigem
 * @var list<array{id:int,tipo:string,nome:string,ativo:int,ordem:int}> $osOpcoesProblema
 * @var bool $osOpcoesTabelaOk
 * @var string $configSecao
 */

$osOpcoesOrigem   = is_array($osOpcoesOrigem ?? null) ? $osOpcoesOrigem : [];
$osOpcoesProblema = is_array($osOpcoesProblema ?? null) ? $osOpcoesProblema : [];
$osOpcoesTabelaOk = !empty($osOpcoesTabelaOk);

$renderLista = static function (string $tipo, string $titulo, array $rows) use ($osOpcoesTabelaOk): void {
    $tipoEsc = htmlspecialchars($tipo, ENT_QUOTES, 'UTF-8');
    ?>
    <section class="config-os-block<?= $tipo === 'problema' ? ' config-os-block--next' : '' ?>">
      <h4 class="config-os-block__title"><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?></h4>
      <p class="config-os-block__sub muted">Aparecem no formulário de chamado / OS</p>

      <?php if (!$osOpcoesTabelaOk): ?>
        <p class="muted" style="margin:0;">Tabela <code>chamado_os_opcoes</code> ausente. Execute a migração <code>060_chamado_os_opcoes.sql</code>.</p>
      <?php else: ?>
      <div class="table-wrap config-os-table-wrap">
        <table class="config-os-table">
          <thead>
            <tr>
              <th class="config-os-col-nome">Nome</th>
              <th class="config-os-col-ordem">Ordem</th>
              <th class="config-os-col-ativo">Ativo</th>
              <th class="config-os-col-acoes"></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($rows === []): ?>
            <tr>
              <td colspan="4" class="muted config-os-empty">Nenhuma opção cadastrada.</td>
            </tr>
            <?php else: foreach ($rows as $row):
                $id  = (int) ($row['id'] ?? 0);
                $fid = 'os_opcao_form_' . $tipo . '_' . $id;
                $fidEsc = htmlspecialchars($fid, ENT_QUOTES, 'UTF-8');
                ?>
            <tr>
              <td class="config-os-col-nome">
                <form id="<?= $fidEsc ?>" method="post" action="configuracoes.php?tab=geral&secao=os" class="config-os-row-form">
                  <input type="hidden" name="acao" value="os_opcao_salvar">
                  <input type="hidden" name="_secao" value="os">
                  <input type="hidden" name="os_tipo" value="<?= $tipoEsc ?>">
                  <input type="hidden" name="os_id" value="<?= $id ?>">
                  <input type="text" name="os_nome" class="input config-os-input-nome" required maxlength="120"
                         value="<?= htmlspecialchars((string) ($row['nome'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </form>
              </td>
              <td class="config-os-col-ordem">
                <input form="<?= $fidEsc ?>" type="text" name="os_ordem" class="input config-os-input-ordem"
                       inputmode="numeric" pattern="[0-9\-]*" maxlength="6"
                       value="<?= (int) ($row['ordem'] ?? 0) ?>" aria-label="Ordem">
              </td>
              <td class="config-os-col-ativo">
                <label class="checkbox config-os-check">
                  <input form="<?= $fidEsc ?>" type="checkbox" name="os_ativo" value="1" <?= !empty($row['ativo']) ? 'checked' : '' ?>>
                  <span class="config-os-check-label" aria-hidden="true"></span>
                </label>
              </td>
              <td class="config-os-col-acoes">
                <div class="config-os-actions">
                  <button form="<?= $fidEsc ?>" type="submit" class="btn btn-secondary btn-sm">Salvar</button>
                  <form method="post" action="configuracoes.php?tab=geral&secao=os" class="config-os-del-form"
                        data-confirm="Excluir esta opção? Chamados que já usam o valor mantêm o texto gravado.">
                    <input type="hidden" name="acao" value="os_opcao_excluir">
                    <input type="hidden" name="_secao" value="os">
                    <input type="hidden" name="os_id" value="<?= $id ?>">
                    <button type="submit" class="btn btn-secondary btn-sm">Excluir</button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <form method="post" action="configuracoes.php?tab=geral&secao=os" class="config-os-nova">
        <input type="hidden" name="acao" value="os_opcao_salvar">
        <input type="hidden" name="_secao" value="os">
        <input type="hidden" name="os_tipo" value="<?= $tipoEsc ?>">
        <div class="form-group config-os-nova__nome">
          <label for="os_novo_<?= $tipoEsc ?>">Nova opção</label>
          <input type="text" id="os_novo_<?= $tipoEsc ?>" name="os_nome" class="input" required maxlength="120"
                 placeholder="Nome exibido no select">
        </div>
        <div class="form-group config-os-nova__ordem">
          <label for="os_ordem_<?= $tipoEsc ?>">Ordem</label>
          <input type="text" id="os_ordem_<?= $tipoEsc ?>" name="os_ordem" class="input config-os-input-ordem"
                 inputmode="numeric" pattern="[0-9\-]*" maxlength="6" value="100">
        </div>
        <label class="checkbox config-os-nova__ativo">
          <input type="checkbox" name="os_ativo" value="1" checked>
          <span>Ativo</span>
        </label>
        <button type="submit" class="btn btn-primary btn-sm config-os-nova__btn">Adicionar</button>
      </form>
      <?php endif; ?>
    </section>
    <?php
};
?>
<style>
.config-os-panel .panel-body { padding-top: 8px; }
.config-os-intro {
  font-size: 14px;
  line-height: 1.55;
  margin: 0 0 22px;
  color: var(--muted);
}
.config-os-block--next { margin-top: 32px; padding-top: 28px; border-top: 1px solid var(--border-soft, #eef0f4); }
.config-os-block__title {
  margin: 0 0 4px;
  font-size: 16px;
  font-weight: 700;
  color: var(--text);
}
.config-os-block__sub { margin: 0 0 14px; font-size: 13px; }
.config-os-table-wrap { margin: 0 0 16px; border: 1px solid var(--border-soft, #eef0f4); border-radius: 12px; overflow: hidden; }
.config-os-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.config-os-table thead th {
  background: #f3f4f8;
  color: var(--muted);
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  padding: 12px 16px;
  border-bottom: 1px solid var(--border-soft, #eef0f4);
}
.config-os-table tbody td {
  padding: 12px 16px;
  border-bottom: 1px solid #f2f2f7;
  vertical-align: middle;
}
.config-os-table tbody tr:last-child td { border-bottom: none; }
.config-os-col-nome { width: auto; }
.config-os-col-ordem { width: 96px; text-align: center; }
.config-os-col-ativo { width: 80px; text-align: center; }
.config-os-col-acoes { width: 190px; }
.config-os-table thead .config-os-col-ordem,
.config-os-table thead .config-os-col-ativo { text-align: center; }
.config-os-row-form { margin: 0; }
.config-os-input-nome { width: 100%; max-width: none; }
.config-os-input-ordem {
  width: 72px;
  max-width: 100%;
  text-align: center;
  -moz-appearance: textfield;
  appearance: textfield;
}
.config-os-input-ordem::-webkit-outer-spin-button,
.config-os-input-ordem::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}
.config-os-check { justify-content: center; margin: 0; }
.config-os-check-label { display: none; }
.config-os-actions {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 8px;
  flex-wrap: nowrap;
}
.config-os-del-form { display: inline; margin: 0; }
.config-os-empty { text-align: center; padding: 22px 16px !important; }
.config-os-nova {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 14px 16px;
  margin: 0;
  padding: 14px 16px;
  background: #f8f9fc;
  border: 1px solid var(--border-soft, #eef0f4);
  border-radius: 12px;
}
.config-os-nova__nome { flex: 1 1 220px; margin: 0; min-width: 180px; }
.config-os-nova__ordem { width: 96px; margin: 0; flex: 0 0 96px; }
.config-os-nova__ativo { margin: 0 0 8px; white-space: nowrap; }
.config-os-nova__btn { margin: 0 0 2px auto; }
@media (max-width: 720px) {
  .config-os-col-acoes { width: 120px; }
  .config-os-actions { flex-wrap: wrap; justify-content: flex-start; }
  .config-os-nova__btn { margin-left: 0; }
}
</style>

<div class="card mt-24 config-section-panel config-os-panel<?= (($configSecao ?? '') === 'os') ? ' active' : '' ?>" data-config-panel="os">
  <div class="panel-head">
    <h4>Opções da Ordem de Serviço</h4>
    <span class="panel-sub">Origem da OS e Problema usados nos chamados</span>
  </div>
  <div class="panel-body">
    <p class="config-os-intro">
      As opções ativas alimentam os selects do formulário de chamado. Desative ou exclua para esconder do cadastro novo;
      valores já gravados em chamados antigos permanecem.
    </p>
    <?php
    $renderLista('origem', 'Origem da OS', $osOpcoesOrigem);
    $renderLista('problema', 'Problema', $osOpcoesProblema);
    ?>
  </div>
</div>

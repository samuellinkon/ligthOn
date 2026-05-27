<?php
/**
 * Formulário de exclusão do BM de um mês (admin).
 *
 * Requer: $bmExcluirMes, $bmExcluirPrev (preview), $bmExcluirPeriodoDe, $bmExcluirPeriodoAte
 * Opcional: $bmExcluirClienteId, $bmExcluirEscopoEmpresa, $bmExcluirMostrarCheckboxItens (bool, default true)
 *           $bmExcluirIconOnly (bool), $bmExcluirBtnClass (string), $bmExcluirBtnLabel (string)
 */
if (empty($bmExcluirPrev['ok'])) {
    return;
}

$bmExcluirMes = (string) ($bmExcluirMes ?? '');
$bmExcluirPeriodoDe  = (string) ($bmExcluirPeriodoDe ?? ($bmExcluirMes !== '' ? $bmExcluirMes . '-01' : ''));
$bmExcluirPeriodoAte = (string) ($bmExcluirPeriodoAte ?? '');
if ($bmExcluirPeriodoAte === '' && preg_match('/^\d{4}-\d{2}$/', $bmExcluirMes)) {
    $bmExcluirPeriodoAte = date('Y-m-t', strtotime($bmExcluirMes . '-01 12:00:00'));
}

$bmExcluirEscopoEmpresa       = $bmExcluirEscopoEmpresa ?? null;
$bmExcluirClienteId           = (int) ($bmExcluirClienteId ?? 0);
$bmExcluirMostrarCheckboxItens = (bool) ($bmExcluirMostrarCheckboxItens ?? true);
$bmExcluirIconOnly            = (bool) ($bmExcluirIconOnly ?? false);
$bmExcluirBtnClass            = (string) ($bmExcluirBtnClass ?? 'btn btn-danger btn-sm');
$bmExcluirBtnLabel            = trim((string) ($bmExcluirBtnLabel ?? ''));
if ($bmExcluirBtnLabel === '') {
    $bmExcluirBtnLabel = $bmExcluirIconOnly ? 'Excluir' : 'Excluir BM do mês';
}

$bmExcluirMsg = medicao_bm_excluir_mensagem_confirmacao(
    $bmExcluirPrev,
    $bmExcluirMes,
    $bmExcluirMostrarCheckboxItens && !$bmExcluirIconOnly
);
if ($bmExcluirIconOnly) {
    $bmExcluirMsg .= ' Itens do catálogo usados apenas nesses chamados também serão removidos.';
}
?>
<form method="post" action="medicao_excluir_bm.php" class="medicao-bm-excluir-form<?= $bmExcluirIconOnly ? ' medicao-bm-excluir-form--icon' : '' ?>"
      data-confirm="<?= htmlspecialchars($bmExcluirMsg, ENT_QUOTES, 'UTF-8') ?>"
      data-confirm-danger>
  <input type="hidden" name="mes" value="<?= htmlspecialchars($bmExcluirMes, ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="periodo_de" value="<?= htmlspecialchars($bmExcluirPeriodoDe, ENT_QUOTES, 'UTF-8') ?>">
  <input type="hidden" name="periodo_ate" value="<?= htmlspecialchars($bmExcluirPeriodoAte, ENT_QUOTES, 'UTF-8') ?>">
  <?php if ($bmExcluirEscopoEmpresa === null && $bmExcluirClienteId > 0): ?>
    <input type="hidden" name="cliente_id" value="<?= $bmExcluirClienteId ?>">
  <?php endif; ?>
  <?php if ($bmExcluirMostrarCheckboxItens && !$bmExcluirIconOnly): ?>
    <label class="medicao-bm-excluir-check">
      <input type="checkbox" name="excluir_itens_catalogo" value="1" checked>
      Remover itens do catálogo criados só nos chamados desta importação
    </label>
  <?php elseif ($bmExcluirIconOnly): ?>
    <input type="hidden" name="excluir_itens_catalogo" value="1">
  <?php else: ?>
    <input type="hidden" name="excluir_itens_catalogo" value="1">
  <?php endif; ?>
  <?php if ($bmExcluirIconOnly): ?>
    <button type="submit" class="action-icon danger" title="<?= htmlspecialchars($bmExcluirBtnLabel, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($bmExcluirBtnLabel, ENT_QUOTES, 'UTF-8') ?>">🗑</button>
  <?php else: ?>
    <button type="submit" class="<?= htmlspecialchars($bmExcluirBtnClass, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($bmExcluirBtnLabel, ENT_QUOTES, 'UTF-8') ?></button>
  <?php endif; ?>
</form>

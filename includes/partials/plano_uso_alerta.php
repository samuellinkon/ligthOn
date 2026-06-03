<?php
/**
 * Faixa de alerta quando uso do plano >= 80%.
 */
if (!function_exists('db_ok') || !db_ok()) {
    return;
}
if (!function_exists('current_user') || !function_exists('current_user_painel_interno')) {
    return;
}
$cuAlerta = current_user();
if (!$cuAlerta || !current_user_painel_interno()) {
    return;
}
$perfAlerta = (string) ($cuAlerta['perfil'] ?? '');
if (!in_array($perfAlerta, ['admin', 'gestor'], true)) {
    return;
}
require_once __DIR__ . '/../cliente_plano_limites.php';
if (!repo_cliente_plano_columns_exists()) {
    return;
}
$clienteAlertaId = cliente_plano_cliente_id_escopo($cuAlerta);
if ($clienteAlertaId <= 0 || !cliente_plano_tem_alerta($clienteAlertaId)) {
    return;
}
$resumoAlerta = cliente_plano_resumo_metricas($clienteAlertaId);
$alertasTxt = [];
foreach ($resumoAlerta as $row) {
    $nivel = (string) ($row['nivel'] ?? '');
    if ($nivel !== 'warn' && $nivel !== 'danger') {
        continue;
    }
    $pct = $row['pct'] !== null ? number_format((float) $row['pct'], 0, ',', '.') . '%' : '80%+';
    $alertasTxt[] = (string) ($row['label'] ?? '') . ' (' . $pct . ')';
}
if (!$alertasTxt) {
    return;
}
$cfgHref = rtrim($basePath ?? '', '/') . '/admin/configuracoes.php?tab=geral&secao=clientes';
if (!empty($cuAlerta['is_super_admin'])) {
    $cfgHref = rtrim($basePath ?? '', '/') . '/admin/configuracoes.php?tab=geral&secao=clientes';
}
?>
<div class="plano-alerta-topbar" role="status">
  <span class="plano-alerta-topbar-icon" aria-hidden="true">⚠</span>
  <span>
    <strong>Uso do plano:</strong>
    <?= htmlspecialchars(implode(' · ', $alertasTxt)) ?>.
    <a href="<?= htmlspecialchars($cfgHref) ?>">Ver limites em Configurações</a>
  </span>
</div>

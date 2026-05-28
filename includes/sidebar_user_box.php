<?php
/**
 * Bloco de usuário no rodapé da sidebar.
 * Espera: $sidebarUser (array com nome, perfil, tipo).
 */
if (!isset($sidebarUser) || !is_array($sidebarUser)) {
    $sidebarUser = [];
}
require_once __DIR__ . '/usuario_labels.php';

$sbNomeRaw = (string) ($sidebarUser['nome'] ?? '');
$sbNome    = usuario_nome_sidebar($sbNomeRaw);
if ($sbNome === '') {
    $sbNome = $sbNomeRaw;
}
$sbPerfil = usuario_perfil_sidebar_label($sidebarUser);
$sbInicial = usuario_inicial_sidebar_avatar($sbNome !== '' ? $sbNome : $sbNomeRaw);
?>
<div class="user-box">
  <div class="avatar"><?= htmlspecialchars($sbInicial) ?></div>
  <div>
    <div class="user-name"><?= htmlspecialchars($sbNome) ?></div>
    <div class="user-role"><?= htmlspecialchars($sbPerfil) ?></div>
  </div>
</div>

<?php
/**
 * Aba de módulos por role de acesso.
 * Espera as variáveis preparadas em admin/configuracoes.php.
 */

$renderModuloLista = static function (array $mods, string $grupo, string $vazio): void {
    if (empty($mods)) {
        echo '<p class="muted">' . $vazio . '</p>';
        return;
    }
    echo '<ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:12px;">';
    foreach ($mods as $m) {
        $key = htmlspecialchars((string) ($m['modulo_key'] ?? ''), ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars((string) ($m['label'] ?? ''), ENT_QUOTES, 'UTF-8');
        $checked = !empty($m['habilitado']) ? ' checked' : '';
        echo '<li><label class="checkbox" style="margin:0;display:flex;align-items:flex-start;gap:10px;">';
        echo '<input type="checkbox" name="hab[' . $grupo . '][' . $key . ']" value="1"' . $checked . '>';
        echo '<span><strong>' . $label . '</strong><span class="muted" style="display:block;font-size:12px;margin-top:2px;">Chave: <code>' . $key . '</code></span></span>';
        echo '</label></li>';
    }
    echo '</ul>';
};
?>

<div class="hero" style="margin-bottom:22px;">
  <div>
    <h3>Permissões por role de acesso</h3>
    <p class="muted" style="max-width:780px;margin:0;line-height:1.55;">
      Configure quais módulos aparecem para cada role: <strong>admin</strong>, <strong>gestor</strong>,
      <strong>cliente</strong> e <strong>operador</strong>. A permissão agora é controlada diretamente
      pelo tipo de acesso do usuário.
    </p>
  </div>
</div>

<?php if (!db_ok()): ?>
  <div class="toast toast-err" style="position:static;margin-bottom:18px;">
    <div class="toast-icon">!</div>
    <div>Sem conexão com o banco. Rode as migrations de módulos e atualize a página.</div>
  </div>
<?php else: ?>

<form method="post" action="configuracoes.php?tab=modulos" class="form-stack" style="display:flex;flex-direction:column;gap:22px;">
  <input type="hidden" name="_form" value="saas_modulos">

  <div class="card">
    <div class="panel-head">
      <h4>Role — admin</h4>
      <span class="panel-sub">Permissões do administrador da plataforma</span>
    </div>
    <div class="panel-body">
      <?php $renderModuloLista($modsSuper ?? [], 'super_admin', 'Nenhum módulo cadastrado para admin.'); ?>
    </div>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Role — gestor</h4>
      <span class="panel-sub">Permissões do painel interno da empresa</span>
    </div>
    <div class="panel-body">
      <?php $renderModuloLista($modsGestor ?? [], 'gestor', 'Nenhum módulo cadastrado para gestor.'); ?>
    </div>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Role — cliente</h4>
      <span class="panel-sub">Permissões do portal do cliente</span>
    </div>
    <div class="panel-body">
      <?php $renderModuloLista($modsCliente ?? [], 'cliente', 'Nenhum módulo cadastrado para cliente.'); ?>
    </div>
  </div>

  <div class="card">
    <div class="panel-head">
      <h4>Role — operador</h4>
      <span class="panel-sub">Permissões do app/PWA de campo</span>
    </div>
    <div class="panel-body">
      <?php $renderModuloLista($modsOperador ?? [], 'operador', 'Nenhum módulo cadastrado para operador.'); ?>
    </div>
  </div>

  <div class="form-actions">
    <button type="submit" class="btn btn-primary">Salvar permissões por role</button>
  </div>
</form>

<?php endif; ?>

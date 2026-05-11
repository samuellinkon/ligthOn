<?php
/**
 * Formulário de edição de usuário (admin).
 * Variáveis: $userId, $usuario, $me, $escopoUe, $opcoesCliente, $opcoesEmpresaRaiz, $empresaIdUsuario
 * Opcional: $embedForm (bool), $returnUrlHidden (string cliente_detalhe.php?id=n), $cancelHref (string relativa admin/)
 */
$embedForm = !empty($embedForm ?? false);
$returnUrlHidden = isset($returnUrlHidden) ? (string) $returnUrlHidden : '';
$cancelHref = isset($cancelHref) && $cancelHref !== '' ? (string) $cancelHref : 'index.php';
$formTarget = $embedForm ? ' target="_top"' : '';
$perfilAtual = strtolower(trim((string) ($usuario['perfil'] ?? '')));
$perfilLabels = [
    'admin'     => 'Administrador',
    'gestor'    => 'Gestor (empresa de gestão)',
    'cliente'   => 'Portal da prefeitura',
    'operador'  => 'Operador (campo / app)',
];
$perfilLabelAtual = $perfilLabels[$perfilAtual] ?? $perfilAtual;
?>
<form class="card" method="post" action="usuario_editar.php?id=<?= (int) $userId ?>" autocomplete="off"<?= $formTarget ?>>
  <?php if ($embedForm && $returnUrlHidden !== ''): ?>
  <input type="hidden" name="return_url" value="<?= htmlspecialchars($returnUrlHidden, ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <div class="panel-head">
    <h4>Dados do usuário</h4>
    <?php if ($embedForm): ?>
    <span class="panel-sub">Altere nome, e-mail ou senha. O perfil não pode ser alterado nesta tela.</span>
    <?php else: ?>
    <span class="panel-sub">Perfil define o acesso ao painel interno ou ao portal do cliente</span>
    <?php endif; ?>
  </div>

  <div class="panel-body form form-grid form-grid--usuario">
    <div class="form-group full">
      <label for="nome">Nome completo</label>
      <input type="text" id="nome" name="nome" class="input" required maxlength="120"
             placeholder="Nome e sobrenome"
             value="<?= htmlspecialchars((string) ($usuario['nome'] ?? '')) ?>">
    </div>
    <div class="form-group full">
      <label for="email">E-mail (login)</label>
      <input type="email" id="email" name="email" class="input" required maxlength="150"
             placeholder="nome@organização.gov.br"
             value="<?= htmlspecialchars((string) ($usuario['email'] ?? '')) ?>">
    </div>
    <div class="form-group">
      <label for="perfil">Perfil</label>
      <?php if ($embedForm): ?>
        <input type="hidden" name="perfil" value="<?= htmlspecialchars($perfilAtual, ENT_QUOTES, 'UTF-8') ?>">
        <input type="text" id="perfil" class="input" readonly tabindex="-1" aria-readonly="true"
               value="<?= htmlspecialchars($perfilLabelAtual, ENT_QUOTES, 'UTF-8') ?>">
      <?php elseif ($escopoUe !== null && (int) ($me['id'] ?? 0) === $userId && (($usuario['perfil'] ?? '') === 'gestor')): ?>
        <input type="hidden" name="perfil" value="gestor">
        <input type="text" class="input" readonly value="Gestor (empresa)">
      <?php elseif ($escopoUe !== null): ?>
      <select id="perfil" name="perfil" class="select" data-perfil-select>
        <option value="cliente" <?= (($usuario['perfil'] ?? '') === 'cliente') ? 'selected' : '' ?>>Cliente (portal)</option>
        <option value="operador" <?= (($usuario['perfil'] ?? '') === 'operador') ? 'selected' : '' ?>>Operador (campo / app)</option>
        <option value="gestor" <?= (($usuario['perfil'] ?? '') === 'gestor') ? 'selected' : '' ?>>Gestor (empresa)</option>
      </select>
      <?php else: ?>
      <select id="perfil" name="perfil" class="select" data-perfil-select>
        <option value="admin" <?= (($usuario['perfil'] ?? '') === 'admin') ? 'selected' : '' ?>>Administrador</option>
        <option value="gestor" <?= (($usuario['perfil'] ?? '') === 'gestor') ? 'selected' : '' ?>>Gestor (empresa)</option>
        <option value="cliente" <?= (($usuario['perfil'] ?? '') === 'cliente') ? 'selected' : '' ?>>Cliente (portal)</option>
        <option value="operador" <?= (($usuario['perfil'] ?? '') === 'operador') ? 'selected' : '' ?>>Operador (campo / app)</option>
      </select>
      <?php endif; ?>
    </div>
    <?php if ($escopoUe !== null): ?>
    <input type="hidden" name="empresa_id" id="empresa_id_hidden" value="<?= (int) $escopoUe ?>">
    <?php elseif (!empty($opcoesEmpresaRaiz)): ?>
    <div class="form-group" id="wrap-empresa" style="display:none;">
      <label for="empresa_id">Empresa (raiz) — operador / gestor</label>
      <select id="empresa_id" name="empresa_id" class="select">
        <option value="0">— Selecione —</option>
        <?php foreach ($opcoesEmpresaRaiz as $er): ?>
        <option value="<?= (int) $er['id'] ?>" <?= ($empresaIdUsuario === (int) $er['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($er['empresa'] ?? '') ?> (#<?= (int) $er['id'] ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <?php if ($embedForm): ?>
    <?php if ($perfilAtual === 'cliente'): ?>
    <input type="hidden" name="cliente_id" value="<?= (int) ($usuario['cliente_id'] ?? 0) ?>">
    <?php endif; ?>
    <?php else: ?>
    <div class="form-group" id="wrap-cliente" style="<?= in_array(($usuario['perfil'] ?? ''), ['admin'], true) ? 'opacity:.45' : '' ?>">
      <label for="cliente_id">Acesso portal (cliente)</label>
      <select id="cliente_id" name="cliente_id" class="select" <?= in_array(($usuario['perfil'] ?? ''), ['admin'], true) ? 'disabled' : '' ?>>
        <option value="0">— Selecione —</option>
        <?php foreach ($opcoesCliente as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= ((int) ($usuario['cliente_id'] ?? 0) === (int) $c['id']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($c['empresa'] ?? '') ?> (#<?= (int) $c['id'] ?>)
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
  </div>

  <div class="panel-head" style="margin-top:8px;">
    <h4>Nova senha (opcional)</h4>
    <span class="panel-sub">Deixe em branco para manter a senha atual. Mínimo 6 caracteres.</span>
  </div>
  <div class="panel-body form form-grid">
    <div class="form-group">
      <label for="senha_nova">Nova senha</label>
      <input type="password" id="senha_nova" name="senha_nova" class="input" autocomplete="new-password" minlength="6"
             placeholder="Mínimo 6 caracteres">
    </div>
    <div class="form-group">
      <label for="senha_nova2">Confirmar nova senha</label>
      <input type="password" id="senha_nova2" name="senha_nova2" class="input" autocomplete="new-password" minlength="6"
             placeholder="Repita a nova senha">
    </div>
  </div>

  <div class="panel-body">
    <div class="form-actions" style="padding:0;border:0;background:transparent;">
      <a href="<?= htmlspecialchars($cancelHref, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary"<?= $embedForm ? ' target="_top"' : '' ?>>Cancelar</a>
      <button type="submit" class="btn btn-primary">Salvar</button>
    </div>
  </div>
</form>

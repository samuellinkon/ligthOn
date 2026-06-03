<?php
/**
 * Bloco “cadastro + acessos” (espelho cliente_detalhe / portal Meus dados).
 *
 * Antes do include:
 * - $meusDadosPainelCliente (array)
 * - $meusDadosPainelClienteId (int)
 * - $meusDadosPainelEmpresaRaizId (int)
 * - $meusDadosPainelModo — 'admin' | 'portal'
 * - $meusDadosPainelUsuarios (list<array>) — repo_usuarios_por_cliente(...)
 *
 * Opcional (admin ou portal com atalhos):
 * - $meusDadosPainelAnexosCount (int)
 * - $meusDadosPainelQtdItensCat (int)
 * - $meusDadosPainelChamadosAbertos (int)
 *
 * Só admin:
 * - $meusDadosPainelPodeUsuarios (bool)
 * - $meusDadosPainelEmpresaOperadorModal (int)
 * - $meusDadosPainelClientePortalModal (int)
 * - $meusDadosPainelUnicoEmpresaModo (bool)
 * - $meusDadosPainelCatPadraoId (int)
 *
 * Só portal:
 * - $meusDadosPainelUsuarioLogadoId (int)
 */

declare(strict_types=1);

if (!isset(
    $meusDadosPainelCliente,
    $meusDadosPainelClienteId,
    $meusDadosPainelEmpresaRaizId,
    $meusDadosPainelModo,
    $meusDadosPainelUsuarios
)) {
    return;
}

$cliente    = $meusDadosPainelCliente;
$clienteId  = (int) $meusDadosPainelClienteId;
$empresaRaizId = (int) $meusDadosPainelEmpresaRaizId;
$mdm        = (string) $meusDadosPainelModo;
$usuariosPortal = is_array($meusDadosPainelUsuarios) ? $meusDadosPainelUsuarios : [];
$isPortal   = ($mdm === 'portal');

if (!function_exists('perfil_acesso_label_pt')) {
    require_once __DIR__ . '/usuario_labels.php';
}

$anexosCount     = (int) ($meusDadosPainelAnexosCount ?? 0);
$qtdItensCat     = (int) ($meusDadosPainelQtdItensCat ?? 0);
$chamadosAbr     = (int) ($meusDadosPainelChamadosAbertos ?? 0);
$podeUsuarios    = !empty($meusDadosPainelPodeUsuarios);
$unicoEmpresaModo = !empty($meusDadosPainelUnicoEmpresaModo);
$catPadraoId     = (int) ($meusDadosPainelCatPadraoId ?? 0);
$empresaOperadorModal = (int) ($meusDadosPainelEmpresaOperadorModal ?? 0);
$clientePortalModal   = (int) ($meusDadosPainelClientePortalModal ?? 0);
$usuarioLogadoPortal  = (int) ($meusDadosPainelUsuarioLogadoId ?? 0);

$desdeTxt = !empty($cliente['desde']) ? date('d/m/Y', strtotime((string) $cliente['desde'])) : null;

?>
  <!-- HEADER do cliente -->
  <div class="card client-head">
    <div class="client-head-avatar">
      <div class="avatar avatar-lg"><?= htmlspecialchars(initials((string) ($cliente['empresa'] ?? ''))) ?></div>
    </div>

    <div class="client-head-info">
      <div class="flex-gap" style="align-items:center; gap:10px; flex-wrap:wrap;">
        <h3 style="margin:0;"><?= htmlspecialchars((string) ($cliente['empresa'] ?? '')) ?></h3>
        <span class="badge <?= status_class((string) ($cliente['status'] ?? '')) ?>"><?= htmlspecialchars((string) ($cliente['status'] ?? '')) ?></span>
      </div>

      <div class="muted" style="font-size:13px;">
        <?php
          $meta = [];
          if ($desdeTxt) {
              $meta[] = 'Na base desde ' . $desdeTxt;
          }
          if (!empty($cliente['doc'])) {
              $meta[] = htmlspecialchars((string) $cliente['doc']);
          }
          if ($isPortal) {
              $meta[] = 'Cadastro #' . $clienteId;
          }
          echo implode(' · ', $meta);
        ?>
      </div>

      <div style="font-size:13px;">
        <?php
          $ct = [];
          if (!empty($cliente['nome'])) {
              $ct[] = htmlspecialchars((string) $cliente['nome']);
          }
          if (!empty($cliente['email'])) {
              $ct[] = '<a href="mailto:' . htmlspecialchars((string) $cliente['email']) . '">' . htmlspecialchars((string) $cliente['email']) . '</a>';
          }
          if (!empty($cliente['telefone'])) {
              $ct[] = '<a href="tel:' . preg_replace('/\D+/', '', (string) $cliente['telefone']) . '">' . htmlspecialchars((string) $cliente['telefone']) . '</a>';
          }
          echo implode(' <span class="muted">·</span> ', $ct);
        ?>
      </div>
    </div>

    <div class="client-head-actions">
      <?php if ($isPortal): ?>
      <a href="perfil.php" class="btn btn-secondary btn-sm">Minha conta</a>
      <a href="documentos.php" class="btn btn-secondary btn-sm">
        Documentos<?php if ($anexosCount): ?><span class="btn-count"><?= $anexosCount ?></span><?php endif; ?>
      </a>
      <a href="chamados.php" class="btn btn-secondary btn-sm">
        Meus chamados<?php if ($chamadosAbr): ?><span class="btn-count"><?= $chamadosAbr ?></span><?php endif; ?>
      </a>
      <a href="catalogo.php" class="btn btn-secondary btn-sm">
        Catálogo<?php if ($qtdItensCat): ?><span class="btn-count"><?= $qtdItensCat ?></span><?php endif; ?>
      </a>
      <?php else: ?>
      <a href="cliente_editar.php?id=<?= $clienteId ?>" class="btn btn-secondary btn-sm">Editar</a>
      <a href="cliente_anexos.php?id=<?= $clienteId ?>" class="btn btn-secondary btn-sm">
        Anexos<?php if ($anexosCount): ?><span class="btn-count"><?= $anexosCount ?></span><?php endif; ?>
      </a>
      <a href="chamado_novo.php?cliente_id=<?= $clienteId ?>" class="btn btn-secondary btn-sm">
        Abrir chamado<?php if ($chamadosAbr): ?><span class="btn-count"><?= $chamadosAbr ?></span><?php endif; ?>
      </a>
      <a href="catalogo.php?cliente_id=<?= $empresaRaizId ?>" class="btn btn-secondary btn-sm">
        Catálogo<?php if ($qtdItensCat): ?><span class="btn-count"><?= $qtdItensCat ?></span><?php endif; ?>
      </a>
      <?php endif; ?>
    </div>
  </div>

  <?php
    $planoClienteRaizId = $empresaRaizId;
    require __DIR__ . '/partials/cliente_plano_resumo_compacto.php'; // modal
  ?>

  <!-- Acessos: abas Todos / Gestores / Clientes / Técnicos -->
  <div class="card cliente-acessos-card" style="margin-top:20px;">
    <div class="panel-head" style="flex-wrap:wrap;gap:12px;align-items:flex-start;">
      <div style="flex:1;min-width:0;">
        <h4 style="margin:0;">Acessos ao sistema</h4>
        <span class="panel-sub">Contas de <strong>clientes</strong> (portal), <strong>gestores</strong> e <strong>técnicos</strong> da empresa<?= $empresaRaizId !== $clienteId ? ' (#' . $empresaRaizId . ')' : '' ?>.</span>
      </div>
    </div>
    <style>
      .cliente-acessos-tabrow {
        display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px;
        padding:12px 20px; border-bottom:1px solid var(--border);
      }
      .cliente-acessos-tablist { display:flex; flex-wrap:wrap; gap:8px; margin:0; }
      .cliente-acessos-tab {
        border:1px solid var(--border); background:#fff; padding:8px 14px; border-radius:8px; cursor:pointer;
        font-weight:600; font-size:13px; color:var(--text);
      }
      .cliente-acessos-tab.is-active { background:var(--primary); color:#fff; border-color:transparent; }
      .cliente-acessos-toolbar { display:flex; flex-wrap:wrap; gap:8px; padding:12px 20px 0; align-items:center; }
    </style>
    <?php if (empty($usuariosPortal)): ?>
      <p class="muted" style="padding:16px 20px;margin:0;">
        <?php if ($isPortal): ?>
        Nenhum acesso cadastrado para esta empresa. Peça ao gestor da conta para criar utilizadores.
        <?php else: ?>
        Nenhum acesso cadastrado para esta empresa. Crie utilizadores em <strong>Usuários</strong> ou pelos atalhos abaixo.
        <?php endif; ?>
      </p>
      <?php if (!$isPortal): ?>
      <div class="cliente-acessos-toolbar" style="padding-bottom:16px;">
        <?php if ($podeUsuarios): ?>
        <button type="button" class="btn btn-sm btn-secondary" data-open-equipe-modal data-perfil="gestor">+ Gestor</button>
        <button type="button" class="btn btn-sm btn-primary" data-open-equipe-modal data-perfil="operador">+ Técnico</button>
        <button type="button" class="btn btn-sm btn-secondary" id="btn-modal-cliente">+ Cliente</button>
        <?php else: ?>
        <a href="usuario_novo.php?cliente_id=<?= (int) $empresaOperadorModal ?>&amp;perfil=gestor" class="btn btn-sm btn-secondary">+ Gestor</a>
        <a href="usuario_novo.php?cliente_id=<?= (int) $empresaOperadorModal ?>&amp;perfil=operador" class="btn btn-sm btn-primary">+ Técnico</a>
        <a href="usuario_novo.php?cliente_id=<?= (int) $clientePortalModal ?>&amp;perfil=cliente" class="btn btn-sm">+ Cliente</a>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    <?php else: ?>
    <?php
      $hrefNovoGestor   = 'usuario_novo.php?' . http_build_query(['cliente_id' => (int) $empresaOperadorModal, 'perfil' => 'gestor']);
      $hrefNovoOperador = 'usuario_novo.php?' . http_build_query(['cliente_id' => (int) $empresaOperadorModal, 'perfil' => 'operador']);
      $hrefNovoCliente  = 'usuario_novo.php?' . http_build_query(['cliente_id' => (int) $clientePortalModal, 'perfil' => 'cliente']);
    ?>
    <div class="cliente-acessos-tabs" data-acessos-tabs
      <?php if (!$isPortal && !$podeUsuarios): ?>
      data-href-gestor="<?= htmlspecialchars($hrefNovoGestor, ENT_QUOTES, 'UTF-8') ?>"
      data-href-operador="<?= htmlspecialchars($hrefNovoOperador, ENT_QUOTES, 'UTF-8') ?>"
      data-href-cliente="<?= htmlspecialchars($hrefNovoCliente, ENT_QUOTES, 'UTF-8') ?>"
      <?php endif; ?>>
      <div class="cliente-acessos-tabrow">
        <div class="cliente-acessos-tablist" role="tablist" aria-label="Filtrar acessos por perfil">
          <button type="button" class="cliente-acessos-tab is-active" role="tab" id="tab-acessos-all" aria-selected="true" aria-controls="panel-acessos-table" data-tab="all">Todos</button>
          <button type="button" class="cliente-acessos-tab" role="tab" id="tab-acessos-gestor" aria-selected="false" aria-controls="panel-acessos-table" data-tab="gestor" tabindex="-1">Gestores</button>
          <button type="button" class="cliente-acessos-tab" role="tab" id="tab-acessos-cliente" aria-selected="false" aria-controls="panel-acessos-table" data-tab="cliente" tabindex="-1">Clientes</button>
          <button type="button" class="cliente-acessos-tab" role="tab" id="tab-acessos-operador" aria-selected="false" aria-controls="panel-acessos-table" data-tab="operador" tabindex="-1">Técnicos</button>
        </div>
        <?php if (!$isPortal && $podeUsuarios): ?>
        <button type="button" class="btn btn-sm btn-primary" id="btn-acesso-novo-uni" aria-live="polite">+ Cliente</button>
        <button type="button" id="btn-modal-cliente" hidden aria-hidden="true" tabindex="-1">Abrir formulário novo cliente</button>
        <?php elseif (!$isPortal): ?>
        <a class="btn btn-sm btn-primary" id="lnk-acesso-novo-uni" href="<?= htmlspecialchars($hrefNovoCliente, ENT_QUOTES, 'UTF-8') ?>">+ Cliente</a>
        <?php endif; ?>
      </div>

      <div id="panel-acessos-table" role="tabpanel" aria-labelledby="tab-acessos-all">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Nome</th>
                <th>E-mail</th>
                <th>Perfil</th>
                <th>Cadastro</th>
                <?php if (!$isPortal): ?>
                <th class="text-right">Ações</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($usuariosPortal as $pu):
                $perfilU = (string) ($pu['perfil'] ?? '');
                $rowPerfilTab = $perfilU === 'operador' ? 'operador' : $perfilU;
                $hrefChamadosUser = 'chamados.php?' . http_build_query([
                    'cliente_id'      => (int) $empresaRaizId,
                    'envolvido_user' => (int) ($pu['id'] ?? 0),
                ]);
                $mostraLinkChamados = !$isPortal && ($perfilU !== 'cliente'
                    || (isset($pu['cliente_id']) && (int) $pu['cliente_id'] > 0));
                ?>
            <tr data-row-perfil="<?= htmlspecialchars($rowPerfilTab, ENT_QUOTES, 'UTF-8') ?>">
              <td>
                <div class="cell-client">
                  <div class="avatar avatar-sm"><?= htmlspecialchars((string) ($pu['iniciais'] ?? '?')) ?></div>
                  <div>
                    <strong><?= htmlspecialchars((string) ($pu['nome'] ?? '')) ?></strong>
                    <?php if ($isPortal && $usuarioLogadoPortal > 0 && (int) ($pu['id'] ?? 0) === $usuarioLogadoPortal): ?>
                      <span class="badge open" style="margin-left:6px;font-size:11px;">Você</span>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td class="td-mute"><?= htmlspecialchars((string) ($pu['email'] ?? '')) ?></td>
              <td><span class="badge badge-plain"><?= htmlspecialchars(perfil_acesso_label_pt($perfilU)) ?></span></td>
              <td class="td-mute"><small><?= htmlspecialchars((string) ($pu['criado_em'] ?? '')) ?></small></td>
              <?php if (!$isPortal): ?>
              <td class="td-actions">
                <?php if ($mostraLinkChamados): ?>
                <a class="action" href="<?= htmlspecialchars($hrefChamadosUser) ?>" title="Chamados em que este utilizador intervém">Chamados</a>
                <?php endif; ?>
                <?php if ($podeUsuarios): ?>
                <button type="button" class="action primary js-modal-editar-usuario" data-user-id="<?= (int) $pu['id'] ?>">Editar</button>
                <?php else: ?>
                <a class="action primary" href="usuario_editar.php?id=<?= (int) $pu['id'] ?>">Editar</a>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

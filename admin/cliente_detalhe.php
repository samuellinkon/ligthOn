<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_once __DIR__ . '/../includes/usuario_labels.php';
require_modulo_admin('chamados');

$pageTitle  = 'Prefeitura';
$basePath   = '../';
$activePage = 'clientes';

$clienteId = (int) ($_GET['id'] ?? 0);
if ($clienteId <= 0) {
    flash_set('err', 'Cadastro não informado.');
    header('Location: clientes.php'); exit;
}
if (!db_ok()) {
    flash_set('err', 'Banco indisponível. Execute install.php primeiro.');
    header('Location: clientes.php'); exit;
}

$cliente = repo_cliente($clienteId);
if (!$cliente) {
    flash_set('err', 'Cadastro #' . $clienteId . ' não encontrado.');
    header('Location: clientes.php'); exit;
}
gestor_assert_escopo_cliente($clienteId, 'chamados.php');

$podeUsuarios     = app_modulo_painel_habilitado('usuarios', $me);
$escopoGestor     = gestao_scope_cliente_id($me);
$unicoEmpresaModo = true;
$catPadraoId      = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
/** Exclusão total da empresa: apenas admin sem escopo de gestor (visão global). */
$podeExcluirEmpresaCompleta = ($escopoGestor === null);

// ---- POST: excluir / cadastro usuário (modal) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acaoPost = (string) ($_POST['acao'] ?? '');
    if ($acaoPost === 'excluir') {
        if (!$podeExcluirEmpresaCompleta) {
            flash_set('err', 'Apenas administradores da plataforma podem excluir o cadastro completo.');
            header('Location: cliente_detalhe.php?id=' . $clienteId);
            exit;
        }
        if (empty($_POST['confirmar_exclusao_total'])) {
            flash_set('err', 'Confirme que entende que todos os dados serão apagados.');
            header('Location: cliente_detalhe.php?id=' . $clienteId);
            exit;
        }
        $uidAdmin = (int) ($me['id'] ?? 0);
        if (repo_delete_empresa_completa($clienteId, $uidAdmin)) {
            flash_set('ok', 'Empresa e dados vinculados foram excluídos. Você pode cadastrar uma nova prefeitura.');
        } else {
            flash_set('err', 'Não foi possível concluir a exclusão. Verifique registros vinculados ou o log do servidor.');
        }
        header('Location: clientes.php');
        exit;
    }
    if ($acaoPost === 'cad_usuario_modal') {
        if (!$podeUsuarios) {
            flash_set('err', 'Módulo Usuários indisponível ou sem permissão.');
            header('Location: cliente_detalhe.php?id=' . $clienteId);
            exit;
        }
        $perfilM = strtolower(trim((string) ($_POST['perfil_modal'] ?? '')));
        if (!in_array($perfilM, ['cliente', 'operador', 'gestor'], true)) {
            flash_set('err', 'Perfil inválido.');
            header('Location: cliente_detalhe.php?id=' . $clienteId);
            exit;
        }
        $nome   = trim((string) ($_POST['nome'] ?? ''));
        $email  = trim((string) ($_POST['email'] ?? ''));
        $senha  = (string) ($_POST['senha'] ?? '');
        $senha2 = (string) ($_POST['senha2'] ?? '');
        if ($nome === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('err', 'Informe nome e um e-mail válido.');
            header('Location: cliente_detalhe.php?id=' . $clienteId);
            exit;
        }
        if ($senha !== $senha2) {
            flash_set('err', 'As senhas não coincidem.');
            header('Location: cliente_detalhe.php?id=' . $clienteId);
            exit;
        }
        if (strlen($senha) < 6) {
            flash_set('err', 'A senha deve ter pelo menos 6 caracteres.');
            header('Location: cliente_detalhe.php?id=' . $clienteId);
            exit;
        }
        if (repo_email_existe($email)) {
            flash_set('err', 'Já existe um usuário com este e-mail.');
            header('Location: cliente_detalhe.php?id=' . $clienteId);
            exit;
        }

        $empresaRaizPost = repo_cliente_catalogo_dono_id($clienteId);
        $empOper         = ($unicoEmpresaModo && $catPadraoId > 0) ? $catPadraoId : $empresaRaizPost;
        $cliPortal       = $clienteId;
        if ($escopoGestor !== null) {
            if ($empOper <= 0 || !repo_cliente_pertence_empresa($empOper, $escopoGestor)) {
                $empOper = $escopoGestor;
            }
            if ($cliPortal <= 0 || !repo_cliente_pertence_empresa($cliPortal, $escopoGestor)) {
                $cliPortal = $escopoGestor;
            }
        }
        $clienteCriar = null;
        $empresaCriar = null;
        if ($perfilM === 'operador' || $perfilM === 'gestor') {
            $empresaCriar = $empOper;
            if ($empresaCriar <= 0) {
                flash_set('err', 'Empresa para equipe inválida.');
                header('Location: cliente_detalhe.php?id=' . $clienteId);
                exit;
            }
        } else {
            $clienteCriar = $cliPortal;
            if ($clienteCriar <= 0) {
                flash_set('err', 'Conta portal inválida.');
                header('Location: cliente_detalhe.php?id=' . $clienteId);
                exit;
            }
            $opcoesCli = $escopoGestor !== null ? repo_clientes_na_empresa($escopoGestor) : repo_clientes();
            $okCli     = false;
            foreach ($opcoesCli as $c) {
                if ((int) ($c['id'] ?? 0) === $clienteCriar) {
                    $okCli = true;
                    break;
                }
            }
            if (!$okCli) {
                flash_set('err', 'Cadastro do portal inválido para o vínculo.');
                header('Location: cliente_detalhe.php?id=' . $clienteId);
                exit;
            }
        }

        $newId = repo_create_usuario([
            'nome'          => $nome,
            'email'         => $email,
            'senha'         => $senha,
            'perfil'        => $perfilM,
            'cliente_id'    => $clienteCriar,
            'empresa_id'    => $empresaCriar,
            'iniciais'      => repo_usuario_calcular_iniciais($nome),
            'modulo_perfil' => null,
        ]);
        if (!$newId) {
            flash_set('err', 'Não foi possível criar o usuário.');
            header('Location: cliente_detalhe.php?id=' . $clienteId);
            exit;
        }
        flash_set('ok', 'Usuário #' . $newId . ' criado com sucesso.');
        header('Location: cliente_detalhe.php?id=' . $clienteId);
        exit;
    }
}

$anexosCount     = (int) count(repo_cliente_anexos($clienteId));
$usuariosPortal  = repo_usuarios_por_cliente($clienteId);
$temUsuarioPortalCliente = false;
foreach ($usuariosPortal as $_pu) {
    if ((string) ($_pu['perfil'] ?? '') === 'cliente') {
        $temUsuarioPortalCliente = true;
        break;
    }
}
$empresaRaizId   = repo_cliente_catalogo_dono_id($clienteId);
$qtdItensCat     = count(repo_cliente_itens_list($empresaRaizId, false));

$empresaOperadorModal = ($unicoEmpresaModo && $catPadraoId > 0) ? $catPadraoId : $empresaRaizId;
$clientePortalModal  = $clienteId;
if ($escopoGestor !== null) {
    if ($empresaOperadorModal <= 0 || !repo_cliente_pertence_empresa($empresaOperadorModal, $escopoGestor)) {
        $empresaOperadorModal = $escopoGestor;
    }
    if ($clientePortalModal <= 0 || !repo_cliente_pertence_empresa($clientePortalModal, $escopoGestor)) {
        $clientePortalModal = $escopoGestor;
    }
}
$rowVincOper   = repo_cliente($empresaOperadorModal);
$rowVincPortal = repo_cliente($clientePortalModal);
$nomeEmpresaOperador  = trim((string) ($rowVincOper['empresa'] ?? ''));
$nomeEmpresaPortal    = trim((string) ($rowVincPortal['empresa'] ?? ''));

$topTitle    = $cliente['empresa'];
$topSubtitle = 'Cadastro #' . $clienteId . ' · ' . $cliente['status'];
$topSearch   = 'Buscar cadastro...';
$topAction   = ['label' => 'Voltar', 'href' => 'clientes.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">

  <!-- HEADER do cliente -->
  <?php
    $desdeTxt    = !empty($cliente['desde']) ? date('d/m/Y', strtotime($cliente['desde'])) : null;
    $chamadosAbr = (int) ($cliente['pendentes'] ?? 0);
  ?>
  <div class="card client-head">
    <div class="client-head-avatar">
      <div class="avatar avatar-lg"><?= htmlspecialchars(initials($cliente['empresa'])) ?></div>
    </div>

    <div class="client-head-info">
      <div class="flex-gap" style="align-items:center; gap:10px; flex-wrap:wrap;">
        <h3 style="margin:0;"><?= htmlspecialchars($cliente['empresa']) ?></h3>
        <span class="badge <?= status_class($cliente['status']) ?>"><?= htmlspecialchars($cliente['status']) ?></span>
      </div>

      <div class="muted" style="font-size:13px;">
        <?php
          $meta = [];
          if ($desdeTxt)                                 $meta[] = 'Na base desde ' . $desdeTxt;
          if (!empty($cliente['doc']))                   $meta[] = htmlspecialchars($cliente['doc']);
          echo implode(' · ', $meta);
        ?>
      </div>

      <div style="font-size:13px;">
        <?php
          $ct = [];
          if (!empty($cliente['nome']))     $ct[] = htmlspecialchars($cliente['nome']);
          if (!empty($cliente['email']))    $ct[] = '<a href="mailto:' . htmlspecialchars($cliente['email']) . '">' . htmlspecialchars($cliente['email']) . '</a>';
          if (!empty($cliente['telefone'])) $ct[] = '<a href="tel:' . preg_replace('/\D+/', '', $cliente['telefone']) . '">' . htmlspecialchars($cliente['telefone']) . '</a>';
          echo implode(' <span class="muted">·</span> ', $ct);
        ?>
      </div>
    </div>

    <div class="client-head-actions">
      <a href="cliente_editar.php?id=<?= $clienteId ?>" class="btn btn-secondary btn-sm">Editar</a>
      <a href="cliente_anexos.php?id=<?= $clienteId ?>" class="btn btn-secondary btn-sm">
        Anexos<?php if ($anexosCount): ?><span class="btn-count"><?= $anexosCount ?></span><?php endif; ?>
      </a>
      <a href="chamado_novo.php?cliente_id=<?= $clienteId ?>" class="btn btn-secondary btn-sm">
        Abrir chamado<?php if ($chamadosAbr): ?><span class="btn-count"><?= $chamadosAbr ?></span><?php endif; ?>
      </a>
      <a href="catalogo.php?cliente_id=<?= (int) $empresaRaizId ?>" class="btn btn-secondary btn-sm">
        Catálogo<?php if ($qtdItensCat): ?><span class="btn-count"><?= $qtdItensCat ?></span><?php endif; ?>
      </a>
    </div>
  </div>

  <!-- Acessos: abas Todos / Gestores / Clientes / Técnicos -->
  <div class="card cliente-acessos-card" style="margin-top:20px;">
    <div class="panel-head" style="flex-wrap:wrap;gap:12px;align-items:flex-start;">
      <div style="flex:1;min-width:0;">
        <h4 style="margin:0;">Acessos ao sistema</h4>
        <span class="panel-sub">Contas de <strong>clientes</strong> (portal), <strong>gestores</strong> e <strong>técnicos</strong> da empresa<?= $empresaRaizId !== $clienteId ? ' (#' . (int) $empresaRaizId . ')' : '' ?>.</span>
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
      <p class="muted" style="padding:16px 20px;margin:0;">Nenhum acesso cadastrado para esta empresa. Crie utilizadores em <strong>Usuários</strong> ou pelos atalhos abaixo.</p>
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
    <?php else: ?>
    <?php
      $hrefNovoGestor   = 'usuario_novo.php?' . http_build_query(['cliente_id' => (int) $empresaOperadorModal, 'perfil' => 'gestor']);
      $hrefNovoOperador = 'usuario_novo.php?' . http_build_query(['cliente_id' => (int) $empresaOperadorModal, 'perfil' => 'operador']);
      $hrefNovoCliente  = 'usuario_novo.php?' . http_build_query(['cliente_id' => (int) $clientePortalModal, 'perfil' => 'cliente']);
    ?>
    <div class="cliente-acessos-tabs" data-acessos-tabs
      <?php if (!$podeUsuarios): ?>
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
        <?php if ($podeUsuarios): ?>
        <button type="button" class="btn btn-sm btn-primary" id="btn-acesso-novo-uni" aria-live="polite">+ Cliente</button>
        <button type="button" id="btn-modal-cliente" hidden aria-hidden="true" tabindex="-1">Abrir formulário novo cliente</button>
        <?php else: ?>
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
                <th class="text-right">Ações</th>
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
                $mostraLinkChamados = $perfilU !== 'cliente'
                    || (isset($pu['cliente_id']) && (int) $pu['cliente_id'] > 0);
                ?>
            <tr data-row-perfil="<?= htmlspecialchars($rowPerfilTab, ENT_QUOTES, 'UTF-8') ?>">
              <td>
                <div class="cell-client">
                  <div class="avatar avatar-sm"><?= htmlspecialchars((string) ($pu['iniciais'] ?? '?')) ?></div>
                  <strong><?= htmlspecialchars((string) ($pu['nome'] ?? '')) ?></strong>
                </div>
              </td>
              <td class="td-mute"><?= htmlspecialchars((string) ($pu['email'] ?? '')) ?></td>
              <td><span class="badge badge-plain"><?= htmlspecialchars(perfil_acesso_label_pt($perfilU)) ?></span></td>
              <td class="td-mute"><small><?= htmlspecialchars((string) ($pu['criado_em'] ?? '')) ?></small></td>
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
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- OBSERVAÇÕES + AÇÃO DESTRUTIVA -->
  <?php if (!empty($cliente['obs'])): ?>
  <div class="card" style="margin-top:20px;">
    <div class="panel-head">
      <h4>Observações</h4>
      <span class="panel-sub">Notas internas do cliente</span>
    </div>
    <div class="panel-body cliente-detalhe-obs"><?= htmlspecialchars((string) $cliente['obs']) ?></div>
  </div>
  <?php endif; ?>

  <?php if ($podeExcluirEmpresaCompleta): ?>
  <?php
    $matrizExclusaoId = repo_cliente_matriz_raiz_id($clienteId);
    $idsEmpresaExclusao = array_map(static function ($r) {
        return (int) ($r['id'] ?? 0);
    }, repo_clientes_na_empresa($matrizExclusaoId));
    $idsEmpresaExclusao = array_values(array_filter($idsEmpresaExclusao, static function ($x) {
        return $x > 0;
    }));
    $qUnidadesExclusao = count($idsEmpresaExclusao) > 1 ? (count($idsEmpresaExclusao) - 1) : 0;
  ?>
  <div class="card" style="margin-top:20px;border-color:rgba(220,38,38,.35);">
    <div class="panel-head" style="flex-wrap:wrap;gap:10px;">
      <div>
        <h4 style="margin:0;color:#b91c1c;">Excluir empresa e todos os dados</h4>
        <span class="panel-sub">Remove chamados, pontos de iluminação, importações de medição, OS, usuários do portal e da equipe (exceto administradores), anexos e cadastros desta empresa<?= $qUnidadesExclusao > 0 ? ' (' . $qUnidadesExclusao . ' unidade(s) filha(s))' : '' ?>.</span>
      </div>
    </div>
    <div style="padding:0 20px 20px;">
      <form method="post" action="cliente_detalhe.php?id=<?= (int) $clienteId ?>" class="form-delete-empresa" onsubmit="return confirm('Excluir DEFINITIVAMENTE a empresa #<?= (int) $matrizExclusaoId ?> e todos os dados relacionados? Esta ação não pode ser desfeita.');">
        <input type="hidden" name="acao" value="excluir">
        <label class="dashboard-pontos-tools-toggle" style="margin:0 0 14px;display:flex;align-items:flex-start;gap:10px;white-space:normal;font-weight:600;color:#374151;">
          <input type="checkbox" name="confirmar_exclusao_total" value="1" required style="margin-top:4px;">
          Entendo que todos os dados desta empresa serão apagados de forma irreversível.
        </label>
        <button type="submit" class="btn btn-sm" style="background:#dc2626;border-color:#dc2626;color:#fff;">Excluir empresa permanentemente</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($podeUsuarios): ?>
  <div class="app-modal-overlay" id="modal-novo-operador" hidden aria-hidden="true">
    <div class="app-modal app-modal--form" style="max-width:440px;" role="dialog" aria-modal="true" aria-labelledby="tit-modal-op">
      <h3 class="app-modal-title" id="tit-modal-op">Novo técnico</h3>
      <div class="modal-vinculo-resumo" style="margin:0 0 18px;padding:14px 16px;border-radius:14px;background:var(--panel-highlight,#f4f6fb);border:1px solid var(--border);">
        <div style="display:grid;gap:10px;">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <span class="muted" style="font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">Perfil</span>
            <span class="badge badge-plain" id="modal-equipe-perfil-label">Técnico</span>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
            <span class="muted" style="font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">Empresa</span>
            <div style="text-align:right;line-height:1.35;">
              <strong>#<?= (int) $empresaOperadorModal ?></strong>
              <?php if ($nomeEmpresaOperador !== ''): ?>
                <div class="muted" style="font-size:13px;margin-top:2px;max-width:240px;"><?= htmlspecialchars($nomeEmpresaOperador) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($unicoEmpresaModo): ?>
            <p class="muted" style="margin:4px 0 0;font-size:12px;line-height:1.45;padding-top:10px;border-top:1px solid var(--border-soft, #e8e8ef);">Modo empresa única: vínculo à empresa padrão do catálogo.</p>
          <?php else: ?>
            <p class="muted" style="margin:4px 0 0;font-size:12px;line-height:1.45;padding-top:10px;border-top:1px solid var(--border-soft, #e8e8ef);">Atende chamados da matriz e unidades desta empresa.</p>
          <?php endif; ?>
        </div>
      </div>
      <form method="post" action="cliente_detalhe.php?id=<?= (int) $clienteId ?>">
        <input type="hidden" name="acao" value="cad_usuario_modal">
        <input type="hidden" name="perfil_modal" id="equipe_perfil_modal" value="operador">
        <div class="form-group" style="margin-bottom:12px;">
          <label for="op_nome">Nome completo</label>
          <input type="text" id="op_nome" name="nome" class="input" required maxlength="120" autocomplete="name" placeholder="Nome e sobrenome">
        </div>
        <div class="form-group" style="margin-bottom:12px;">
          <label for="op_email">E-mail (login)</label>
          <input type="email" id="op_email" name="email" class="input" required maxlength="150" autocomplete="email" placeholder="nome@organização.gov.br">
        </div>
        <div class="form-group" style="margin-bottom:12px;">
          <label for="op_senha">Senha</label>
          <input type="password" id="op_senha" name="senha" class="input" required minlength="6" autocomplete="new-password" placeholder="Mínimo 6 caracteres">
        </div>
        <div class="form-group" style="margin-bottom:16px;">
          <label for="op_senha2">Confirmar senha</label>
          <input type="password" id="op_senha2" name="senha2" class="input" required minlength="6" autocomplete="new-password" placeholder="Repita a senha">
        </div>
        <div class="app-modal-actions" style="margin-top:0;">
          <button type="button" class="btn btn-secondary" data-close-modal-op>Cancelar</button>
          <button type="submit" class="btn btn-primary" id="modal-equipe-submit">Criar técnico</button>
        </div>
      </form>
    </div>
  </div>

  <div class="app-modal-overlay" id="modal-novo-cliente-user" hidden aria-hidden="true">
    <div class="app-modal app-modal--form" style="max-width:440px;" role="dialog" aria-modal="true" aria-labelledby="tit-modal-cli">
      <h3 class="app-modal-title" id="tit-modal-cli">Novo cliente (portal)</h3>
      <div class="modal-vinculo-resumo" style="margin:0 0 18px;padding:14px 16px;border-radius:14px;background:var(--panel-highlight,#f4f6fb);border:1px solid var(--border);">
        <div style="display:grid;gap:10px;">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
            <span class="muted" style="font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">Perfil</span>
            <span class="badge badge-plain">Cliente</span>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
            <span class="muted" style="font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">Conta</span>
            <div style="text-align:right;line-height:1.35;">
              <strong>#<?= (int) $clientePortalModal ?></strong>
              <?php if ($nomeEmpresaPortal !== ''): ?>
                <div class="muted" style="font-size:13px;margin-top:2px;max-width:240px;"><?= htmlspecialchars($nomeEmpresaPortal) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($unicoEmpresaModo): ?>
            <p class="muted" style="margin:4px 0 0;font-size:12px;line-height:1.45;padding-top:10px;border-top:1px solid var(--border-soft, #e8e8ef);">Modo empresa única: login ligado à empresa padrão do catálogo.</p>
          <?php else: ?>
            <p class="muted" style="margin:4px 0 0;font-size:12px;line-height:1.45;padding-top:10px;border-top:1px solid var(--border-soft, #e8e8ef);">Acesso ao portal desta ficha da prefeitura.</p>
          <?php endif; ?>
        </div>
      </div>
      <form method="post" action="cliente_detalhe.php?id=<?= (int) $clienteId ?>">
        <input type="hidden" name="acao" value="cad_usuario_modal">
        <input type="hidden" name="perfil_modal" value="cliente">
        <div class="form-group" style="margin-bottom:12px;">
          <label for="cli_nome">Nome completo</label>
          <input type="text" id="cli_nome" name="nome" class="input" required maxlength="120" autocomplete="name" placeholder="Nome e sobrenome">
        </div>
        <div class="form-group" style="margin-bottom:12px;">
          <label for="cli_email">E-mail (login)</label>
          <input type="email" id="cli_email" name="email" class="input" required maxlength="150" autocomplete="email" placeholder="nome@organização.gov.br">
        </div>
        <div class="form-group" style="margin-bottom:12px;">
          <label for="cli_senha">Senha</label>
          <input type="password" id="cli_senha" name="senha" class="input" required minlength="6" autocomplete="new-password" placeholder="Mínimo 6 caracteres">
        </div>
        <div class="form-group" style="margin-bottom:16px;">
          <label for="cli_senha2">Confirmar senha</label>
          <input type="password" id="cli_senha2" name="senha2" class="input" required minlength="6" autocomplete="new-password" placeholder="Repita a senha">
        </div>
        <div class="app-modal-actions" style="margin-top:0;">
          <button type="button" class="btn btn-secondary" data-close-modal-cli>Cancelar</button>
          <button type="submit" class="btn btn-primary">Criar acesso</button>
        </div>
      </form>
    </div>
  </div>

  <div class="app-modal-overlay" id="modal-editar-usuario" hidden aria-hidden="true">
    <div class="app-modal" style="max-width:820px;width:94%;padding:0;overflow:hidden;" role="dialog" aria-modal="true" aria-labelledby="tit-modal-edit-user">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid var(--border);flex-shrink:0;">
        <h3 class="app-modal-title" id="tit-modal-edit-user" style="margin:0;font-size:1.05rem;">Editar usuário</h3>
        <button type="button" class="btn btn-ghost btn-sm" data-close-edit-user aria-label="Fechar">Fechar</button>
      </div>
      <iframe id="iframe-editar-usuario" title="Editar usuário" style="width:100%;min-height:70vh;border:0;display:block;background:#fff;"></iframe>
    </div>
  </div>
  <?php endif; ?>
</section>

<?php if ($podeUsuarios || !empty($usuariosPortal)): ?>
<script>
(function () {
  var podeM = <?= $podeUsuarios ? 'true' : 'false' ?>;

  var rootAcessos = document.querySelector('[data-acessos-tabs]');
  if (rootAcessos) {
    var tabsA = rootAcessos.querySelectorAll('.cliente-acessos-tablist [role="tab"]');
    var rowsA = rootAcessos.querySelectorAll('tbody [data-row-perfil]');
    var btnNovoUni = document.getElementById('btn-acesso-novo-uni');
    var lnkNovoUni = document.getElementById('lnk-acesso-novo-uni');

    function effPerfilFromTab(name) {
      if (name === 'gestor') return 'gestor';
      if (name === 'operador') return 'operador';
      return 'cliente';
    }

    function setAbaAcessos(name) {
      rootAcessos.dataset.activeTab = name;
      tabsA.forEach(function (t) {
        var on = (t.getAttribute('data-tab') || '') === name;
        t.classList.toggle('is-active', on);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
      });
      rowsA.forEach(function (tr) {
        var p = tr.getAttribute('data-row-perfil') || '';
        tr.hidden = name !== 'all' && p !== name;
      });
      var eff = effPerfilFromTab(name);
      if (btnNovoUni) {
        if (eff === 'gestor') {
          btnNovoUni.textContent = '+ Gestor';
          btnNovoUni.setAttribute('aria-label', 'Adicionar gestor');
        } else if (eff === 'operador') {
          btnNovoUni.textContent = '+ Técnico';
          btnNovoUni.setAttribute('aria-label', 'Adicionar técnico');
        } else {
          btnNovoUni.textContent = '+ Cliente';
          btnNovoUni.setAttribute('aria-label', 'Adicionar cliente (portal)');
        }
      }
      if (lnkNovoUni) {
        var href = rootAcessos.getAttribute('data-href-' + eff);
        if (href) {
          lnkNovoUni.setAttribute('href', href);
        }
        if (eff === 'gestor') {
          lnkNovoUni.textContent = '+ Gestor';
        } else if (eff === 'operador') {
          lnkNovoUni.textContent = '+ Técnico';
        } else {
          lnkNovoUni.textContent = '+ Cliente';
        }
      }
    }
    tabsA.forEach(function (tab) {
      tab.addEventListener('click', function () {
        setAbaAcessos(tab.getAttribute('data-tab') || 'all');
      });
    });
    setAbaAcessos('all');
  }

  function wireModal(openId, overlayId, closeSel) {
    var openBtn = document.getElementById(openId);
    var ov = document.getElementById(overlayId);
    if (!openBtn || !ov) return;
    function openM() {
      ov.classList.add('is-open');
      ov.removeAttribute('hidden');
      ov.setAttribute('aria-hidden', 'false');
      var inp = overlayId === 'modal-novo-operador' ? ov.querySelector('#op_nome') : ov.querySelector('#cli_nome');
      if (inp) setTimeout(function () { inp.focus(); }, 40);
    }
    function closeM() {
      ov.classList.remove('is-open');
      ov.setAttribute('hidden', 'hidden');
      ov.setAttribute('aria-hidden', 'true');
    }
    openBtn.addEventListener('click', openM);
    ov.addEventListener('click', function (e) {
      if (e.target === ov) closeM();
    });
    var closes = ov.querySelectorAll(closeSel);
    for (var i = 0; i < closes.length; i++) {
      closes[i].addEventListener('click', closeM);
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && ov.classList.contains('is-open')) {
        e.preventDefault();
        closeM();
      }
    });
  }

  var modalEquipe = podeM ? document.getElementById('modal-novo-operador') : null;
  var equipePerfilInput = podeM ? document.getElementById('equipe_perfil_modal') : null;
  var equipeTitulo = podeM ? document.getElementById('tit-modal-op') : null;
  var equipePerfilLabel = podeM ? document.getElementById('modal-equipe-perfil-label') : null;
  var equipeSubmit = podeM ? document.getElementById('modal-equipe-submit') : null;
  function closeEquipeModal() {
    if (!modalEquipe) return;
    modalEquipe.classList.remove('is-open');
    modalEquipe.setAttribute('hidden', 'hidden');
    modalEquipe.setAttribute('aria-hidden', 'true');
  }
  function openEquipeModal(perfil) {
    if (!podeM || !modalEquipe || !equipePerfilInput) return;
    var isGestor = perfil === 'gestor';
    equipePerfilInput.value = isGestor ? 'gestor' : 'operador';
    if (equipeTitulo) equipeTitulo.textContent = isGestor ? 'Novo gestor' : 'Novo técnico';
    if (equipePerfilLabel) equipePerfilLabel.textContent = isGestor ? 'Gestor' : 'Técnico';
    if (equipeSubmit) equipeSubmit.textContent = isGestor ? 'Criar gestor' : 'Criar técnico';
    modalEquipe.classList.add('is-open');
    modalEquipe.removeAttribute('hidden');
    modalEquipe.setAttribute('aria-hidden', 'false');
    var inp = modalEquipe.querySelector('#op_nome');
    if (inp) setTimeout(function () { inp.focus(); }, 40);
  }

  if (podeM) {
    wireModal('btn-modal-cliente', 'modal-novo-cliente-user', '[data-close-modal-cli]');

    var btnNovoUni = document.getElementById('btn-acesso-novo-uni');
    if (btnNovoUni && rootAcessos) {
      btnNovoUni.addEventListener('click', function () {
        var tab = rootAcessos.dataset.activeTab || 'all';
        if (tab === 'gestor') {
          openEquipeModal('gestor');
        } else if (tab === 'operador') {
          openEquipeModal('operador');
        } else {
          var b = document.getElementById('btn-modal-cliente');
          if (b) b.click();
        }
      });
    }

    document.querySelectorAll('[data-open-equipe-modal]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openEquipeModal(btn.getAttribute('data-perfil') || 'operador');
      });
    });
    if (modalEquipe) {
      modalEquipe.addEventListener('click', function (e) {
        if (e.target === modalEquipe) closeEquipeModal();
      });
      modalEquipe.querySelectorAll('[data-close-modal-op]').forEach(function (btn) {
        btn.addEventListener('click', closeEquipeModal);
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modalEquipe && modalEquipe.classList.contains('is-open')) {
        e.preventDefault();
        closeEquipeModal();
      }
    });
  }

  var ovEdit = document.getElementById('modal-editar-usuario');
  var iframeEdit = document.getElementById('iframe-editar-usuario');
  var returnEdit = <?= json_encode('cliente_detalhe.php?id=' . (int) $clienteId) ?>;
  function openEditUserModal(userId) {
    if (!ovEdit || !iframeEdit) return;
    iframeEdit.src = 'usuario_editar.php?id=' + encodeURIComponent(userId) + '&embed=1&return=' + encodeURIComponent(returnEdit);
    ovEdit.classList.add('is-open');
    ovEdit.removeAttribute('hidden');
    ovEdit.setAttribute('aria-hidden', 'false');
  }
  function closeEditUserModal() {
    if (!ovEdit || !iframeEdit) return;
    ovEdit.classList.remove('is-open');
    ovEdit.setAttribute('hidden', 'hidden');
    ovEdit.setAttribute('aria-hidden', 'true');
    iframeEdit.src = 'about:blank';
  }
  if (podeM) {
    document.querySelectorAll('.js-modal-editar-usuario').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-user-id');
        if (id) openEditUserModal(id);
      });
    });
    if (ovEdit) {
      ovEdit.addEventListener('click', function (e) {
        if (e.target === ovEdit) closeEditUserModal();
      });
      ovEdit.querySelectorAll('[data-close-edit-user]').forEach(function (el) {
        el.addEventListener('click', closeEditUserModal);
      });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && ovEdit && ovEdit.classList.contains('is-open')) {
        e.preventDefault();
        closeEditUserModal();
      }
    });

    <?php if (!$temUsuarioPortalCliente && empty($_GET['sem_modal_portal'])): ?>
    window.setTimeout(function () {
      var b = document.getElementById('btn-modal-cliente');
      if (b) b.click();
    }, 280);
    <?php endif; ?>
  }
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

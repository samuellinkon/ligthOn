<?php
/**
 * Portal: cadastro da prefeitura + lista de acessos (espelho de cliente_detalhe, só leitura).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';

$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';

$pageTitle  = 'Meus dados';
$basePath   = '../';
$activePage = 'meus_dados';

$cid = (int) ($user['cliente_id'] ?? 0);
if (!db_ok()) {
    flash_set('err', 'Banco indisponível.');
    header('Location: index.php');
    exit;
}
if ($cid <= 0) {
    flash_set('err', 'Sessão sem cadastro vinculado.');
    header('Location: index.php');
    exit;
}

$cliente = repo_cliente($cid);
if (!$cliente) {
    flash_set('err', 'Cadastro não encontrado.');
    header('Location: index.php');
    exit;
}

$empresaRaizId = repo_cliente_catalogo_dono_id($cid);
$usuariosPortal = repo_usuarios_por_cliente($cid);
$anexosCount = count(repo_cliente_anexos($cid));
$qtdItensCat = $empresaRaizId > 0 ? count(repo_cliente_itens_list($empresaRaizId, false)) : 0;
$chamadosAbr = (int) ($cliente['pendentes'] ?? 0);

$catPadraoId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? 0);
$unicoEmpresaModo = true;
$empresaOperadorModal = ($unicoEmpresaModo && $catPadraoId > 0) ? $catPadraoId : $empresaRaizId;
$clientePortalModal = $cid;

$meusDadosPainelCliente = $cliente;
$meusDadosPainelClienteId = $cid;
$meusDadosPainelEmpresaRaizId = $empresaRaizId;
$meusDadosPainelModo = 'portal';
$meusDadosPainelUsuarios = $usuariosPortal;
$meusDadosPainelAnexosCount = $anexosCount;
$meusDadosPainelQtdItensCat = $qtdItensCat;
$meusDadosPainelChamadosAbertos = $chamadosAbr;
$meusDadosPainelUsuarioLogadoId = (int) ($user['id'] ?? 0);
$meusDadosPainelEmpresaOperadorModal = $empresaOperadorModal;
$meusDadosPainelClientePortalModal = $clientePortalModal;
$meusDadosPainelUnicoEmpresaModo = $unicoEmpresaModo;
$meusDadosPainelCatPadraoId = $catPadraoId;

require_once __DIR__ . '/../includes/cliente_plano_limites.php';
$topbarPlanoBtn = $empresaRaizId > 0 && repo_cliente_plano_columns_exists();
$topbarPlanoWarn = $topbarPlanoBtn && cliente_plano_tem_alerta($empresaRaizId);

$topTitle    = 'Meus dados';
$topSubtitle = 'Cadastro da prefeitura e contas com acesso ao sistema';
$topSearch   = '';
$topAction   = ['label' => 'Minha conta', 'href' => 'perfil.php', 'icon' => '→'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <?php require __DIR__ . '/../includes/cliente_meus_dados_painel.php'; ?>
</section>

<?php if (!empty($usuariosPortal)): ?>
<script>
(function () {
  var rootAcessos = document.querySelector('[data-acessos-tabs]');
  if (!rootAcessos) return;
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
})();
</script>
<?php endif; ?>

</main>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

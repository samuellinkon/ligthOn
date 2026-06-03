<?php
require_once __DIR__ . '/../includes/auth.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';

require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/cliente_plano_limites.php';

$isSuperAdminConfig = !empty($me['is_super_admin']);
if (!$isSuperAdminConfig) {
    require_modulo_admin('configuracoes');
}
$tab = (string) ($_GET['tab'] ?? 'geral');
if (!in_array($tab, ['geral', 'modulos'], true)) {
    $tab = 'geral';
}
$configSecao = (string) ($_GET['secao'] ?? 'sistema');
if (!in_array($configSecao, ['sistema', 'clientes', 'email', 'teste', 'api'], true)) {
    $configSecao = 'sistema';
}
if ($configSecao === 'api' && !$isSuperAdminConfig) {
    header('Location: configuracoes.php?tab=geral&secao=sistema');
    exit;
}
if ($tab === 'modulos' && !$isSuperAdminConfig) {
    flash_set('err', 'Apenas o super administrador pode acessar os módulos da instância.');
    header('Location: configuracoes.php?tab=geral');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_form'] ?? '') === 'saas_modulos') {
    if (!$isSuperAdminConfig) {
        flash_set('err', 'Apenas o super administrador pode alterar módulos.');
        header('Location: configuracoes.php?tab=geral');
        exit;
    }
    if (!db_ok()) {
        flash_set('err', 'Banco indisponível.');
        header('Location: configuracoes.php?tab=modulos');
        exit;
    }
    foreach (['super_admin', 'gestor', 'cliente', 'operador'] as $grupo) {
        $lista = repo_saas_modulos_list($grupo);
        foreach ($lista as $row) {
            $key = (string) ($row['modulo_key'] ?? '');
            if ($key === '') {
                continue;
            }
            repo_saas_modulo_set_habilitado($grupo, $key, !empty($_POST['hab'][$grupo][$key]));
        }
    }
    flash_set('ok', 'Módulos da instância atualizados.');
    header('Location: configuracoes.php?tab=modulos');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!db_ok()) {
        flash_set('err', 'Banco de dados indisponível. Verifique a conexão antes de salvar.');
        header('Location: configuracoes.php?tab=geral&secao=' . urlencode($configSecao));
        exit;
    }

    $acao = $_POST['acao'] ?? 'salvar';
    $postSecao = (string) ($_POST['_secao'] ?? $configSecao);
    if (!in_array($postSecao, ['sistema', 'clientes', 'email', 'teste', 'api'], true)) {
        $postSecao = 'sistema';
    }

    if ($acao === 'api_public_gerar_chaves') {
        if (!$isSuperAdminConfig) {
            flash_set('err', 'Apenas o super administrador pode gerar chaves da API.');
            header('Location: configuracoes.php?tab=geral&secao=sistema');
            exit;
        }
        if (!db_ok()) {
            flash_set('err', 'Banco indisponível.');
            header('Location: configuracoes.php?tab=geral&secao=api');
            exit;
        }
        $public = bin2hex(random_bytes(16));
        $secret = bin2hex(random_bytes(32));
        $ok = repo_config_set('api_public_key', $public)
            && repo_config_set('api_secret_hash', password_hash($secret, PASSWORD_DEFAULT));
        if ($ok) {
            $_SESSION['_api_secret_plain_once'] = $secret;
            flash_set('ok', 'Novo par de chaves gerado. Copie a chave secreta abaixo — ela não será exibida de novo.');
        } else {
            flash_set('err', 'Não foi possível gravar as chaves.');
        }
        header('Location: configuracoes.php?tab=geral&secao=api');
        exit;
    }

    if ($acao === 'teste_email') {
        $para = trim((string) ($_POST['email_teste'] ?? ''));
        if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
            flash_set('err', 'Informe um e-mail válido para o teste.');
            header('Location: configuracoes.php?tab=geral&secao=teste');
            exit;
        }
        $quando = date('d/m/Y') . ' às ' . date('H:i:s');
        $nomeMarca = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'OnLight';
        $html = '<p style="font-family:system-ui,sans-serif;font-size:15px;">Este é um <strong>e-mail de teste</strong> enviado pelo '
            . htmlspecialchars($nomeMarca, ENT_QUOTES, 'UTF-8')
            . ' em '
            . htmlspecialchars($quando, ENT_QUOTES, 'UTF-8')
            . '.</p><p style="font-size:13px;color:#64748b;">Se recebeu esta mensagem, o envio (SMTP ou servidor) está funcionando.</p>';
        $res = mail_send($para, $nomeMarca . ' — teste de envio', $html);
        flash_set(
            $res['ok'] ? 'ok' : 'err',
            $res['ok'] ? 'E-mail de teste enviado para ' . htmlspecialchars($para) . '.' : ('Falha no envio: ' . htmlspecialchars($res['motivo'] ?: 'erro desconhecido'))
        );
        header('Location: configuracoes.php?tab=geral&secao=teste');
        exit;
    }

    $ok = true;
    $ok = repo_config_set('debug_mode', !empty($_POST['debug_mode']) ? '1' : '0') && $ok;
    $ok = repo_config_set('clientes_modo', 'unico') && $ok;

    if ($isSuperAdminConfig && $postSecao === 'api') {
        $ok = repo_config_set('api_public_enabled', !empty($_POST['api_public_enabled']) ? '1' : '0') && $ok;
    }

    if ($isSuperAdminConfig) {
        $catPadRaw = trim((string) ($_POST['catalogo_cliente_padrao_id'] ?? ''));
        if ($catPadRaw === '' || $catPadRaw === '0') {
            $ok = repo_config_set('catalogo_cliente_padrao_id', '') && $ok;
        } else {
            $cidCfg = (int) $catPadRaw;
            if ($cidCfg > 0 && repo_cliente($cidCfg)) {
                $ok = repo_config_set('catalogo_cliente_padrao_id', (string) $cidCfg) && $ok;
            } else {
                flash_set('err', 'Prefeitura padrão do catálogo: escolha um cadastro existente ou “Automático”.');
                header('Location: configuracoes.php?tab=geral&secao=clientes');
                exit;
            }
        }
    }

    if ($isSuperAdminConfig && $postSecao === 'clientes' && repo_cliente_plano_columns_exists()) {
        $planoClienteIdSave = (int) (repo_catalogo_cliente_id_padrao_admin() ?? repo_cliente_raiz_principal_id() ?? 0);
        if ($planoClienteIdSave > 0) {
            $ok = repo_cliente_plano_salvar($planoClienteIdSave, [
                'plano_codigo' => $_POST['plano_codigo'] ?? 'padrao',
                'plano_mensalidade' => $_POST['plano_mensalidade'] ?? '',
                'limite_pontos' => $_POST['limite_pontos'] ?? '',
                'limite_chamados_mes' => $_POST['limite_chamados_mes'] ?? '',
                'limite_itens_mes' => $_POST['limite_itens_mes'] ?? '',
                'limite_storage_mb' => $_POST['limite_storage_mb'] ?? '',
                'limite_usuarios' => $_POST['limite_usuarios'] ?? '',
            ]) && $ok;
        }
    }

    $mailFrom = trim((string) ($_POST['mail_from'] ?? ''));
    $mailName = trim((string) ($_POST['mail_from_name'] ?? ''));
    if ($mailFrom !== '' && !filter_var($mailFrom, FILTER_VALIDATE_EMAIL)) {
        flash_set('err', 'E-mail do remetente inválido.');
        header('Location: configuracoes.php?tab=geral&secao=email');
        exit;
    }
    $ok = repo_config_set('mail_from', $mailFrom) && $ok;
    $ok = repo_config_set('mail_from_name', $mailName) && $ok;

    $cfgPre         = repo_config_all();
    $smtpOn         = !empty($_POST['smtp_enabled']);
    $smtpHostPost   = trim((string) ($_POST['smtp_host'] ?? ''));
    $smtpUserPost   = trim((string) ($_POST['smtp_user'] ?? ''));
    $smtpPassPost   = (string) ($_POST['smtp_password'] ?? '');
    $smtpPassStored = trim((string) ($cfgPre['smtp_password'] ?? ''));
    $smtpPassOk     = ($smtpPassPost !== '') || ($smtpPassStored !== '');
    if ($smtpOn && ($smtpHostPost === '' || $smtpUserPost === '' || !$smtpPassOk)) {
        flash_set(
            'err',
            'Com «Usar SMTP autenticado» marcado: preencha servidor, utilizador e senha da conta de e-mail (na primeira vez a senha é obrigatória) e guarde. Sem SMTP completo, o PHP usa mail()/sendmail e na Hostinger aparece erro tipo “Sendmail exited…”.'
        );
        header('Location: configuracoes.php?tab=geral&secao=email');
        exit;
    }

    $ok = repo_config_set('smtp_enabled', $smtpOn ? '1' : '0') && $ok;
    $ok = repo_config_set('smtp_host', $smtpHostPost) && $ok;

    $port = (int) ($_POST['smtp_port'] ?? 587);
    if ($port < 1 || $port > 65535) {
        $port = 587;
    }
    $ok = repo_config_set('smtp_port', (string) $port) && $ok;

    $enc = strtolower(trim((string) ($_POST['smtp_encryption'] ?? 'tls')));
    if (!in_array($enc, ['tls', 'ssl', 'none'], true)) {
        $enc = 'tls';
    }
    $ok = repo_config_set('smtp_encryption', $enc) && $ok;
    $ok = repo_config_set('smtp_user', $smtpUserPost) && $ok;

    if ($smtpPassPost !== '') {
        $ok = repo_config_set('smtp_password', $smtpPassPost) && $ok;
    }

    flash_set($ok ? 'ok' : 'err', $ok ? 'Configurações salvas.' : 'Não foi possível salvar algumas opções (verifique se a tabela app_config existe — rode a migração 008).');
    header('Location: configuracoes.php?tab=geral&secao=' . urlencode($postSecao));
    exit;
}

$cfg = repo_config_all();

$apiSecretPlainOnce = null;
if (!empty($_SESSION['_api_secret_plain_once'])) {
    $apiSecretPlainOnce = (string) $_SESSION['_api_secret_plain_once'];
    unset($_SESSION['_api_secret_plain_once']);
}

$scriptNameCfg = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$apiV1Path = '/api/v1';
if ($scriptNameCfg !== '' && strpos($scriptNameCfg, '/admin/') !== false) {
    $apiV1Path = dirname(dirname($scriptNameCfg)) . '/api/v1';
}
$apiV1Path = str_replace('\\', '/', $apiV1Path);
$hostCfg = (string) ($_SERVER['HTTP_HOST'] ?? '');
$schemeCfg = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$apiV1Abs = ($hostCfg !== '' ? $schemeCfg . '://' . $hostCfg : '') . $apiV1Path;

$empresasMatrizCatalogoCfg = [];
$planoClienteId = 0;
$planoLimites = [];
$planoResumo = [];
if ($tab === 'geral' && $isSuperAdminConfig && db_ok()) {
    $empresasMatrizCatalogoCfg = repo_clientes_empresas();
    if (repo_cliente_plano_columns_exists()) {
        $planoClienteId = (int) (repo_catalogo_cliente_id_padrao_admin() ?? repo_cliente_raiz_principal_id() ?? 0);
        if ($planoClienteId > 0) {
            $planoLimites = cliente_plano_limites($planoClienteId);
            $planoResumo = cliente_plano_resumo_metricas($planoClienteId);
        }
    }
}

$modsSuper = $modsGestor = $modsCliente = $modsOperador = [];
if ($tab === 'modulos' && $isSuperAdminConfig) {
    if (db_ok()) {
        repo_saas_modulos_prune_invalidos();
    }
    $modsSuper    = db_ok() ? repo_saas_modulos_list('super_admin') : [];
    $modsGestor   = db_ok() ? repo_saas_modulos_list('gestor') : [];
    $modsCliente  = db_ok() ? repo_saas_modulos_list('cliente') : [];
    $modsOperador = db_ok() ? repo_saas_modulos_list('operador') : [];
}

$pageTitle   = 'Configurações';
$basePath    = '../';
$activePage  = 'configuracoes';
$topTitle    = $tab === 'modulos' ? 'Configurações e módulos' : 'Configurações do sistema';
$topSubtitle = $tab === 'modulos'
    ? 'Permissões por role de acesso: admin, gestor, cliente e operador.'
    : 'Modo debug, remetente e SMTP (Hostinger e outros provedores).';
$topSearch   = 'Buscar...';
$topAction   = ['label' => 'Voltar ao painel', 'href' => 'index.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<style>
  .config-section-panel {
    display: none;
  }
  .config-section-panel.active {
    display: block;
  }
  .admin-tabs button.admin-tab {
    border: 0;
    cursor: pointer;
    font: inherit;
  }
  .plano-uso-grid { display: grid; gap: 14px; }
  .plano-uso-row-head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; font-size: 14px; }
  .plano-uso-valores { color: var(--muted); font-size: 13px; }
  .plano-uso-bar { height: 8px; border-radius: 999px; background: var(--border-soft, #e2e8f0); overflow: hidden; margin-top: 8px; }
  .plano-uso-bar-fill { display: block; height: 100%; border-radius: inherit; background: #22c55e; transition: width .2s ease; }
  .plano-uso-warn .plano-uso-bar-fill { background: #f59e0b; }
  .plano-uso-danger .plano-uso-bar-fill { background: #ef4444; }
  .plano-uso-pct { font-weight: 600; }
  .plano-limite-row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
  .plano-limite-row .input { flex: 1; min-width: 140px; }
</style>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">

  <nav class="admin-tabs card mb-24" aria-label="Seções de configuração">
    <a href="configuracoes.php?tab=geral" class="admin-tab<?= $tab === 'geral' ? ' active' : '' ?>">Geral</a>
    <?php if ($isSuperAdminConfig): ?>
      <a href="configuracoes.php?tab=modulos" class="admin-tab<?= $tab === 'modulos' ? ' active' : '' ?>">Módulos SaaS</a>
    <?php endif; ?>
  </nav>

  <?php if ($tab === 'modulos'): ?>
    <?php include __DIR__ . '/../includes/partials/config_modulos_tab.php'; ?>
  <?php else: ?>

  <?php if (!db_ok()): ?>
  <div class="toast toast-err" style="position:static;margin-bottom:18px;">
    <div class="toast-icon">!</div>
    <div>Sem conexão com o banco. As opções abaixo não podem ser salvas até o MySQL estar disponível.</div>
  </div>
  <?php endif; ?>

  <nav class="admin-tabs card mb-24" aria-label="Opções de configuração">
    <button type="button" class="admin-tab<?= $configSecao === 'sistema' ? ' active' : '' ?>" data-config-tab="sistema">Sistema</button>
    <button type="button" class="admin-tab<?= $configSecao === 'clientes' ? ' active' : '' ?>" data-config-tab="clientes">Prefeitura e catálogo</button>
    <button type="button" class="admin-tab<?= $configSecao === 'email' ? ' active' : '' ?>" data-config-tab="email">E-mail e SMTP</button>
    <button type="button" class="admin-tab<?= $configSecao === 'teste' ? ' active' : '' ?>" data-config-tab="teste">Teste de envio</button>
    <?php if ($isSuperAdminConfig): ?>
    <button type="button" class="admin-tab<?= $configSecao === 'api' ? ' active' : '' ?>" data-config-tab="api">API JSON</button>
    <?php endif; ?>
  </nav>

  <form class="card config-section-panel<?= $configSecao !== 'teste' ? ' active' : '' ?>" method="post" action="configuracoes.php?tab=geral&secao=<?= htmlspecialchars($configSecao) ?>" autocomplete="off" data-config-save-form>
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="_secao" value="<?= htmlspecialchars($configSecao) ?>" data-config-current-section>

    <div class="config-section-panel<?= $configSecao === 'sistema' ? ' active' : '' ?>" data-config-panel="sistema">
    <div class="panel-head">
      <h4>Modo debug</h4>
      <span class="panel-sub">Desative em produção na Hostinger — expõe ferramenta de preenchimento automático em formulários</span>
    </div>
    <div class="panel-body">
      <p class="muted" style="font-size:14px;line-height:1.55;margin:0 0 14px;">
        Quando ativo, aparece o botão <strong>Preencher teste</strong> nas telas com formulário, para agilizar validações.
        Sem banco de dados, o fallback continua sendo a constante <code>FORM_FILL_DEV_TOOL</code> em <code>includes/config.php</code> / <code>config.local.php</code>.
      </p>
      <label class="checkbox" style="margin:0;">
        <input type="checkbox" name="debug_mode" value="1" <?= (($cfg['debug_mode'] ?? '') === '1') ? 'checked' : '' ?>>
        <span>Ativar modo debug (botão de preenchimento de teste nos formulários)</span>
      </label>
    </div>
    </div>

    <div class="config-section-panel<?= $configSecao === 'clientes' ? ' active' : '' ?>" data-config-panel="clientes">
    <div class="panel-head">
      <h4>Organização do sistema</h4>
      <span class="panel-sub">Hierarquia fixa nesta instância</span>
    </div>
    <div class="panel-body">
      <p class="muted" style="font-size:14px;line-height:1.6;margin:0;">
        <strong>Administrador</strong> — acesso total à configuração e supervisão.<br>
        <strong>Empresa de gestão e iluminação</strong> — equipe que opera chamados, medição e postes (perfil gestor no painel interno).<br>
        <strong>Prefeitura</strong> — órgão atendido; usuários do portal acompanham protocolos e aberturas (perfil de acesso ao portal).<br>
        <strong>Operadores</strong> — execução em campo vinculada à empresa de gestão.<br>
        Não há opção de várias empresas concorrentes na mesma instalação: o cadastro da prefeitura e da operação segue esse modelo.
      </p>
    </div>

    <?php if ($isSuperAdminConfig): ?>
    <div class="panel-head" style="border-top:1px solid var(--border-soft);">
      <h4>Catálogo · prefeitura (cadastro principal)</h4>
      <span class="panel-sub">Define qual cadastro raiz abre no menu <strong>Catálogo</strong> e nas URLs sem <code>cliente_id</code>. Vazio = primeira matriz (menor ID).</span>
    </div>
    <div class="panel-body form form-grid">
      <div class="form-group full">
        <label for="catalogo_cliente_padrao_id">Prefeitura dona do catálogo (padrão)</label>
        <?php $catPadAtual = trim((string) ($cfg['catalogo_cliente_padrao_id'] ?? '')); ?>
        <select id="catalogo_cliente_padrao_id" name="catalogo_cliente_padrao_id" class="select">
          <option value="" <?= $catPadAtual === '' ? 'selected' : '' ?>>Automático (primeira matriz por ID)</option>
          <?php foreach ($empresasMatrizCatalogoCfg as $em): ?>
            <?php $eid = (int) ($em['id'] ?? 0); ?>
            <option value="<?= $eid ?>" <?= $catPadAtual !== '' && (int) $catPadAtual === $eid ? 'selected' : '' ?>>
              <?= htmlspecialchars((string) ($em['empresa'] ?? '')) ?> (#<?= $eid ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <small class="muted" style="display:block;margin-top:6px;">
          Apenas empresas <strong>raiz</strong> (sem matriz pai). Unidades filhas seguem o catálogo da matriz vinculada.
        </small>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($isSuperAdminConfig && $planoClienteId > 0 && repo_cliente_plano_columns_exists()): ?>
      <?php include __DIR__ . '/../includes/partials/config_cliente_plano_panel.php'; ?>
    <?php endif; ?>
    </div>

    <div class="config-section-panel<?= $configSecao === 'email' ? ' active' : '' ?>" data-config-panel="email">
    <div class="panel-head">
      <h4>E-mail do sistema</h4>
      <span class="panel-sub">Remetente das mensagens (boas-vindas, cobranças, OS). Na Hostinger use um endereço do seu domínio já criado no hPanel</span>
    </div>
    <div class="panel-body form form-grid">
      <div class="form-group">
        <label for="mail_from_name">Nome do remetente</label>
        <input type="text" id="mail_from_name" name="mail_from_name" class="input"
               value="<?= htmlspecialchars((string) ($cfg['mail_from_name'] ?? '')) ?>"
               placeholder="<?= htmlspecialchars(defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : (defined('APP_BRAND_NAME') ? APP_BRAND_NAME : 'OnLight')) ?>">
        <small class="muted">Aparece como “De:” no cliente de e-mail.</small>
      </div>
      <div class="form-group">
        <label for="mail_from">E-mail do remetente</label>
        <input type="email" id="mail_from" name="mail_from" class="input"
               value="<?= htmlspecialchars((string) ($cfg['mail_from'] ?? '')) ?>"
               placeholder="<?= htmlspecialchars(defined('MAIL_FROM') ? MAIL_FROM : 'nao-responda@seudominio.com') ?>">
        <small class="muted">Se vazio, usa o padrão definido em <code>includes/config.php</code> (<code>MAIL_FROM</code>).</small>
      </div>
    </div>

    <div class="panel-head" style="border-top:1px solid var(--border-soft);">
      <h4>SMTP (recomendado na Hostinger)</h4>
      <span class="panel-sub">Com SMTP desligado, o PHP usa <code>mail()</code> do servidor (pode funcionar, mas o hPanel costuma exigir SMTP autenticado)</span>
    </div>
    <div class="panel-body">
      <div class="panel-note" style="margin-bottom:20px;">
        <div class="panel-head"><h4>Dados típicos Hostinger</h4></div>
        <div class="panel-body" style="font-size:14px;line-height:1.6;">
          <ul style="margin:0;padding-left:20px;color:var(--muted);">
            <li>Servidor: <code>smtp.hostinger.com</code></li>
            <li>Porta <strong>465</strong> com criptografia <strong>SSL</strong>, ou porta <strong>587</strong> com <strong>TLS</strong></li>
            <li>Usuário e senha: o mesmo e-mail e senha da conta de e-mail criada no hPanel → E-mails</li>
            <li>O endereço “E-mail do remetente” acima deve ser o mesmo domínio (ou alias autorizado)</li>
          </ul>
        </div>
      </div>

      <div class="form form-grid">
        <div class="form-group full">
          <label class="checkbox">
            <input type="checkbox" name="smtp_enabled" value="1" <?= (($cfg['smtp_enabled'] ?? '') === '1') ? 'checked' : '' ?>>
            <span>Usar SMTP autenticado em vez de <code>mail()</code></span>
          </label>
          <small class="muted" style="display:block;margin-top:8px;line-height:1.5;">
            Tem de ficar <strong>marcado</strong> ao gravar se quiser validar que nada fica a meio; o envio em si usa <strong>SMTP automaticamente</strong> quando servidor, utilizador e senha estão todos guardados na base (mesmo que um dia a caixa fique desmarcada por engano). Na <strong>primeira</strong> vez escreva a senha da caixa no hPanel e clique em Salvar. Sem senha gravada, o PHP usa <code>mail()</code> e na Hostinger costuma falhar (ex.: «Sendmail exited…»).
          </small>
        </div>
        <div class="form-group">
          <label for="smtp_host">Servidor SMTP</label>
          <input type="text" id="smtp_host" name="smtp_host" class="input"
                 value="<?= htmlspecialchars((string) ($cfg['smtp_host'] ?? '')) ?>"
                 placeholder="smtp.hostinger.com">
        </div>
        <div class="form-group">
          <label for="smtp_port">Porta</label>
          <input type="number" id="smtp_port" name="smtp_port" class="input" min="1" max="65535"
                 placeholder="587 ou 465"
                 value="<?= (int) ($cfg['smtp_port'] ?? 587) ?>">
        </div>
        <div class="form-group">
          <label for="smtp_encryption">Criptografia</label>
          <?php
          $curEnc = strtolower((string) ($cfg['smtp_encryption'] ?? 'tls'));
          ?>
          <select id="smtp_encryption" name="smtp_encryption" class="select">
            <option value="tls" <?= $curEnc === 'tls' ? 'selected' : '' ?>>TLS (porta 587)</option>
            <option value="ssl" <?= $curEnc === 'ssl' ? 'selected' : '' ?>>SSL (porta 465)</option>
            <option value="none" <?= $curEnc === 'none' ? 'selected' : '' ?>>Nenhuma (rede interna)</option>
          </select>
        </div>
        <div class="form-group">
          <label for="smtp_user">Usuário SMTP</label>
          <input type="text" id="smtp_user" name="smtp_user" class="input" autocomplete="username"
                 value="<?= htmlspecialchars((string) ($cfg['smtp_user'] ?? '')) ?>"
                 placeholder="voce@seudominio.com">
        </div>
        <div class="form-group">
          <label for="smtp_password">Senha SMTP</label>
          <input type="password" id="smtp_password" name="smtp_password" class="input" autocomplete="new-password"
                 placeholder="<?= !empty($cfg['smtp_password']) ? '•••••••• (deixe em branco para manter)' : 'Senha da conta de e-mail' ?>">
          <small class="muted">Nunca reexibimos a senha. Na <strong>primeira</strong> configuração SMTP, tem de a escrever aqui e guardar. Depois pode deixar em branco para manter a já gravada.</small>
        </div>
      </div>
    </div>
    </div>

    <?php if ($isSuperAdminConfig): ?>
    <div class="config-section-panel<?= $configSecao === 'api' ? ' active' : '' ?>" data-config-panel="api">
    <div class="panel-head">
      <h4>API JSON (chamados e medição)</h4>
      <span class="panel-sub">Endpoints protegidos por par de chaves; a secreta fica só como hash no banco</span>
    </div>
    <div class="panel-body">
      <label class="checkbox" style="margin:0 0 16px;">
        <input type="checkbox" name="api_public_enabled" value="1" <?= (($cfg['api_public_enabled'] ?? '') === '1') ? 'checked' : '' ?>>
        <span>Ativar API JSON</span>
      </label>
      <p class="muted" style="font-size:14px;line-height:1.55;margin:0 0 14px;">
        Envie nos pedidos HTTP os cabeçalhos <code>X-Api-Key</code> (chave pública) e <code>X-Api-Secret</code> (chave privada).
        Os dados seguem a <strong>prefeitura padrão do catálogo</strong> (aba Prefeitura e catálogo).
      </p>
      <div class="form form-grid">
        <div class="form-group full">
          <label for="api_public_key_ro">Chave pública</label>
          <input type="text" id="api_public_key_ro" class="input" readonly
                 value="<?= htmlspecialchars((string) ($cfg['api_public_key'] ?? '')) ?>"
                 placeholder="Gere um par de chaves abaixo">
        </div>
      </div>
      <?php if ($apiSecretPlainOnce !== null): ?>
      <div class="form-group full" style="margin-top:16px;">
        <label for="api_secret_once_ro">Chave secreta <span class="muted" style="font-weight:normal;font-size:13px;">— copie agora; não será exibida de novo</span></label>
        <div class="flex-gap" style="display:flex;gap:10px;align-items:stretch;flex-wrap:wrap;">
          <input type="text" id="api_secret_once_ro" class="input" readonly autocomplete="off" spellcheck="false"
                 style="font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px;letter-spacing:0.02em;flex:1;min-width:min(100%,280px);"
                 value="<?= htmlspecialchars($apiSecretPlainOnce, ENT_QUOTES, 'UTF-8') ?>">
          <button type="button" class="btn btn-secondary" id="api_secret_copy_btn" style="flex-shrink:0;">Copiar</button>
        </div>
      </div>
      <?php elseif (trim((string) ($cfg['api_secret_hash'] ?? '')) !== ''): ?>
      <p class="muted" style="font-size:13px;margin:12px 0 0;">A chave secreta está definida. Para ver uma nova secreta, gere outro par (a anterior deixa de funcionar).</p>
      <?php else: ?>
      <p class="muted" style="font-size:13px;margin:12px 0 0;">Nenhuma chave gerada ainda — use o botão abaixo.</p>
      <?php endif; ?>

      <div class="panel-head" style="border-top:1px solid var(--border-soft);margin-top:20px;">
        <h4>URLs dos endpoints</h4>
      </div>
      <div class="panel-body" style="font-size:13px;line-height:1.65;color:var(--muted);">
        <p style="margin:0 0 8px;"><strong>Chamados:</strong> <code><?= htmlspecialchars($apiV1Abs . '/chamados.php') ?></code></p>
        <p style="margin:0 0 8px;"><strong>Medição (resumo mensal):</strong> <code><?= htmlspecialchars($apiV1Abs . '/medicao.php') ?></code></p>
        <p style="margin:0;">Parâmetros opcionais: chamados — <code>page</code>, <code>per_page</code>, <code>filtro</code>, <code>q</code>, <code>data_de</code>, <code>data_ate</code>; medição — <code>limite</code>.</p>
      </div>
    </div>
    </div>
    <?php endif; ?>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary" <?= !db_ok() ? 'disabled' : '' ?>>Salvar configurações</button>
    </div>
  </form>

  <?php if ($isSuperAdminConfig): ?>
  <div class="card mt-24 config-section-panel<?= $configSecao === 'api' ? ' active' : '' ?>" data-config-panel="api">
    <div class="panel-head">
      <h4>Gerar par de chaves</h4>
      <span class="panel-sub">Substitui chave pública e invalida a secreta anterior</span>
    </div>
    <div class="panel-body">
      <form method="post" action="configuracoes.php?tab=geral&secao=api" class="form flex-gap" style="align-items:flex-end;flex-wrap:wrap;gap:14px;">
        <input type="hidden" name="acao" value="api_public_gerar_chaves">
        <p class="muted" style="margin:0;flex:1;min-width:220px;font-size:14px;">
          Gera nova chave pública e secreta. Quem usa integrações deve atualizar as duas no cliente.
        </p>
        <button type="submit" class="btn btn-secondary" <?= !db_ok() ? 'disabled' : '' ?>>Gerar novo par de chaves</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="card mt-24 config-section-panel<?= $configSecao === 'teste' ? ' active' : '' ?>" data-config-panel="teste">
    <div class="panel-head">
      <h4>Testar envio</h4>
      <span class="panel-sub">Usa as configurações já salvas (salve antes se alterou algo acima)</span>
    </div>
    <div class="panel-body">
      <form method="post" action="configuracoes.php?tab=geral&secao=teste" class="form flex-gap" style="align-items:flex-end;flex-wrap:wrap;gap:14px;">
        <input type="hidden" name="acao" value="teste_email">
        <input type="hidden" name="_secao" value="teste">
        <div class="form-group" style="flex:1;min-width:220px;margin:0;">
          <label for="email_teste">Enviar teste para</label>
          <input type="email" id="email_teste" name="email_teste" class="input" required
                 value="<?= htmlspecialchars((string) ($me['email'] ?? '')) ?>"
                 placeholder="seu@email.com">
        </div>
        <button type="submit" class="btn btn-secondary" <?= !db_ok() ? 'disabled' : '' ?>>Enviar e-mail de teste</button>
      </form>
    </div>
  </div>

  <?php endif; ?>

</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const tabs = Array.from(document.querySelectorAll('[data-config-tab]'));
  const panels = Array.from(document.querySelectorAll('[data-config-panel]'));
  const saveForm = document.querySelector('[data-config-save-form]');
  const currentInput = document.querySelector('[data-config-current-section]');

  function activateConfigSection(section) {
    const isTeste = section === 'teste';
    tabs.forEach(function (tabBtn) {
      tabBtn.classList.toggle('active', tabBtn.dataset.configTab === section);
    });
    panels.forEach(function (panel) {
      panel.classList.toggle('active', panel.dataset.configPanel === section);
    });
    if (saveForm) {
      saveForm.classList.toggle('active', !isTeste);
      saveForm.action = 'configuracoes.php?tab=geral&secao=' + encodeURIComponent(section);
    }
    if (currentInput) {
      currentInput.value = section;
    }
    const url = new URL(window.location.href);
    url.searchParams.set('tab', 'geral');
    url.searchParams.set('secao', section);
    window.history.replaceState({}, '', url.toString());
  }

  tabs.forEach(function (tabBtn) {
    tabBtn.addEventListener('click', function () {
      activateConfigSection(tabBtn.dataset.configTab || 'sistema');
    });
  });

  var copyBtn = document.getElementById('api_secret_copy_btn');
  var copyInp = document.getElementById('api_secret_once_ro');
  if (copyBtn && copyInp) {
    copyBtn.addEventListener('click', function () {
      copyInp.focus();
      copyInp.select();
      copyInp.setSelectionRange(0, copyInp.value.length);
      var txt = copyInp.value;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).catch(function () {
          try { document.execCommand('copy'); } catch (e) {}
        });
      } else {
        try { document.execCommand('copy'); } catch (e) {}
      }
      var prev = copyBtn.textContent;
      copyBtn.textContent = 'Copiado!';
      setTimeout(function () { copyBtn.textContent = prev; }, 2000);
    });
  }
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>

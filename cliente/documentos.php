<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('documentos');

$pageTitle  = 'Meus documentos';
$basePath   = '../';
$activePage = 'documentos';

$clienteId = (int) ($user['cliente_id'] ?? 0);
$anexos    = $clienteId && db_ok() ? repo_cliente_anexos($clienteId) : [];

$topTitle    = 'Meus documentos';
$topSubtitle = 'Contratos e arquivos enviados pela equipe para você.';
$topSearch   = 'Buscar documento...';
$topAction   = ['label' => 'Suporte', 'href' => 'suporte.php', 'icon' => '?'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="card">
    <div class="panel-head">
      <h4>Arquivos disponíveis</h4>
      <span class="panel-sub"><?= count($anexos) ?> documento(s)</span>
    </div>

    <?php if (empty($anexos)): ?>
      <div class="empty-state">
        <div class="empty-icon">📁</div>
        <p>Você ainda não tem documentos disponíveis.</p>
        <small>Assim que um contrato ou documento for anexado ao seu cadastro, ele aparecerá aqui.</small>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Arquivo</th>
              <th>Tipo</th>
              <th>Tamanho</th>
              <th>Enviado em</th>
              <th class="text-right">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($anexos as $a): ?>
              <tr>
                <td>
                  <div class="cell-client">
                    <div class="avatar avatar-sm" aria-hidden="true"><?= upload_icone_por_ext($a['nome_original']) ?></div>
                    <div>
                      <strong><?= htmlspecialchars($a['nome_original']) ?></strong>
                      <?php if (!empty($a['descricao'])): ?>
                        <small><?= htmlspecialchars($a['descricao']) ?></small>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td><span class="badge"><?= htmlspecialchars($a['tipo']) ?></span></td>
                <td class="td-mute"><?= upload_formatar_tamanho($a['tamanho']) ?></td>
                <td class="td-mute"><?= htmlspecialchars($a['enviado_em']) ?></td>
                <td class="td-actions">
                  <a class="action primary" href="download.php?id=<?= $a['id'] ?>">Baixar</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>

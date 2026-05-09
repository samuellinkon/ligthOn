<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
$user = require_auth('cliente');
require_once __DIR__ . '/../includes/modules.php';
require_modulo_cliente('suporte');
$pageTitle  = 'Suporte';
$basePath   = '../';
$activePage = 'suporte';

$cid = (int) ($user['cliente_id'] ?? 0);
if (db_ok() && $cid > 0) {
    $minhas = repo_suporte_por_cliente($cid);
} else {
    $minhas = array_values(array_filter($MOCK_SUPORTE, fn ($s) => (int) ($s['cliente_id'] ?? 0) === $cid));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!db_ok()) {
        flash_set('err', 'Banco indisponível.');
        header('Location: suporte.php'); exit;
    }
    try {
        $id = repo_create_suporte([
            'cliente_id' => (int) ($user['cliente_id'] ?? 0),
            'pergunta'   => trim($_POST['assunto'] ?? ''),
            'detalhe'    => trim($_POST['detalhe'] ?? ''),
            'status'     => 'Aberta',
        ]);
        flash_set('ok', 'Dúvida #' . $id . ' enviada. Responderemos em breve.');
    } catch (Throwable $e) {
        flash_set('err', 'Falha: ' . $e->getMessage());
    }
    header('Location: suporte.php'); exit;
}

$topTitle    = 'Central de Suporte';
$topSubtitle = 'Tire uma dúvida e acompanhe respostas anteriores.';
$topSearch   = 'Buscar nas minhas dúvidas...';

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-cliente.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">

  <div class="grid-main">
    <div class="card">
      <div class="panel-head">
        <h4>Minhas dúvidas</h4>
        <span class="panel-sub">Histórico completo</span>
      </div>

      <?php if (empty($minhas)): ?>
        <div class="support-item" style="opacity:.7;">
          <p>Você ainda não enviou dúvidas. Use o formulário ao lado.</p>
        </div>
      <?php else: foreach ($minhas as $s): ?>
        <div class="support-item">
          <div class="support-meta" style="margin-bottom:4px;">
            <strong style="color:var(--text); font-size:14px;"><?= htmlspecialchars($s['pergunta']) ?></strong>
            <span class="badge <?= status_class($s['status']) ?>"><?= $s['status'] ?></span>
          </div>
          <p><?= htmlspecialchars($s['detalhe']) ?></p>

          <?php if (!empty($s['resposta'])): ?>
            <div class="msg" style="margin-top:10px;">
              <div class="avatar avatar-sm">SL</div>
              <div class="bubble">
                <div class="bubble-head"><strong>Suporte</strong><span><?= date('d/m/Y', strtotime($s['data'])) ?></span></div>
                <p><?= htmlspecialchars($s['resposta']) ?></p>
              </div>
            </div>
          <?php endif; ?>

          <div class="support-meta" style="margin-top:8px;">
            <span>Enviada em <?= date('d/m/Y H:i', strtotime($s['data'])) ?></span>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <div class="card" style="align-self:start;">
      <div class="panel-head">
        <h4>Abrir nova dúvida</h4>
        <span class="panel-sub">Responderemos assim que possível</span>
      </div>
      <form class="form flex-col" style="gap:16px;" action="suporte.php" method="post">
        <div class="form-group">
          <label for="assunto">Assunto</label>
          <input type="text" id="assunto" name="assunto" class="input" placeholder="Ex: Como emitir 2ª via do boleto?" required>
        </div>
        <div class="form-group">
          <label for="detalhe">Detalhe</label>
          <textarea id="detalhe" name="detalhe" class="textarea" placeholder="Explique com detalhes para podermos responder melhor..."></textarea>
        </div>
        <div class="flex" style="justify-content:flex-end;">
          <button type="submit" class="btn btn-primary">Enviar dúvida</button>
        </div>
      </form>
    </div>
  </div>

</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>

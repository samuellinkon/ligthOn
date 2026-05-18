<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('suporte');

$escopoSup = gestao_scope_cliente_id($me);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: suporte.php');
    exit;
}

function admin_suporte_row(int $id, ?int $clienteIdEscopo = null): ?array
{
    if (db_ok()) {
        foreach (repo_suporte($clienteIdEscopo) as $x) {
            if ((int) ($x['id'] ?? 0) === $id) {
                return $x;
            }
        }

        return null;
    }
    global $MOCK_SUPORTE;

    return find_by_id($MOCK_SUPORTE ?? [], $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!db_ok()) {
        flash_set('err', 'Banco indisponível.');
        header('Location: suporte_detalhe.php?id=' . $id);
        exit;
    }
    $resposta = trim((string) ($_POST['resposta'] ?? ''));
    if ($resposta !== '') {
        try {
            repo_responder_suporte($id, $resposta);
            flash_set('ok', 'Dúvida #' . $id . ' respondida.');
        } catch (Throwable $e) {
            flash_set('err', 'Falha: ' . $e->getMessage());
        }
    } else {
        flash_set('err', 'Digite uma resposta.');
    }
    header('Location: suporte_detalhe.php?id=' . $id);
    exit;
}

$s = admin_suporte_row($id, $escopoSup);
if (!$s) {
    flash_set('err', 'Dúvida não encontrada.');
    header('Location: suporte.php');
    exit;
}
gestor_assert_escopo_cliente((int) ($s['cliente_id'] ?? 0), 'suporte.php');

$pageTitle  = 'Suporte #' . $id;
$basePath   = '../';
$activePage = 'suporte';

$topTitle    = 'Dúvida #' . $id;
$topSubtitle = htmlspecialchars((string) ($s['cliente'] ?? '')) . ' · ' . htmlspecialchars((string) ($s['status'] ?? ''));
$topSearch   = 'Buscar...';
$topAction   = ['label' => 'Voltar', 'href' => 'suporte.php', 'icon' => '←'];

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content">
  <div class="card">
    <div class="panel-head">
      <div>
        <h4><?= htmlspecialchars((string) ($s['pergunta'] ?? '')) ?></h4>
        <span class="panel-sub"><?= htmlspecialchars((string) ($s['cliente'] ?? '')) ?> · <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($s['data'] ?? 'now')))) ?></span>
      </div>
      <span class="badge <?= status_class((string) ($s['status'] ?? '')) ?>"><?= htmlspecialchars((string) ($s['status'] ?? '')) ?></span>
    </div>
    <div class="panel-body">
      <p class="muted" style="line-height:1.65;margin:0;"><?= nl2br(htmlspecialchars((string) ($s['detalhe'] ?? ''))) ?></p>

      <?php if (!empty($s['resposta'])): ?>
      <div class="panel-head" style="margin-top:20px;border-top:1px solid var(--border-soft);">
        <h4>Resposta enviada</h4>
      </div>
      <div class="panel-note" style="margin-top:0;">
        <p style="margin:0;line-height:1.65;"><?= nl2br(htmlspecialchars((string) $s['resposta'])) ?></p>
      </div>
      <?php endif; ?>
    </div>

    <?php if (($s['status'] ?? '') !== 'Respondida'): ?>
    <form class="panel-body" method="post" action="suporte_detalhe.php?id=<?= $id ?>" style="border-top:1px solid var(--border-soft);">
      <div class="form-group">
        <label for="resposta">Sua resposta ao cliente</label>
        <textarea id="resposta" name="resposta" class="textarea" rows="6" required placeholder="Resposta que aparecerá no portal da prefeitura..."></textarea>
      </div>
      <div class="form-actions" style="padding:0;border:0;background:transparent;">
        <button type="submit" class="btn btn-primary" <?= !db_ok() ? 'disabled' : '' ?>>Enviar resposta</button>
        <?php if (!db_ok()): ?>
        <span class="muted" style="font-size:13px;">Ative o MySQL para gravar respostas.</span>
        <?php endif; ?>
      </div>
    </form>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>

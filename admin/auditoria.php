<?php
/**
 * Logs e auditoria — eventos do sistema (chamados, autenticação, anexos, etc.).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/audit_log.php';

$me = require_auth_gestao();
require_once __DIR__ . '/../includes/modules.php';
require_modulo_admin('auditoria');

$exportCsv = isset($_GET['export']) && $_GET['export'] === 'csv';

$filtros = [
    'de'  => trim((string) ($_GET['de'] ?? '')),
    'ate' => trim((string) ($_GET['ate'] ?? '')),
];

if ($exportCsv) {
    $rows = repo_audit_logs_export_rows($me, $filtros);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="auditoria_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    if ($out !== false) {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['id', 'criado_em', 'ator_user_id', 'ator_nome', 'ator_perfil', 'acao', 'entidade_tipo', 'entidade_id', 'cliente_id', 'ip', 'user_agent', 'payload'], ';');
        foreach ($rows as $r) {
            $payload = $r['payload'] ?? '';
            if (is_string($payload)) {
                $pl = $payload;
            } else {
                $pl = json_encode($payload, JSON_UNESCAPED_UNICODE);
            }
            fputcsv($out, [
                (int) ($r['id'] ?? 0),
                (string) ($r['criado_em'] ?? ''),
                $r['ator_user_id'] !== null && $r['ator_user_id'] !== '' ? (string) $r['ator_user_id'] : '',
                (string) ($r['ator_nome'] ?? ''),
                (string) ($r['ator_perfil'] ?? ''),
                (string) ($r['acao'] ?? ''),
                (string) ($r['entidade_tipo'] ?? ''),
                $r['entidade_id'] !== null && $r['entidade_id'] !== '' ? (string) $r['entidade_id'] : '',
                $r['cliente_id'] !== null && $r['cliente_id'] !== '' ? (string) $r['cliente_id'] : '',
                (string) ($r['ip'] ?? ''),
                (string) ($r['user_agent'] ?? ''),
                $pl !== false ? (string) $pl : '',
            ], ';');
        }
        fclose($out);
    }
    exit;
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$res     = db_ok() ? repo_audit_logs_list($me, $page, $perPage, $filtros) : ['rows' => [], 'total' => 0];
$total   = (int) $res['total'];
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $res = db_ok() ? repo_audit_logs_list($me, $page, $perPage, $filtros) : ['rows' => [], 'total' => 0];
}
$rows    = $res['rows'];
$offset  = ($page - 1) * $perPage;

function adm_auditoria_url(int $p, array $f): string
{
    $qs = array_filter([
        'de'   => $f['de'] ?? '',
        'ate'  => $f['ate'] ?? '',
        'page' => $p > 1 ? $p : 0,
    ], static fn ($v) => $v !== null && $v !== '' && $v !== 0);
    if (isset($qs['page']) && (int) $qs['page'] < 2) {
        unset($qs['page']);
    }

    return 'auditoria.php' . ($qs ? '?' . http_build_query($qs) : '');
}

$fromN = $total === 0 ? 0 : $offset + 1;
$toN   = $total === 0 ? 0 : min($offset + count($rows), $total);

$pageTitle  = 'Auditoria';
$basePath   = '../';
$activePage = 'auditoria';

$topTitle    = 'Auditoria';
$topSubtitle = 'Histórico de ações (chamados, login, anexos, etc.) — filtro por intervalo de datas e exportação CSV.';
$topSearch   = '';
$topAction   = null;

include __DIR__ . '/../includes/head.php';
?>
<div class="app">
<?php include __DIR__ . '/../includes/sidebar-admin.php'; ?>
<main class="main">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<section class="content audit-page">
  <div class="card">
    <div class="panel-head">
      <div>
        <h4>Registos de auditoria</h4>
        <span class="panel-sub">Até <?= (int) AUDIT_LOG_EXPORT_MAX_ROWS ?> linhas no CSV. O perfil gestor vê apenas eventos da sua empresa.</span>
      </div>
      <a class="btn btn-secondary btn-sm audit-export-btn" href="<?= htmlspecialchars('auditoria.php?' . http_build_query(array_merge($filtros, ['export' => 'csv']))) ?>">Exportar CSV</a>
    </div>
    <div class="panel-body">
      <form method="get" class="filters filters--form audit-filters">
        <div class="form-group">
          <label for="f_de">De</label>
          <input type="date" name="de" id="f_de" class="input" value="<?= htmlspecialchars($filtros['de']) ?>" />
        </div>
        <div class="form-group">
          <label for="f_ate">Até</label>
          <input type="date" name="ate" id="f_ate" class="input" value="<?= htmlspecialchars($filtros['ate']) ?>" />
        </div>
        <div class="form-group form-group--submit">
          <label for="audit-filtrar">Filtrar</label>
          <button type="submit" id="audit-filtrar" class="btn btn-primary">Filtrar</button>
        </div>
      </form>

      <?php if (!db_ok()): ?>
        <div class="empty">Banco indisponível — sem registos de auditoria.</div>
      <?php elseif ($rows === []): ?>
        <div class="empty"><strong>Nenhum registo</strong> no intervalo de datas selecionado. Ajuste «De» / «Até» ou deixe em branco para ver os mais recentes.</div>
      <?php else: ?>
      <p class="audit-results-meta">A mostrar <?= (int) $fromN ?>–<?= (int) $toN ?> de <?= (int) $total ?>.</p>
      <div class="table-wrap audit-table-wrap">
        <table class="audit-logs-table">
          <thead>
            <tr>
              <th>Data</th>
              <th>Ação</th>
              <th>Utilizador</th>
              <th>Entidade</th>
              <th>Empresa</th>
              <th>IP</th>
              <th>Detalhe</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
                $pid = $r['payload'] ?? '';
                if (is_string($pid)) {
                    $payloadStr = $pid;
                } else {
                    $payloadStr = json_encode($pid, JSON_UNESCAPED_UNICODE);
                }
                $payloadShort = function_exists('mb_substr')
                    ? mb_substr((string) $payloadStr, 0, 120, 'UTF-8')
                    : substr((string) $payloadStr, 0, 120);
                if (strlen((string) $payloadStr) > 120) {
                    $payloadShort .= '…';
                }
                $acaoRaw = (string) ($r['acao'] ?? '');
                $acaoCls = 'audit-action';
                if (str_starts_with($acaoRaw, 'auth.')) {
                    $acaoCls .= ' audit-action--auth';
                } elseif (str_starts_with($acaoRaw, 'chamado.')) {
                    $acaoCls .= ' audit-action--chamado';
                }
                ?>
            <tr>
              <td class="audit-cell-date"><?= htmlspecialchars((string) ($r['criado_em'] ?? '')) ?></td>
              <td><span class="<?= htmlspecialchars($acaoCls) ?>"><?= htmlspecialchars($acaoRaw) ?></span></td>
              <td><?php
                $an = trim((string) ($r['ator_nome'] ?? ''));
                $ap = trim((string) ($r['ator_perfil'] ?? ''));
                $atorLinha = $an !== '' ? $an : '(sem nome)';
                if ($ap !== '') {
                    $atorLinha .= ' · ' . $ap;
                }
                echo htmlspecialchars($atorLinha);
                ?></td>
              <td><?php
                $et = (string) ($r['entidade_tipo'] ?? '');
                $ei = $r['entidade_id'] ?? null;
                echo htmlspecialchars($et . ($ei !== null && $ei !== '' ? ' #' . $ei : ''));
                ?></td>
              <td><?= $r['cliente_id'] !== null && $r['cliente_id'] !== '' ? (int) $r['cliente_id'] : '—' ?></td>
              <td class="td-mute" style="font-size:12px;"><?= htmlspecialchars((string) ($r['ip'] ?? '—')) ?></td>
              <td>
                <details class="audit-payload">
                  <summary><?= htmlspecialchars($payloadShort) ?></summary>
                  <pre class="audit-payload__pre"><?= htmlspecialchars((string) $payloadStr) ?></pre>
                </details>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
      <nav class="pagination">
        <?php if ($page > 1): ?>
        <a class="pag-btn" href="<?= htmlspecialchars(adm_auditoria_url($page - 1, $filtros)) ?>">Anterior</a>
        <?php endif; ?>
        <span class="muted" style="padding:0 12px;">Página <?= (int) $page ?> / <?= (int) $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
        <a class="pag-btn" href="<?= htmlspecialchars(adm_auditoria_url($page + 1, $filtros)) ?>">Seguinte</a>
        <?php endif; ?>
      </nav>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</section>

</main>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

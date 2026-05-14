<?php
/**
 * Migrador web — aplica apenas os arquivos de crm/database/migrations/*.sql.
 *
 * IMPORTANTE:
 *  - Não apaga nada; é seguro rodar várias vezes (migrations usam
 *    CREATE TABLE IF NOT EXISTS e ADD COLUMN IF NOT EXISTS).
 *  - Use este arquivo quando quiser ATUALIZAR o banco mantendo os dados.
 *  - Após concluir, APAGUE este arquivo por segurança.
 */

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Atualizar banco · OnLight';
$logs      = [];
$ok        = false;
$erro      = null;
$executar  = isset($_POST['rodar']);

function log_line(array &$logs, string $msg, string $tipo = 'info'): void
{
    $logs[] = ['msg' => $msg, 'tipo' => $tipo];
}

function exec_sql_file(PDO $pdo, string $arquivo, array &$logs): void
{
    $sql = file_get_contents($arquivo);
    if ($sql === false) {
        throw new RuntimeException("Não consegui ler $arquivo");
    }

    // Remove comentários de linha (-- ...) preservando o resto.
    $linhas = preg_split('/\r?\n/', $sql);
    $limpo = [];
    foreach ($linhas as $l) {
        $trim = ltrim($l);
        if (strncmp($trim, '--', 2) === 0) continue;
        $limpo[] = $l;
    }
    $sql = implode("\n", $limpo);

    $statements = preg_split('/;\s*(\r?\n|$)/', $sql);
    $count = 0; $skip = 0;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        try {
            $pdo->exec($stmt);
            $count++;
        } catch (PDOException $e) {
            // Duplicate / already-exists -> pular silenciosamente
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false
                || stripos($msg, 'already exists') !== false
                || stripos($msg, 'check that column/key') !== false
                || stripos($msg, "Can't DROP COLUMN") !== false
                || stripos($msg, 'check that it exists') !== false) {
                $skip++;
            } else {
                throw $e;
            }
        }
    }
    log_line($logs, basename($arquivo) . ": $count executado(s), $skip já existente(s).", 'ok');
}

$migrationsDir = __DIR__ . '/database/migrations';
$arquivos = [];
if (is_dir($migrationsDir)) {
    $arquivos = glob($migrationsDir . '/*.sql');
    sort($arquivos);
}

if ($executar) {
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        ]);
        log_line($logs, 'Conectado em ' . DB_HOST . ':' . DB_PORT . '/' . DB_NAME, 'ok');

        if (empty($arquivos)) {
            log_line($logs, 'Nenhum arquivo em database/migrations/. Nada a fazer.', 'info');
        } else {
            foreach ($arquivos as $arq) {
                $nomeArq = basename($arq);
                /* Limpeza total de dados: nunca rodar em loop com migrate.php */
                if (preg_match('/reset_dados_manter_admin|_RESET_DATA_/i', $nomeArq)) {
                    log_line($logs, 'Ignorado (uso manual): ' . $nomeArq, 'info');
                    continue;
                }
                exec_sql_file($pdo, $arq, $logs);
            }
        }

        $ok = true;
    } catch (Throwable $e) {
        $erro = $e->getMessage();
        log_line($logs, $erro, 'err');
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { box-sizing: border-box; }
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 40px 20px; margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        .card { background:#fff; max-width:680px; width:100%; border-radius:14px;
                box-shadow:0 20px 60px rgba(0,0,0,.35); overflow:hidden; }
        .head { padding:28px 32px; background:linear-gradient(135deg,#059669 0%,#047857 100%); color:#fff; }
        .head h1 { margin:0 0 4px; font-size:22px; }
        .head p  { margin:0; opacity:.85; font-size:14px; }
        .body { padding:28px 32px; }
        .cfg { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;
               padding:14px 16px; font-size:13px; color:#334155;
               display:grid; grid-template-columns:max-content 1fr; gap:6px 16px; margin-bottom:20px; }
        .cfg strong { color:#0f172a; }
        .list { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;
                padding:12px 16px; font-size:13px; margin-bottom:20px; }
        .list h3 { margin:0 0 8px; font-size:13px; color:#0f172a; text-transform:uppercase; letter-spacing:.3px; }
        .list ul { margin:0; padding-left:18px; color:#475569; }
        .btn { display:inline-block; background:#059669; color:#fff; border:none;
               padding:12px 26px; font-size:15px; font-weight:600;
               border-radius:8px; cursor:pointer; transition:background .15s; }
        .btn:hover { background:#047857; }
        .logs { margin-top:22px; background:#0f172a; color:#e2e8f0; border-radius:8px;
                padding:16px 18px; font-family:Consolas,Menlo,monospace; font-size:13px;
                line-height:1.7; max-height:320px; overflow-y:auto; }
        .logs .ok{color:#4ade80;} .logs .err{color:#f87171;} .logs .info{color:#94a3b8;}
        .alert { border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:14px; }
        .alert-ok  { background:#dcfce7; color:#14532d; border:1px solid #86efac; }
        .alert-err { background:#fee2e2; color:#7f1d1d; border:1px solid #fca5a5; }
        .warn { background:#fef3c7; border:1px solid #fcd34d; color:#78350f;
                border-radius:8px; padding:12px 16px; font-size:13px; margin-bottom:18px; }
        code { background:#eef; padding:1px 5px; border-radius:4px; font-size:12px; }
        a.btn-link { display:inline-block; margin-top:8px; color:#065f46; text-decoration:none; font-weight:600; }
    </style>
</head>
<body>

<div class="card">
    <div class="head">
        <h1>Atualizar banco</h1>
        <p>Aplica as migrations em <code>database/migrations/</code> preservando os dados.</p>
    </div>

    <div class="body">

        <div class="cfg">
            <strong>Host:</strong><span><?= htmlspecialchars(DB_HOST) ?>:<?= (int) DB_PORT ?></span>
            <strong>Banco:</strong><span><?= htmlspecialchars(DB_NAME) ?></span>
            <strong>Usuário:</strong><span><?= htmlspecialchars(DB_USER) ?: '(vazio)' ?></span>
            <strong>Senha:</strong><span><?= DB_PASS === '' ? '(vazia)' : str_repeat('•', 6) ?></span>
        </div>

        <div class="list">
            <h3>Migrations encontradas</h3>
            <?php if (empty($arquivos)): ?>
                <p>Nenhum arquivo em <code>database/migrations/</code>. Suba a pasta antes de continuar.</p>
            <?php else: ?>
                <ul>
                    <?php foreach ($arquivos as $f): ?>
                        <?php
                        $bn = basename($f);
                        $manual = preg_match('/reset_dados_manter_admin|_RESET_DATA_/i', $bn);
                        ?>
                        <li><?= htmlspecialchars($bn) ?><?= $manual ? ' <em>(ignorado pelo migrador — executar só via MySQL/phpMyAdmin)</em>' : '' ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <?php if (!$executar): ?>
            <div class="warn">
                Este script é <strong>idempotente</strong>: pode ser rodado mais de uma vez sem risco.
                Ele NÃO apaga dados. Depois de concluir, <strong>apague este arquivo</strong> do servidor.
            </div>
        <?php endif; ?>

        <?php if ($ok): ?>
            <div class="alert alert-ok">Banco atualizado com sucesso.</div>
        <?php elseif ($erro): ?>
            <div class="alert alert-err">Falhou: <?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <?php if (!$ok && !empty($arquivos)): ?>
            <form method="post">
                <button type="submit" name="rodar" class="btn">
                    <?= $erro ? 'Tentar novamente' : 'Aplicar migrations' ?>
                </button>
            </form>
        <?php endif; ?>

        <?php if ($logs): ?>
            <div class="logs">
                <?php foreach ($logs as $l): ?>
                    <div class="<?= $l['tipo'] ?>"><?= $l['tipo'] === 'ok' ? '✓ ' : ($l['tipo'] === 'err' ? '✗ ' : '• ') ?><?= htmlspecialchars($l['msg']) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($ok): ?>
            <div class="warn" style="margin-top:18px;">
                <strong>Importante:</strong> apague <code>migrate.php</code> do servidor agora para evitar acesso indevido.
            </div>
            <a class="btn-link" href="login.php">→ Ir para o login</a>
        <?php endif; ?>

    </div>
</div>

</body>
</html>

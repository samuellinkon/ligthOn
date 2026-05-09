<?php
/**
 * Instalador web do LightOn.
 * Passos:
 *   1) Conecta no MySQL (sem DB).
 *   2) Cria o banco (DB_NAME) se não existir.
 *   3) Executa schema.sql.
 *   4) Executa seed.sql.
 *   5) Cria os usuários padrão (admin + cliente) com password_hash.
 */

require_once __DIR__ . '/includes/config.php';

$pageTitle = 'Instalador · LightOn';
$basePath  = '';
$logs      = [];
$ok        = false;
$erro      = null;
$executar  = isset($_POST['instalar']);

function log_line(array &$logs, string $msg, string $tipo = 'info'): void
{
    $logs[] = ['msg' => $msg, 'tipo' => $tipo];
}

function rodar_sql(PDO $pdo, string $arquivo, array &$logs): void
{
    $sql = file_get_contents($arquivo);
    if ($sql === false) {
        throw new RuntimeException("Não consegui ler $arquivo");
    }

    // Remove linhas de comentário (-- ...) preservando o resto.
    $linhas = preg_split('/\r?\n/', $sql);
    $limpo = [];
    foreach ($linhas as $l) {
        $trim = ltrim($l);
        if (strncmp($trim, '--', 2) === 0) continue;
        $limpo[] = $l;
    }
    $sql = implode("\n", $limpo);

    $statements = preg_split('/;\s*(\r?\n|$)/', $sql);
    $count = 0;
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        $pdo->exec($stmt);
        $count++;
    }
    log_line($logs, basename($arquivo) . ": $count statements executados.", 'ok');
}

if ($executar) {
    try {
        $dsnBase = sprintf('mysql:host=%s;port=%d;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
        $pdo = new PDO($dsnBase, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        log_line($logs, 'Conectado ao MySQL em ' . DB_HOST . ':' . DB_PORT, 'ok');

        $pdo->exec(
            'CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` '
            . 'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
        log_line($logs, 'Banco `' . DB_NAME . '` pronto.', 'ok');

        $pdo->exec('USE `' . DB_NAME . '`');

        rodar_sql($pdo, __DIR__ . '/database/schema.sql', $logs);
        rodar_sql($pdo, __DIR__ . '/database/seed.sql',   $logs);

        $hashAdmin   = password_hash('admin123',   PASSWORD_BCRYPT);
        $hashCliente = password_hash('cliente123', PASSWORD_BCRYPT);

        $pdo->exec("DELETE FROM usuarios");

        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (id, nome, email, senha_hash, perfil, is_super_admin, modulo_perfil, cliente_id, empresa_id, iniciais) VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([1,  'Samuel Lima',   'admin@crm.com',   $hashAdmin,   'admin', 1, null, null, null, 'SL']);
        $stmt->execute([10, 'Mariana Costa', 'cliente@crm.com', $hashCliente, 'cliente', 0, null, 1, null, 'MC']);
        log_line($logs, 'Usuários padrão criados (admin@crm.com super admin / cliente@crm.com).', 'ok');

        $ok = true;
    } catch (Throwable $e) {
        $erro = $e->getMessage();
        log_line($logs, $erro, 'err');
    }
}

$cssBustInstall = static function (string $file): int {
    $path = __DIR__ . '/assets/css/' . basename($file);
    return is_file($path) ? (int) filemtime($path) : 0;
};
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/img/lighton-icon.png">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= $cssBustInstall('style.css') ?>">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        .install-card {
            background: #fff;
            max-width: 640px;
            width: 100%;
            border-radius: 14px;
            box-shadow: 0 20px 60px rgba(0,0,0,.35);
            overflow: hidden;
        }

        .install-head {
            padding: 28px 32px;
            background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%);
            color: #fff;
        }

        .install-head h1 { margin: 0 0 4px; font-size: 22px; }
        .install-head p  { margin: 0; opacity: .85; font-size: 14px; }

        .install-body { padding: 28px 32px; }

        .cfg {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px 16px;
            font-size: 13px;
            color: #334155;
            display: grid;
            grid-template-columns: max-content 1fr;
            gap: 6px 16px;
            margin-bottom: 20px;
        }

        .cfg strong { color: #0f172a; }

        .btn-install {
            display: inline-block;
            background: #2563eb;
            color: #fff;
            border: none;
            padding: 12px 26px;
            font-size: 15px;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: background .15s;
        }

        .btn-install:hover { background: #1d4ed8; }
        .btn-install[disabled] { opacity: .5; cursor: not-allowed; }

        .logs {
            margin-top: 22px;
            background: #0f172a;
            color: #e2e8f0;
            border-radius: 8px;
            padding: 16px 18px;
            font-family: Consolas, Menlo, monospace;
            font-size: 13px;
            line-height: 1.7;
            max-height: 320px;
            overflow-y: auto;
        }

        .logs .ok  { color: #4ade80; }
        .logs .err { color: #f87171; }
        .logs .info{ color: #94a3b8; }

        .alert {
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .alert-ok  { background: #dcfce7; color: #14532d; border: 1px solid #86efac; }
        .alert-err { background: #fee2e2; color: #7f1d1d; border: 1px solid #fca5a5; }

        .next-steps {
            margin-top: 22px;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 14px 18px;
            font-size: 14px;
            color: #1e3a8a;
        }

        .next-steps a {
            display: inline-block;
            margin-top: 8px;
            color: #1e40af;
            text-decoration: none;
            font-weight: 600;
        }

        .next-steps a:hover { text-decoration: underline; }

        .warn-box {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            color: #78350f;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 13px;
            margin-bottom: 18px;
        }
    </style>
</head>
<body>

<div class="install-card">
    <div class="install-head">
        <h1>Instalador do LightOn</h1>
        <p>Cria o banco <code><?= htmlspecialchars(DB_NAME) ?></code>, executa schema + seed e cria usuários padrão.</p>
    </div>

    <div class="install-body">

        <div class="cfg">
            <strong>Host:</strong><span><?= htmlspecialchars(DB_HOST) ?>:<?= (int) DB_PORT ?></span>
            <strong>Banco:</strong><span><?= htmlspecialchars(DB_NAME) ?></span>
            <strong>Usuário:</strong><span><?= htmlspecialchars(DB_USER) ?: '(vazio)' ?></span>
            <strong>Senha:</strong><span><?= DB_PASS === '' ? '(vazia)' : str_repeat('•', 6) ?></span>
        </div>

        <?php if (!$executar): ?>
            <div class="warn-box">
                Verifique se o <strong>MySQL do XAMPP está ligado</strong> (XAMPP Control Panel → Start no MySQL).
                Se suas credenciais forem diferentes, edite <code>includes/config.php</code> antes de continuar.
            </div>
        <?php endif; ?>

        <?php if ($ok): ?>
            <div class="alert alert-ok">
                Instalação concluída com sucesso. O LightOn já está ligado ao banco.
            </div>
        <?php elseif ($erro): ?>
            <div class="alert alert-err">
                Falhou: <?= htmlspecialchars($erro) ?>
            </div>
        <?php endif; ?>

        <?php if (!$ok): ?>
            <form method="post">
                <button type="submit" name="instalar" class="btn-install">
                    <?= $erro ? 'Tentar novamente' : 'Instalar agora' ?>
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
            <div class="next-steps">
                <strong>Próximos passos:</strong>
                <ol style="margin:10px 0 0 18px; padding: 0;">
                    <li>Apague este arquivo (<code>install.php</code>) antes de publicar.</li>
                    <li>Acesse o login abaixo e teste com os usuários padrão.</li>
                </ol>
                <a href="login.php">→ Ir para o login</a>
            </div>
        <?php endif; ?>

    </div>
</div>

</body>
</html>

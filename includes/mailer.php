<?php
/**
 * Envio de e-mail: SMTP (configurável, ex.: Hostinger) ou mail() nativo.
 */

require_once __DIR__ . '/config.php';

if (!defined('MAIL_FROM')) {
    define('MAIL_FROM', 'nao-responda@crm-control.com');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'OnLight');
}

require_once __DIR__ . '/app_runtime.php';
require_once __DIR__ . '/smtp_client.php';

function mail_send(string $to, string $subject, string $htmlBody): array
{
    $s       = app_mail_settings();
    $subjectEncoded = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $useSmtp = $s['smtp_enabled']
        && $s['smtp_host'] !== ''
        && $s['smtp_user'] !== ''
        && $s['smtp_password'] !== '';

    if ($useSmtp) {
        return smtp_mail_send(
            $s['smtp_host'],
            $s['smtp_port'],
            $s['smtp_encryption'],
            $s['smtp_user'],
            $s['smtp_password'],
            $s['from'],
            $s['from_name'],
            $to,
            $subjectEncoded,
            $htmlBody
        );
    }

    $boundary = 'crm-' . bin2hex(random_bytes(8));
    $headers  = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . smtp_encode_header_name($s['from_name']) . ' <' . $s['from'] . '>',
        'Reply-To: ' . $s['from'],
        'X-Mailer: PHP/' . phpversion(),
    ];

    $ok = @mail($to, $subjectEncoded, $htmlBody, implode("\r\n", $headers));

    return [
        'ok'      => (bool) $ok,
        'to'      => $to,
        'subject' => $subject,
        'motivo'  => $ok ? '' : (error_get_last()['message'] ?? 'mail() retornou false'),
    ];
}

/**
 * E-mail de boas-vindas com credenciais de acesso.
 */
function mail_welcome(string $to, string $nome, string $empresa, string $email, string $senha, string $portalUrl): array
{
    $brand      = defined('APP_BRAND_NAME') ? (string) APP_BRAND_NAME : 'OnLight';
    $brandH     = htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');
    $logoColor = '#2563eb';
    $rodape     = htmlspecialchars(app_mail_settings()['from_name'], ENT_QUOTES, 'UTF-8');
    $body = '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;">'
        . '<div style="max-width:560px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,.08);">'
        . '<div style="background:linear-gradient(135deg,' . $logoColor . ' 0%,#1e40af 100%);color:#fff;padding:28px 32px;">'
        . '<h1 style="margin:0 0 4px;font-size:22px;">Bem-vindo ao ' . $brandH . '</h1>'
        . '<p style="margin:0;opacity:.9;font-size:14px;">Seu acesso foi criado com sucesso</p>'
        . '</div>'
        . '<div style="padding:28px 32px;">'
        . '<p>Olá, <strong>' . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . '</strong>!</p>'
        . '<p>Criamos seu acesso ao portal da <strong>' . htmlspecialchars($empresa, ENT_QUOTES, 'UTF-8') . '</strong>. '
        . 'Por lá você consegue abrir chamados, acompanhar ordens de serviço, tirar dúvidas e visualizar suas cobranças.</p>'

        . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin:20px 0;">'
        . '<table style="width:100%;font-size:14px;">'
        . '<tr><td style="color:#64748b;padding:4px 0;">E-mail:</td><td><strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong></td></tr>'
        . '<tr><td style="color:#64748b;padding:4px 0;">Senha:</td><td><strong style="font-family:Consolas,monospace;background:#fef3c7;padding:2px 8px;border-radius:4px;">' . htmlspecialchars($senha, ENT_QUOTES, 'UTF-8') . '</strong></td></tr>'
        . '</table>'
        . '</div>'

        . '<p style="text-align:center;margin:28px 0;">'
        . '<a href="' . htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:' . $logoColor . ';color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;">Acessar o portal</a>'
        . '</p>'

        . '<p style="font-size:13px;color:#64748b;">Recomendamos alterar sua senha após o primeiro acesso. '
        . 'Se você não solicitou este cadastro, ignore este e-mail.</p>'
        . '</div>'
        . '<div style="background:#f8fafc;padding:16px 32px;font-size:12px;color:#64748b;text-align:center;border-top:1px solid #e2e8f0;">'
        . $rodape . ' · mensagem automática, não responda.'
        . '</div>'
        . '</div></body></html>';

    return mail_send($to, 'Seu acesso ao ' . $brand, $body);
}

/**
 * Formata valor em reais para corpo de e-mail (sem depender de mock.php).
 */
function mail_format_brl(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}

/**
 * E-mail ao cliente com cobrança: valor, vencimento, boleto e PIX.
 *
 * @param array  $conta               id, descricao, valor, vencimento, status, observacoes?, boleto_*, pix_* (use chave PIX já resolvida p/ exibição)
 * @param string $urlDetalheCliente   link direto para cliente/conta_detalhe.php?id=
 * @param string|null $urlPdfBoleto link cliente/conta_boleto.php?id= quando houver PDF anexado
 */
function mail_conta_cobranca(string $to, array $conta, array $cliente, string $urlDetalheCliente, ?string $urlPdfBoleto = null): array
{
    $empresa = (string) ($cliente['empresa'] ?? '');
    $nome    = trim((string) ($cliente['nome'] ?? ''));
    $cid     = (int) ($conta['id'] ?? 0);
    $desc    = (string) ($conta['descricao'] ?? '');
    $valor   = (float) ($conta['valor'] ?? 0);
    $venc    = (string) ($conta['vencimento'] ?? '');
    $vencBr  = $venc !== '' ? date('d/m/Y', strtotime($venc)) : '—';
    $status  = (string) ($conta['status'] ?? '');
    $obs     = trim((string) ($conta['observacoes'] ?? ''));
    $linha   = trim((string) ($conta['boleto_linha'] ?? ''));
    $burl    = trim((string) ($conta['boleto_url'] ?? ''));
    $pixk    = trim((string) ($conta['pix_chave'] ?? ''));
    $pixt    = trim((string) ($conta['pix_tipo'] ?? ''));
    $temPdf  = !empty($conta['boleto_arquivo']) && $urlPdfBoleto;

    $accent = '#0f766e';
    $boletoHtml = '';
    if ($linha !== '' || $burl !== '' || $temPdf) {
        $boletoHtml = '<div style="margin-top:20px;padding:18px;background:#f0fdfa;border:1px solid #99f6e4;border-radius:10px;">'
            . '<div style="font-size:12px;font-weight:700;color:#0f766e;letter-spacing:.04em;margin-bottom:10px;">BOLETO</div>';
        if ($linha !== '') {
            $boletoHtml .= '<p style="margin:0 0 8px;font-size:12px;color:#64748b;">Linha digitável</p>'
                . '<p style="margin:0 0 12px;font-size:13px;font-family:Consolas,monospace;word-break:break-all;line-height:1.5;background:#fff;padding:12px;border-radius:8px;border:1px solid #ccfbf1;">'
                . htmlspecialchars($linha, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        if ($burl !== '') {
            $boletoHtml .= '<a href="' . htmlspecialchars($burl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:' . $accent . ';color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-weight:600;font-size:14px;margin-right:8px;margin-bottom:8px;">Abrir link externo</a>';
        }
        if ($temPdf) {
            $boletoHtml .= '<a href="' . htmlspecialchars($urlPdfBoleto, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#115e59;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-weight:600;font-size:14px;margin-bottom:8px;">Baixar PDF do boleto</a>';
        }
        $boletoHtml .= '</div>';
    }

    $pixHtml = '';
    if ($pixk !== '') {
        $pixHtml = '<div style="margin-top:16px;padding:18px;background:#fefce8;border:1px solid #fde047;border-radius:10px;">'
            . '<div style="font-size:12px;font-weight:700;color:#854d0e;letter-spacing:.04em;margin-bottom:10px;">PIX</div>'
            . ($pixt !== '' ? '<p style="margin:0 0 6px;font-size:13px;"><span style="color:#64748b;">Tipo:</span> <strong>' . htmlspecialchars($pixt, ENT_QUOTES, 'UTF-8') . '</strong></p>' : '')
            . '<p style="margin:0;font-size:12px;color:#64748b;">Chave</p>'
            . '<p style="margin:6px 0 0;font-size:15px;font-weight:700;word-break:break-all;">' . htmlspecialchars($pixk, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div>';
    }

    if ($boletoHtml === '' && $pixHtml === '') {
        $boletoHtml = '<p style="margin-top:16px;font-size:14px;color:#64748b;">Acesse o portal para ver instruções de pagamento quando disponíveis.</p>';
    }

    $obsBlock = '';
    if ($obs !== '') {
        $obsBlock = '<div style="margin-top:16px;padding:14px;background:#f8fafc;border-radius:8px;font-size:14px;"><strong>Observações</strong><br>'
            . nl2br(htmlspecialchars($obs, ENT_QUOTES, 'UTF-8')) . '</div>';
    }

    $rodape = htmlspecialchars(app_mail_settings()['from_name'], ENT_QUOTES, 'UTF-8');

    $body = '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;">'
        . '<div style="max-width:560px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,.08);">'
        . '<div style="background:linear-gradient(135deg,' . $accent . ' 0%,#115e59 100%);color:#fff;padding:28px 32px;">'
        . '<p style="margin:0 0 6px;font-size:12px;opacity:.9;">COBRANÇA / FATURA</p>'
        . '<h1 style="margin:0;font-size:22px;">#' . $cid . ' — ' . htmlspecialchars($empresa, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p style="margin:10px 0 0;font-size:18px;font-weight:700;">' . mail_format_brl($valor) . '</p>'
        . '</div>'
        . '<div style="padding:28px 32px;">'
        . '<p style="margin:0 0 16px;">Olá' . ($nome !== '' ? ', <strong>' . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . '</strong>' : '') . '!</p>'
        . '<p style="margin:0 0 18px;line-height:1.55;">Segue o detalhe da cobrança referente a <strong>' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
        . '<table style="width:100%;font-size:14px;margin-bottom:8px;">'
        . '<tr><td style="color:#64748b;padding:6px 0;">Vencimento</td><td style="padding:6px 0;"><strong>' . htmlspecialchars($vencBr, ENT_QUOTES, 'UTF-8') . '</strong></td></tr>'
        . '<tr><td style="color:#64748b;padding:6px 0;">Status</td><td style="padding:6px 0;"><strong>' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</strong></td></tr>'
        . '</table>'
        . $boletoHtml
        . $pixHtml
        . $obsBlock
        . '<p style="text-align:center;margin:28px 0 0;">'
        . '<a href="' . htmlspecialchars($urlDetalheCliente, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:' . $accent . ';color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;">Abrir cobrança no portal</a>'
        . '</p>'
        . '<p style="font-size:12px;color:#64748b;margin-top:22px;">Em caso de dúvida, responda este e-mail ou fale com seu consultor.</p>'
        . '</div>'
        . '<div style="background:#f8fafc;padding:16px 32px;font-size:12px;color:#64748b;text-align:center;border-top:1px solid #e2e8f0;">'
        . $rodape . ' · mensagem automática'
        . '</div></div></body></html>';

    $subject = 'Cobrança #' . $cid . ' — ' . mail_format_brl($valor) . ' — venc. ' . $vencBr;

    return mail_send($to, $subject, $body);
}

/**
 * E-mail ao cliente com resumo da OS (estilo nota / fatura informativa).
 *
 * @param array $cliente  ['empresa' => string, 'nome' => string|null]
 * @param array $os      titulo, tipo, descricao, horas_previstas, valor_hora, status, responsavel, chamado_id?, dentro_contrato
 */
function mail_os_invoice(
    string $to,
    int $osId,
    array $cliente,
    array $os,
    float $valorTotalEstimado,
    string $portalUrl
): array {
    $empresa = (string) ($cliente['empresa'] ?? '');
    $nome    = trim((string) ($cliente['nome'] ?? ''));
    $titulo  = (string) ($os['titulo'] ?? '');
    $tipo    = (string) ($os['tipo'] ?? '');
    $desc    = trim((string) ($os['descricao'] ?? ''));
    $hp      = (float) ($os['horas_previstas'] ?? 0);
    $vh      = (float) ($os['valor_hora'] ?? 0);
    $status  = (string) ($os['status'] ?? '');
    $resp    = (string) ($os['responsavel'] ?? '');
    $chamado = isset($os['chamado_id']) && $os['chamado_id'] !== null && $os['chamado_id'] !== ''
        ? (int) $os['chamado_id']
        : 0;
    $dentro  = !empty($os['dentro_contrato']);
    $regime  = $dentro
        ? 'Dentro do contrato (abatimento do pacote de horas, sem cobrança avulsa neste valor).'
        : 'Fora do contrato (hora extra) — valor estimado conforme horas previstas × valor/hora.';

    $accent = '#534AB7';
    $rows   = [
        ['OS', '#' . $osId, false],
        ['Empresa', $empresa, false],
        ['Título', $titulo, false],
        ['Tipo', $tipo, false],
        ['Status', $status, false],
        ['Responsável', $resp !== '' ? $resp : '—', false],
        ['Horas previstas', rtrim(rtrim(number_format($hp, 2, ',', ''), '0'), ',') . ' h', false],
        ['Valor / hora', mail_format_brl($vh), false],
        ['Valor total estimado', '<strong style="font-size:18px;color:' . $accent . ';">' . mail_format_brl($valorTotalEstimado) . '</strong>', true],
        ['Regime', $regime, false],
    ];
    if ($chamado > 0) {
        array_splice($rows, 3, 0, [['Chamado vinculado', '#' . $chamado, false]]);
    }

    $tableHtml = '<table style="width:100%;font-size:14px;border-collapse:collapse;">';
    foreach ($rows as $r) {
        $cell = $r[2] ? $r[1] : htmlspecialchars((string) $r[1], ENT_QUOTES, 'UTF-8');
        $tableHtml .= '<tr style="border-bottom:1px solid #e2e8f0;">'
            . '<td style="color:#64748b;padding:10px 0;width:38%;vertical-align:top;">' . htmlspecialchars($r[0], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:10px 0;vertical-align:top;">' . $cell . '</td></tr>';
    }
    $tableHtml .= '</table>';

    $descBlock = '';
    if ($desc !== '') {
        $descBlock = '<div style="margin-top:20px;padding:16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">'
            . '<div style="font-size:12px;color:#64748b;margin-bottom:6px;">Descrição do serviço</div>'
            . '<div style="font-size:14px;white-space:pre-wrap;">' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</div>'
            . '</div>';
    }

    $rodape = htmlspecialchars(app_mail_settings()['from_name'], ENT_QUOTES, 'UTF-8');

    $body = '<!DOCTYPE html><html lang="pt-br"><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,Segoe UI,Roboto,sans-serif;color:#0f172a;">'
        . '<div style="max-width:560px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,.08);">'
        . '<div style="background:linear-gradient(135deg,' . $accent . ' 0%,#3d3690 100%);color:#fff;padding:28px 32px;">'
        . '<p style="margin:0 0 6px;font-size:12px;letter-spacing:.06em;opacity:.9;">DOCUMENTO INFORMATIVO</p>'
        . '<h1 style="margin:0;font-size:22px;">Ordem de serviço #' . $osId . '</h1>'
        . '<p style="margin:8px 0 0;opacity:.92;font-size:14px;">' . htmlspecialchars($empresa, ENT_QUOTES, 'UTF-8') . '</p>'
        . '</div>'
        . '<div style="padding:28px 32px;">'
        . '<p style="margin:0 0 16px;">Olá' . ($nome !== '' ? ', <strong>' . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . '</strong>' : '') . '!</p>'
        . '<p style="margin:0 0 20px;color:#334155;line-height:1.55;">Registramos uma nova ordem de serviço em seu nome. '
        . 'Abaixo estão os valores e informações para seu controle. O valor total é <strong>estimado</strong> com base nas horas previstas informadas na abertura.</p>'
        . $tableHtml
        . $descBlock
        . '<p style="text-align:center;margin:28px 0 0;">'
        . '<a href="' . htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:' . $accent . ';color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;">Ver no portal do cliente</a>'
        . '</p>'
        . '<p style="font-size:12px;color:#64748b;margin-top:24px;">Em caso de dúvidas, responda a este e-mail ou abra um chamado no portal. '
        . 'Este é um aviso automático — não substitui nota fiscal fiscal quando aplicável.</p>'
        . '</div>'
        . '<div style="background:#f8fafc;padding:16px 32px;font-size:12px;color:#64748b;text-align:center;border-top:1px solid #e2e8f0;">'
        . $rodape . ' · mensagem automática'
        . '</div></div></body></html>';

    $subject = 'OS #' . $osId . ' aberta — ' . $empresa . ' — ' . mail_format_brl($valorTotalEstimado) . ' (estimado)';

    return mail_send($to, $subject, $body);
}

/**
 * Gera uma senha temporária curta e legível.
 */
function gerar_senha_temp(int $tamanho = 10): string
{
    $alfabeto = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $tamanho; $i++) {
        $s .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }
    return $s;
}

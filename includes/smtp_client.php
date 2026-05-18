<?php
/**
 * Cliente SMTP mínimo (AUTH LOGIN, HTML UTF-8).
 * Testado com fluxo típico da Hostinger: SSL 465 ou TLS 587.
 */

function smtp_read_multiline($fp): string
{
    $buf = '';
    while (!feof($fp)) {
        $line = fgets($fp, 4096);
        if ($line === false) {
            break;
        }
        $buf .= $line;
        $len = strlen($line);
        if ($len >= 4 && $line[3] === ' ') {
            break;
        }
    }
    return $buf;
}

function smtp_expect_code(string $resp, array $codes): bool
{
    if ($resp === '') {
        return false;
    }
    $code = (int) substr($resp, 0, 3);
    return in_array($code, $codes, true);
}

function smtp_cmd($fp, string $cmd, array $expectCodes): array
{
    fwrite($fp, $cmd . "\r\n");
    $resp = smtp_read_multiline($fp);
    return ['ok' => smtp_expect_code($resp, $expectCodes), 'resp' => $resp];
}

/**
 * @return array{ok: bool, to: string, subject: string, motivo: string}
 */
function smtp_mail_send(
    string $host,
    int $port,
    string $encryption,
    string $user,
    string $password,
    string $fromEmail,
    string $fromName,
    string $to,
    string $subjectMime,
    string $htmlBody
): array {
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'Destinatário inválido.'];
    }
    if ($host === '' || $user === '') {
        return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'SMTP sem host ou usuário.'];
    }

    $ehlo = preg_replace('/[^a-zA-Z0-9.-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    if ($ehlo === '') {
        $ehlo = 'localhost';
    }

    $boundary = 'bnd-' . bin2hex(random_bytes(8));
    $headers  = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'From: ' . smtp_encode_header_name($fromName) . ' <' . $fromEmail . '>',
        'To: <' . $to . '>',
        'Subject: ' . $subjectMime,
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
    ];

    $plain = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
    $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $body = "--$boundary\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $plain . "\r\n\r\n"
        . "--$boundary\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $htmlBody . "\r\n\r\n"
        . "--$boundary--\r\n";

    $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $data = preg_replace("/\r\n\./", "\r\n..", $data);

    $errno = 0;
    $errstr = '';
    $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
        $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    }

    $fp = null;
    $encryption = strtolower($encryption);

    try {
        if ($encryption === 'ssl') {
            $target = 'ssl://' . $host . ':' . $port;
            $fp = @stream_socket_client($target, $errno, $errstr, 25, STREAM_CLIENT_CONNECT);
        } else {
            $target = 'tcp://' . $host . ':' . $port;
            $fp = @stream_socket_client($target, $errno, $errstr, 25, STREAM_CLIENT_CONNECT);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => $e->getMessage()];
    }

    if ($fp === false) {
        return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => $errstr ?: 'Falha ao conectar no SMTP.'];
    }

    stream_set_timeout($fp, 25);
    $greet = smtp_read_multiline($fp);
    if (!smtp_expect_code($greet, [220])) {
        fclose($fp);
        return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'SMTP: ' . trim($greet)];
    }

    $r = smtp_cmd($fp, 'EHLO ' . $ehlo, [250]);
    if (!$r['ok']) {
        fclose($fp);
        return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'EHLO: ' . trim($r['resp'])];
    }

    if ($encryption === 'tls') {
        $r = smtp_cmd($fp, 'STARTTLS', [220]);
        if (!$r['ok']) {
            fclose($fp);
            return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'STARTTLS: ' . trim($r['resp'])];
        }
        if (!@stream_socket_enable_crypto($fp, true, $crypto)) {
            fclose($fp);
            return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'Falha ao negociar TLS.'];
        }
        $r = smtp_cmd($fp, 'EHLO ' . $ehlo, [250]);
        if (!$r['ok']) {
            fclose($fp);
            return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'EHLO pós-TLS: ' . trim($r['resp'])];
        }
    }
    /* encryption === 'none': segue com a conexão já estabelecida (rede interna / relay sem TLS). */

    $r = smtp_cmd($fp, 'AUTH LOGIN', [334]);
    if (!$r['ok']) {
        fclose($fp);
        return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'AUTH: ' . trim($r['resp'])];
    }
    $r = smtp_cmd($fp, base64_encode($user), [334]);
    if (!$r['ok']) {
        fclose($fp);
        return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'Usuário SMTP recusado.'];
    }
    $r = smtp_cmd($fp, base64_encode($password), [235]);
    if (!$r['ok']) {
        fclose($fp);
        return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'Senha SMTP recusada ou conta bloqueada.'];
    }

    $r = smtp_cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', [250]);
    if (!$r['ok']) {
        fclose($fp);
        return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'MAIL FROM: ' . trim($r['resp'])];
    }
    $r = smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
    if (!$r['ok']) {
        fclose($fp);
        return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'RCPT TO: ' . trim($r['resp'])];
    }
    $r = smtp_cmd($fp, 'DATA', [354]);
    if (!$r['ok']) {
        fclose($fp);
        return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'DATA: ' . trim($r['resp'])];
    }

    fwrite($fp, $data . "\r\n.\r\n");
    $dataResp = smtp_read_multiline($fp);
    if (!smtp_expect_code($dataResp, [250])) {
        fclose($fp);
        return ['ok' => false, 'to' => $to, 'subject' => $subjectMime, 'motivo' => 'Envio: ' . trim($dataResp)];
    }

    smtp_cmd($fp, 'QUIT', [221]);
    fclose($fp);

    return ['ok' => true, 'to' => $to, 'subject' => $subjectMime, 'motivo' => ''];
}

function smtp_encode_header_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'CRM';
    }
    if (preg_match('/[\x00-\x1f\x7f"<>]/', $name)) {
        return '=?UTF-8?B?' . base64_encode($name) . '?=';
    }
    return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
}

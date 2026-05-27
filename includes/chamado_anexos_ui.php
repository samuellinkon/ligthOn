<?php
/**
 * Helpers JSON / payload — anexos do chamado (painel admin).
 */

function chamado_anexo_url(int $anexoId, bool $inline = false): string
{
    $url = 'chamado_download.php?id=' . max(0, $anexoId);

    return $inline ? $url . '&inline=1' : $url;
}

function chamado_anexo_para_json(array $a): array
{
    $nome = (string) ($a['nome_original'] ?? '');
    $mime = strtolower((string) ($a['mime'] ?? ''));
    $ehImagem = upload_extensao_imagem($nome)
        || (strncmp($mime, 'image/', 8) === 0 && strpos($mime, 'svg') === false);

    return [
        'id'            => (int) ($a['id'] ?? 0),
        'nome_original' => $nome,
        'tamanho'       => (int) ($a['tamanho'] ?? 0),
        'tamanho_fmt'   => upload_formatar_tamanho((int) ($a['tamanho'] ?? 0)),
        'enviado_por'   => (string) ($a['enviado_por'] ?? '—'),
        'enviado_tipo'  => (string) ($a['enviado_tipo'] ?? ''),
        'enviado_em'    => (string) ($a['enviado_em'] ?? ''),
        'eh_imagem'     => $ehImagem,
        'url'           => chamado_anexo_url((int) ($a['id'] ?? 0)),
        'url_inline'    => $ehImagem ? chamado_anexo_url((int) ($a['id'] ?? 0), true) : null,
        'icone_html'    => upload_icone_por_ext($nome),
    ];
}

function chamado_anexos_json_send(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function chamado_anexos_lista_json(int $chamadoId, array $rows = []): array
{
    if ($rows === []) {
        $rows = repo_chamado_anexos($chamadoId);
    }

    return array_map('chamado_anexo_para_json', $rows);
}

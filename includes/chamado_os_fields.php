<?php
declare(strict_types=1);

/**
 * Campos estilo "Ordem de Serviço" (contribuinte, endereço estruturado, classificações).
 */

/** @return array<string, string> valor interno => rótulo */
function chamado_os_opcoes_origem(): array
{
    return [
        'Telefone' => 'Telefone',
        'WhatsApp' => 'WhatsApp',
        'Presencial' => 'Presencial',
        'E-mail' => 'E-mail',
        'Portal' => 'Portal',
        'Outro' => 'Outro',
    ];
}

/** @return array<string, string> */
function chamado_os_opcoes_problema(): array
{
    return [
        'Ponto Apagado' => 'Ponto Apagado',
        'Vazamento de Corrente' => 'Vazamento de Corrente',
        'Implantação' => 'Implantação',
        'Evento' => 'Evento',
        'Serviços Gerais' => 'Serviços Gerais',
        'Outros' => 'Outros',
    ];
}

/** @return array<string, string> */
function chamado_os_opcoes_tipo(): array
{
    return [
        'Corretiva' => 'Corretiva',
        'Preventiva' => 'Preventiva',
        'Emergencial' => 'Emergencial',
        'Inspeção' => 'Inspeção',
        'Melhoria' => 'Melhoria',
    ];
}

/** Gera o título do chamado a partir da classificação da OS (problema, origem opcional). */
function chamado_os_titulo_from_post(array $post): string
{
    $prob = trim((string) ($post['problema_os'] ?? ''));
    $orig = trim((string) ($post['origem_os'] ?? ''));
    $parts = array_filter([$prob, $orig]);
    $out = implode(' · ', $parts);

    return mb_substr($out !== '' ? $out : 'Solicitação de serviço', 0, 200);
}

/** Valida campos obrigatórios da OS no POST. @return list<string> mensagens de erro */
function chamado_os_validar_obrigatorios(array $post): array
{
    $erros = [];
    if (trim((string) ($post['origem_os'] ?? '')) === '') {
        $erros[] = 'Selecione a origem da OS.';
    }
    if (trim((string) ($post['problema_os'] ?? '')) === '') {
        $erros[] = 'Selecione o tipo de problema.';
    }
    if (trim((string) ($post['descricao'] ?? '')) === '') {
        $erros[] = 'Informe a descrição do chamado.';
    }

    return $erros;
}

function chamado_os_sanitize_cpf(?string $cpf): ?string
{
    if ($cpf === null || $cpf === '') {
        return null;
    }
    $d = preg_replace('/\D/', '', $cpf);
    if ($d === '') {
        return null;
    }
    if (strlen($d) === 11) {
        return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
    }

    return substr($cpf, 0, 20);
}

/**
 * Monta texto de endereço único a partir dos campos estruturados (mapas / export legado).
 *
 * @param array<string, mixed> $p
 */
function chamado_os_compor_endereco_completo(array $p): ?string
{
    $parts = [];
    $log = trim((string) ($p['os_logradouro'] ?? ''));
    $num = trim((string) ($p['os_numero'] ?? ''));
    if ($log !== '') {
        $parts[] = $num !== '' ? $log . ', ' . $num : $log;
    } elseif ($num !== '') {
        $parts[] = $num;
    }
    $comp = trim((string) ($p['os_complemento'] ?? ''));
    if ($comp !== '') {
        $parts[] = $comp;
    }
    $bai = trim((string) ($p['os_bairro'] ?? ''));
    if ($bai !== '') {
        $parts[] = $bai;
    }
    $cid = trim((string) ($p['os_cidade'] ?? ''));
    $uf = strtoupper(trim((string) ($p['os_uf'] ?? '')));
    if ($uf !== '' && strlen($uf) > 2) {
        $uf = substr($uf, 0, 2);
    }
    if ($cid !== '' && $uf !== '') {
        $parts[] = $cid . ' — ' . $uf;
    } elseif ($cid !== '') {
        $parts[] = $cid;
    } elseif ($uf !== '') {
        $parts[] = $uf;
    }
    $cep = trim((string) ($p['os_cep'] ?? ''));
    if ($cep !== '') {
        $parts[] = 'CEP ' . $cep;
    }
    $s = implode(' · ', array_filter($parts));

    return $s !== '' ? $s : null;
}

/**
 * @param array<string, mixed> $post
 * @return array<string, mixed>
 */
function chamado_os_parse_post(array $post): array
{
    $dabRaw = trim((string) ($post['data_abertura_os'] ?? ''));
    $dab = null;
    if ($dabRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dabRaw)) {
        $dab = $dabRaw;
    }

    return [
        'contribuinte_cpf' => chamado_os_sanitize_cpf((string) ($post['contribuinte_cpf'] ?? '')),
        'contribuinte_nome' => trim((string) ($post['contribuinte_nome'] ?? '')) !== ''
            ? mb_substr(trim((string) ($post['contribuinte_nome'] ?? '')), 0, 200) : null,
        'contribuinte_telefone' => trim((string) ($post['contribuinte_telefone'] ?? '')) !== ''
            ? mb_substr(trim((string) ($post['contribuinte_telefone'] ?? '')), 0, 40) : null,
        'contribuinte_email' => trim((string) ($post['contribuinte_email'] ?? '')) !== ''
            ? mb_substr(trim((string) ($post['contribuinte_email'] ?? '')), 0, 160) : null,
        'data_abertura_os' => $dab,
        'origem_os' => trim((string) ($post['origem_os'] ?? '')) !== ''
            ? mb_substr(trim((string) ($post['origem_os'] ?? '')), 0, 80) : null,
        'problema_os' => trim((string) ($post['problema_os'] ?? '')) !== ''
            ? mb_substr(trim((string) ($post['problema_os'] ?? '')), 0, 120) : null,
        'tipo_os' => trim((string) ($post['tipo_os'] ?? '')) !== ''
            ? mb_substr(trim((string) ($post['tipo_os'] ?? '')), 0, 80) : null,
        'ponto_referencia' => trim((string) ($post['ponto_referencia'] ?? '')) !== ''
            ? mb_substr(trim((string) ($post['ponto_referencia'] ?? '')), 0, 255) : null,
        'os_cep' => trim((string) ($post['os_cep'] ?? '')) !== ''
            ? mb_substr(trim((string) ($post['os_cep'] ?? '')), 0, 12) : null,
        'os_logradouro' => trim((string) ($post['os_logradouro'] ?? '')) !== ''
            ? mb_substr(trim((string) ($post['os_logradouro'] ?? '')), 0, 255) : null,
        'os_numero' => trim((string) ($post['os_numero'] ?? '')) !== ''
            ? mb_substr(trim((string) ($post['os_numero'] ?? '')), 0, 32) : null,
        'os_complemento' => trim((string) ($post['os_complemento'] ?? '')) !== ''
            ? mb_substr(trim((string) ($post['os_complemento'] ?? '')), 0, 160) : null,
        'os_bairro' => trim((string) ($post['os_bairro'] ?? '')) !== ''
            ? mb_substr(trim((string) ($post['os_bairro'] ?? '')), 0, 120) : null,
        'os_cidade' => trim((string) ($post['os_cidade'] ?? '')) !== ''
            ? mb_substr(trim((string) ($post['os_cidade'] ?? '')), 0, 160) : null,
        'os_uf' => trim((string) ($post['os_uf'] ?? '')) !== ''
            ? mb_substr(strtoupper(trim((string) ($post['os_uf'] ?? ''))), 0, 2) : null,
    ];
}

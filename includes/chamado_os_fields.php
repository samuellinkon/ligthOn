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
        'Rede Ipojuca' => 'Rede Ipojuca',
        'Outro' => 'Outro',
    ];
}

/** Valor legado «Portal» → rótulo atual; demais valores das opções ou texto bruto. */
function chamado_os_rotulo_origem(?string $valor): string
{
    $v = trim((string) $valor);
    if ($v === 'Portal') {
        return 'Rede Ipojuca';
    }
    $opts = chamado_os_opcoes_origem();

    return $opts[$v] ?? ($v !== '' ? $v : '—');
}

/** Normaliza origem armazenada para comparar com as opções do select. */
function chamado_os_origem_valor_form(?string $valor): string
{
    $v = trim((string) $valor);

    return $v === 'Portal' ? 'Rede Ipojuca' : $v;
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

/** Chamado criado pela importação do relatório / boletim de medição. */
function chamado_eh_origem_importacao_bm(?array $chamado): bool
{
    return trim((string) ($chamado['origem_os'] ?? '')) === 'Importação BM';
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
 * Extrai campos OS estruturados a partir do endereço textual do ponto (heurística BR).
 *
 * @return array{os_cep:string,os_logradouro:string,os_numero:string,os_complemento:string,os_bairro:string,os_cidade:string,os_uf:string}
 */
function chamado_os_endereco_estruturado_from_texto(string $endereco, ?string $bairroPonto = null): array
{
    $out = [
        'os_cep'          => '',
        'os_logradouro'   => '',
        'os_numero'       => '',
        'os_complemento'  => '',
        'os_bairro'       => '',
        'os_cidade'       => '',
        'os_uf'           => '',
    ];
    $s = trim($endereco);
    if ($s === '') {
        if ($bairroPonto !== null && trim($bairroPonto) !== '') {
            $out['os_bairro'] = trim($bairroPonto);
        }

        return $out;
    }
    if (preg_match('/,?\s*(\d{5})-?(\d{3})\s*$/u', $s, $m)) {
        $out['os_cep'] = $m[1] . '-' . $m[2];
        $s = trim((string) preg_replace('/,?\s*\d{5}-?\d{3}\s*$/u', '', $s));
    }
    if (preg_match('/,\s*([^,]+?)\s*-\s*([A-Za-z]{2})\s*$/u', $s, $m)) {
        $out['os_cidade'] = trim($m[1]);
        $out['os_uf']     = strtoupper(substr(trim($m[2]), 0, 2));
        $s = trim((string) preg_replace('/,\s*[^,]+?\s*-\s*[A-Za-z]{2}\s*$/u', '', $s));
    }
    if (preg_match('/^(.+?),\s*(s\/n|sn)\s*(?:-\s*(.+))?$/iu', $s, $m)) {
        $out['os_logradouro'] = trim($m[1]);
        $bairroInline        = trim((string) ($m[3] ?? ''));
        if ($bairroInline !== '') {
            $out['os_bairro'] = $bairroInline;
        }
    } elseif (preg_match('/^(.+?),\s*([^,]+?)\s*-\s*(.+)$/u', $s, $m)) {
        $out['os_logradouro'] = trim($m[1]);
        $mid                 = trim($m[2]);
        if (preg_match('/^\d+[\w\-\/]*$/u', $mid)) {
            $out['os_numero'] = $mid;
            $out['os_bairro'] = trim($m[3]);
        } else {
            $out['os_bairro'] = $mid . ' - ' . trim($m[3]);
        }
    } elseif (preg_match('/^(.+?),\s*(\d+[\w\-\/]*)\s*$/u', $s, $m)) {
        $out['os_logradouro'] = trim($m[1]);
        $out['os_numero']     = trim($m[2]);
    } else {
        $out['os_logradouro'] = $s;
    }
    if ($bairroPonto !== null && trim($bairroPonto) !== '' && $out['os_bairro'] === '') {
        $out['os_bairro'] = trim($bairroPonto);
    }

    return $out;
}

/**
 * Payload para preencher o formulário / gravar chamado a partir do cadastro do ponto.
 *
 * @param array<string, mixed> $ponto
 * @return array<string, mixed>
 */
function chamado_os_fill_payload_from_ponto(array $ponto): array
{
    $endP = trim((string) ($ponto['endereco_completo'] ?? ''));
    $estr = chamado_os_endereco_estruturado_from_texto($endP, trim((string) ($ponto['bairro'] ?? '')) ?: null);
    $out  = $estr;
    $ref  = trim((string) ($ponto['referencia'] ?? ''));
    if ($ref !== '') {
        $out['ponto_referencia'] = $ref;
    }
    if (isset($ponto['latitude']) && $ponto['latitude'] !== null && $ponto['latitude'] !== '') {
        $out['latitude'] = (float) $ponto['latitude'];
    }
    if (isset($ponto['longitude']) && $ponto['longitude'] !== null && $ponto['longitude'] !== '') {
        $out['longitude'] = (float) $ponto['longitude'];
    }
    if ($endP !== '') {
        $out['endereco_completo'] = $endP;
    }

    return $out;
}

/**
 * Mescla dados do ponto no array do chamado (POST ou gravação).
 *
 * @param array<string, mixed> $d
 * @param array<string, mixed>|null $ponto
 * @param bool $somente_vazios se true, não sobrescreve campos já preenchidos no $d
 * @return array<string, mixed>
 */
function chamado_os_aplicar_dados_ponto(array $d, ?array $ponto, bool $somente_vazios = true): array
{
    if (!$ponto) {
        return $d;
    }
    $fill = chamado_os_fill_payload_from_ponto($ponto);
    foreach ($fill as $k => $v) {
        if ($v === null || $v === '') {
            continue;
        }
        if ($somente_vazios) {
            $cur = $d[$k] ?? null;
            if (is_string($cur)) {
                if (trim($cur) !== '') {
                    continue;
                }
            } elseif ($cur !== null && $cur !== '') {
                continue;
            }
        }
        $d[$k] = $v;
    }

    return $d;
}

/**
 * Indica se o chamado já tem endereço textual (campo único ou campos OS estruturados).
 *
 * @param array<string, mixed> $ch
 */
function chamado_os_endereco_ja_cadastrado(array $ch): bool
{
    return chamado_tem_endereco_cadastrado($ch, null);
}

/**
 * Endereço exibível: chamado → campos OS compostos → cadastro do ponto vinculado.
 *
 * @param array<string, mixed> $ch
 * @param array<string, mixed>|null $ponto
 */
function chamado_endereco_efetivo(array $ch, ?array $ponto = null): string
{
    $full = trim((string) ($ch['endereco_completo'] ?? ''));
    if ($full !== '') {
        return $full;
    }
    $comp = chamado_os_compor_endereco_completo($ch);
    if ($comp !== null && trim($comp) !== '') {
        return trim($comp);
    }
    if ($ponto !== null) {
        $endP = trim((string) ($ponto['endereco_completo'] ?? ''));
        if ($endP !== '') {
            return $endP;
        }
    }

    return '';
}

/**
 * Indica se há endereço no chamado ou no ponto vinculado (para UI e sync).
 *
 * @param array<string, mixed> $ch
 * @param array<string, mixed>|null $ponto
 */
function chamado_tem_endereco_cadastrado(array $ch, ?array $ponto = null): bool
{
    return chamado_endereco_efetivo($ch, $ponto) !== '';
}

/**
 * Coordenadas efetivas: chamado → ponto vinculado.
 *
 * @param array<string, mixed> $ch
 * @param array<string, mixed>|null $ponto
 * @return array{0: float|null, 1: float|null}
 */
function chamado_coordenadas_efetivas(array $ch, ?array $ponto = null): array
{
    require_once __DIR__ . '/chamado_geo.php';
    [$cla, $clo] = chamado_geo_row_latlng($ch);
    if ($cla !== null && $clo !== null) {
        return [$cla, $clo];
    }
    if ($ponto !== null) {
        [$pla, $plo] = chamado_geo_row_latlng($ponto);
        if ($pla !== null && $plo !== null) {
            return [$pla, $plo];
        }
    }

    return [null, null];
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

/** Operador não pode alterar itens/fotos após encerramento pelo gestor. */
function operador_chamado_materiais_fotos_bloqueados(array $ch): bool
{
    return in_array((string) ($ch['status'] ?? ''), ['Resolvido', 'Validado', 'Fechado', 'Cancelado'], true);
}

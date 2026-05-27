<?php
declare(strict_types=1);

/**
 * Rótulo legível para referência YYYY-MM (ex.: abril de 2026).
 */
function medicao_mes_label_pt(string $ym): string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
        return $ym;
    }
    $nomes = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho',
        7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];
    $mi = (int) $m[2];

    return ($nomes[$mi] ?? $m[2]) . ' de ' . $m[1];
}

/**
 * Converte linhas gravadas da importação BM no formato usado pela tabela «Chamados no período».
 *
 * @param list<array<string,mixed>> $impLinhas
 * @return list<array<string,mixed>>
 */
function medicao_import_linhas_como_chamados_view(array $impLinhas): array
{
    $out = [];
    foreach ($impLinhas as $il) {
        $desc = trim((string) ($il['descricao'] ?? ''));
        $cod  = trim((string) ($il['item_codigo'] ?? ''));
        $un   = trim((string) ($il['unidade'] ?? ''));
        $v    = (float) ($il['valor_medido_periodo'] ?? 0);
        $qtd  = $il['qtd_medido_periodo'] ?? null;

        $cat = $cod;
        if ($un !== '') {
            $cat .= ($cat !== '' ? ' · ' : '') . $un;
        }
        if ($qtd !== null && $qtd !== '') {
            $cat .= ($cat !== '' ? ' · ' : '') . 'Qtd ' . (string) $qtd;
        }

        $out[] = [
            'id'                         => 0,
            'aberto_em_br'               => '—',
            'unidade_nome'               => '—',
            'titulo'                     => $desc !== '' ? $desc : ($cod !== '' ? 'Item ' . $cod : 'Linha importada'),
            'status'                     => 'Medição (BM)',
            'prioridade'                 => '—',
            'tecnico_nome'               => '—',
            'servico_principal_nome'     => $cat !== '' ? $cat : '—',
            'valor_materiais'            => 0.0,
            'valor_servicos_itens'       => $v,
            'valor_total_linha'          => $v,
            'medicao_bm_item_codigo'     => $cod,
        ];
    }

    return $out;
}

/**
 * @param array{n_chamados:int,valor_materiais:float,valor_servicos:float,valor_total:float} $tot
 * @param list<array<string,mixed>> $impLinhas
 * @return array{n_chamados:int,valor_materiais:float,valor_servicos:float,valor_total:float}
 */
function medicao_tot_resumo_com_import_bm(array $tot, array $impLinhas): array
{
    if ((int) ($tot['n_chamados'] ?? 0) > 0) {
        return $tot;
    }
    if ($impLinhas === []) {
        return $tot;
    }
    $vm = 0.0;
    foreach ($impLinhas as $il) {
        $vm += (float) ($il['valor_medido_periodo'] ?? 0);
    }

    return [
        'n_chamados'      => count($impLinhas),
        'valor_materiais' => 0.0,
        'valor_servicos'  => $vm,
        'valor_total'     => $vm,
    ];
}

/**
 * Inclui custos adicionais aprovados no total do BM (telas e listagem mensal).
 *
 * @param array{n_chamados:int,valor_materiais:float,valor_servicos:float,valor_total:float} $tot
 * @return array{n_chamados:int,valor_materiais:float,valor_servicos:float,valor_total:float,valor_custos_adicionais:float}
 */
function medicao_totais_incluir_custos_aprovados(array $tot, int $matrizId, string $refYm): array
{
    $custos = 0.0;
    if ($matrizId > 0 && preg_match('/^\d{4}-\d{2}$/', $refYm) && function_exists('repo_medicao_custos_valor_aprovado')) {
        $custos = repo_medicao_custos_valor_aprovado($matrizId, $refYm);
    }
    $tot['valor_custos_adicionais'] = $custos;
    $tot['valor_total']             = round((float) ($tot['valor_total'] ?? 0) + $custos, 2);

    return $tot;
}

/**
 * @param list<array<string,mixed>> $linhasChamados
 * @param list<array<string,mixed>> $impLinhas
 * @return list<array<string,mixed>>
 */
function medicao_linhas_exibicao_mes(array $linhasChamados, array $impLinhas): array
{
    if ($linhasChamados !== []) {
        return $linhasChamados;
    }
    if ($impLinhas === []) {
        return [];
    }

    return medicao_import_linhas_como_chamados_view($impLinhas);
}

/**
 * @param list<array<string,mixed>> $linhasChamados
 * @param list<array<string,mixed>> $impLinhas
 */
function medicao_listagem_so_import_bm(array $linhasChamados, array $impLinhas): bool
{
    return $linhasChamados === [] && $impLinhas !== [];
}

/**
 * Converte linhas gravadas da importação BM em registos «tipo chamado» para listagens (admin/cliente).
 * Não cria chamados na base — só exibição, alinhada ao boletim do mês.
 *
 * @param list<array<string,mixed>> $impLinhas
 * @return list<array<string,mixed>>
 */
function medicao_import_linhas_para_chamados_listagem(array $impLinhas, string $ymRef, string $empresaNome, string $qFiltro): array
{
    $qFiltro = trim($qFiltro);
    $out     = [];
    $dataRef = $ymRef . '-01 12:00';
    foreach ($impLinhas as $il) {
        $lid  = (int) ($il['linha_id'] ?? 0);
        $desc = trim((string) ($il['descricao'] ?? ''));
        $cod  = trim((string) ($il['item_codigo'] ?? ''));
        $un   = trim((string) ($il['unidade'] ?? ''));
        $v    = (float) ($il['valor_medido_periodo'] ?? 0);
        $qtd  = $il['qtd_medido_periodo'] ?? null;

        if ($qFiltro !== '') {
            if (ctype_digit($qFiltro)) {
                if ($lid !== (int) $qFiltro) {
                    continue;
                }
            } else {
                $ql = mb_strtolower($qFiltro);
                $hay = mb_strtolower($desc . ' ' . $cod . ' ' . $un);
                if (mb_strpos($hay, $ql) === false) {
                    continue;
                }
            }
        }

        $titulo = $desc !== '' ? $desc : ($cod !== '' ? 'Medição BM · item ' . $cod : 'Medição BM (linha)');
        $partes = ['Serviço / medição importada (planilha BM)'];
        if ($cod !== '') {
            $partes[] = 'Código: ' . $cod;
        }
        if ($un !== '') {
            $partes[] = 'Unidade: ' . $un;
        }
        if ($qtd !== null && $qtd !== '') {
            $partes[] = 'Qtd: ' . (string) $qtd;
        }
        $partes[] = 'Valor no período: R$ ' . number_format($v, 2, ',', '.');
        $resumo   = implode(' · ', $partes);

        $out[] = [
            'medicao_bm'            => true,
            'medicao_bm_linha_id'   => $lid,
            'id'                    => 0,
            'cliente_id'           => 0,
            'titulo'                => $titulo,
            'descricao'             => $resumo,
            'cliente'               => $empresaNome,
            'prioridade'            => 'Normal',
            'status'                => 'Medição (BM)',
            'data'                  => $dataRef,
            'endereco_completo'     => '',
            'latitude'              => null,
            'longitude'             => null,
            'tecnico_nome'          => '—',
            'responsavel'           => '—',
            'finalizado_operador_em' => null,
            'aprovado_gestor_em'    => null,
            'medicao_bm_resumo'     => $resumo,
            'medicao_bm_ref_ym'      => $ymRef,
            'servico_nome'          => $cod !== '' ? 'Item ' . $cod : 'Medição BM',
            'servico_tipo'          => 'servico',
        ];
    }

    return $out;
}

/**
 * Texto usado nos cabeçalhos de coluna BM (ex.: MAI/2026) para alinhar export/import ao mês da medição.
 */
/**
 * Converte serial de data do Excel ou texto dd/mm/aaaa para Y-m-d.
 */
function medicao_planilha_data_para_ymd(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $raw, $m)) {
        $d = (int) $m[1];
        $mo = (int) $m[2];
        $y = (int) $m[3];
        if (checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }

        return null;
    }
    if (preg_match('/^\d{4,6}$/', $raw)) {
        $serial = (int) $raw;
        if ($serial >= 30000 && $serial <= 80000) {
            $base = new DateTimeImmutable('1899-12-30');
            try {
                $dt = $base->modify('+' . $serial . ' days');

                return $dt->format('Y-m-d');
            } catch (Throwable $e) {
                return null;
            }
        }
    }

    return null;
}

function medicao_bm_needle_periodo_planilha(string $refYm): ?string
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $refYm, $m)) {
        return null;
    }
    $siglas = [
        1 => 'JAN', 2 => 'FEV', 3 => 'MAR', 4 => 'ABR', 5 => 'MAI', 6 => 'JUN',
        7 => 'JUL', 8 => 'AGO', 9 => 'SET', 10 => 'OUT', 11 => 'NOV', 12 => 'DEZ',
    ];
    $mo = (int) $m[2];
    $y  = (int) $m[1];
    $s  = $siglas[$mo] ?? '';
    if ($s === '') {
        return null;
    }

    return $s . '/' . $y;
}

/**
 * Data final efectiva ao exportar o boletim BM v2 dentro do mesmo mês civil (`AAAA-MM`): hoje até o fim do mês, ou sempre o último dia em meses passados.
 */
function medicao_bm_export_v2_periodo_ate(string $refYm): string
{
    $primeiro = $refYm . '-01';
    $ts       = strtotime($primeiro);
    if ($ts === false) {
        return $refYm . '-01';
    }
    $last = date('Y-m-t', $ts);

    return date('Y-m') === $refYm ? min(date('Y-m-d'), $last) : $last;
}

/**
 * Data inicial mínima permitida no boletim BM v2 (início de período livre):
 * primeiro dia do mês civil 5 meses antes do mês de referência (janela de até 6 meses até o fecho do BM).
 */
function medicao_bm_export_v2_periodo_de_min(string $refYm): string
{
    $primeiro = $refYm . '-01';
    $ts       = strtotime($primeiro . ' -5 months');
    if ($ts === false) {
        return $primeiro;
    }

    return date('Y-m-01', $ts);
}

/**
 * Formata Y-m-d para dd/mm/YYYY (exportação / UI).
 */
function medicao_data_br(string $ymd): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
        return $ymd;
    }
    $ts = strtotime($ymd);

    return $ts !== false ? date('d/m/Y', $ts) : $ymd;
}

/** Faixa curta para cards de medição: 01/05 → 25/05 */
function medicao_periodo_faixa_curta(string $de, string $ate): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) {
        return '—';
    }
    $tsDe  = strtotime($de);
    $tsAte = strtotime($ate);
    if ($tsDe === false || $tsAte === false) {
        return '—';
    }

    return date('d/m', $tsDe) . ' → ' . date('d/m', $tsAte);
}

/**
 * Rótulo de período para PDF/XLSX com datas explícitas.
 */
function medicao_periodo_export_label(?string $de, ?string $ate, string $mesRef = ''): string
{
    $de  = $de !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($de)) ? trim($de) : '';
    $ate = $ate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($ate)) ? trim($ate) : '';

    if ($de !== '' && $ate !== '') {
        return 'Data início: ' . medicao_data_br($de) . ' · Data fim: ' . medicao_data_br($ate);
    }
    if ($de !== '') {
        return 'Data início: ' . medicao_data_br($de);
    }
    if ($ate !== '') {
        return 'Data fim: ' . medicao_data_br($ate);
    }
    if ($mesRef !== '' && preg_match('/^\d{4}-\d{2}$/', $mesRef)) {
        return 'Mês ' . medicao_mes_label_pt($mesRef);
    }

    return '—';
}

/**
 * Rótulo curto do intervalo (dd/mm/YYYY a dd/mm/YYYY).
 */
function medicao_periodo_label_curto(?string $de, ?string $ate): string
{
    $de  = $de !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($de)) ? trim($de) : '';
    $ate = $ate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($ate)) ? trim($ate) : '';

    if ($de !== '' && $ate !== '') {
        return medicao_data_br($de) . ' a ' . medicao_data_br($ate);
    }
    if ($de !== '') {
        return 'a partir de ' . medicao_data_br($de);
    }
    if ($ate !== '') {
        return 'até ' . medicao_data_br($ate);
    }

    return '—';
}

/**
 * Resolve intervalo de filtro para uma linha de medição (mês de referência + datas opcionais).
 *
 * @return array{ok: bool, err: string, de: string, ate: string, label: string, label_curto: string}
 */
function medicao_resolve_periodo_filtro(string $mesRef, string $dataInicio = '', string $dataFim = ''): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $mesRef)) {
        return [
            'ok'          => false,
            'err'         => 'Mês de referência inválido.',
            'de'          => '',
            'ate'         => '',
            'label'       => '—',
            'label_curto' => '—',
        ];
    }

    $deDefault  = $mesRef . '-01';
    $ateDefault = medicao_bm_export_v2_periodo_ate($mesRef);
    $dataInicio = trim($dataInicio);
    $dataFim    = trim($dataFim);

    $de  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio) ? $dataInicio : $deDefault;
    $ate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim) ? $dataFim : $ateDefault;

    if ($de > $ate) {
        return [
            'ok'          => false,
            'err'         => 'A data início não pode ser posterior à data fim.',
            'de'          => $de,
            'ate'         => $ate,
            'label'       => '—',
            'label_curto' => '—',
        ];
    }

    return [
        'ok'          => true,
        'err'         => '',
        'de'          => $de,
        'ate'         => $ate,
        'label'       => medicao_periodo_export_label($de, $ate, $mesRef),
        'label_curto' => medicao_periodo_label_curto($de, $ate),
    ];
}

/**
 * Resolve período quando não há mês de medição (só datas na listagem de chamados).
 *
 * @return array{ok: bool, err: string, de: ?string, ate: ?string}
 */
function medicao_resolve_periodo_livre(string $dataInicio = '', string $dataFim = ''): array
{
    $dataInicio = trim($dataInicio);
    $dataFim    = trim($dataFim);
    $hasDe      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio);
    $hasAte     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim);

    if (!$hasDe && !$hasAte) {
        return ['ok' => true, 'err' => '', 'de' => null, 'ate' => null];
    }

    $hoje = date('Y-m-d');
    $de   = $hasDe ? $dataInicio : date('Y-m-01');
    $ate  = $hasAte ? $dataFim : $hoje;

    if ($de > $ate) {
        return [
            'ok'  => false,
            'err' => 'A data início não pode ser posterior à data fim.',
            'de'  => $de,
            'ate' => $ate,
        ];
    }

    return ['ok' => true, 'err' => '', 'de' => $de, 'ate' => $ate];
}

/**
 * Classificação orçamentária pelo código do item na planilha BM.
 * Prefixo 2. = serviço; prefixo 3. = material (produto no catálogo).
 *
 * @return 'produto'|'servico'|null
 */
function medicao_item_tipo_por_codigo_orcamento(string $codigo): ?string
{
    $c = trim($codigo);
    if ($c === '') {
        return null;
    }
    if (preg_match('/^3\./u', $c)) {
        return 'produto';
    }
    if (preg_match('/^2\./u', $c)) {
        return 'servico';
    }

    return null;
}

/** Normaliza código de item para união BM / CRM (maiúsculas, trim). */
function medicao_bm_boletim_norm_cod(?string $cod): string
{
    return strtoupper(trim((string) $cod));
}

/** Garante chave string (evita int em arrays PHP quando o código é só numérico). */
function medicao_bm_boletim_key_string(mixed $k): string
{
    return (string) $k;
}

/**
 * Chave estável para indexar linhas BM/CRM/catálogo.
 * Códigos só dígitos viram COD:123 para não virar chave int no array.
 */
function medicao_bm_boletim_key_from_cod(string $normCod, int $fallbackItemId = 0): string
{
    $n = medicao_bm_boletim_norm_cod($normCod);
    if ($n === '') {
        return $fallbackItemId > 0 ? 'ID:' . $fallbackItemId : '';
    }
    if (ctype_digit($n)) {
        return 'COD:' . $n;
    }

    return $n;
}

/** Código de exibição / lookup a partir da chave interna. */
function medicao_bm_boletim_cod_from_key(string $k): string
{
    $k = medicao_bm_boletim_key_string($k);
    if (strpos($k, 'COD:') === 0) {
        return substr($k, 4);
    }
    if (strpos($k, 'MIL:') === 0 || strpos($k, 'ID:') === 0) {
        return '';
    }

    return $k;
}

/**
 * Comparação para ordenar itens do BM pelo id do catálogo (cliente_itens.id), menor → maior.
 */
function medicao_bm_boletim_cmp_item_id_codigo(int $idA, int $idB, string $codA, string $codB): int
{
    if ($idA > 0 && $idB > 0) {
        $c = $idA <=> $idB;
        if ($c !== 0) {
            return $c;
        }
    } elseif ($idA > 0) {
        return -1;
    } elseif ($idB > 0) {
        return 1;
    }

    $codA = trim($codA);
    $codB = trim($codB);
    if ($codA !== '' && $codB !== '') {
        $n = strnatcasecmp($codA, $codB);
        if ($n !== 0) {
            return $n;
        }
    } elseif ($codA !== '') {
        return -1;
    } elseif ($codB !== '') {
        return 1;
    }

    return 0;
}

/**
 * Id do catálogo para ordenação de linhas/chaves do boletim BM.
 *
 * @param array<string,mixed>|null $catLinha
 * @param array<string,mixed>|null $crmLine
 * @param array<string,mixed>|null $ilLinhaImport
 */
function medicao_bm_boletim_item_id_ordenacao(
    string $bmKey,
    ?array $catLinha,
    ?array $crmLine,
    ?array $ilLinhaImport = null
): int {
    if ($catLinha !== null) {
        $id = (int) ($catLinha['item_id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
    }
    if ($crmLine !== null) {
        $id = (int) ($crmLine['item_id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
    }
    if ($ilLinhaImport !== null) {
        $id = (int) ($ilLinhaImport['item_id'] ?? $ilLinhaImport['cliente_item_id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
    }
    $k = medicao_bm_boletim_key_string($bmKey);
    if (strpos($k, 'ID:') === 0) {
        $id = (int) substr($k, strlen('ID:'));
        if ($id > 0) {
            return $id;
        }
    }

    return PHP_INT_MAX;
}

/**
 * Ordena chaves do boletim BM v2 pelo id do item no catálogo (crescente).
 *
 * @param list<string> $keys
 * @param array<string, array<string,mixed>> $catPorCodNorm
 * @param array<string, array<string,mixed>> $crmPorKey
 * @param array<string, array<string,mixed>> $importPorCodNorm
 *
 * @return list<string>
 */
function medicao_bm_boletim_ordenar_keys_por_item_id(
    array $keys,
    array $catPorCodNorm,
    array $crmPorKey,
    array $importPorCodNorm = []
): array {
    if ($keys === []) {
        return [];
    }

    $meta = [];
    foreach ($keys as $kRaw) {
        $k = medicao_bm_boletim_key_string($kRaw);
        $meta[] = [
            'key' => $k,
            'id'  => medicao_bm_boletim_item_id_ordenacao(
                $k,
                $catPorCodNorm[$k] ?? null,
                $crmPorKey[$k] ?? null,
                $importPorCodNorm[$k] ?? null
            ),
            'cod' => medicao_bm_boletim_cod_from_key($k),
        ];
    }

    usort($meta, static function (array $a, array $b): int {
        $c = medicao_bm_boletim_cmp_item_id_codigo((int) $a['id'], (int) $b['id'], (string) $a['cod'], (string) $b['cod']);
        if ($c !== 0) {
            return $c;
        }

        return strcmp((string) $a['key'], (string) $b['key']);
    });

    return array_values(array_map(static fn (array $m): string => (string) $m['key'], $meta));
}

/**
 * Ordena linhas do detalhamento institucional (BM completo) pelo id do item no catálogo.
 *
 * @param array<string,mixed> $a
 * @param array<string,mixed> $b
 */
function medicao_bm_boletim_cmp_detalhe_linha(array $a, array $b): int
{
    return medicao_bm_boletim_cmp_item_id_codigo(
        (int) ($a['item_id_catalogo'] ?? 0),
        (int) ($b['item_id_catalogo'] ?? 0),
        trim((string) ($a['item_codigo'] ?? '')),
        trim((string) ($b['item_codigo'] ?? ''))
    );
}

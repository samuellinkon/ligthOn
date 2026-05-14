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

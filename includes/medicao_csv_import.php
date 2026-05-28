<?php
declare(strict_types=1);

require_once __DIR__ . '/medicao_helpers.php';

function medicao_csv_row_is_relatorio_header_row(array $row): bool
{
    $hasData = false;
    $hasCodigo = false;
    $hasDescItens = false;
    $hasQtd = false;
    $hasValorTotal = false;
    foreach ($row as $c) {
        $u = medicao_csv_header_norm_cell_for_match((string) $c);
        if ($u === '') {
            continue;
        }
        if (str_starts_with($u, 'DATA')) {
            $hasData = true;
        }
        if ($u === 'CODIGO') {
            $hasCodigo = true;
        }
        if (str_contains($u, 'DESCRI') && str_contains($u, 'ITEN')) {
            $hasDescItens = true;
        }
        if (preg_match('/^QTD/', $u)) {
            $hasQtd = true;
        }
        if (str_contains($u, 'VALOR') && str_contains($u, 'TOTAL')) {
            $hasValorTotal = true;
        }
    }

    return $hasData && $hasCodigo && $hasDescItens && $hasQtd && $hasValorTotal;
}

/**
 * @return array<string,int|null>
 */
function medicao_csv_map_relatorio_header_row(array $row): ?array
{
    $m = [
        'col_data'    => null,
        'col_proto'   => null,
        'col_codigo'  => null,
        'col_desc'    => null,
        'col_qtd'     => null,
        'col_vtot'    => null,
        'col_vunit'   => null,
        'col_un'      => null,
        'col_bairro'  => null,
        'col_rua'     => null,
        'col_tipo'    => null,
        'col_equipe'  => null,
        'col_lat'     => null,
        'col_lng'     => null,
        'col_plaqueta'=> null,
        'col_servicos'=> null,
    ];
    foreach ($row as $j => $c) {
        $u = medicao_csv_header_norm_cell_for_match((string) $c);
        if ($u === '') {
            continue;
        }
        if (str_starts_with($u, 'DATA')) {
            $m['col_data'] = $j;
        } elseif (preg_match('/PROTOCOLO|PROCOCOLO|^ID$/', $u)) {
            $m['col_proto'] = $j;
        } elseif ($u === 'CODIGO') {
            $m['col_codigo'] = $j;
        } elseif (str_contains($u, 'DESCRI') && str_contains($u, 'ITEN')) {
            $m['col_desc'] = $j;
        } elseif (preg_match('/^QTD/', $u)) {
            $m['col_qtd'] = $j;
        } elseif (str_contains($u, 'VALOR') && str_contains($u, 'UNIT')) {
            $m['col_vunit'] = $j;
        } elseif (str_contains($u, 'VALOR') && str_contains($u, 'TOTAL')) {
            $m['col_vtot'] = $j;
        } elseif ($u === 'UN' || str_starts_with($u, 'UNID')) {
            $m['col_un'] = $j;
        } elseif (str_contains($u, 'COORDENADA') && str_contains($u, 'S')) {
            $m['col_lat'] = $j;
        } elseif (str_contains($u, 'COORDENADA') && (str_contains($u, 'W') || str_contains($u, 'O'))) {
            $m['col_lng'] = $j;
        } elseif (str_contains($u, 'PLAQUETA') || str_contains($u, 'BARRAMENTO')) {
            $m['col_plaqueta'] = $j;
        } elseif (str_contains($u, 'SERVICO') || str_contains($u, 'MATERIAIS')) {
            $m['col_servicos'] = $j;
        } elseif ($u === 'BAIRRO') {
            $m['col_bairro'] = $j;
        } elseif (str_contains($u, 'RUA') || str_contains($u, 'AVENIDA')) {
            $m['col_rua'] = $j;
        } elseif (str_contains($u, 'TIPO') && str_contains($u, 'SERVI')) {
            $m['col_tipo'] = $j;
        } elseif (str_contains($u, 'EQUIPE')) {
            $m['col_equipe'] = $j;
        }
    }
    if ($m['col_codigo'] === null || $m['col_desc'] === null || $m['col_qtd'] === null || $m['col_vtot'] === null) {
        return null;
    }

    return $m;
}

function medicao_csv_find_relatorio_header_row_index(array $rows): int
{
    $last = -1;
    $lim = min(count($rows), 2500);
    for ($i = 0; $i < $lim; $i++) {
        if (medicao_csv_row_is_relatorio_header_row($rows[$i])) {
            $last = $i;
        }
    }

    return $last;
}

/**
 * Formato «RELATÓRIO DETALHADO BM» (uma linha por intervenção).
 *
 * @param list<list<string>> $rows
 * @return array{ok:bool,erro:string,idx_qtd_medido:?int,idx_valor_medido:?int,linhas:list<array<string,mixed>>}
 */
function medicao_csv_try_parse_relatorio_detalhado(array $rows): array
{
    $empty = [
        'ok'               => false,
        'erro'             => '',
        'idx_qtd_medido'   => null,
        'idx_valor_medido' => null,
        'linhas'           => [],
    ];

    $h = medicao_csv_find_relatorio_header_row_index($rows);
    if ($h < 0) {
        return $empty;
    }

    $map = medicao_csv_map_relatorio_header_row($rows[$h]);
    if ($map === null) {
        return array_merge($empty, [
            'erro' => 'Linha de cabeçalho do relatório detalhado incompleta (confira CÓDIGO, DESCRIÇÃO DOS ITENS, QTD. e VALOR TOTAL).',
        ]);
    }

    $linhas = [];
    $n = count($rows);
    for ($r = $h + 1; $r < $n; $r++) {
        $row = $rows[$r];
        $desc = trim((string) ($row[$map['col_desc']] ?? ''));
        if ($desc !== '' && str_starts_with($desc, '=')) {
            continue;
        }

        $proto = $map['col_proto'] !== null ? trim((string) ($row[$map['col_proto']] ?? '')) : '';
        $cod   = trim((string) ($row[$map['col_codigo']] ?? ''));
        $dataT = $map['col_data'] !== null ? trim((string) ($row[$map['col_data']] ?? '')) : '';
        $qtd   = medicao_br_decimal((string) ($row[$map['col_qtd']] ?? ''));
        $vtot  = medicao_br_decimal((string) ($row[$map['col_vtot']] ?? ''));

        if ($desc === '' && $proto === '' && $cod === '' && $vtot === null && $qtd === null) {
            continue;
        }
        if (strtoupper($desc) === 'TOTAL' || strtoupper($proto) === 'TOTAL') {
            continue;
        }

        $parts = array_filter([
            $dataT !== '' ? $dataT : null,
            $proto !== '' ? 'Prot. ' . $proto : null,
            $map['col_bairro'] !== null ? trim((string) ($row[$map['col_bairro']] ?? '')) : null,
            $map['col_rua'] !== null ? trim((string) ($row[$map['col_rua']] ?? '')) : null,
            $map['col_equipe'] !== null ? trim((string) ($row[$map['col_equipe']] ?? '')) : null,
            $map['col_tipo'] !== null ? trim((string) ($row[$map['col_tipo']] ?? '')) : null,
            $desc !== '' ? $desc : null,
        ], static fn ($x) => $x !== null && $x !== '');

        $descricao = implode(' · ', $parts);
        if ($descricao === '') {
            continue;
        }

        $seq = count($linhas) + 1;
        $itemCod = '';
        $pNorm = preg_replace('/\s+/', '', $proto);
        if ($pNorm !== '' && preg_match('/^\d{2,}$/', $pNorm)) {
            $itemCod = $pNorm;
            if ($cod !== '' && mb_strlen($itemCod) < 22) {
                $itemCod .= '-' . preg_replace('/[^\d.\w]/u', '', mb_substr($cod, 0, 12));
            }
        } elseif ($cod !== '' && preg_match('/^[\d.\w]+$/u', $cod)) {
            $itemCod = mb_substr($cod, 0, 32);
        } else {
            $itemCod = 'R' . (string) $seq;
        }
        $itemCod = mb_substr($itemCod, 0, 32);

        $unid = $map['col_un'] !== null ? trim((string) ($row[$map['col_un']] ?? '')) : '';

        $linhas[] = [
            'item_codigo'          => $itemCod,
            'descricao'            => $descricao,
            'unidade'              => $unid,
            'qtd_prevista'         => null,
            'qtd_total'            => null,
            'preco_unitario'       => $map['col_vunit'] !== null ? medicao_br_decimal((string) ($row[$map['col_vunit']] ?? '')) : null,
            'qtd_medido_periodo'   => $qtd,
            'valor_medido_periodo' => $vtot,
        ];
    }

    if ($linhas === []) {
        return array_merge($empty, ['erro' => 'Relatório detalhado: nenhuma linha de dados válida após o cabeçalho.']);
    }

    return [
        'ok'               => true,
        'erro'             => '',
        'idx_qtd_medido'   => $map['col_qtd'],
        'idx_valor_medido' => $map['col_vtot'],
        'linhas'           => $linhas,
        'relatorio_map'    => $map,
        'relatorio_header' => $h,
    ];
}

/**
 * Extrai linhas estruturadas e grupos por protocolo (relatório detalhado).
 *
 * @param list<list<string>> $rows
 * @return array{
 *   ok: bool,
 *   erro: string,
 *   linhas: list<array<string,mixed>>,
 *   grupos: list<array<string,mixed>>,
 *   n_chamados: int,
 *   n_itens: int
 * }
 */
function medicao_relatorio_parse_grupos_chamados(array $rows): array
{
    $empty = [
        'ok'         => false,
        'erro'       => '',
        'linhas'     => [],
        'grupos'     => [],
        'n_chamados' => 0,
        'n_itens'    => 0,
    ];

    $parsed = medicao_csv_try_parse_relatorio_detalhado($rows);
    if (empty($parsed['ok'])) {
        return array_merge($empty, ['erro' => $parsed['erro'] !== '' ? $parsed['erro'] : 'Formato de relatório detalhado não reconhecido.']);
    }

    $h   = (int) ($parsed['relatorio_header'] ?? -1);
    $map = is_array($parsed['relatorio_map'] ?? null) ? $parsed['relatorio_map'] : null;
    if ($h < 0 || $map === null) {
        return array_merge($empty, ['erro' => 'Cabeçalho do relatório detalhado não encontrado.']);
    }

    $grupos = [];
    $n      = count($rows);
    for ($r = $h + 1; $r < $n; $r++) {
        $row = $rows[$r];
        $desc = trim((string) ($row[$map['col_desc']] ?? ''));
        if ($desc === '' || str_starts_with($desc, '=')) {
            continue;
        }

        $proto = $map['col_proto'] !== null ? trim((string) ($row[$map['col_proto']] ?? '')) : '';
        $cod   = trim((string) ($row[$map['col_codigo']] ?? ''));
        $qtd   = medicao_br_decimal((string) ($row[$map['col_qtd']] ?? ''));
        $vtot  = medicao_br_decimal((string) ($row[$map['col_vtot']] ?? ''));
        $vunit = $map['col_vunit'] !== null ? medicao_br_decimal((string) ($row[$map['col_vunit']] ?? '')) : null;
        $unid  = $map['col_un'] !== null ? trim((string) ($row[$map['col_un']] ?? '')) : 'UN';

        if ($cod === '' || $qtd === null || $qtd <= 0) {
            continue;
        }

        $protoKey = $proto !== '' && $proto !== '00' ? $proto : ('LINHA-' . (string) $r);
        if (!isset($grupos[$protoKey])) {
            $dataRaw = $map['col_data'] !== null ? trim((string) ($row[$map['col_data']] ?? '')) : '';
            $latRaw  = $map['col_lat'] !== null ? trim((string) ($row[$map['col_lat']] ?? '')) : '';
            $lngRaw  = $map['col_lng'] !== null ? trim((string) ($row[$map['col_lng']] ?? '')) : '';
            $lat     = is_numeric($latRaw) ? (float) $latRaw : null;
            $lng     = is_numeric($lngRaw) ? (float) $lngRaw : null;
            $bairro  = $map['col_bairro'] !== null ? trim((string) ($row[$map['col_bairro']] ?? '')) : '';
            $rua     = $map['col_rua'] !== null ? trim((string) ($row[$map['col_rua']] ?? '')) : '';
            $equipe  = $map['col_equipe'] !== null ? trim((string) ($row[$map['col_equipe']] ?? '')) : '';
            $tipoOs  = $map['col_tipo'] !== null ? trim((string) ($row[$map['col_tipo']] ?? '')) : '';
            $plaqueta = $map['col_plaqueta'] !== null ? trim((string) ($row[$map['col_plaqueta']] ?? '')) : '';
            $servGrp = $map['col_servicos'] !== null ? trim((string) ($row[$map['col_servicos']] ?? '')) : '';

            $grupos[$protoKey] = [
                'protocolo'      => $protoKey,
                'data_ymd'       => medicao_planilha_data_para_ymd($dataRaw),
                'latitude'       => $lat,
                'longitude'      => $lng,
                'bairro'         => $bairro,
                'rua'            => $rua,
                'equipe'         => $equipe,
                'tipo_os'        => $tipoOs,
                'plaqueta'       => $plaqueta,
                'servicos_grupo' => $servGrp,
                'itens'          => [],
            ];
        }

        $servLinha = $map['col_servicos'] !== null ? trim((string) ($row[$map['col_servicos']] ?? '')) : '';
        $tipoItem  = medicao_relatorio_inferir_tipo_item($cod, $servLinha);

        $grupos[$protoKey]['itens'][] = [
            'codigo'         => $cod,
            'descricao'      => $desc,
            'unidade'        => $unid !== '' ? $unid : 'UN',
            'quantidade'     => $qtd,
            'valor_unitario' => $vunit,
            'valor_total'    => $vtot,
            'tipo'           => $tipoItem,
        ];
    }

    if ($grupos === []) {
        return array_merge($empty, ['erro' => 'Nenhum item válido para criar chamados.']);
    }

    $gruposList = array_values($grupos);
    $nItens     = 0;
    foreach ($gruposList as $g) {
        $nItens += count($g['itens'] ?? []);
    }

    return [
        'ok'         => true,
        'erro'       => '',
        'linhas'     => $parsed['linhas'],
        'grupos'     => $gruposList,
        'n_chamados' => count($gruposList),
        'n_itens'    => $nItens,
    ];
}

function medicao_relatorio_inferir_tipo_item(string $codigo, string $servicosCol): string
{
    if (function_exists('medicao_item_tipo_por_codigo_orcamento')) {
        $porCodigo = medicao_item_tipo_por_codigo_orcamento($codigo);
        if ($porCodigo !== null) {
            return $porCodigo;
        }
    }

    $s = mb_strtoupper($servicosCol, 'UTF-8');
    if (str_contains($s, 'MATERIAL')) {
        return 'produto';
    }
    if (str_contains($s, 'SERVICO') || str_contains($s, 'SERVIÇO')) {
        return 'servico';
    }
    if (preg_match('/^\d+$/', trim($codigo))) {
        return 'produto';
    }

    return 'servico';
}

/**
 * Parser de CSV exportado de planilha BM (Excel → CSV).
 * Formatos suportados:
 * 1) Relatório detalhado BM (aba «RELATÓRIO DETALHADO BM …», ex.: `relatorio_detalhado.xlsx` exportado como CSV):
 *    cabeçalho com DATA, CÓDIGO, DESCRIÇÃO DOS ITENS, QTD., VALOR TOTAL (R$), etc.
 * 2) Matriz / boletim com coluna ITEM e colunas «medido no período» (quantidade e valor).
 *
 * @return array{
 *   ok: bool,
 *   erro: string,
 *   idx_qtd_medido: int|null,
 *   idx_valor_medido: int|null,
 *   linhas: list<array{
 *     item_codigo: string,
 *     descricao: string,
 *     unidade: string,
 *     qtd_prevista: float|null,
 *     qtd_total: float|null,
 *     preco_unitario: float|null,
 *     qtd_medido_periodo: float|null,
 *     valor_medido_periodo: float|null
 *   }>
 * }
 */
function medicao_csv_parse_bm_planilha(string $path, ?string $refYm = null): array
{
    $empty = [
        'ok'                 => false,
        'erro'               => '',
        'idx_qtd_medido'     => null,
        'idx_valor_medido'   => null,
        'linhas'             => [],
    ];

    if (!is_readable($path)) {
        return array_merge($empty, ['erro' => 'Arquivo ilegível.']);
    }

    $full = file_get_contents($path);
    if ($full === false || $full === '') {
        return array_merge($empty, ['erro' => 'Arquivo vazio ou ilegível.']);
    }
    if (str_starts_with($full, "\xEF\xBB\xBF")) {
        $full = substr($full, 3);
    }

    $headLen   = min(524288, strlen($full));
    $delimiter = medicao_csv_sniff_delimiter_best(substr($full, 0, $headLen));

    $rows = [];
    $fp = fopen('php://memory', 'r+b');
    if ($fp === false) {
        return array_merge($empty, ['erro' => 'Memória temporária indisponível.']);
    }
    fwrite($fp, $full);
    rewind($fp);
    while (($row = fgetcsv($fp, 0, $delimiter, '"', '\\')) !== false) {
        $rows[] = array_map(static function ($c): string {
            if (!is_string($c)) {
                return '';
            }
            return trim(str_replace("\xc2\xa0", ' ', $c));
        }, $row);
    }
    fclose($fp);

    if ($rows === []) {
        return array_merge($empty, ['erro' => 'Nenhuma linha no CSV.']);
    }

    return medicao_parse_bm_from_rows($rows, $refYm);
}

/**
 * Texto agregado de cabeçalho (linhas iniciais + linha ITEM) para detetar colunas BM.
 *
 * @param list<list<string>> $rows
 */
function medicao_bm_linhas_texto_cabecalho_coluna(array $rows, int $itemRowIdx, int $colIdx): string
{
    $nRows = count($rows);
    if ($nRows === 0) {
        return '';
    }
    $want = array_unique(array_merge(
        range(0, min(19, $nRows - 1)),
        [$itemRowIdx, min($itemRowIdx + 1, $nRows - 1)]
    ));
    $parts = [];
    foreach ($want as $ri) {
        $c = (string) ($rows[$ri][$colIdx] ?? '');
        if ($c === '') {
            continue;
        }
        $parts[] = medicao_csv_header_norm_cell_for_match($c);
    }

    return trim(implode(' ', $parts));
}

/**
 * @param list<list<string>> $rows
 */
function medicao_bm_linhas_coluna_predominantemente_datas_excel_lixo(array $rows, int $itemRowIdx, int $colIdx): bool
{
    static $ghostNeedles = [
        '31/12/1899', '30/12/1899', '29/12/1899', '00/01/1900', '01/01/1900', '02/01/1900', '03/01/1900',
        '04/01/1900', '05/01/1900', '06/01/1900', '07/01/1900', '08/01/1900', '09/01/1900', '10/01/1900',
        '11/01/1900', '12/01/1900', '13/01/1900', '14/01/1900', '15/01/1900', '16/01/1900',
        '14/02/1900', '28/02/1900', '29/02/1900',
    ];
    $n = 0;
    $g = 0;
    $lim = min(count($rows), $itemRowIdx + 260);
    for ($r = $itemRowIdx + 2; $r < $lim; $r++) {
        $t = trim((string) ($rows[$r][$colIdx] ?? ''));
        if ($t === '' || $t === '—' || $t === '-') {
            continue;
        }
        $n++;
        foreach ($ghostNeedles as $gh) {
            if (str_contains($t, $gh)) {
                $g++;
                break;
            }
        }
    }

    return $n >= 4 && $g >= (int) ceil($n * 0.55);
}

/**
 * Remove colunas de períodos BM antigos (cabeçalho BMnn) e colunas de «datas fantasma» 1899/1900.
 *
 * @param list<list<string>> $rows
 * @return list<list<string>>
 */
function medicao_bm_linhas_remover_colunas_historicas_bm(array $rows, int $itemRowIdx, ?string $refYm): array
{
    $maxCol = 0;
    foreach ($rows as $row) {
        $maxCol = max($maxCol, count($row));
    }
    if ($maxCol < 10) {
        return $rows;
    }

    $needleRaw = ($refYm !== null && preg_match('/^\d{4}-\d{2}$/', $refYm))
        ? medicao_bm_needle_periodo_planilha($refYm)
        : null;
    $needle = ($needleRaw !== null && $needleRaw !== '')
        ? mb_strtoupper((string) $needleRaw, 'UTF-8')
        : null;

    $bmCols = [];
    for ($j = 0; $j < $maxCol; $j++) {
        $h = mb_strtoupper(medicao_bm_linhas_texto_cabecalho_coluna($rows, $itemRowIdx, $j), 'UTF-8');
        if ($h !== '' && preg_match('/\bBM\s*\d+/u', $h)) {
            $bmCols[] = $j;
        }
    }

    $toRemove = [];
    if ($bmCols !== []) {
        if ($needle !== null && $needle !== '') {
            foreach ($bmCols as $j) {
                $h = mb_strtoupper(medicao_bm_linhas_texto_cabecalho_coluna($rows, $itemRowIdx, $j), 'UTF-8');
                if (!str_contains($h, $needle)) {
                    $toRemove[] = $j;
                }
            }
        } else {
            $keepJ = max($bmCols);
            foreach ($bmCols as $j) {
                if ($j !== $keepJ) {
                    $toRemove[] = $j;
                }
            }
        }
    }

    for ($j = 0; $j < $maxCol; $j++) {
        if (in_array($j, $toRemove, true)) {
            continue;
        }
        $h = mb_strtoupper(medicao_bm_linhas_texto_cabecalho_coluna($rows, $itemRowIdx, $j), 'UTF-8');
        if (preg_match('/\bBM\s*\d+/u', $h)) {
            continue;
        }
        if ($h !== '' && (
            str_contains($h, 'ITEM') || str_contains($h, 'DESCRI') || str_contains($h, 'UNID')
            || str_contains($h, 'PRECO') || str_contains($h, 'PREC') || str_contains($h, 'QUANT')
            || str_contains($h, 'TERMO') || str_contains($h, 'EXECUTADO') || str_contains($h, 'MEDIDO')
            || str_contains($h, 'VALOR') || str_contains($h, 'ADIT')
        )) {
            continue;
        }
        if (medicao_bm_linhas_coluna_predominantemente_datas_excel_lixo($rows, $itemRowIdx, $j)) {
            $toRemove[] = $j;
        }
    }

    $toRemove = array_values(array_unique($toRemove));
    if ($toRemove === []) {
        return $rows;
    }
    rsort($toRemove);
    foreach ($rows as $i => $_row) {
        foreach ($toRemove as $j) {
            if ($j < count($rows[$i])) {
                array_splice($rows[$i], $j, 1);
            }
        }
    }

    return $rows;
}

/**
 * Interpreta linhas já normalizadas (CSV ou Excel) nos formatos relatório detalhado ou matriz BM.
 *
 * @param list<list<string>> $rows
 * @return array{ok:bool,erro:string,idx_qtd_medido:?int,idx_valor_medido:?int,linhas:list<array<string,mixed>>}
 */
function medicao_parse_bm_from_rows(array $rows, ?string $refYm = null): array
{
    $empty = [
        'ok'                 => false,
        'erro'               => '',
        'idx_qtd_medido'     => null,
        'idx_valor_medido'   => null,
        'linhas'             => [],
    ];

    if ($rows === []) {
        return array_merge($empty, ['erro' => 'Nenhuma linha no arquivo.']);
    }

    $maxCols = 0;
    foreach ($rows as $r) {
        $maxCols = max($maxCols, count($r));
    }
    foreach ($rows as &$r) {
        $r = array_pad($r, $maxCols, '');
        $r = array_map(static function ($c): string {
            if (!is_string($c)) {
                return '';
            }
            return trim(str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $c));
        }, $r);
    }
    unset($r);

    $rel = medicao_csv_try_parse_relatorio_detalhado($rows);
    if (!empty($rel['ok'])) {
        return $rel;
    }
    if (($rel['erro'] ?? '') !== '') {
        return $rel;
    }

    $itemRowIdx = -1;
    $itemCol    = -1;
    foreach ($rows as $i => $row) {
        foreach ($row as $j => $c) {
            if (medicao_csv_header_norm($c) === 'ITEM') {
                $itemRowIdx = $i;
                $itemCol    = $j;
                break 2;
            }
        }
    }

    if ($itemRowIdx < 0 || $itemCol < 0) {
        return array_merge($empty, ['erro' => 'Não reconhecido como relatório detalhado BM nem como matriz com coluna ITEM. Exporte a aba «RELATÓRIO DETALHADO BM» ou a matriz em CSV/Excel.']);
    }

    $rows = medicao_bm_linhas_remover_colunas_historicas_bm($rows, $itemRowIdx, $refYm);
    $maxCols = 0;
    foreach ($rows as $r) {
        $maxCols = max($maxCols, count($r));
    }
    foreach ($rows as &$r) {
        $r = array_pad($r, $maxCols, '');
    }
    unset($r);

    $headerRow = $rows[$itemRowIdx];
    $descCol   = medicao_csv_find_col_header($headerRow, static function (string $u): bool {
        return str_starts_with($u, 'DESCRI');
    });
    $unidCol = medicao_csv_find_col_header($headerRow, static function (string $u): bool {
        return str_starts_with($u, 'UNID');
    });

    $picked = medicao_csv_pick_medido_columns($rows, $itemRowIdx, $itemCol);
    $subRowIdx = $picked['subRowIdx'];
    $idxQtd    = $picked['idxQtd'];
    $idxValor  = $picked['idxValor'];

    if ($subRowIdx < 0 || $idxQtd === null || $idxValor === null) {
        return array_merge($empty, ['erro' => 'Não foi possível localizar colunas de quantidade/valor «medido no período» (cabeçalhos MEDIDO NO PERÍODO ou equivalentes).']);
    }

    $subRow = $rows[$subRowIdx];
    $colPrevista = medicao_csv_find_col_subheader($subRow, '/PREVIST/i');
    $colTotal    = medicao_csv_find_col_subheader($subRow, '/\bTOTAL\b/i');
    $colPreco    = medicao_csv_find_col_subheader($subRow, '/PRE[ÇC]O.*UNIT|UNIT.*PRE[ÇC]O/i');

    $bmExtra = medicao_csv_pick_bm_saldo_periodo_cols($rows, $itemRowIdx, $itemCol, $colTotal, $refYm);
    if ($bmExtra['idx_qtd_periodo'] !== null) {
        $idxQtd = $bmExtra['idx_qtd_periodo'];
    }

    $linhas = [];
    for ($r = $subRowIdx + 1, $n = count($rows); $r < $n; $r++) {
        $row = $rows[$r];
        $cod = (string) ($row[$itemCol] ?? '');
        if ($cod === '' || !preg_match('/^\d+(\.\d+)+$/', $cod)) {
            continue;
        }
        $desc = $descCol !== null ? (string) ($row[$descCol] ?? '') : '';
        $unid = $unidCol !== null ? (string) ($row[$unidCol] ?? '') : '';
        $qtdTot = $colTotal !== null ? medicao_br_decimal((string) ($row[$colTotal] ?? '')) : null;
        $saldoRest = null;
        if ($bmExtra['idx_saldo_restante'] !== null) {
            $saldoRest = medicao_br_decimal((string) ($row[$bmExtra['idx_saldo_restante']] ?? ''));
        }
        if ($saldoRest === null && $qtdTot !== null && $bmExtra['idx_qtd_periodo'] !== null) {
            $qPer = medicao_br_decimal((string) ($row[$bmExtra['idx_qtd_periodo']] ?? ''));
            if ($qPer !== null) {
                $saldoRest = max(0.0, (float) $qtdTot - (float) $qPer);
            }
        }

        $linhas[] = [
            'item_codigo'          => $cod,
            'descricao'            => $desc,
            'unidade'              => $unid,
            'qtd_prevista'         => $colPrevista !== null ? medicao_br_decimal((string) ($row[$colPrevista] ?? '')) : null,
            'qtd_total'            => $qtdTot,
            'preco_unitario'       => $colPreco !== null ? medicao_br_decimal((string) ($row[$colPreco] ?? '')) : null,
            'qtd_medido_periodo'   => medicao_br_decimal((string) ($row[$idxQtd] ?? '')),
            'valor_medido_periodo' => medicao_br_decimal((string) ($row[$idxValor] ?? '')),
            'saldo_restante'       => $saldoRest,
        ];
    }

    if ($linhas === []) {
        return array_merge($empty, ['erro' => 'Nenhuma linha de itens numéricos (ex.: 1.1, 2.3) encontrada após o cabeçalho.']);
    }

    return [
        'ok'               => true,
        'erro'             => '',
        'idx_qtd_medido'   => $idxQtd,
        'idx_valor_medido' => $idxValor,
        'linhas'           => $linhas,
    ];
}

function medicao_csv_sniff_delimiter(string $firstLine): string
{
    $nComma = substr_count($firstLine, ',');
    $nSemi  = substr_count($firstLine, ';');
    return $nSemi > $nComma ? ';' : ',';
}

/**
 * Escolhe `,` ou `;` pela linha que contém ITEM com mais colunas; empate favorece `;` (Excel PT-BR).
 */
function medicao_csv_sniff_delimiter_best(string $rawHead): string
{
    $bestDelim = ',';
    $bestCols  = 0;
    foreach ([',', ';'] as $d) {
        $fp = fopen('php://memory', 'r+b');
        if ($fp === false) {
            continue;
        }
        fwrite($fp, $rawHead);
        rewind($fp);
        $maxOne = 0;
        while (($row = fgetcsv($fp, 0, $d, '"', '\\')) !== false) {
            $cnt = count($row);
            foreach ($row as $c) {
                if (is_string($c) && medicao_csv_header_norm($c) === 'ITEM') {
                    $maxOne = max($maxOne, $cnt);
                    break;
                }
            }
            if (medicao_csv_row_is_relatorio_header_row($row)) {
                $maxOne = max($maxOne, $cnt);
            }
        }
        fclose($fp);
        if ($maxOne > $bestCols) {
            $bestCols = $maxOne;
            $bestDelim = $d;
        } elseif ($maxOne === $bestCols && $maxOne > 0 && $d === ';') {
            $bestDelim = ';';
        }
    }

    return $bestCols > 0 ? $bestDelim : medicao_csv_sniff_delimiter(strtok($rawHead, "\r\n") ?: '');
}

/**
 * Localiza colunas de saldo restante e quantidade medida no período (BM tratado).
 *
 * @return array{idx_saldo_restante: int|null, idx_qtd_periodo: int|null}
 */
function medicao_csv_pick_bm_saldo_periodo_cols(
    array $rows,
    int $itemRowIdx,
    int $itemCol,
    ?int $colTotal,
    ?string $refYm = null
): array {
    $ret = ['idx_saldo_restante' => null, 'idx_qtd_periodo' => null];
    if ($colTotal === null) {
        return $ret;
    }

    $maxCol = 0;
    $lim    = min(count($rows), $itemRowIdx + 250);
    for ($r = $itemRowIdx + 1; $r < $lim; $r++) {
        $maxCol = max($maxCol, count($rows[$r] ?? []));
    }
    if ($maxCol <= $colTotal + 2) {
        return $ret;
    }

    $needle = ($refYm !== null && preg_match('/^\d{4}-\d{2}$/', $refYm))
        ? medicao_bm_needle_periodo_planilha($refYm)
        : null;
    if ($needle !== null && $needle !== '') {
        $needleU = mb_strtoupper($needle, 'UTF-8');
        for ($j = $colTotal + 1; $j < $maxCol; $j++) {
            $h = mb_strtoupper(medicao_bm_linhas_texto_cabecalho_coluna($rows, $itemRowIdx, $j), 'UTF-8');
            if ($h !== '' && str_contains($h, $needleU) && preg_match('/\bBM\s*\d+/u', $h)) {
                $ret['idx_qtd_periodo'] = $j;
                break;
            }
        }
    }

    $minJ = $colTotal + 1;
    $maxJ = min($maxCol - 2, $colTotal + 18);
    $bestScore = 0;
    $bestSaldo = null;
    $bestPer   = null;
    for ($j = $minJ; $j <= $maxJ; $j++) {
        $score = 0;
        for ($r = $itemRowIdx + 1; $r < $lim; $r++) {
            $row = $rows[$r];
            $cod = (string) ($row[$itemCol] ?? '');
            if ($cod === '' || !preg_match('/^\d+(\.\d+)+$/', $cod)) {
                continue;
            }
            $tot = medicao_br_decimal((string) ($row[$colTotal] ?? ''));
            $a   = medicao_br_decimal((string) ($row[$j] ?? ''));
            $b   = medicao_br_decimal((string) ($row[$j + 1] ?? ''));
            if ($tot === null || $tot <= 0 || $a === null || $b === null) {
                continue;
            }
            if ($a < 0 || $b < 0 || $a > $tot + 1e-6 || $b > $tot + 1e-6) {
                continue;
            }
            if (abs(($a + $b) - $tot) < max(0.01, $tot * 1e-6)) {
                $score += 4;
            } elseif ($a <= $tot + 1e-6) {
                $score += 1;
            }
        }
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestSaldo = $j;
            $bestPer   = $j + 1;
        }
    }

    if ($bestSaldo !== null && $bestScore >= 6) {
        $ret['idx_saldo_restante'] = $bestSaldo;
        if ($ret['idx_qtd_periodo'] === null) {
            $ret['idx_qtd_periodo'] = $bestPer;
        }
    }

    return $ret;
}

/**
 * @return array{subRowIdx:int, idxQtd:?int, idxValor:?int}
 */
function medicao_csv_pick_medido_columns(array $rows, int $itemRowIdx, int $itemCol): array
{
    $lim = min(count($rows), $itemRowIdx + 22);
    $best = ['subRowIdx' => -1, 'idxQtd' => null, 'idxValor' => null, 'score' => -1];

    for ($r = $itemRowIdx; $r < $lim; $r++) {
        $row = $rows[$r];
        $medidoIdxs = [];
        foreach ($row as $j => $c) {
            if (medicao_csv_cell_is_medido_periodo($c)) {
                $medidoIdxs[] = (int) $j;
            }
        }
        if (count($medidoIdxs) >= 2) {
            $iq = $medidoIdxs[0];
            $iv = $medidoIdxs[count($medidoIdxs) - 1];
            $sc = medicao_csv_score_qtd_valor_columns($rows, $r, $itemCol, $iq, $iv);
            if ($sc > $best['score']) {
                $best = ['subRowIdx' => $r, 'idxQtd' => $iq, 'idxValor' => $iv, 'score' => $sc];
            }
            if (count($medidoIdxs) > 2) {
                for ($a = 0, $nm = count($medidoIdxs); $a < $nm - 1; $a++) {
                    for ($b = $a + 1; $b < $nm; $b++) {
                        $iq2 = $medidoIdxs[$a];
                        $iv2 = $medidoIdxs[$b];
                        $sc2 = medicao_csv_score_qtd_valor_columns($rows, $r, $itemCol, $iq2, $iv2);
                        if ($sc2 > $best['score']) {
                            $best = ['subRowIdx' => $r, 'idxQtd' => $iq2, 'idxValor' => $iv2, 'score' => $sc2];
                        }
                    }
                }
            }
        }
    }

    if ($best['score'] > 0) {
        return [
            'subRowIdx' => $best['subRowIdx'],
            'idxQtd'    => $best['idxQtd'],
            'idxValor'  => $best['idxValor'],
        ];
    }

    $best = ['subRowIdx' => -1, 'idxQtd' => null, 'idxValor' => null, 'score' => -1];

    for ($r = $itemRowIdx; $r < $lim; $r++) {
        $pair = medicao_csv_find_valor_medido_pair($rows[$r] ?? []);
        if ($pair !== null) {
            [$iq, $iv] = $pair;
            $sc = medicao_csv_score_qtd_valor_columns($rows, $r, $itemCol, $iq, $iv);
            if ($sc > $best['score']) {
                $best = ['subRowIdx' => $r, 'idxQtd' => $iq, 'idxValor' => $iv, 'score' => $sc];
            }
        }
    }

    if ($best['idxQtd'] !== null && $best['idxValor'] !== null) {
        return [
            'subRowIdx' => $best['subRowIdx'],
            'idxQtd'    => $best['idxQtd'],
            'idxValor'  => $best['idxValor'],
        ];
    }

    $bf = medicao_csv_brute_force_medido_columns($rows, $itemRowIdx, $itemCol);
    if ($bf !== null) {
        return $bf;
    }

    return ['subRowIdx' => -1, 'idxQtd' => null, 'idxValor' => null];
}

/**
 * Último recurso: duas colunas à direita de ITEM com mais valores numéricos nas linhas de itens.
 *
 * @return array{subRowIdx:int, idxQtd:int, idxValor:int}|null
 */
function medicao_csv_brute_force_medido_columns(array $rows, int $itemRowIdx, int $itemCol): ?array
{
    $firstData = -1;
    $limScan   = min(count($rows), $itemRowIdx + 60);
    for ($r = $itemRowIdx + 1; $r < $limScan; $r++) {
        $cod = (string) ($rows[$r][$itemCol] ?? '');
        if ($cod !== '' && preg_match('/^\d+(\.\d+)+$/', $cod)) {
            $firstData = $r;
            break;
        }
    }
    if ($firstData < 0) {
        return null;
    }
    $fakeSub = $firstData - 1;
    $maxCol  = 0;
    for ($r = $firstData; $r < min(count($rows), $firstData + 200); $r++) {
        $maxCol = max($maxCol, count($rows[$r]));
    }
    $maxCol = min($maxCol, 80);
    $minJ   = min($itemCol + 1, $maxCol - 2);
    $minJ   = max(0, $minJ);

    $bestIq = null;
    $bestIv = null;
    $bestSc = 0;
    for ($iq = $minJ; $iq < $maxCol - 1; $iq++) {
        for ($iv = $iq + 1; $iv < $maxCol; $iv++) {
            $sc = medicao_csv_score_qtd_valor_columns($rows, $fakeSub, $itemCol, $iq, $iv);
            if ($sc > $bestSc) {
                $bestSc = $sc;
                $bestIq = $iq;
                $bestIv = $iv;
            }
        }
    }

    if ($bestIq === null || $bestIv === null || $bestSc < 6) {
        return null;
    }

    return [
        'subRowIdx' => $fakeSub,
        'idxQtd'    => $bestIq,
        'idxValor'  => $bestIv,
    ];
}

/**
 * Conta células numéricas nas linhas de itens após a linha de subcabeçalho.
 */
function medicao_csv_score_qtd_valor_columns(
    array $rows,
    int $subRowIdx,
    int $itemCol,
    int $idxQtd,
    int $idxValor
): int {
    $score = 0;
    $n     = min(count($rows), $subRowIdx + 1 + 200);
    for ($r = $subRowIdx + 1; $r < $n; $r++) {
        $row = $rows[$r];
        $cod = (string) ($row[$itemCol] ?? '');
        if ($cod === '' || !preg_match('/^\d+(\.\d+)+$/', $cod)) {
            continue;
        }
        $vq = medicao_br_decimal((string) ($row[$idxQtd] ?? ''));
        $vv = medicao_br_decimal((string) ($row[$idxValor] ?? ''));
        if ($vq !== null) {
            $score += 3;
        }
        if ($vv !== null) {
            $score += 3;
        }
    }

    return $score;
}

/**
 * Cabeçalhos alternativos: «VALOR MEDIDO» / «R$ …» sem repetir MEDIDO duas vezes.
 *
 * @return array{0:int,1:int}|null [qtdCol, valorCol]
 */
function medicao_csv_find_valor_medido_pair(array $row): ?array
{
    $idxQtd   = null;
    $idxValor = null;
    foreach ($row as $j => $c) {
        $u = medicao_csv_header_norm_cell_for_match($c);
        if ($u === '') {
            continue;
        }
        if ($idxQtd === null && preg_match('/QTD.*MEDIDO|MEDIDO.*QTD|QUANT.*MEDIDO/i', $u)) {
            $idxQtd = (int) $j;
        }
        if ($idxValor === null && preg_match('/VALOR.*MEDIDO|MEDIDO.*VALOR|VL\.?\s*MEDIDO|VALOR\s*\(?R\$\)?/i', $u)
            && !preg_match('/UNIT|UNITÁRIO|UNITARIO/i', $u)) {
            $idxValor = (int) $j;
        }
    }
    if ($idxQtd !== null && $idxValor !== null && $idxQtd !== $idxValor) {
        return [$idxQtd, $idxValor];
    }

    return null;
}

function medicao_csv_header_norm_cell_for_match(string $c): string
{
    $u = mb_strtoupper(trim($c), 'UTF-8');
    $u = str_replace(
        ['Á', 'À', 'Â', 'Ã', 'É', 'Ê', 'Í', 'Ì', 'Î', 'Ó', 'Ò', 'Ô', 'Õ', 'Ú', 'Ù', 'Ç'],
        ['A', 'A', 'A', 'A', 'E', 'E', 'I', 'I', 'I', 'O', 'O', 'O', 'O', 'U', 'U', 'C'],
        $u
    );
    return preg_replace('/\s+/u', ' ', $u) ?? $u;
}

function medicao_csv_header_norm(string $c): string
{
    $c = mb_strtoupper(trim($c), 'UTF-8');
    return preg_replace('/\s+/u', ' ', $c) ?? $c;
}

/** @param callable(string):bool $pred recebe header já normalizado em maiúsculas */
function medicao_csv_find_col_header(array $row, callable $pred): ?int
{
    foreach ($row as $j => $c) {
        $u = medicao_csv_header_norm($c);
        if ($u !== '' && $pred($u)) {
            return (int) $j;
        }
    }

    return null;
}

function medicao_csv_cell_is_medido_periodo(string $c): bool
{
    $u = mb_strtoupper(trim($c), 'UTF-8');
    $u = str_replace(
        ['Á', 'À', 'Â', 'Ã', 'É', 'Ê', 'Í', 'Ì', 'Î', 'Ó', 'Ò', 'Ô', 'Õ', 'Ú', 'Ù', 'Ç'],
        ['A', 'A', 'A', 'A', 'E', 'E', 'I', 'I', 'I', 'O', 'O', 'O', 'O', 'U', 'U', 'C'],
        $u
    );
    return str_contains($u, 'MEDIDO') && str_contains($u, 'PERIODO');
}

function medicao_csv_find_col_subheader(array $row, string $regex): ?int
{
    foreach ($row as $j => $c) {
        $t = trim($c);
        if ($t !== '' && preg_match($regex, $t)) {
            return (int) $j;
        }
    }

    return null;
}

function medicao_br_decimal(?string $s): ?float
{
    if ($s === null) {
        return null;
    }
    $s = trim($s);
    if ($s === '' || strtoupper($s) === '#N/A' || $s === '-' || $s === '—') {
        return null;
    }
    $s = str_replace(["\xc2\xa0", ' ', "\xe2\x80\xaf"], '', $s);
    $s = preg_replace('/^R\$\s*/iu', '', $s) ?? $s;
    // Formato pt-BR: 1.234,56 ou 1234,56
    if (preg_match('/,\d{1,6}$/', $s)) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (preg_match('/^\d+\.\d+$/', $s) && !str_contains($s, ',')) {
        // Um único ponto como decimal (exportação en-US)
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
        // Só separador de milhar com ponto, sem parte decimal
        $s = str_replace('.', '', $s);
    } else {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    }
    if ($s === '' || !is_numeric($s)) {
        return null;
    }

    return (float) $s;
}

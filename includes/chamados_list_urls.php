<?php
declare(strict_types=1);

/**
 * Query string da listagem de chamados (paginação e filtros).
 *
 * @param array{medicao_mes?:string,periodo_de?:string,periodo_ate?:string,periodo_limpar?:bool,cliente_id?:int|null,envolvido_user?:int|null,tecnico_user_id?:int|null,local_q?:string|null} $periodoCtx
 */
function adm_chamados_url(int $p, string $filtro, string $busca, array $periodoCtx): string
{
    $qs = [];
    if ($filtro !== '') {
        $qs['f'] = $filtro;
    }
    if ($busca !== '') {
        $qs['q'] = $busca;
    }
    if ($p > 1) {
        $qs['page'] = $p;
    }
    if (!empty($periodoCtx['periodo_limpar'])) {
        $qs['periodo_limpar'] = '1';
    } elseif (($periodoCtx['medicao_mes'] ?? '') !== '') {
        $qs['medicao_mes'] = $periodoCtx['medicao_mes'];
    } else {
        if (($periodoCtx['periodo_de'] ?? '') !== '') {
            $qs['periodo_de'] = $periodoCtx['periodo_de'];
        }
        if (($periodoCtx['periodo_ate'] ?? '') !== '') {
            $qs['periodo_ate'] = $periodoCtx['periodo_ate'];
        }
    }
    if (!empty($periodoCtx['cliente_id'])) {
        $qs['cliente_id'] = (int) $periodoCtx['cliente_id'];
    }
    if (!empty($periodoCtx['envolvido_user'])) {
        $qs['envolvido_user'] = (int) $periodoCtx['envolvido_user'];
    }
    if (!empty($periodoCtx['tecnico_user_id'])) {
        $qs['tecnico_user_id'] = (int) $periodoCtx['tecnico_user_id'];
    }
    if (!empty($periodoCtx['local_q'])) {
        $qs['local_q'] = (string) $periodoCtx['local_q'];
    }

    return 'chamados.php' . ($qs ? '?' . http_build_query($qs) : '');
}

/**
 * URL de exportação (Excel/PDF) com os mesmos filtros da listagem.
 *
 * @param array{medicao_mes?:string,periodo_de?:string,periodo_ate?:string,periodo_limpar?:bool,cliente_id?:int|null,envolvido_user?:int|null,tecnico_user_id?:int|null,local_q?:string|null} $periodoCtx
 */
function adm_chamados_export_url(string $format, string $filtro, string $busca, array $periodoCtx): string
{
    $base = adm_chamados_url(1, $filtro, $busca, $periodoCtx);

    return $base . ((strpos($base, '?') !== false) ? '&' : '?') . 'export=' . rawurlencode($format);
}

/**
 * Intervalo civil para atalhos de período na listagem de chamados.
 *
 * @return array{de: string, ate: string}|null
 */
function chamados_periodo_preset(string $preset): ?array
{
    $preset = strtolower(trim($preset));
    if ($preset === '') {
        return null;
    }
    $hoje = new DateTimeImmutable('today');

    return match ($preset) {
        'hoje' => [
            'de'  => $hoje->format('Y-m-d'),
            'ate' => $hoje->format('Y-m-d'),
        ],
        'semana' => (static function () use ($hoje): array {
            $dow = (int) $hoje->format('N');
            $seg = $hoje->modify('-' . ($dow - 1) . ' days');
            $dom = $seg->modify('+6 days');

            return ['de' => $seg->format('Y-m-d'), 'ate' => $dom->format('Y-m-d')];
        })(),
        'mes' => [
            'de'  => $hoje->format('Y-m-01'),
            'ate' => $hoje->format('Y-m-t'),
        ],
        'mes_anterior' => (static function () use ($hoje): array {
            $ref = $hoje->modify('first day of last month');

            return ['de' => $ref->format('Y-m-01'), 'ate' => $ref->format('Y-m-t')];
        })(),
        default => null,
    };
}

/**
 * URL da listagem com atalho de período (ou todo o período).
 *
 * @param array{medicao_mes?:string,periodo_de?:string,periodo_ate?:string,periodo_limpar?:bool,cliente_id?:int|null,envolvido_user?:int|null,tecnico_user_id?:int|null,local_q?:string|null} $periodoCtx
 */
function adm_chamados_periodo_quick_url(int $p, string $filtro, string $busca, array $periodoCtx, ?string $preset): string
{
    $ctx = $periodoCtx;
    unset($ctx['periodo_limpar'], $ctx['periodo_de'], $ctx['periodo_ate'], $ctx['medicao_mes']);

    if ($preset === null || $preset === 'limpar') {
        $ctx['periodo_limpar'] = true;
    } else {
        $range = chamados_periodo_preset($preset);
        if ($range === null) {
            return adm_chamados_url($p, $filtro, $busca, $periodoCtx);
        }
        $ctx['periodo_de']  = $range['de'];
        $ctx['periodo_ate'] = $range['ate'];
    }

    return adm_chamados_url($p, $filtro, $busca, $ctx);
}

/**
 * Identifica qual atalho de período está ativo (null = custom / outro intervalo).
 */
function chamados_periodo_preset_ativo(?string $periodoDe, ?string $periodoAte, bool $periodoLimpar, string $medicaoMes): ?string
{
    if ($periodoLimpar || $medicaoMes !== '') {
        return $periodoLimpar ? 'limpar' : null;
    }
    if ($periodoDe === null || $periodoAte === null) {
        return null;
    }
    foreach (['hoje', 'mes'] as $key) {
        $r = chamados_periodo_preset($key);
        if ($r !== null && $periodoDe === $r['de'] && $periodoAte === $r['ate']) {
            return $key;
        }
    }

    return null;
}

/** Listagem operador: só `f`, `q` e `page` (sem período nem escopos de gestão). */
function oper_chamados_url(int $p, string $filtro, string $busca): string
{
    $qs = [];
    if ($filtro !== '') {
        $qs['f'] = $filtro;
    }
    if ($busca !== '') {
        $qs['q'] = $busca;
    }
    if ($p > 1) {
        $qs['page'] = $p;
    }

    return 'chamados.php' . ($qs !== [] ? '?' . http_build_query($qs) : '');
}

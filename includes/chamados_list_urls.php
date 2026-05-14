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

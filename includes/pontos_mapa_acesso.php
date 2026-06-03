<?php
declare(strict_types=1);

/**
 * Permissões compartilhadas — APIs do mapa de pontos de iluminação.
 */

require_once __DIR__ . '/repository.php';

/**
 * Resolve o escopo (empresa raiz) a partir do usuário e parâmetro opcional cliente_id.
 *
 * @return array{ok:bool,escopo_id:int,err:string}
 */
function pontos_mapa_resolver_escopo(array $user, int $clienteIdParam = 0): array
{
    $perfil = (string) ($user['perfil'] ?? '');

    if ($perfil === 'cliente') {
        $cid = (int) ($user['cliente_id'] ?? 0);
        if ($cid <= 0) {
            return ['ok' => false, 'escopo_id' => 0, 'err' => 'Cliente não vinculado ao usuário.'];
        }
        $raiz = repo_cliente_matriz_raiz_id($cid);

        return $raiz > 0
            ? ['ok' => true, 'escopo_id' => $raiz, 'err' => '']
            : ['ok' => false, 'escopo_id' => 0, 'err' => 'Escopo de empresa inválido.'];
    }

    if (in_array($perfil, ['admin', 'gestor'], true)) {
        $scopeGestor = gestao_scope_cliente_id($user);
        if ($scopeGestor !== null) {
            return ['ok' => true, 'escopo_id' => $scopeGestor, 'err' => ''];
        }
        if ($clienteIdParam > 0) {
            $raiz = repo_cliente_matriz_raiz_id($clienteIdParam);

            return $raiz > 0
                ? ['ok' => true, 'escopo_id' => $raiz, 'err' => '']
                : ['ok' => false, 'escopo_id' => 0, 'err' => 'Empresa inválida.'];
        }

        return ['ok' => false, 'escopo_id' => 0, 'err' => 'Informe cliente_id (escopo).'];
    }

    if ($perfil === 'operador') {
        if ($clienteIdParam > 0) {
            $raiz = repo_cliente_matriz_raiz_id($clienteIdParam);

            return $raiz > 0
                ? ['ok' => true, 'escopo_id' => $raiz, 'err' => '']
                : ['ok' => false, 'escopo_id' => 0, 'err' => 'Empresa inválida.'];
        }

        return ['ok' => false, 'escopo_id' => 0, 'err' => 'Informe cliente_id (escopo).'];
    }

    return ['ok' => false, 'escopo_id' => 0, 'err' => 'Perfil sem permissão.'];
}

/**
 * @param array<string,mixed> $user
 */
function pontos_mapa_usuario_pode_escopo(array $user, int $escopoId): bool
{
    if ($escopoId <= 0) {
        return false;
    }

    $perfil = (string) ($user['perfil'] ?? '');

    if ($perfil === 'cliente') {
        $cid = (int) ($user['cliente_id'] ?? 0);
        if ($cid <= 0) {
            return false;
        }
        $raiz = repo_cliente_matriz_raiz_id($cid);

        return $raiz === $escopoId;
    }

    if (in_array($perfil, ['admin', 'gestor'], true)) {
        $scope = gestao_scope_cliente_id($user);
        if ($scope === null) {
            return true;
        }

        return $scope === $escopoId;
    }

    if ($perfil === 'operador') {
        return true;
    }

    return false;
}

/**
 * @param array<string,mixed> $user
 */
function ponto_mapa_detalhe_usuario_pode_acessar(int $pontoId, array $user): bool
{
    if ($pontoId <= 0) {
        return false;
    }

    $perfil = (string) ($user['perfil'] ?? '');

    if ($perfil === 'cliente') {
        $cid = (int) ($user['cliente_id'] ?? 0);
        if ($cid <= 0) {
            return false;
        }
        $raiz = repo_cliente_matriz_raiz_id($cid);

        return $raiz > 0 && repo_ponto_iluminacao_pertence_empresa($pontoId, $raiz);
    }

    if (in_array($perfil, ['admin', 'gestor'], true)) {
        $scope = gestao_scope_cliente_id($user);
        if ($scope === null) {
            return repo_ponto_iluminacao($pontoId) !== null;
        }

        return repo_ponto_iluminacao_pertence_empresa($pontoId, $scope);
    }

    if ($perfil === 'operador') {
        return repo_ponto_iluminacao($pontoId) !== null;
    }

    return false;
}

/**
 * @return array{status:string,bairro:string,busca:string,somente_chamados_abertos:bool,force_points:bool,ref_lat:?float,ref_lng:?float,ref_raio_m:int}
 */
function pontos_mapa_parse_filtros_request(): array
{
    $status = trim((string) ($_GET['status'] ?? ''));
    if ($status !== '' && !in_array($status, ['Ativo', 'Inativo'], true)) {
        $statusLower = strtolower($status);
        if ($statusLower === 'ativo') {
            $status = 'Ativo';
        } elseif ($statusLower === 'inativo') {
            $status = 'Inativo';
        } else {
            $status = '';
        }
    }

    $somenteCh = $_GET['somente_chamados_abertos'] ?? $_GET['filtro'] ?? '';
    $somenteChamados = in_array((string) $somenteCh, ['1', 'true', 'chamados', 'sim'], true);
    $forcePoints = in_array((string) ($_GET['force_points'] ?? ''), ['1', 'true', 'sim'], true);

    $refLat = isset($_GET['ref_lat']) ? (float) $_GET['ref_lat'] : NAN;
    $refLng = isset($_GET['ref_lng']) ? (float) $_GET['ref_lng'] : NAN;
    $refRaioM = (int) ($_GET['ref_raio_m'] ?? 10);
    $refRaioM = max(1, min(100, $refRaioM));

    $filtros = [
        'status' => $status,
        'bairro' => trim((string) ($_GET['bairro'] ?? '')),
        'busca' => trim((string) ($_GET['busca'] ?? $_GET['q'] ?? '')),
        'somente_chamados_abertos' => $somenteChamados,
        'force_points' => $forcePoints,
        'ref_raio_m' => $refRaioM,
    ];

    if (is_finite($refLat) && is_finite($refLng) && abs($refLat) <= 90 && abs($refLng) <= 180) {
        $filtros['ref_lat'] = $refLat;
        $filtros['ref_lng'] = $refLng;
    }

    return $filtros;
}

function pontos_mapa_filtro_pinpoint_ativo(array $filtros): bool
{
    return isset($filtros['ref_lat'], $filtros['ref_lng'])
        && is_finite((float) $filtros['ref_lat'])
        && is_finite((float) $filtros['ref_lng']);
}

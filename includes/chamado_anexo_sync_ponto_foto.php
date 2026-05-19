<?php
/**
 * Se o chamado referencia um poste sem fotos no cadastro, a primeira imagem anexada
 * é copiada para uploads do poste e registada como foto principal.
 * Dispara em repo_create_chamado_anexo (upload) e repo_update_chamado_os_dados (vincular ponto).
 * Falhas não impedem o anexo do chamado nem a gravação da ficha; apenas error_log.
 */

/**
 * Copia um anexo de imagem do chamado para o cadastro do poste (foto principal).
 */
function chamado_copiar_anexo_imagem_para_ponto(
    int $chamadoId,
    int $pontoId,
    string $nomeArquivo,
    ?string $mime,
    int $tamanho,
    string $nomeOriginal
): void {
    if ($chamadoId <= 0 || $pontoId <= 0) {
        return;
    }
    $fn = basename((string) $nomeArquivo);
    if ($fn === '' || $fn === '.' || $fn === '..') {
        return;
    }
    if (!chamado_anexo_eh_imagem_para_sync_ponto($mime, $fn, $nomeOriginal)) {
        return;
    }

    $src = upload_dir_chamado($chamadoId) . DIRECTORY_SEPARATOR . $fn;
    if (!is_file($src) || !is_readable($src)) {
        error_log('[crm_prefeitura] sync foto poste: ficheiro do anexo inexistente ou ilegível — chamado ' . $chamadoId . ' / ' . $fn);

        return;
    }

    $destDir = upload_dir_ponto_iluminacao($pontoId);
    $dest    = $destDir . DIRECTORY_SEPARATOR . $fn;
    if (is_file($dest)) {
        error_log('[crm_prefeitura] sync foto poste: destino já existe (poste sem linhas?) — ponto ' . $pontoId . ' / ' . $fn);

        return;
    }
    if (!@copy($src, $dest)) {
        error_log('[crm_prefeitura] sync foto poste: copy falhou — ' . $src . ' → ' . $dest);

        return;
    }

    $tamFs = (int) (@filesize($dest) ?: 0);
    $ins   = repo_ponto_iluminacao_imagem_inserir(
        $pontoId,
        $nomeOriginal,
        $fn,
        $mime !== null && $mime !== '' ? $mime : null,
        $tamFs > 0 ? $tamFs : $tamanho,
        true
    );
    if (empty($ins['ok'])) {
        @unlink($dest);
        error_log('[crm_prefeitura] sync foto poste: BD falhou — ponto ' . $pontoId . ' — ' . ($ins['err'] ?? ''));
    }
}

/**
 * @param int $chamadoId ID do chamado
 * @param string $nomeArquivo Nome em disco do anexo (basename apenas é usado)
 */
function chamado_anexo_sync_primeira_imagem_para_ponto(int $chamadoId, string $nomeArquivo, ?string $mime, int $tamanho, string $nomeOriginal): void
{
    if ($chamadoId <= 0) {
        return;
    }

    $ch = repo_chamado($chamadoId);
    if (!$ch) {
        return;
    }
    $pontoId = (int) ($ch['ponto_iluminacao_id'] ?? 0);
    if ($pontoId <= 0 || !repo_ponto_iluminacao($pontoId)) {
        return;
    }
    if (repo_ponto_iluminacao_imagens_list($pontoId) !== []) {
        return;
    }

    chamado_copiar_anexo_imagem_para_ponto($chamadoId, $pontoId, $nomeArquivo, $mime, $tamanho, $nomeOriginal);
}

/**
 * Ao vincular ponto na ficha OS: copia a primeira imagem já anexada ao chamado, se o poste não tiver fotos.
 *
 * @param int|null $pontoId ID do poste (omitir para ler do chamado)
 */
function chamado_sync_primeira_imagem_chamado_para_ponto(int $chamadoId, ?int $pontoId = null): void
{
    if ($chamadoId <= 0) {
        return;
    }

    if ($pontoId === null || $pontoId <= 0) {
        $ch = repo_chamado($chamadoId);
        if (!$ch) {
            return;
        }
        $pontoId = (int) ($ch['ponto_iluminacao_id'] ?? 0);
    }

    if ($pontoId <= 0 || !repo_ponto_iluminacao($pontoId)) {
        return;
    }
    if (repo_ponto_iluminacao_imagens_list($pontoId) !== []) {
        return;
    }

    $anexos = chamado_anexos_imagens_cronologicos($chamadoId);
    if ($anexos === []) {
        return;
    }
    $anexo = $anexos[0];
    chamado_copiar_anexo_imagem_para_ponto(
        $chamadoId,
        $pontoId,
        (string) ($anexo['nome_arquivo'] ?? ''),
        $anexo['mime'] ?? null,
        (int) ($anexo['tamanho'] ?? 0),
        (string) ($anexo['nome_original'] ?? '')
    );
}

/**
 * Anexos de imagem do chamado, do mais antigo ao mais recente.
 *
 * @return list<array{nome_arquivo: string, nome_original: string, mime: ?string, tamanho: int}>
 */
function chamado_anexos_imagens_cronologicos(int $chamadoId): array
{
    if ($chamadoId <= 0) {
        return [];
    }

    $pdo = db();
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->prepare('
        SELECT nome_original, nome_arquivo, mime, tamanho
        FROM chamado_anexos
        WHERE chamado_id = ?
        ORDER BY enviado_em ASC, id ASC
    ');
    $stmt->execute([$chamadoId]);
    $out = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $fn  = basename((string) ($r['nome_arquivo'] ?? ''));
        $nom = (string) ($r['nome_original'] ?? '');
        $mime = isset($r['mime']) && $r['mime'] !== '' ? (string) $r['mime'] : null;
        if (!chamado_anexo_eh_imagem_para_sync_ponto($mime, $fn, $nom)) {
            continue;
        }
        $out[] = [
            'nome_arquivo'  => $fn,
            'nome_original' => $nom,
            'mime'          => $mime,
            'tamanho'       => (int) ($r['tamanho'] ?? 0),
        ];
    }

    return $out;
}

function chamado_anexo_eh_imagem_para_sync_ponto(?string $mime, string $nomeArquivo, string $nomeOriginal): bool
{
    $mimeNorm = $mime !== null ? strtolower(trim($mime)) : '';
    if ($mimeNorm !== '' && preg_match('#^image/(jpeg|jpg|png|gif|webp)$#', $mimeNorm)) {
        return true;
    }
    foreach ([$nomeArquivo, $nomeOriginal] as $nome) {
        $ext = strtolower(pathinfo((string) $nome, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            return true;
        }
    }

    return false;
}

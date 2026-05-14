<?php
/**
 * Se o chamado referencia um poste sem fotos no cadastro, a primeira imagem anexada
 * é copiada para uploads do poste e registada como foto principal.
 * Chamado a partir de repo_create_chamado_anexo (anexo já gravado em disco + BD).
 * Falhas não impedem o anexo do chamado; apenas error_log.
 */

/**
 * @param int $chamadoId ID do chamado
 * @param string $nomeArquivo Nome em disco do anexo (basename apenas é usado)
 */
function chamado_anexo_sync_primeira_imagem_para_ponto(int $chamadoId, string $nomeArquivo, ?string $mime, int $tamanho, string $nomeOriginal): void
{
    if ($chamadoId <= 0) {
        return;
    }
    $fn = basename((string) $nomeArquivo);
    if ($fn === '' || $fn === '.' || $fn === '..') {
        return;
    }
    if (!chamado_anexo_eh_imagem_para_sync_ponto($mime, $fn, $nomeOriginal)) {
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

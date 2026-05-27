<?php
/**
 * Vincula 1 foto principal padronizada (1200×900 JPEG) em cada ponto IPOJUCA-PREF-*.
 *
 * Pré-requisito: database/seed_assets/poste_iluminacao.jpg
 *
 * Uso:
 *   php scripts/seed_pontos_iluminacao_fotos_ipojuca.php
 *   php scripts/seed_pontos_iluminacao_fotos_ipojuca.php --force
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/repository.php';

const SEED_TARGET_W = 1200;
const SEED_TARGET_H = 900;
const SEED_JPEG_QUALITY = 84;
const SEED_NOME_ARQUIVO = 'principal_1200x900.jpg';
const SEED_NOME_ORIGINAL = 'Foto do poste (seed)';
const SEED_ASSET_REL = 'database/seed_assets/poste_iluminacao.jpg';
const SEED_CODIGO_LIKE = 'IPOJUCA-PREF-%';
const SEED_OBSERVACOES = 'Cadastro teste — Prefeitura Ipojuca (10 pontos)';

$force = in_array('--force', $argv ?? [], true);

$assetPath = $root . '/' . SEED_ASSET_REL;
if (!is_file($assetPath) || !is_readable($assetPath)) {
    fwrite(STDERR, "Arquivo não encontrado: {$assetPath}\n");
    fwrite(STDERR, "Coloque uma foto de poste em database/seed_assets/poste_iluminacao.jpg (veja README).\n");
    exit(1);
}

if (!extension_loaded('gd')) {
    fwrite(STDERR, "Extensão PHP GD não está habilitada (necessária para redimensionar).\n");
    exit(1);
}

$tmpJpeg = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm_seed_poste_' . bin2hex(random_bytes(8)) . '.jpg';
try {
    if (!seed_poste_resize_crop_4x3($assetPath, $tmpJpeg, SEED_TARGET_W, SEED_TARGET_H, SEED_JPEG_QUALITY)) {
        fwrite(STDERR, "Falha ao redimensionar a imagem de origem.\n");
        exit(1);
    }
} catch (Throwable $e) {
    @unlink($tmpJpeg);
    fwrite(STDERR, 'Erro ao processar imagem: ' . $e->getMessage() . "\n");
    exit(1);
}

$pdo = db();
if (!$pdo) {
    @unlink($tmpJpeg);
    fwrite(STDERR, "Banco de dados indisponível. Verifique includes/config.local.php ou MySQL.\n");
    exit(1);
}

$st = $pdo->prepare('
    SELECT id, codigo_poste
    FROM pontos_iluminacao
    WHERE codigo_poste LIKE ?
       OR observacoes = ?
    ORDER BY codigo_poste ASC
');
$st->execute([SEED_CODIGO_LIKE, SEED_OBSERVACOES]);
$pontos = $st->fetchAll(PDO::FETCH_ASSOC);

if ($pontos === []) {
    @unlink($tmpJpeg);
    fwrite(STDERR, "Nenhum ponto encontrado (filtro: " . SEED_CODIGO_LIKE . ").\n");
    exit(1);
}

$ok = 0;
$skip = 0;
$err = 0;

foreach ($pontos as $p) {
    $pontoId = (int) ($p['id'] ?? 0);
    $codigo  = (string) ($p['codigo_poste'] ?? '');
    if ($pontoId <= 0) {
        continue;
    }

    $principalExistente = seed_poste_imagem_principal($pontoId);
    if ($principalExistente !== null && !$force) {
        echo "[skip] {$codigo} (id {$pontoId}) — já tem foto principal. Use --force para substituir.\n";
        $skip++;
        continue;
    }

    if ($principalExistente !== null && $force) {
        repo_ponto_iluminacao_imagem_excluir((int) $principalExistente['id'], $pontoId);
    }

    $dir  = upload_dir_ponto_iluminacao($pontoId);
    $dest = $dir . DIRECTORY_SEPARATOR . SEED_NOME_ARQUIVO;

    if (!@copy($tmpJpeg, $dest)) {
        echo "[erro] {$codigo} (id {$pontoId}) — não foi possível gravar {$dest}\n";
        $err++;
        continue;
    }

    @chmod($dest, 0664);
    @chmod($dir, 0777);

    $tamanho = (int) (@filesize($dest) ?: 0);
    $ins = repo_ponto_iluminacao_imagem_inserir(
        $pontoId,
        SEED_NOME_ORIGINAL,
        SEED_NOME_ARQUIVO,
        'image/jpeg',
        $tamanho,
        true
    );

    if (empty($ins['ok'])) {
        @unlink($dest);
        echo "[erro] {$codigo} (id {$pontoId}) — BD: " . ($ins['err'] ?? 'falha') . "\n";
        $err++;
        continue;
    }

    echo "[ok]   {$codigo} (id {$pontoId}) — {$dest} ({$tamanho} bytes, imagem_id {$ins['id']})\n";
    $ok++;
}

@unlink($tmpJpeg);

echo "\nResumo: {$ok} vinculado(s), {$skip} ignorado(s), {$err} erro(s).\n";
exit($err > 0 ? 1 : 0);

/**
 * @return array<string, mixed>|null
 */
function seed_poste_imagem_principal(int $pontoId): ?array
{
    foreach (repo_ponto_iluminacao_imagens_list($pontoId) as $img) {
        if ((int) ($img['principal'] ?? 0) === 1) {
            return $img;
        }
    }

    return null;
}

function seed_poste_resize_crop_4x3(string $srcPath, string $destPath, int $tw, int $th, int $jpegQuality): bool
{
    $info = @getimagesize($srcPath);
    if ($info === false) {
        return false;
    }

    $sw = (int) $info[0];
    $sh = (int) $info[1];
    if ($sw < 1 || $sh < 1) {
        return false;
    }

    $mime = $info['mime'] ?? '';
    $src  = seed_poste_image_create_from_file($srcPath, $mime);
    if ($src === false) {
        return false;
    }

    $targetRatio = $tw / $th;
    $srcRatio    = $sw / $sh;

    if ($srcRatio > $targetRatio) {
        $cropH = $sh;
        $cropW = (int) round($sh * $targetRatio);
    } else {
        $cropW = $sw;
        $cropH = (int) round($sw / $targetRatio);
    }

    $srcX = (int) max(0, floor(($sw - $cropW) / 2));
    $srcY = (int) max(0, floor(($sh - $cropH) / 2));

    $dst = imagecreatetruecolor($tw, $th);
    if ($dst === false) {
        imagedestroy($src);

        return false;
    }

    imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $tw, $th, $cropW, $cropH);
    imagedestroy($src);

    $ok = imagejpeg($dst, $destPath, max(1, min(100, $jpegQuality)));
    imagedestroy($dst);

    return $ok && is_file($destPath);
}

/**
 * @return \GdImage|resource|false
 */
function seed_poste_image_create_from_file(string $path, string $mime)
{
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            return imagecreatefromjpeg($path);
        case 'image/png':
            $im = imagecreatefrompng($path);
            if ($im !== false) {
                imagealphablending($im, true);
                imagesavealpha($im, true);
            }

            return $im;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                return imagecreatefromwebp($path);
            }

            return false;
        case 'image/gif':
            return imagecreatefromgif($path);
        default:
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg'], true)) {
                return imagecreatefromjpeg($path);
            }
            if ($ext === 'png') {
                return imagecreatefrompng($path);
            }
            if ($ext === 'webp' && function_exists('imagecreatefromwebp')) {
                return imagecreatefromwebp($path);
            }

            return false;
    }
}

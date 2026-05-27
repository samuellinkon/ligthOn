<?php
/**
 * Helpers para upload/armazenamento de anexos de cliente.
 *
 * - Valida tamanho e extensão (whitelist).
 * - Gera nome único em disco (hash), preserva nome original no BD.
 * - Arquivos ficam em crm/uploads/clientes/{cliente_id}/.
 * - A pasta uploads/ está bloqueada por .htaccess; downloads passam por
 *   admin/download.php ou cliente/download.php.
 */

const UPLOAD_MAX_BYTES       = 15 * 1024 * 1024; // 15 MB por arquivo
const UPLOAD_EXT_PERMITIDAS  = [
    'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt',
    'xls', 'xlsx', 'ods', 'csv',
    'png', 'jpg', 'jpeg', 'gif', 'webp',
    'zip', 'rar', '7z',
];

/**
 * Garante diretório de upload existente e gravável pelo PHP (ex.: Apache daemon no XAMPP).
 */
function upload_ensure_dir_writable(string $dir): bool
{
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true)) {
            return false;
        }
    }
    if (is_writable($dir)) {
        return true;
    }
    @chmod($dir, 0775);
    if (is_writable($dir)) {
        return true;
    }
    @chmod($dir, 0777);
    return is_writable($dir);
}

function upload_dir_cliente(int $clienteId): string
{
    $base = __DIR__ . '/../uploads/clientes/' . $clienteId;
    upload_ensure_dir_writable($base);
    return $base;
}

function upload_dir_chamado(int $chamadoId): string
{
    $base = __DIR__ . '/../uploads/chamados/' . $chamadoId;
    upload_ensure_dir_writable($base);
    return $base;
}

/** Anexos de OS (aprovação): uploads/os_pedidos/{id}/ */
function upload_dir_os_pedido(int $osPedidoId): string
{
    $base = __DIR__ . '/../uploads/os_pedidos/' . $osPedidoId;
    upload_ensure_dir_writable($base);
    return $base;
}

/** PDF de boleto por cobrança: uploads/contas/{conta_id}/ */
function upload_dir_conta(int $contaId): string
{
    $base = __DIR__ . '/../uploads/contas/' . $contaId;
    upload_ensure_dir_writable($base);
    return $base;
}

/** Fotos do poste: uploads/pontos_iluminacao/{ponto_id}/ */
function upload_dir_ponto_iluminacao(int $pontoId): string
{
    $base = __DIR__ . '/../uploads/pontos_iluminacao/' . $pontoId;
    upload_ensure_dir_writable($base);
    return $base;
}

function upload_formatar_tamanho(int $bytes): string
{
    if ($bytes < 1024)                 return $bytes . ' B';
    if ($bytes < 1024 * 1024)          return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    if ($bytes < 1024 * 1024 * 1024)   return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
}

function upload_extensao_permitida(string $nome): bool
{
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    return in_array($ext, UPLOAD_EXT_PERMITIDAS, true);
}

/** Apenas imagens (postes, galerias). */
function upload_extensao_imagem(string $nome): bool
{
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true);
}

/**
 * Recebe um item do array $_FILES (single) e grava o arquivo.
 * Aceita $destino como:
 *  - int  -> clienteId (compat. retroativa: salva em uploads/clientes/{id}/)
 *  - string (path) -> grava nesse diretório diretamente.
 */
function upload_gravar_arquivo(array $file, $destino): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'msg' => 'Requisição de upload inválida.'];
    }
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return ['ok' => false, 'msg' => 'Nenhum arquivo enviado.'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['ok' => false, 'msg' => 'Arquivo excede o tamanho permitido.'];
        default:
            return ['ok' => false, 'msg' => 'Erro desconhecido no upload.'];
    }

    if ($file['size'] > UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'msg' => 'Arquivo maior que ' . upload_formatar_tamanho(UPLOAD_MAX_BYTES) . '.'];
    }

    $nomeOriginal = $file['name'];
    if (!upload_extensao_permitida($nomeOriginal)) {
        return ['ok' => false, 'msg' => 'Tipo de arquivo não permitido: ' . htmlspecialchars($nomeOriginal)];
    }

    $ext    = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
    $hash   = bin2hex(random_bytes(12));
    $nomeFs = date('Ymd_His') . '_' . $hash . '.' . $ext;

    if (is_int($destino)) {
        $dir = upload_dir_cliente($destino);
    } else {
        $dir = (string) $destino;
        if (!upload_ensure_dir_writable($dir)) {
            return ['ok' => false, 'msg' => 'Pasta de destino sem permissão de escrita no servidor.'];
        }
    }
    $dest = $dir . DIRECTORY_SEPARATOR . $nomeFs;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        $msg = 'Falha ao gravar arquivo no servidor.';
        if (!is_writable($dir)) {
            $msg .= ' Verifique permissões em uploads/.';
        }
        return ['ok' => false, 'msg' => $msg];
    }

    $mime = function_exists('mime_content_type') ? (mime_content_type($dest) ?: null) : null;

    return [
        'ok'            => true,
        'msg'           => 'Arquivo enviado.',
        'nome_arquivo'  => $nomeFs,
        'nome_original' => $nomeOriginal,
        'mime'          => $mime,
        'tamanho'       => (int) filesize($dest),
    ];
}

/**
 * Itera sobre um campo <input type="file" multiple name="anexos[]">
 * e grava todos os arquivos. Retorna [erros[], sucessos[]].
 *
 * @param array      $campo   array do $_FILES (multiple)
 * @param int|string $destino clienteId (int) OU diretório (string).
 */
function upload_gravar_multiplos(array $campo, $destino): array
{
    $erros = [];
    $salvos = [];

    if (!isset($campo['name']) || !is_array($campo['name'])) {
        return ['erros' => $erros, 'salvos' => $salvos];
    }

    $total = count($campo['name']);
    for ($i = 0; $i < $total; $i++) {
        if (($campo['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;

        $single = [
            'name'     => $campo['name'][$i],
            'type'     => $campo['type'][$i]     ?? '',
            'tmp_name' => $campo['tmp_name'][$i],
            'error'    => $campo['error'][$i],
            'size'     => $campo['size'][$i],
        ];
        $res = upload_gravar_arquivo($single, $destino);
        if ($res['ok']) {
            $salvos[] = $res;
        } else {
            $erros[] = $single['name'] . ': ' . $res['msg'];
        }
    }
    return ['erros' => $erros, 'salvos' => $salvos];
}

function upload_icone_por_ext(string $nome): string
{
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    if (in_array($ext, ['pdf'], true)) {
        return '📄';
    }
    if (in_array($ext, ['doc','docx','odt','rtf','txt'], true)) {
        return '📝';
    }
    if (in_array($ext, ['xls','xlsx','ods','csv'], true)) {
        return '📊';
    }
    if (in_array($ext, ['png','jpg','jpeg','gif','webp'], true)) {
        return '🖼️';
    }
    if (in_array($ext, ['zip','rar','7z'], true)) {
        return '🗜️';
    }
    return '📎';
}

<?php
/**
 * Preenche ficha OS (contribuinte, endereço, classificação, descrição) nos chamados Seed Ipojuca.
 *
 * Uso: php scripts/seed_chamados_os_dados_ipojuca.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/config.php';
require_once $root . '/includes/db.php';
require_once $root . '/includes/chamado_os_fields.php';
require_once $root . '/includes/repository.php';

$pdo = db();
if (!$pdo) {
    fwrite(STDERR, "Banco indisponível.\n");
    exit(1);
}

$solicitantes = [
    ['Ana Paula Ferreira', '529.982.247-25', '(81) 99123-4501', 'ana.ferreira@email.com'],
    ['João Carlos Mendes', '384.761.092-88', '(81) 98765-1203', 'joao.mendes@email.com'],
    ['Francisca Alves', '701.445.623-09', '(81) 99334-7788', 'francisca.alves@email.com'],
    ['Roberto Nascimento', '156.892.334-71', '(81) 98211-3344', 'roberto.n@email.com'],
    ['Juliana Costa', '823.109.556-42', '(81) 99678-9012', 'juliana.costa@email.com'],
    ['Pedro Henrique Lima', '447.238.901-63', '(81) 98876-5543', 'pedro.lima@email.com'],
    ['Mariana Santos', '290.556.718-34', '(81) 99445-2210', 'mariana.s@email.com'],
    ['Antônio Souza', '618.334.902-17', '(81) 98123-6677', 'antonio.souza@email.com'],
    ['Carla Beatriz Rocha', '973.221.445-80', '(81) 99234-8890', 'carla.rocha@email.com'],
    ['Lucas Oliveira', '512.887.390-55', '(81) 98567-4432', 'lucas.oliveira@email.com'],
    ['Helena Duarte', '864.009.112-26', '(81) 99789-1100', 'helena.duarte@email.com'],
    ['Marcos Vinícius', '331.774.668-91', '(81) 98345-7766', 'marcos.v@email.com'],
    ['Patrícia Gomes', '205.998.341-07', '(81) 99012-3388', 'patricia.gomes@email.com'],
    ['Ricardo Barros', '778.443.290-18', '(81) 98654-2299', 'ricardo.barros@email.com'],
    ['Fernanda Melo', '449.112.887-53', '(81) 99567-8811', 'fernanda.melo@email.com'],
    ['Eduardo Campos', '692.556.103-44', '(81) 98432-5567', 'eduardo.campos@email.com'],
    ['Simone Pereira', '118.902.776-39', '(81) 99198-0044', 'simone.pereira@email.com'],
    ['Diego Martins', '557.334.821-60', '(81) 98901-7722', 'diego.martins@email.com'],
    ['Lúcia Helena', '903.221.554-72', '(81) 99321-6655', 'lucia.helena@email.com'],
    ['Gustavo Araújo', '266.778.990-15', '(81) 98712-9900', 'gustavo.araujo@email.com'],
];

$origens = array_keys(chamado_os_opcoes_origem());
$problemas = array_keys(chamado_os_opcoes_problema());
$tipos = array_keys(chamado_os_opcoes_tipo());

$st = $pdo->query("
    SELECT ch.id, ch.ponto_iluminacao_id, ch.titulo, ch.endereco_completo, ch.latitude, ch.longitude,
           ch.os_bairro, p.codigo_poste, p.endereco_completo AS ponto_endereco, p.referencia AS ponto_ref
    FROM chamados ch
    LEFT JOIN pontos_iluminacao p ON p.id = ch.ponto_iluminacao_id
    WHERE ch.titulo LIKE 'Seed Ipojuca —%'
    ORDER BY ch.id ASC
");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

if ($rows === []) {
    fwrite(STDERR, "Nenhum chamado Seed Ipojuca encontrado.\n");
    exit(1);
}

$ok = 0;
$fail = 0;
$i = 0;

foreach ($rows as $ch) {
    $sol = $solicitantes[$i % count($solicitantes)];
    $i++;

    $origem = $origens[$i % count($origens)];
    $problema = $problemas[$i % count($problemas)];
    $tipo = $tipos[$i % count($tipos)];

    $addr = seed_chamado_parse_endereco(
        (string) ($ch['ponto_endereco'] ?? $ch['endereco_completo'] ?? ''),
        (string) ($ch['os_bairro'] ?? 'Centro')
    );

    $codigoPoste = trim((string) ($ch['codigo_poste'] ?? ''));
    $pontoRef = trim((string) ($ch['ponto_ref'] ?? ''));
    if ($codigoPoste !== '') {
        $pontoRef = $pontoRef !== '' ? ('Poste ' . $codigoPoste . ' — ' . $pontoRef) : ('Poste ' . $codigoPoste);
    }

    $descricao = 'Solicitação registrada pela prefeitura de Ipojuca. ';
    if ($codigoPoste !== '') {
        $descricao .= 'Referência ao ' . $codigoPoste . '. ';
    }
    $descricao .= 'Morador relata ' . strtolower($problema) . ' no local indicado. Equipe de iluminação pública deve verificar rede, luminária e comando fotoelétrico.';

    $d = [
        'ponto_iluminacao_id' => !empty($ch['ponto_iluminacao_id']) ? (int) $ch['ponto_iluminacao_id'] : null,
        'titulo' => $problema . ' · ' . $origem,
        'descricao' => $descricao,
        'contribuinte_cpf' => $sol[1],
        'contribuinte_nome' => $sol[0],
        'contribuinte_telefone' => $sol[2],
        'contribuinte_email' => $sol[3],
        'data_abertura_os' => '2026-05-19',
        'origem_os' => $origem,
        'problema_os' => $problema,
        'tipo_os' => $tipo,
        'ponto_referencia' => $pontoRef !== '' ? $pontoRef : 'Próximo à prefeitura de Ipojuca',
        'os_cep' => $addr['cep'],
        'os_logradouro' => $addr['logradouro'],
        'os_numero' => $addr['numero'],
        'os_complemento' => $addr['complemento'],
        'os_bairro' => $addr['bairro'],
        'os_cidade' => $addr['cidade'],
        'os_uf' => $addr['uf'],
        'latitude' => $ch['latitude'] ?? null,
        'longitude' => $ch['longitude'] ?? null,
    ];

    if (repo_update_chamado_os_dados((int) $ch['id'], $d)) {
        echo "[ok] #{$ch['id']} {$sol[0]} | {$problema} | {$addr['logradouro']}, {$addr['numero']}\n";
        $ok++;
    } else {
        echo "[erro] #{$ch['id']}\n";
        $fail++;
    }
}

echo "\nAtualizados: {$ok}, falhas: {$fail}\n";
exit($fail > 0 ? 1 : 0);

/**
 * @return array{cep:string,logradouro:string,numero:string,complemento:string,bairro:string,cidade:string,uf:string}
 */
function seed_chamado_parse_endereco(string $texto, string $bairroDefault = 'Centro'): array
{
    $out = [
        'cep' => '55592-000',
        'logradouro' => 'R. Cel. João de Souza Leão',
        'numero' => 's/n',
        'complemento' => '',
        'bairro' => $bairroDefault !== '' ? $bairroDefault : 'Centro',
        'cidade' => 'Ipojuca',
        'uf' => 'PE',
    ];

    $t = trim($texto);
    if ($t === '') {
        return $out;
    }

    if (preg_match('/(\d{5}-?\d{3})/', $t, $mCep)) {
        $cep = preg_replace('/\D/', '', $mCep[1]);
        if (strlen($cep) === 8) {
            $out['cep'] = substr($cep, 0, 5) . '-' . substr($cep, 5, 3);
        }
    }

    if (preg_match('/\s-\s*([^,]+)\s*,\s*Ipojuca/i', $t, $mBai)) {
        $out['bairro'] = trim($mBai[1]);
    } elseif (preg_match('/,\s*([^,]+)\s*-\s*Ipojuca/i', $t, $mBai2)) {
        $out['bairro'] = trim($mBai2[1]);
    }

    $antesCidade = preg_split('/\s*-\s*Ipojuca/i', $t, 2)[0];
    $partes = array_map('trim', explode(',', (string) $antesCidade));

    if (isset($partes[0]) && $partes[0] !== '') {
        $out['logradouro'] = $partes[0];
    }
    if (preg_match('/,\s*(\d+|s\/n)\s*-/i', $t, $mNum2)) {
        $out['numero'] = $mNum2[1];
    } elseif (isset($partes[1]) && $partes[1] !== '' && !preg_match('/^(centro|ipojuca|\d{5})/i', $partes[1])) {
        if (preg_match('/^(\d+|s\/n)$/i', $partes[1])) {
            $out['numero'] = $partes[1];
        } else {
            $out['numero'] = 's/n';
            $out['complemento'] = $partes[1];
        }
    }

    if (stripos($t, 'esq.') !== false && $out['complemento'] === '') {
        $out['complemento'] = trim((string) preg_replace('/^[^,]+,\s*/', '', preg_split('/\s*-\s*Ipojuca/i', $t, 2)[0]));
    }

    return $out;
}

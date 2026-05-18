<?php
/**
 * Dados exibidos nas telas: com MySQL ativo vêm do repositório (mesmo formato).
 * Sem banco: listas vazias (sem dados fictícios).
 */

// --------- Helpers ---------

if (!function_exists('brl')) {
    function brl($v) {
        return 'R$ ' . number_format((float) $v, 2, ',', '.');
    }
}

if (!function_exists('initials')) {
    function initials($name) {
        $parts = preg_split('/\s+/', trim((string) $name));
        $a = mb_substr($parts[0] ?? '?', 0, 1, 'UTF-8');
        $b = mb_substr($parts[count($parts) - 1] ?? '', 0, 1, 'UTF-8');
        return strtoupper($a . $b);
    }
}

if (!function_exists('status_class')) {
    function status_class($status) {
        $map = [
            'Aberto'       => 'open',
            'Aberta'       => 'open',
            'Em andamento' => 'progress',
            'Respondendo'  => 'progress',
            'Aguardando Aprovação' => 'waiting',
            'Pendente'     => 'waiting',
            'Normal'       => 'waiting',
            'Baixa'        => 'waiting',
            'Alta'         => 'urgent',
            'Urgente'      => 'urgent',
            'Vencido'      => 'urgent',
            'Resolvido'    => 'done',
            'Fechado'      => 'done',
            'Cancelado'    => 'cancelled',
            'Pago'         => 'done',
            'Respondida'   => 'done',
            'Ativo'        => 'done',
            'Revisão'      => 'waiting',
            'Concluído'    => 'done',
            'A Fazer'      => 'open',
            'Em Progresso' => 'progress',
            'Em execução'  => 'progress',
            'Concluída'    => 'done',
            'Cancelada'    => 'urgent',
            'Medição (BM)' => 'done',
            'Rascunho'         => 'waiting',
            'Enviada ao cliente' => 'progress',
            'Aprovada'         => 'done',
            'Rejeitada'        => 'urgent',
            'Concluida'        => 'done',
        ];
        return $map[$status] ?? 'plain';
    }
}

// --------- Placeholders só para ?? na sidebar (sem sessão) ---------

$MOCK_USER_ADMIN = [
    'id'              => 0,
    'nome'            => 'Administrador',
    'email'           => '',
    'tipo'            => 'Admin',
    'empresa'         => '',
    'iniciais'        => 'AD',
    'perfil'          => 'admin',
    'is_super_admin'  => 0,
    'modulo_perfil'   => null,
];

$MOCK_USER_CLIENTE = [
    'id'                 => 0,
    'cliente_id'         => null,
    'nome'               => 'Cliente',
    'email'              => '',
    'tipo'               => 'Cliente',
    'empresa'            => '',
    'iniciais'           => 'CL',
    'perfil'             => 'cliente',
    'modulo_perfil'      => null,
];

$MOCK_USER_OPERADOR = [
    'id'            => 0,
    'cliente_id'    => null,
    'empresa_id'    => 1,
    'nome'          => 'Operador',
    'email'         => '',
    'tipo'          => 'Operador',
    'empresa'       => '',
    'iniciais'      => 'OP',
    'perfil'        => 'operador',
    'modulo_perfil' => null,
];

/** Sem banco ativo não há credenciais demo (use MySQL + usuarios). */
$MOCK_CREDENCIAIS = [];

$MOCK_CLIENTES = [];
$MOCK_CHAMADOS = [];
$MOCK_CHAMADO_RESPOSTAS = [];
$MOCK_CONTAS = [];
$MOCK_SUPORTE = [];
$MOCK_OS = [];

/** Estrutura fixa de colunas (Kanban sem dados = colunas vazias). */
$MOCK_KANBAN = [
    'todo'     => ['label' => 'A Fazer', 'cards' => []],
    'progress' => ['label' => 'Em Progresso', 'cards' => []],
    'review'   => ['label' => 'Revisão', 'cards' => []],
    'done'     => ['label' => 'Concluído', 'cards' => []],
];

$MOCK_APP_CONFIG = [];

if (!function_exists('find_by_id')) {
    function find_by_id($list, $id) {
        foreach ($list as $item) {
            if ((int) ($item['id'] ?? 0) === (int) $id) return $item;
        }
        return null;
    }
}

require_once __DIR__ . '/repository.php';

if (db_ok()) {
    try {
        $MOCK_CLIENTES          = repo_clientes();
        $MOCK_CHAMADOS          = repo_chamados();
        $MOCK_CHAMADO_RESPOSTAS = repo_chamado_respostas();
        $MOCK_CONTAS            = repo_contas();
        $MOCK_SUPORTE           = repo_suporte();
    } catch (Throwable $e) {
        /* mantém listas vazias / kanban vazio */
    }
}

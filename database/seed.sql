-- =====================================================
-- CRM Control - Dados iniciais (seed)
-- =====================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------
-- Clientes
-- -----------------------------------------------------
INSERT INTO clientes (id, nome, empresa, email, telefone, status, desde) VALUES
(1, 'Mariana Costa',   'Clínica Visão',      'mariana@clinicavisao.com.br', '(11) 98745-1001', 'Ativo',    '2024-03-12'),
(2, 'Ricardo Alves',   'Grupo Alfa',         'ricardo@grupoalfa.com',       '(11) 98811-4522', 'Ativo',    '2023-11-04'),
(3, 'Paula Nogueira',  'Construtora Max',    'paula@construmax.com.br',     '(11) 99110-2231', 'Ativo',    '2024-01-30'),
(4, 'Felipe Martins',  'Empresa Delta',      'felipe@empresadelta.com',     '(11) 94412-8877', 'Pendente', '2025-02-18'),
(5, 'Juliana Prado',   'Studio Criar',       'juliana@studiocriar.com.br',  '(11) 97711-5533', 'Ativo',    '2024-08-02'),
(6, 'Henrique Souza',  'TransLog Express',   'henrique@translog.com',       '(11) 98555-3321', 'Ativo',    '2023-05-22'),
(7, 'Carla Rezende',   'Boutique Laine',     'carla@laine.com.br',          '(11) 94432-1199', 'Fechado',  '2022-09-14'),
(8, 'Rodrigo Mendes',  'AgroVerde',          'rodrigo@agroverde.com',       '(11) 98833-7766', 'Ativo',    '2024-10-01');

-- -----------------------------------------------------
-- Chamados
-- -----------------------------------------------------
INSERT INTO chamados (id, cliente_id, titulo, descricao, prioridade, status, responsavel, aberto_em) VALUES
(1042, 1, 'Erro no acesso ao painel',
   'Desde ontem à noite o sistema retorna "sessão expirada" logo após o login. Já testamos em três computadores e acontece em todos. Precisamos urgentemente voltar a acessar a agenda do consultório.',
   'Urgente', 'Em andamento', 'Samuel', '2026-04-22 09:15:00'),
(1041, 2, '2ª via do boleto de abril',
   'Preciso da segunda via do boleto de mensalidade referente a abril/2026 para envio ao departamento financeiro.',
   'Normal', 'Aberto', 'Financeiro', '2026-04-22 10:03:00'),
(1038, 3, 'Solicitação de nova OS',
   'Gostaríamos de abrir uma nova OS para o canteiro de obras da zona leste. Anexo um PDF com as especificações iniciais.',
   'Alta', 'Aguardando', 'Ana', '2026-04-21 16:42:00'),
(1033, 1, 'Upload inválido de anexo',
   'Ao tentar anexar um PDF de exame o sistema retornava "formato inválido". Já foi corrigido após atualização.',
   'Baixa', 'Resolvido', 'Samuel', '2026-04-20 11:22:00'),
(1031, 4, 'Suporte para cadastro inicial',
   'Sou nova usuária da plataforma e preciso de ajuda para importar a planilha de clientes do nosso sistema antigo.',
   'Normal', 'Aberto', 'Ana', '2026-04-19 14:00:00'),
(1029, 6, 'Relatório mensal sem dados',
   'O relatório de desempenho mensal está vindo zerado mesmo com chamados registrados. Pode verificar?',
   'Alta', 'Em andamento', 'Samuel', '2026-04-19 09:31:00'),
(1025, 5, 'Alteração de contato principal',
   'Solicitamos atualização do e-mail principal de contato da conta.',
   'Baixa', 'Resolvido', 'Financeiro', '2026-04-17 18:10:00'),
(1022, 3, 'Instabilidade no chat interno',
   'O chat interno cai várias vezes ao dia. Impacta diretamente a comunicação entre a obra e o escritório.',
   'Urgente', 'Em andamento', 'Samuel', '2026-04-16 15:45:00'),
(1018, 8, 'Dúvida sobre tipo de conta',
   'Gostaríamos de entender a diferença entre os planos ofertados e qual se encaixa melhor em uma operação agrícola.',
   'Normal', 'Fechado', 'Ana', '2026-04-14 10:00:00');

UPDATE chamados SET
    endereco_completo = 'Av. Paulista, 1578 - Bela Vista, São Paulo - SP, CEP 01310-200',
    latitude = -23.5614140,
    longitude = -46.6558810
WHERE id = 1042;

UPDATE chamados SET
    endereco_completo = 'Rua Oscar Freire, 379 - Jardins, São Paulo - SP',
    latitude = -23.5629360,
    longitude = -46.6682420
WHERE id = 1041;

UPDATE chamados SET
    endereco_completo = 'Av. Brigadeiro Faria Lima, 3477 - Itaim Bibi, São Paulo - SP',
    latitude = -23.5874140,
    longitude = -46.6924140
WHERE id = 1038;

-- -----------------------------------------------------
-- Respostas do chamado 1042
-- -----------------------------------------------------
INSERT INTO chamado_respostas (chamado_id, autor, tipo, texto, enviado_em) VALUES
(1042, 'Mariana Costa', 'cliente', 'Bom dia! Desde ontem à noite nenhum colaborador consegue acessar. Aparece "sessão expirada" logo após logar. Já tentamos em vários computadores.', '2026-04-22 09:15:00'),
(1042, 'Samuel Lima',   'admin',   'Olá, Mariana. Recebemos o chamado e já estamos investigando. Pode me informar se algum navegador específico dá certo (Chrome/Edge)?',             '2026-04-22 09:32:00'),
(1042, 'Mariana Costa', 'cliente', 'Testei nos dois, mesmo problema. Também testei em um tablet e repete o erro.',                                                                 '2026-04-22 09:41:00'),
(1042, 'Samuel Lima',   'admin',   'Identificamos um problema no serviço de sessão. Em até 30 minutos o acesso deve estabilizar. Vou te avisar aqui mesmo quando confirmar o restabelecimento.', '2026-04-22 10:07:00');

-- -----------------------------------------------------
-- Ordens de Serviço
-- -----------------------------------------------------
INSERT INTO os (id, chamado_id, cliente_id, titulo, descricao, tipo, horas_previstas, horas_realizadas, valor_hora, status, responsavel, dentro_contrato, data_abertura, data_conclusao) VALUES
(2001, 1042, 1, 'Correção do serviço de sessão',
   'Investigação e correção do serviço que causava expiração imediata do login no painel.',
   'Corretiva',   4.00, 3.50, 180.00, 'Em execução', 'Samuel Lima', 1, '2026-04-22', NULL),
(2002, 1029, 6, 'Ajuste no relatório mensal de desempenho',
   'Relatório retornava zero — revisão da query e dos filtros de período.',
   'Corretiva',   2.00, 2.00, 180.00, 'Concluída',   'Samuel Lima', 1, '2026-04-19', '2026-04-20'),
(2003, 1038, 3, 'Abertura de OS da obra zona leste',
   'Configuração de módulo e cadastro inicial do canteiro e responsáveis.',
   'Implantação', 8.00, 6.00, 180.00, 'Em execução', 'Ana',         1, '2026-04-21', NULL),
(2004, NULL, 1, 'Consultoria de melhoria de fluxo',
   'Reunião e levantamento para padronizar agenda de atendimento.',
   'Consultoria', 6.00, 6.00, 180.00, 'Concluída',   'Samuel Lima', 1, '2026-04-05', '2026-04-08'),
(2005, NULL, 1, 'Treinamento de novos colaboradores',
   'Duas sessões online com a equipe administrativa.',
   'Treinamento', 4.00, 4.00, 180.00, 'Concluída',   'Ana',         1, '2026-04-12', '2026-04-13'),
(2006, NULL, 1, 'Integração com agenda externa',
   'Desenvolvimento pontual solicitado fora do escopo do contrato.',
   'Evolutiva',  12.00,10.50, 220.00, 'Em execução', 'Samuel Lima', 0, '2026-04-18', NULL);

-- -----------------------------------------------------
-- Kanban
-- -----------------------------------------------------
INSERT INTO kanban_cards (id, coluna, cliente_id, titulo, descricao, prioridade, responsaveis, prazo, chamado_id, ordem) VALUES
(201, 'todo',     1,    'Ajustar tela de boletos',        'Melhorar layout e adicionar botão direto para download do PDF.',              'Alta',         'SL,GP',   '24/04', NULL, 1),
(202, 'todo',     2,    'Criar onboarding do cliente',    'Primeiro acesso com passo a passo simples na dashboard inicial.',             'Normal',       'JS',      '29/04', NULL, 2),
(203, 'todo',     NULL, 'Revisar textos de e-mail',       'Alinhar tom de voz nas notificações automáticas.',                            'Normal',       'AN',      '30/04', NULL, 3),
(210, 'progress', 3,    'Filtro avançado de chamados',    'Filtrar por status, prioridade, cliente e período com UX mais limpa.',       'Em andamento', 'SL,AN',   '26/04', NULL, 1),
(211, 'progress', 1,    'Painel financeiro resumido',     'Mostrar pendências, pagos e próximos vencimentos logo no topo.',              'Alta',         'FI',      '28/04', NULL, 2),
(220, 'review',   2,    'Fluxo de abertura de OS',        'Validação final antes de liberar para cliente com histórico e comentários.', 'Revisão',      'QA,SL',   '25/04', NULL, 1),
(230, 'done',     NULL, 'Sidebar com permissões',         'Menu separado para admin e cliente com rodapé e avatar.',                     'Concluído',    'SL',      '20/04', NULL, 1),
(231, 'done',     NULL, 'Dashboard com métricas',         'Cards principais finalizados com visual premium e legível.',                  'Concluído',    'UI',      '19/04', NULL, 2);

-- -----------------------------------------------------
-- Contas
-- -----------------------------------------------------
INSERT INTO contas (id, cliente_id, descricao, valor, vencimento, status) VALUES
(901, 1, 'Mensalidade CRM - Abril/2026',  1850.00, '2026-04-24', 'Pendente'),
(902, 2, 'Suporte técnico - Abril/2026',   950.00, '2026-04-26', 'Pendente'),
(903, 3, 'Hospedagem + manutenção',       3500.00, '2026-04-28', 'Pendente'),
(904, 1, 'Implantação módulo OS',         6200.00, '2026-04-30', 'Pendente'),
(905, 6, 'Mensalidade CRM - Março/2026',  2100.00, '2026-03-24', 'Pago'),
(906, 3, 'Mensalidade CRM - Março/2026',  1800.00, '2026-03-24', 'Pago'),
(907, 4, 'Setup inicial',                  450.00, '2026-04-10', 'Vencido'),
(908, 8, 'Mensalidade CRM - Abril/2026',  1200.00, '2026-04-25', 'Pendente');

-- -----------------------------------------------------
-- Suporte / dúvidas
-- -----------------------------------------------------
INSERT INTO suporte (id, cliente_id, pergunta, detalhe, status, resposta, enviado_em) VALUES
(501, 1, 'Como emitir a segunda via do boleto?',              'Cliente relatou dificuldade para localizar o botão de download dentro da área financeira.', 'Aberta',      '',                                                                                                                    '2026-04-22 08:14:00'),
(502, 2, 'Posso anexar PDF e imagem no mesmo chamado?',       'Dúvida recorrente sobre upload em abertura de chamado e limite de arquivos aceitos.',        'Respondendo', '',                                                                                                                    '2026-04-22 07:48:00'),
(503, 3, 'Solicitação de acesso para novo colaborador',       'Empresa pediu criação de um login cliente adicional com permissões limitadas.',             'Pendente',    '',                                                                                                                    '2026-04-22 06:10:00'),
(504, 5, 'Vocês enviam nota fiscal automática após pagamento?','Pergunta da financeira da empresa sobre conciliação contábil.',                            'Respondida',  'Sim, a nota fiscal é emitida automaticamente em até 24h após a confirmação do pagamento e enviada por e-mail.',         '2026-04-21 18:32:00'),
(505, 6, 'Consigo exportar um relatório de chamados em CSV?', 'Relatório mensal por cliente, filtrando por status e prioridade.',                          'Respondida',  'Sim! Dentro da tela de Chamados use o botão "Exportar" no topo direito. Você pode filtrar antes pelos critérios desejados.', '2026-04-20 14:10:00');

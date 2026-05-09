-- =====================================================
-- CRM Control - Schema do banco
-- MySQL / MariaDB - utf8mb4
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS chamado_respostas;
DROP TABLE IF EXISTS chamado_anexos;
DROP TABLE IF EXISTS chamado_templates;
DROP TABLE IF EXISTS os;
DROP TABLE IF EXISTS kanban_cards;
DROP TABLE IF EXISTS pix_chaves_globais;
DROP TABLE IF EXISTS app_config;
DROP TABLE IF EXISTS contas;
DROP TABLE IF EXISTS suporte;
DROP TABLE IF EXISTS os_pedido_anexos;
DROP TABLE IF EXISTS os_pedido_respostas;
DROP TABLE IF EXISTS os_pedido_itens;
DROP TABLE IF EXISTS os_pedidos;
DROP TABLE IF EXISTS medicao_import_linhas;
DROP TABLE IF EXISTS medicao_imports;
DROP TABLE IF EXISTS chamado_itens;
DROP TABLE IF EXISTS chamados;
DROP TABLE IF EXISTS ponto_iluminacao_imagens;
DROP TABLE IF EXISTS pontos_iluminacao;
DROP TABLE IF EXISTS cliente_itens;
DROP TABLE IF EXISTS cliente_anexos;
DROP TABLE IF EXISTS perfil_modulos;
DROP TABLE IF EXISTS saas_modulos;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS clientes;

-- -----------------------------------------------------
-- Clientes (empresas)
-- -----------------------------------------------------
CREATE TABLE clientes (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id         INT UNSIGNED NULL DEFAULT NULL,
    nome               VARCHAR(120) NOT NULL,
    empresa            VARCHAR(120) NOT NULL,
    email              VARCHAR(150) DEFAULT NULL,
    telefone           VARCHAR(30)  DEFAULT NULL,
    doc                VARCHAR(30)  DEFAULT NULL,
    status             ENUM('Ativo','Pendente','Fechado') NOT NULL DEFAULT 'Ativo',
    desde              DATE         DEFAULT NULL,
    obs                TEXT         DEFAULT NULL,
    criado_em          DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_clientes_status (status),
    INDEX idx_clientes_empresa_pai (empresa_id),
    CONSTRAINT fk_clientes_empresa_pai FOREIGN KEY (empresa_id) REFERENCES clientes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- Usuários (admin + cliente que faz login)
-- -----------------------------------------------------
CREATE TABLE usuarios (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome            VARCHAR(120) NOT NULL,
    email           VARCHAR(150) NOT NULL UNIQUE,
    senha_hash      VARCHAR(255) NOT NULL,
    perfil          ENUM('admin','cliente','operador','gestor') NOT NULL,
    is_super_admin  TINYINT(1) NOT NULL DEFAULT 0,
    modulo_perfil   VARCHAR(64) NULL DEFAULT NULL,
    cliente_id      INT UNSIGNED DEFAULT NULL,
    empresa_id      INT UNSIGNED DEFAULT NULL,
    iniciais        VARCHAR(4)   DEFAULT NULL,
    criado_em       DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
    FOREIGN KEY (empresa_id) REFERENCES clientes(id) ON DELETE SET NULL,
    INDEX idx_usuarios_empresa (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Módulos ativáveis (SaaS): sidebar admin/cliente + bloqueio de rotas
CREATE TABLE saas_modulos (
    grupo        ENUM('super_admin','gestor','cliente','operador') NOT NULL,
    modulo_key VARCHAR(64) NOT NULL,
    label        VARCHAR(120) NOT NULL,
    habilitado   TINYINT(1) NOT NULL DEFAULT 1,
    ordem        INT NOT NULL DEFAULT 0,
    PRIMARY KEY (grupo, modulo_key),
    INDEX idx_saas_grupo (grupo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO saas_modulos (grupo, modulo_key, label, habilitado, ordem) VALUES
('super_admin', 'dashboard', 'Dashboard', 1, 3),
('super_admin', 'clientes', 'Clientes', 1, 10),
('super_admin', 'usuarios', 'Usuários', 1, 20),
('super_admin', 'chamados', 'Chamados', 1, 30),
('super_admin', 'medicao', 'Medição', 1, 35),
('super_admin', 'os', 'OS (aprovação)', 1, 36),
('super_admin', 'pontos_iluminacao', 'Pontos de iluminação', 1, 45),
('super_admin', 'catalogo', 'Produtos e serviços', 1, 65),
('super_admin', 'relatorio_financeiro', 'Relatório financeiro', 1, 70),
('super_admin', 'configuracoes', 'Configurações', 1, 80),
('super_admin', 'suporte', 'Suporte interno', 1, 90),
('gestor', 'dashboard', 'Dashboard', 1, 3),
('gestor', 'clientes', 'Clientes (empresa)', 1, 5),
('gestor', 'chamados', 'Chamados', 1, 10),
('gestor', 'medicao', 'Medição', 1, 15),
('gestor', 'os', 'OS (aprovação)', 1, 16),
('gestor', 'usuarios', 'Usuários e operadores', 1, 20),
('gestor', 'pontos_iluminacao', 'Pontos de iluminação', 1, 45),
('gestor', 'catalogo', 'Produtos e serviços', 1, 65),
('cliente', 'chamados', 'Meus chamados', 1, 10),
('cliente', 'medicao', 'Medição', 1, 15),
('cliente', 'os', 'Minhas OS', 1, 16),
('cliente', 'pontos_iluminacao', 'Pontos de iluminação', 1, 25),
('cliente', 'documentos', 'Documentos', 1, 40),
('cliente', 'suporte', 'Suporte', 1, 50),
('operador', 'chamados', 'Chamados', 1, 10);

-- -----------------------------------------------------
-- Anexos do cliente (contratos, documentos)
-- -----------------------------------------------------
CREATE TABLE cliente_anexos (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id     INT UNSIGNED NOT NULL,
    nome_original  VARCHAR(255) NOT NULL,
    nome_arquivo   VARCHAR(255) NOT NULL,
    mime           VARCHAR(100) DEFAULT NULL,
    tamanho        INT UNSIGNED DEFAULT 0,
    tipo           ENUM('Contrato','Documento','Identidade','Outro') NOT NULL DEFAULT 'Outro',
    descricao      VARCHAR(200) DEFAULT NULL,
    enviado_por    VARCHAR(120) DEFAULT NULL,
    enviado_em     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    INDEX idx_anexo_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- Itens por cliente (produtos e serviços — valor unitário para chamados)
-- -----------------------------------------------------
CREATE TABLE cliente_itens (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id       INT UNSIGNED NOT NULL,
    empresa_id       INT UNSIGNED NULL DEFAULT NULL,
    tipo             ENUM('produto','servico') NOT NULL DEFAULT 'servico',
    nome             VARCHAR(160) NOT NULL,
    codigo           VARCHAR(64) NULL DEFAULT NULL,
    unidade          VARCHAR(20) NOT NULL DEFAULT 'UN',
    valor_unitario   DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    descricao        VARCHAR(500) NULL DEFAULT NULL,
    ativo            TINYINT(1) NOT NULL DEFAULT 1,
    ordem            INT NOT NULL DEFAULT 0,
    criado_em        DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    FOREIGN KEY (empresa_id) REFERENCES clientes(id) ON DELETE SET NULL,
    INDEX idx_ci_cliente (cliente_id, ativo, ordem),
    INDEX idx_ci_empresa (empresa_id, ativo, ordem),
    INDEX idx_ci_codigo (cliente_id, codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- Pontos de iluminação pública (postes)
-- -----------------------------------------------------
CREATE TABLE pontos_iluminacao (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id            INT UNSIGNED NOT NULL,
    codigo_poste          VARCHAR(80) NOT NULL,
    identificador_externo VARCHAR(120) NULL DEFAULT NULL,
    endereco_completo     TEXT NULL,
    bairro                VARCHAR(120) NULL DEFAULT NULL,
    referencia            VARCHAR(255) NULL DEFAULT NULL,
    latitude              DECIMAL(10,7) NULL,
    longitude             DECIMAL(11,7) NULL,
    status                ENUM('Ativo','Inativo') NOT NULL DEFAULT 'Ativo',
    observacoes           TEXT NULL,
    criado_em             DATETIME DEFAULT CURRENT_TIMESTAMP,
    atualizado_em         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    UNIQUE KEY uk_ponto_cliente_codigo (cliente_id, codigo_poste),
    INDEX idx_ponto_cliente (cliente_id, status),
    INDEX idx_ponto_geo (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ponto_iluminacao_imagens (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ponto_iluminacao_id   INT UNSIGNED NOT NULL,
    nome_original         VARCHAR(255) NOT NULL,
    nome_arquivo          VARCHAR(255) NOT NULL,
    mime                  VARCHAR(100) NULL,
    tamanho               INT UNSIGNED NOT NULL DEFAULT 0,
    principal             TINYINT(1) NOT NULL DEFAULT 0,
    ordem                 INT NOT NULL DEFAULT 0,
    enviado_em            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ponto_img_ponto (ponto_iluminacao_id, principal, ordem),
    CONSTRAINT fk_ponto_img_ponto FOREIGN KEY (ponto_iluminacao_id) REFERENCES pontos_iluminacao(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- Chamados
-- -----------------------------------------------------
CREATE TABLE chamados (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id         INT UNSIGNED NOT NULL,
    ponto_iluminacao_id INT UNSIGNED NULL,
    titulo             VARCHAR(200) NOT NULL,
    descricao          TEXT,
    contribuinte_cpf   VARCHAR(20) NULL,
    contribuinte_nome  VARCHAR(200) NULL,
    contribuinte_telefone VARCHAR(40) NULL,
    contribuinte_email VARCHAR(160) NULL,
    data_abertura_os   DATE NULL,
    origem_os          VARCHAR(80) NULL,
    problema_os        VARCHAR(120) NULL,
    tipo_os            VARCHAR(80) NULL,
    ponto_referencia   VARCHAR(255) NULL,
    os_cep             VARCHAR(12) NULL,
    os_logradouro      VARCHAR(255) NULL,
    os_numero          VARCHAR(32) NULL,
    os_complemento     VARCHAR(160) NULL,
    os_bairro          VARCHAR(120) NULL,
    os_cidade          VARCHAR(160) NULL,
    os_uf              CHAR(2) NULL,
    endereco_completo  TEXT NULL,
    latitude           DECIMAL(10,7) NULL,
    longitude          DECIMAL(11,7) NULL,
    servico_id                      INT UNSIGNED NULL,
    finalizado_operador_em          DATETIME NULL,
    finalizado_operador_user_id     INT UNSIGNED NULL,
    tecnico_user_id                 INT UNSIGNED NULL,
    aprovado_gestor_em              DATETIME NULL,
    aprovado_gestor_user_id         INT UNSIGNED NULL,
    checklist_realizado             TEXT NULL,
    prioridade         ENUM('Baixa','Normal','Alta','Urgente') NOT NULL DEFAULT 'Normal',
    status               ENUM('Aberto','Em andamento','Aguardando','Resolvido','Fechado','Cancelado') NOT NULL DEFAULT 'Aberto',
    responsavel        VARCHAR(80) DEFAULT NULL,
    aberto_em          DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    FOREIGN KEY (ponto_iluminacao_id) REFERENCES pontos_iluminacao(id) ON DELETE SET NULL,
    FOREIGN KEY (servico_id) REFERENCES cliente_itens(id) ON DELETE SET NULL,
    FOREIGN KEY (finalizado_operador_user_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    FOREIGN KEY (tecnico_user_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    FOREIGN KEY (aprovado_gestor_user_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_chamados_status (status),
    INDEX idx_chamados_cliente (cliente_id),
    INDEX idx_chamados_ponto_iluminacao (ponto_iluminacao_id),
    INDEX idx_chamados_aberto_em (aberto_em),
    INDEX idx_chamados_servico (servico_id),
    INDEX idx_chamados_tecnico (tecnico_user_id),
    INDEX idx_chamados_aprovado (aprovado_gestor_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1001;

-- -----------------------------------------------------
-- Materiais / itens consumidos no chamado (quantidade × valor)
-- -----------------------------------------------------
CREATE TABLE chamado_itens (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chamado_id      INT UNSIGNED NOT NULL,
    item_id          INT UNSIGNED NOT NULL,
    movimento        ENUM('utilizado','devolvido') NOT NULL DEFAULT 'utilizado',
    quantidade       DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
    valor_unitario   DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    subtotal         DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    observacao       VARCHAR(255) NULL DEFAULT NULL,
    criado_em        DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES cliente_itens(id) ON DELETE RESTRICT,
    INDEX idx_chamado_itens_chamado (chamado_id),
    INDEX idx_chamado_itens_item (item_id),
    INDEX idx_chamado_itens_movimento (chamado_id, movimento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- Respostas da thread do chamado
-- -----------------------------------------------------
CREATE TABLE chamado_respostas (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chamado_id  INT UNSIGNED NOT NULL,
    autor       VARCHAR(120) NOT NULL,
    tipo        ENUM('admin','cliente','operador') NOT NULL,
    texto       TEXT NOT NULL,
    interna     TINYINT(1) NOT NULL DEFAULT 0,
    enviado_em  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
    INDEX idx_resp_chamado (chamado_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- Anexos de chamados
-- -----------------------------------------------------
CREATE TABLE chamado_anexos (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chamado_id     INT UNSIGNED NOT NULL,
    resposta_id    INT UNSIGNED DEFAULT NULL,
    nome_original  VARCHAR(255) NOT NULL,
    nome_arquivo   VARCHAR(255) NOT NULL,
    mime           VARCHAR(100) DEFAULT NULL,
    tamanho        INT UNSIGNED DEFAULT 0,
    enviado_por    VARCHAR(120) DEFAULT NULL,
    enviado_tipo   ENUM('admin','cliente','operador') NOT NULL DEFAULT 'admin',
    enviado_em     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
    INDEX idx_chanexo_chamado (chamado_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- Templates de resposta
-- -----------------------------------------------------
CREATE TABLE chamado_templates (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titulo     VARCHAR(120) NOT NULL,
    corpo      TEXT NOT NULL,
    ordem      INT NOT NULL DEFAULT 0,
    criado_em  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- Ordens de Serviço
-- -----------------------------------------------------
CREATE TABLE os (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chamado_id        INT UNSIGNED DEFAULT NULL,
    cliente_id        INT UNSIGNED NOT NULL,
    titulo            VARCHAR(200) NOT NULL,
    descricao         TEXT,
    tipo              ENUM('Corretiva','Evolutiva','Implantação','Consultoria','Treinamento') NOT NULL DEFAULT 'Corretiva',
    horas_previstas   DECIMAL(6,2) NOT NULL DEFAULT 0,
    horas_realizadas  DECIMAL(6,2) NOT NULL DEFAULT 0,
    valor_hora        DECIMAL(10,2) NOT NULL DEFAULT 0,
    status            ENUM('Aberta','Em execução','Concluída','Cancelada') NOT NULL DEFAULT 'Aberta',
    responsavel       VARCHAR(80) DEFAULT NULL,
    dentro_contrato   TINYINT(1)  NOT NULL DEFAULT 1,
    data_abertura     DATE DEFAULT NULL,
    data_conclusao    DATE DEFAULT NULL,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE SET NULL,
    INDEX idx_os_cliente (cliente_id),
    INDEX idx_os_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=2001;

-- -----------------------------------------------------
-- Kanban
-- -----------------------------------------------------
CREATE TABLE kanban_cards (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    coluna        ENUM('todo','progress','review','done') NOT NULL DEFAULT 'todo',
    cliente_id    INT UNSIGNED DEFAULT NULL,
    titulo        VARCHAR(200) NOT NULL,
    descricao     TEXT,
    prioridade    VARCHAR(40) DEFAULT NULL,
    responsaveis  VARCHAR(120) DEFAULT NULL,
    prazo         VARCHAR(20)  DEFAULT NULL,
    chamado_id    INT UNSIGNED DEFAULT NULL,
    ordem         INT NOT NULL DEFAULT 0,
    FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE SET NULL,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
    INDEX idx_kanban_coluna (coluna),
    INDEX idx_kanban_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=200;

-- -----------------------------------------------------
-- Contas
-- -----------------------------------------------------
CREATE TABLE contas (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id        INT UNSIGNED NOT NULL,
    tipo_cobranca     ENUM('setup','mensalidade','os') NOT NULL DEFAULT 'mensalidade',
    os_id             INT UNSIGNED NULL,
    descricao         VARCHAR(200) NOT NULL,
    valor             DECIMAL(10,2) NOT NULL,
    vencimento        DATE NOT NULL,
    status            ENUM('Pendente','Pago','Vencido') NOT NULL DEFAULT 'Pendente',
    observacoes       TEXT NULL,
    boleto_linha      VARCHAR(54) NULL,
    boleto_url        VARCHAR(512) NULL,
    boleto_arquivo    VARCHAR(255) NULL,
    boleto_original   VARCHAR(255) NULL,
    pix_chave         VARCHAR(200) NULL,
    pix_tipo          VARCHAR(64) NULL,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    FOREIGN KEY (os_id) REFERENCES os(id) ON DELETE SET NULL,
    INDEX idx_contas_status (status),
    INDEX idx_contas_os (os_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=901;

-- -----------------------------------------------------
-- Chaves PIX globais da empresa (várias; uma pode ser "padrão")
-- -----------------------------------------------------
CREATE TABLE pix_chaves_globais (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rotulo     VARCHAR(100) NOT NULL DEFAULT '',
    tipo       VARCHAR(64)  NOT NULL,
    chave      VARCHAR(255) NOT NULL,
    padrao     TINYINT(1)   NOT NULL DEFAULT 0,
    ordem      INT          NOT NULL DEFAULT 0,
    criado_em  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pix_chaves_padrao (padrao),
    INDEX idx_pix_chaves_ordem (ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- Configurações (chave/valor) — PIX global, textos, etc.
-- -----------------------------------------------------
CREATE TABLE app_config (
    chave          VARCHAR(64) NOT NULL PRIMARY KEY,
    valor          TEXT NULL,
    atualizado_em  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO app_config (chave, valor) VALUES
('pix_chave_global', ''),
('pix_tipo_global', ''),
('pix_tipos_extras', '[]'),
('debug_mode', '0'),
('clientes_modo', 'unico'),
('mail_from', ''),
('mail_from_name', ''),
('smtp_enabled', '0'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_encryption', 'tls'),
('smtp_user', ''),
('smtp_password', '');

-- -----------------------------------------------------
-- Suporte / dúvidas
-- -----------------------------------------------------
CREATE TABLE suporte (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id  INT UNSIGNED NOT NULL,
    pergunta    VARCHAR(200) NOT NULL,
    detalhe     TEXT,
    status      ENUM('Aberta','Respondendo','Pendente','Respondida','Fechada') NOT NULL DEFAULT 'Aberta',
    resposta    TEXT,
    enviado_em  DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=501;

-- -----------------------------------------------------
-- Importação de planilhas tipo BM (boletim) por mês AAAA-MM
-- -----------------------------------------------------
CREATE TABLE medicao_imports (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_matriz_id   INT UNSIGNED NOT NULL,
    ref_ym              CHAR(7) NOT NULL COMMENT 'AAAA-MM',
    nome_arquivo        VARCHAR(255) NOT NULL,
    importado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    importado_por       VARCHAR(120) NULL DEFAULT NULL,
    idx_qtd_medido      SMALLINT UNSIGNED NULL DEFAULT NULL,
    idx_valor_medido    SMALLINT UNSIGNED NULL DEFAULT NULL,
    UNIQUE KEY uk_medicao_import_matriz_ym (cliente_matriz_id, ref_ym),
    INDEX idx_medicao_import_ym (ref_ym),
    CONSTRAINT fk_medicao_import_cliente FOREIGN KEY (cliente_matriz_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE medicao_import_linhas (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_id             INT UNSIGNED NOT NULL,
    item_codigo           VARCHAR(32) NOT NULL,
    descricao             TEXT NULL,
    unidade               VARCHAR(20) NULL DEFAULT NULL,
    qtd_prevista          DECIMAL(18,4) NULL DEFAULT NULL,
    qtd_total             DECIMAL(18,4) NULL DEFAULT NULL,
    preco_unitario        DECIMAL(18,4) NULL DEFAULT NULL,
    qtd_medido_periodo    DECIMAL(18,4) NULL DEFAULT NULL,
    valor_medido_periodo  DECIMAL(18,4) NULL DEFAULT NULL,
    ordem                 INT NOT NULL DEFAULT 0,
    FOREIGN KEY (import_id) REFERENCES medicao_imports(id) ON DELETE CASCADE,
    INDEX idx_medicao_import_linhas_import (import_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------
-- OS com aprovação do cliente (sem operador; mês de referência ref_ym)
-- -----------------------------------------------------
CREATE TABLE os_pedidos (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id          INT UNSIGNED NOT NULL,
    ref_ym              CHAR(7) NOT NULL,
    titulo              VARCHAR(200) NOT NULL,
    descricao           TEXT,
    endereco_completo   TEXT NULL,
    latitude            DECIMAL(10,7) NULL,
    longitude           DECIMAL(11,7) NULL,
    servico_id          INT UNSIGNED NULL,
    prioridade          ENUM('Baixa','Normal','Alta','Urgente') NOT NULL DEFAULT 'Normal',
    status              ENUM(
        'Rascunho',
        'Enviada ao cliente',
        'Aprovada',
        'Rejeitada',
        'Concluida',
        'Cancelada'
    ) NOT NULL DEFAULT 'Rascunho',
    responsavel         VARCHAR(80) NULL,
    aberto_em           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviada_cliente_em  DATETIME NULL,
    aprovada_cliente_em DATETIME NULL,
    rejeitada_motivo    TEXT NULL,
    criado_por_user_id  INT UNSIGNED NULL,
    INDEX idx_os_ped_ref (cliente_id, ref_ym),
    INDEX idx_os_ped_status (status),
    CONSTRAINT fk_os_ped_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    CONSTRAINT fk_os_ped_servico FOREIGN KEY (servico_id) REFERENCES cliente_itens(id) ON DELETE SET NULL,
    CONSTRAINT fk_os_ped_user FOREIGN KEY (criado_por_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=5001;

CREATE TABLE os_pedido_itens (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    os_pedido_id     INT UNSIGNED NOT NULL,
    item_id          INT UNSIGNED NOT NULL,
    movimento        ENUM('utilizado','devolvido') NOT NULL DEFAULT 'utilizado',
    quantidade       DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
    valor_unitario   DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    subtotal         DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    observacao       VARCHAR(255) NULL,
    criado_em        DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ospi_ped FOREIGN KEY (os_pedido_id) REFERENCES os_pedidos(id) ON DELETE CASCADE,
    CONSTRAINT fk_ospi_item FOREIGN KEY (item_id) REFERENCES cliente_itens(id) ON DELETE RESTRICT,
    INDEX idx_ospi_ped (os_pedido_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE os_pedido_respostas (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    os_pedido_id   INT UNSIGNED NOT NULL,
    autor          VARCHAR(120) NOT NULL,
    tipo           ENUM('admin','cliente') NOT NULL,
    texto          TEXT NOT NULL,
    interna        TINYINT(1) NOT NULL DEFAULT 0,
    enviado_em     DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ospr_ped FOREIGN KEY (os_pedido_id) REFERENCES os_pedidos(id) ON DELETE CASCADE,
    INDEX idx_ospr_ped (os_pedido_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE os_pedido_anexos (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    os_pedido_id   INT UNSIGNED NOT NULL,
    resposta_id    INT UNSIGNED NULL,
    nome_original  VARCHAR(255) NOT NULL,
    nome_arquivo   VARCHAR(255) NOT NULL,
    mime           VARCHAR(100) NULL,
    tamanho        INT UNSIGNED DEFAULT 0,
    enviado_por    VARCHAR(120) NULL,
    enviado_tipo   ENUM('admin','cliente') NOT NULL DEFAULT 'admin',
    enviado_em     DATETIME DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ospa_ped FOREIGN KEY (os_pedido_id) REFERENCES os_pedidos(id) ON DELETE CASCADE,
    INDEX idx_ospa_ped (os_pedido_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

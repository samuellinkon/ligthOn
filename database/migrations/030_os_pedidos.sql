-- OS (ordens de serviço com aprovação do cliente) — paralelo a chamados, sem operador, agrupado por mês (ref_ym).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS os_pedidos (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id          INT UNSIGNED NOT NULL,
    ref_ym              CHAR(7) NOT NULL COMMENT 'AAAA-MM mês de referência',
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
    INDEX idx_os_ped_aberto (aberto_em),
    CONSTRAINT fk_os_ped_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE,
    CONSTRAINT fk_os_ped_servico FOREIGN KEY (servico_id) REFERENCES cliente_itens(id) ON DELETE SET NULL,
    CONSTRAINT fk_os_ped_user FOREIGN KEY (criado_por_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=5001;

CREATE TABLE IF NOT EXISTS os_pedido_itens (
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

CREATE TABLE IF NOT EXISTS os_pedido_respostas (
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

CREATE TABLE IF NOT EXISTS os_pedido_anexos (
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

INSERT IGNORE INTO saas_modulos (grupo, modulo_key, label, habilitado, ordem) VALUES
('super_admin', 'os', 'OS (aprovação)', 1, 36),
('gestor',      'os', 'OS (aprovação)', 1, 16),
('cliente',     'os', 'Minhas OS', 1, 16);

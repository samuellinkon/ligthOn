-- Migration 001: tabela de anexos do cliente
-- Rode uma vez em instalações existentes.

CREATE TABLE IF NOT EXISTS cliente_anexos (
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

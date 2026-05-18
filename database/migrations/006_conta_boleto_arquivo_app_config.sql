-- Arquivo de boleto (PDF) por cobrança + configurações globais (ex.: PIX padrão)
ALTER TABLE contas ADD COLUMN boleto_arquivo VARCHAR(255) NULL;
ALTER TABLE contas ADD COLUMN boleto_original VARCHAR(255) NULL;

CREATE TABLE IF NOT EXISTS app_config (
    chave VARCHAR(64) NOT NULL PRIMARY KEY,
    valor TEXT NULL,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_config (chave, valor) VALUES
('pix_chave_global', ''),
('pix_tipo_global', '');

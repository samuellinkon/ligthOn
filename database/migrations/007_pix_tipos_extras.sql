-- Tipos de chave PIX customizados (JSON em app_config) + mais espaço para rótulo
ALTER TABLE contas MODIFY COLUMN pix_tipo VARCHAR(64) NULL;

INSERT IGNORE INTO app_config (chave, valor) VALUES
('pix_tipos_extras', '[]');

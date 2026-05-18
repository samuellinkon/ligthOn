-- Catálogo por cliente renomeado para cliente_itens (produto/serviço + valor) e consumo por chamado (chamado_itens).
-- Pré-requisito: migração 016 (tabela cliente_servicos e FK em chamados).
-- Remove FK chamados.servico_id -> cliente_servicos se existir (nome pode ser fk_chamados_servico ou auto-gerado;
-- se 016 foi ignorada por coluna duplicada, a FK pode não existir).

SET @fk017 := (
  SELECT CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'chamados'
    AND COLUMN_NAME = 'servico_id'
    AND REFERENCED_TABLE_NAME = 'cliente_servicos'
  LIMIT 1
);
SET @sql017 := IF(@fk017 IS NOT NULL,
  CONCAT('ALTER TABLE chamados DROP FOREIGN KEY `', @fk017, '`'),
  'SET @noop017 = 1');
PREPARE stmt017 FROM @sql017;
EXECUTE stmt017;
DEALLOCATE PREPARE stmt017;

ALTER TABLE cliente_servicos
    ADD COLUMN tipo ENUM('produto','servico') NOT NULL DEFAULT 'servico' AFTER cliente_id,
    ADD COLUMN codigo VARCHAR(64) NULL DEFAULT NULL AFTER nome,
    ADD COLUMN unidade VARCHAR(20) NOT NULL DEFAULT 'UN' AFTER codigo,
    ADD COLUMN valor_unitario DECIMAL(12,4) NOT NULL DEFAULT 0.0000 AFTER unidade,
    ADD COLUMN descricao VARCHAR(500) NULL DEFAULT NULL AFTER valor_unitario;

RENAME TABLE cliente_servicos TO cliente_itens;

ALTER TABLE chamados
    ADD CONSTRAINT fk_chamados_item_principal
    FOREIGN KEY (servico_id) REFERENCES cliente_itens(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS chamado_itens (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chamado_id      INT UNSIGNED NOT NULL,
    item_id          INT UNSIGNED NOT NULL,
    quantidade       DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
    valor_unitario   DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    subtotal         DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    criado_em        DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES cliente_itens(id) ON DELETE RESTRICT,
    INDEX idx_chamado_itens_chamado (chamado_id),
    INDEX idx_chamado_itens_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

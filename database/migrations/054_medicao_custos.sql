-- Custos adicionais na medição mensal (aprovação do cliente antes de compor o BM).
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS medicao_custos (
    id                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_matriz_id       INT UNSIGNED NOT NULL,
    ref_ym                  CHAR(7) NOT NULL COMMENT 'AAAA-MM',
    item_id                 INT UNSIGNED NULL,
    item_codigo             VARCHAR(32) NOT NULL DEFAULT '',
    descricao               VARCHAR(255) NOT NULL,
    unidade                 VARCHAR(20) NOT NULL DEFAULT 'UN',
    quantidade              DECIMAL(12,4) NOT NULL DEFAULT 1.0000,
    valor_unitario          DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
    valor_total             DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    status                  ENUM('Pendente','Aprovado','Rejeitado') NOT NULL DEFAULT 'Pendente',
    observacao              TEXT NULL,
    rejeitado_motivo        TEXT NULL,
    criado_por_user_id      INT UNSIGNED NULL,
    criado_em               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aprovado_em             DATETIME NULL,
    aprovado_por_user_id    INT UNSIGNED NULL,
    rejeitado_em            DATETIME NULL,
    rejeitado_por_user_id   INT UNSIGNED NULL,
    INDEX idx_medicao_custos_matriz_ym (cliente_matriz_id, ref_ym),
    INDEX idx_medicao_custos_matriz_ym_status (cliente_matriz_id, ref_ym, status),
    INDEX idx_medicao_custos_ym (ref_ym),
    CONSTRAINT fk_medicao_custos_cliente FOREIGN KEY (cliente_matriz_id) REFERENCES clientes(id) ON DELETE CASCADE,
    CONSTRAINT fk_medicao_custos_item FOREIGN KEY (item_id) REFERENCES cliente_itens(id) ON DELETE SET NULL,
    CONSTRAINT fk_medicao_custos_criado_por FOREIGN KEY (criado_por_user_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_medicao_custos_aprovado_por FOREIGN KEY (aprovado_por_user_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    CONSTRAINT fk_medicao_custos_rejeitado_por FOREIGN KEY (rejeitado_por_user_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

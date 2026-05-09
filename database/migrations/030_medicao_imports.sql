-- Importação de planilhas tipo BM (boletim de medição) por mês de referência.
CREATE TABLE IF NOT EXISTS medicao_imports (
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

CREATE TABLE IF NOT EXISTS medicao_import_linhas (
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

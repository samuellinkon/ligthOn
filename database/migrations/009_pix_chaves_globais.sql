-- Várias chaves PIX da empresa (tipo + valor + rótulo), com uma marcada como padrão para fallback em cobranças sem chave própria.
CREATE TABLE IF NOT EXISTS pix_chaves_globais (
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

-- Importa chave única legada (app_config) se existir e a tabela estiver vazia.
INSERT INTO pix_chaves_globais (rotulo, tipo, chave, padrao, ordem)
SELECT
    'Importado (config anterior)',
    COALESCE(NULLIF(TRIM(t.tipo), ''), 'E-mail'),
    TRIM(t.chave),
    1,
    0
FROM (
    SELECT
        (SELECT valor FROM app_config WHERE chave = 'pix_tipo_global' LIMIT 1) AS tipo,
        (SELECT valor FROM app_config WHERE chave = 'pix_chave_global' LIMIT 1) AS chave
) AS t
WHERE NOT EXISTS (SELECT 1 FROM pix_chaves_globais LIMIT 1)
  AND TRIM(COALESCE(t.chave, '')) <> '';

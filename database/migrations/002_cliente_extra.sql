-- Migration 002: campos extras do cliente (dia de renovação do ciclo,
-- CPF/CNPJ e observações).
-- Idempotente (usa ALTER TABLE ... ADD COLUMN IF NOT EXISTS).

ALTER TABLE clientes
    ADD COLUMN IF NOT EXISTS dia_renovacao TINYINT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'Dia do mês em que o ciclo de horas reinicia (1-31)',
    ADD COLUMN IF NOT EXISTS doc VARCHAR(30) DEFAULT NULL
        COMMENT 'CPF ou CNPJ',
    ADD COLUMN IF NOT EXISTS obs TEXT DEFAULT NULL
        COMMENT 'Observações livres sobre o cliente';

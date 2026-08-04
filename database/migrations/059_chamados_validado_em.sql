-- Data de validação do chamado: referência de período no BM/medição
-- (em vez da data de abertura/criação).

SET NAMES utf8mb4;

ALTER TABLE chamados
    ADD COLUMN IF NOT EXISTS validado_em DATETIME NULL DEFAULT NULL AFTER aprovado_gestor_user_id;

-- Backfill: mantém o agrupamento histórico atual (mês de abertura) para já validados.
UPDATE chamados
SET validado_em = aberto_em
WHERE status = 'Validado'
  AND validado_em IS NULL
  AND aberto_em IS NOT NULL;

ALTER TABLE chamados
    ADD INDEX idx_chamados_validado_em (validado_em);

-- Quem lançou cada item no chamado (histórico e auditoria).
ALTER TABLE chamado_itens
    ADD COLUMN criado_por_user_id INT UNSIGNED NULL DEFAULT NULL AFTER observacao,
    ADD INDEX idx_chamado_itens_criado_por (criado_por_user_id);

ALTER TABLE chamado_itens
    ADD CONSTRAINT fk_chamado_itens_criado_por
        FOREIGN KEY (criado_por_user_id) REFERENCES usuarios(id) ON DELETE SET NULL;

-- Itens antigos: técnico que finalizou ou técnico principal do chamado.
UPDATE chamado_itens ci
INNER JOIN chamados ch ON ch.id = ci.chamado_id
SET ci.criado_por_user_id = COALESCE(ch.finalizado_operador_user_id, ch.tecnico_user_id)
WHERE ci.criado_por_user_id IS NULL
  AND COALESCE(ch.finalizado_operador_user_id, ch.tecnico_user_id) IS NOT NULL;

-- Descrição curta para exibição ao técnico no lançamento de materiais.
ALTER TABLE cliente_itens
    ADD COLUMN descricao_simplificada VARCHAR(160) NULL DEFAULT NULL AFTER descricao;

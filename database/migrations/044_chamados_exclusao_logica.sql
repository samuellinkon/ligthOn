-- Exclusão lógica de chamados (inativar em vez de apagar).
ALTER TABLE chamados
    ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1 AFTER aberto_em,
    ADD COLUMN excluido_em DATETIME NULL DEFAULT NULL AFTER ativo,
    ADD COLUMN excluido_por_user_id INT UNSIGNED NULL DEFAULT NULL AFTER excluido_em,
    ADD INDEX idx_chamados_ativo (ativo, aberto_em);

ALTER TABLE chamados
    ADD CONSTRAINT fk_chamados_excluido_por
        FOREIGN KEY (excluido_por_user_id) REFERENCES usuarios(id) ON DELETE SET NULL;

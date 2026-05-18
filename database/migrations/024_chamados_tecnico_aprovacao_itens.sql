-- Fluxo de chamados: gestor atribui técnico, técnico lança itens usados/devolvidos e gestor aprova.

ALTER TABLE chamados
    ADD COLUMN tecnico_user_id INT UNSIGNED NULL DEFAULT NULL AFTER finalizado_operador_user_id,
    ADD COLUMN aprovado_gestor_em DATETIME NULL AFTER tecnico_user_id,
    ADD COLUMN aprovado_gestor_user_id INT UNSIGNED NULL AFTER aprovado_gestor_em,
    ADD COLUMN checklist_realizado TEXT NULL AFTER aprovado_gestor_user_id,
    ADD INDEX idx_chamados_tecnico (tecnico_user_id),
    ADD INDEX idx_chamados_aprovado (aprovado_gestor_em),
    ADD CONSTRAINT fk_chamados_tecnico_user FOREIGN KEY (tecnico_user_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_chamados_aprovado_user FOREIGN KEY (aprovado_gestor_user_id) REFERENCES usuarios(id) ON DELETE SET NULL;

ALTER TABLE chamado_itens
    ADD COLUMN movimento ENUM('utilizado','devolvido') NOT NULL DEFAULT 'utilizado' AFTER item_id,
    ADD COLUMN observacao VARCHAR(255) NULL DEFAULT NULL AFTER subtotal,
    ADD INDEX idx_chamado_itens_movimento (chamado_id, movimento);

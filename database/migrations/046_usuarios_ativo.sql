-- Conta de login ativa/inativa (sem excluir o registro). Gestores desativam; administradores podem excluir na lista global.
ALTER TABLE usuarios
    ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1 AFTER iniciais;

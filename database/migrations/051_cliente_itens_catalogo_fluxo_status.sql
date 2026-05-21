-- Status de fluxo do catálogo (ex.: item devolutivo criado pelo gestor no chamado).
ALTER TABLE cliente_itens
    ADD COLUMN catalogo_fluxo_status VARCHAR(32) NULL DEFAULT NULL
        COMMENT 'Ex.: Criado, Aguardando aprovação'
        AFTER ativo;

-- Saldo de estoque por item do catálogo (produtos e serviços)
ALTER TABLE cliente_itens
    ADD COLUMN estoque_saldo DECIMAL(12,4) NOT NULL DEFAULT 0.0000
    AFTER valor_unitario;

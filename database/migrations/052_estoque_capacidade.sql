-- Capacidade de referência para alerta de estoque baixo (10% do saldo de referência).
ALTER TABLE cliente_itens
    ADD COLUMN estoque_capacidade DECIMAL(14,4) NULL DEFAULT NULL AFTER estoque_saldo;

UPDATE cliente_itens
SET estoque_capacidade = GREATEST(estoque_saldo, 0)
WHERE estoque_capacidade IS NULL AND estoque_saldo > 0;

-- Nomes técnicos longos do catálogo (ex.: especificações de materiais) ultrapassam 160.
ALTER TABLE cliente_itens
    MODIFY COLUMN nome VARCHAR(2000) NOT NULL;

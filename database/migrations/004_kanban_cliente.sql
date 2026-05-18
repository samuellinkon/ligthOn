-- Migration 004: vincular cards do Kanban a um cliente.

ALTER TABLE kanban_cards
    ADD COLUMN IF NOT EXISTS cliente_id INT UNSIGNED DEFAULT NULL AFTER coluna;

-- FK é criada só se ainda não existir. MariaDB não tem IF NOT EXISTS para FK,
-- então usamos um bloco com tratamento de duplicado (via SQL dinâmico num SELECT).
-- Se der erro de "duplicate foreign key", pode ignorar — já está criada.
ALTER TABLE kanban_cards
    ADD CONSTRAINT fk_kanban_cliente
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL;

-- Preenche o cliente_id em cards vindos de chamados (retroativo).
UPDATE kanban_cards k
JOIN chamados c ON c.id = k.chamado_id
SET k.cliente_id = c.cliente_id
WHERE k.cliente_id IS NULL;

CREATE INDEX IF NOT EXISTS idx_kanban_cliente ON kanban_cards (cliente_id);

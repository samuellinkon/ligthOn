-- Catálogo (produtos/serviços): vínculo explícito à empresa matriz (cliente raiz) + exemplos iniciais.
-- clientes.empresa_id NULL = matriz; filhos apontam para o id da matriz. cliente_itens.empresa_id espelha o dono do catálogo.

ALTER TABLE cliente_itens
    ADD COLUMN empresa_id INT UNSIGNED NULL DEFAULT NULL AFTER cliente_id,
    ADD INDEX idx_ci_empresa (empresa_id, ativo, ordem);

UPDATE cliente_itens ci
INNER JOIN clientes c ON c.id = ci.cliente_id
SET ci.empresa_id = COALESCE(c.empresa_id, c.id)
WHERE ci.empresa_id IS NULL;

ALTER TABLE cliente_itens
    ADD CONSTRAINT fk_cliente_itens_empresa FOREIGN KEY (empresa_id) REFERENCES clientes(id) ON DELETE SET NULL;

-- Matrizes sem nenhum item: dois exemplos (serviço + produto) para uso em chamados/OS.
INSERT INTO cliente_itens (cliente_id, empresa_id, tipo, nome, codigo, unidade, valor_unitario, descricao, ativo, ordem)
SELECT c.id, c.id, 'servico', 'Atendimento técnico', 'SVC-001', 'H', 0.0000,
       'Serviço de exemplo — edite nome, código e valor em Produtos e serviços.', 1, 10
FROM clientes c
WHERE c.empresa_id IS NULL
  AND c.status = 'Ativo'
  AND NOT EXISTS (SELECT 1 FROM cliente_itens x WHERE x.cliente_id = c.id LIMIT 1);

INSERT INTO cliente_itens (cliente_id, empresa_id, tipo, nome, codigo, unidade, valor_unitario, descricao, ativo, ordem)
SELECT c.id, c.id, 'produto', 'Material / peças', 'MAT-001', 'UN', 0.0000,
       'Produto de exemplo — ajuste valor unitário e unidade conforme o catálogo da empresa.', 1, 20
FROM clientes c
WHERE c.empresa_id IS NULL
  AND c.status = 'Ativo'
  AND EXISTS (SELECT 1 FROM cliente_itens x WHERE x.cliente_id = c.id AND x.codigo = 'SVC-001' LIMIT 1)
  AND NOT EXISTS (SELECT 1 FROM cliente_itens x WHERE x.cliente_id = c.id AND x.codigo = 'MAT-001' LIMIT 1);

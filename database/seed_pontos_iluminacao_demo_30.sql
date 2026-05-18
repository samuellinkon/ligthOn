-- 30 pontos de iluminação para demonstração (cliente_id = 1; ajuste se necessário)
SET @cid := (SELECT id FROM clientes ORDER BY id ASC LIMIT 1);

DELETE FROM pontos_iluminacao WHERE observacoes = 'Demo OnLight — 30 pontos';

INSERT INTO pontos_iluminacao (cliente_id, codigo_poste, bairro, endereco_completo, latitude, longitude, status, observacoes) VALUES
(@cid, 'DEMO-001', 'Centro', 'Rua Principal, 100 — Centro', -8.400100, -35.000100, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-002', 'Centro', 'Rua Principal, 200 — Centro', -8.400200, -35.000200, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-003', 'Centro', 'Av. Central, 50 — Centro', -8.400300, -35.000300, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-004', 'Bairro Novo', 'Rua das Flores, 10', -8.401000, -35.001000, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-005', 'Bairro Novo', 'Rua das Flores, 20', -8.401100, -35.001100, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-006', 'Industrial', 'Rod. Norte, km 2', -8.402000, -35.002000, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-007', 'Industrial', 'Rod. Norte, km 3', -8.402100, -35.002100, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-008', 'Praia', 'Orla, trecho 1', -8.403000, -35.003000, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-009', 'Praia', 'Orla, trecho 2', -8.403100, -35.003100, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-010', 'Praia', 'Orla, trecho 3', -8.403200, -35.003200, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-011', 'Centro', 'Praça da Matriz', -8.400400, -35.000400, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-012', 'Centro', 'Praça da Matriz — lateral', -8.400500, -35.000500, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-013', 'Vila', 'Travessa A, 1', -8.404000, -35.004000, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-014', 'Vila', 'Travessa A, 2', -8.404100, -35.004100, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-015', 'Vila', 'Travessa B, 1', -8.404200, -35.004200, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-016', 'Campo', 'Estrada Rural, s/n', -8.405000, -35.005000, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-017', 'Campo', 'Estrada Rural, km 1', -8.405100, -35.005100, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-018', 'Campo', 'Estrada Rural, km 2', -8.405200, -35.005200, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-019', 'Centro', 'Rua do Comércio, 15', -8.400600, -35.000600, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-020', 'Centro', 'Rua do Comércio, 30', -8.400700, -35.000700, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-021', 'Bairro Novo', 'Av. Progresso, 100', -8.401200, -35.001200, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-022', 'Bairro Novo', 'Av. Progresso, 200', -8.401300, -35.001300, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-023', 'Industrial', 'Galpão 1', -8.402200, -35.002200, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-024', 'Industrial', 'Galpão 2', -8.402300, -35.002300, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-025', 'Praia', 'Acesso 1', -8.403300, -35.003300, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-026', 'Praia', 'Acesso 2', -8.403400, -35.003400, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-027', 'Vila', 'Rua C, 5', -8.404300, -35.004300, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-028', 'Vila', 'Rua C, 10', -8.404400, -35.004400, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-029', 'Centro', 'Terminal', -8.400800, -35.000800, 'Ativo', 'Demo OnLight — 30 pontos'),
(@cid, 'DEMO-030', 'Centro', 'Terminal — fundos', -8.400900, -35.000900, 'Ativo', 'Demo OnLight — 30 pontos');

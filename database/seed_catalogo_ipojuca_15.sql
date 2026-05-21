-- Catálogo demo Ipojuca: 10 produtos (estoque 100) + 5 serviços
-- Uso: php scripts/seed_catalogo_ipojuca_15.php

SET NAMES utf8mb4;

SET @cid := (SELECT id FROM clientes ORDER BY id ASC LIMIT 1);

UPDATE chamados SET servico_id = NULL WHERE servico_id IS NOT NULL;
DELETE FROM chamado_itens;
DELETE opi FROM os_pedido_itens opi
INNER JOIN cliente_itens ci ON ci.id = opi.item_id
WHERE ci.cliente_id = @cid OR ci.empresa_id = @cid;
UPDATE os_pedidos SET servico_id = NULL WHERE servico_id IS NOT NULL;
DELETE FROM cliente_itens;

INSERT INTO cliente_itens (cliente_id, empresa_id, tipo, nome, codigo, unidade, valor_unitario, estoque_saldo, descricao, ativo, ordem) VALUES
(@cid, @cid, 'produto', 'Lâmpada LED 150W', 'PROD-001', 'UN', 85.0000, 100.0000, 'Lâmpada de vias públicas', 1, 1),
(@cid, @cid, 'produto', 'Reator eletrônico', 'PROD-002', 'UN', 120.0000, 100.0000, 'Reator para luminária', 1, 2),
(@cid, @cid, 'produto', 'Relé fotoelétrico', 'PROD-003', 'UN', 45.0000, 100.0000, 'Acionamento automático', 1, 3),
(@cid, @cid, 'produto', 'Fusível DIAZED 25A', 'PROD-004', 'UN', 8.5000, 100.0000, 'Proteção do circuito', 1, 4),
(@cid, @cid, 'produto', 'Cabo flexível 2,5mm²', 'PROD-005', 'M', 12.0000, 100.0000, 'Cabo por metro', 1, 5),
(@cid, @cid, 'produto', 'Braço galvanizado 1,5m', 'PROD-006', 'UN', 95.0000, 100.0000, 'Braço para luminária', 1, 6),
(@cid, @cid, 'produto', 'Parafuso sextavado M12', 'PROD-007', 'UN', 2.5000, 100.0000, 'Fixação do braço', 1, 7),
(@cid, @cid, 'produto', 'Luminária refletor LED', 'PROD-008', 'UN', 210.0000, 100.0000, 'Refletor completo', 1, 8),
(@cid, @cid, 'produto', 'Fita isolante 19mm', 'PROD-009', 'UN', 6.0000, 100.0000, 'Isolação de emendas', 1, 9),
(@cid, @cid, 'produto', 'Conector impermeável', 'PROD-010', 'UN', 18.0000, 100.0000, 'Emenda subterrânea', 1, 10),
(@cid, @cid, 'servico', 'Manutenção corretiva', 'SVC-001', 'H', 75.0000, 0.0000, 'Hora técnica em campo', 1, 11),
(@cid, @cid, 'servico', 'Instalação de luminária', 'SVC-002', 'UN', 168.0000, 0.0000, 'Instalação em braço até 1,5m', 1, 12),
(@cid, @cid, 'servico', 'Troca de lâmpada', 'SVC-003', 'UN', 35.0000, 0.0000, 'Substituição de lâmpada', 1, 13),
(@cid, @cid, 'servico', 'Deslocamento equipe', 'SVC-004', 'UN', 120.0000, 0.0000, 'Deslocamento até 30 km', 1, 14),
(@cid, @cid, 'servico', 'Inspeção preventiva', 'SVC-005', 'UN', 55.0000, 0.0000, 'Vistoria do ponto de luz', 1, 15);

SELECT tipo, COUNT(*) AS qtd, SUM(estoque_saldo) AS estoque_total
FROM cliente_itens
WHERE cliente_id = @cid
GROUP BY tipo;

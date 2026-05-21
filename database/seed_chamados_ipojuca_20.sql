-- =============================================================================
-- 20 chamados de demonstração — Ipojuca (cliente matriz)
-- 10 vinculados aos postes IPOJUCA-PREF-001 … 010
-- 10 sem poste (ponto_iluminacao_id NULL)
-- =============================================================================
-- Uso: phpMyAdmin ou
--   /Applications/XAMPP/xamppfiles/bin/php scripts/seed_chamados_ipojuca_20.php
-- =============================================================================

SET NAMES utf8mb4;

SET @cid := (SELECT id FROM clientes ORDER BY id ASC LIMIT 1);

DELETE FROM chamado_itens WHERE chamado_id IN (
    SELECT id FROM chamados WHERE titulo LIKE 'Seed Ipojuca —%'
);
DELETE FROM chamado_tecnicos WHERE chamado_id IN (
    SELECT id FROM chamados WHERE titulo LIKE 'Seed Ipojuca —%'
);
DELETE FROM chamado_respostas WHERE chamado_id IN (
    SELECT id FROM chamados WHERE titulo LIKE 'Seed Ipojuca —%'
);
DELETE FROM chamado_anexos WHERE chamado_id IN (
    SELECT id FROM chamados WHERE titulo LIKE 'Seed Ipojuca —%'
);
DELETE FROM notificacoes WHERE chamado_id IN (
    SELECT id FROM chamados WHERE titulo LIKE 'Seed Ipojuca —%'
);
DELETE FROM chamados WHERE titulo LIKE 'Seed Ipojuca —%';

-- 10 com poste (JOIN pelos códigos IPOJUCA-PREF-*)
INSERT INTO chamados (
    cliente_id, ponto_iluminacao_id, titulo, descricao,
    endereco_completo, latitude, longitude,
    os_bairro, os_cidade, os_uf, problema_os, prioridade, status, responsavel, aberto_em
)
SELECT
    @cid,
    p.id,
    CONCAT('Seed Ipojuca — Poste ', p.codigo_poste),
    CONCAT('Chamado de teste vinculado ao poste ', p.codigo_poste, '. Lâmpada apagada / manutenção preventiva.'),
    p.endereco_completo,
    p.latitude,
    p.longitude,
    p.bairro,
    'Ipojuca',
    'PE',
    'Lâmpada apagada',
    ELT(1 + (p.id % 4), 'Baixa', 'Normal', 'Alta', 'Urgente'),
    ELT(1 + (p.id % 3), 'Aberto', 'Em andamento', 'Aberto'),
    'Seed script',
    DATE_SUB(NOW(), INTERVAL (p.id % 14) DAY)
FROM pontos_iluminacao p
WHERE p.codigo_poste LIKE 'IPOJUCA-PREF-%'
ORDER BY p.codigo_poste ASC
LIMIT 10;

-- 10 sem poste
INSERT INTO chamados (
    cliente_id, ponto_iluminacao_id, titulo, descricao,
    endereco_completo, latitude, longitude,
    os_bairro, os_cidade, os_uf, problema_os, prioridade, status, responsavel, aberto_em
) VALUES
(@cid, NULL, 'Seed Ipojuca — Av. Beira Mar (sem poste)', 'Chamado avulso: iluminação em via sem cadastro de poste.', 'Av. Beira Mar, 50 - Centro, Ipojuca - PE', -8.4012000, -35.0620000, 'Centro', 'Ipojuca', 'PE', 'Iluminação precária', 'Normal', 'Aberto', 'Seed script', DATE_SUB(NOW(), INTERVAL 2 DAY)),
(@cid, NULL, 'Seed Ipojuca — Praça da Matriz (sem poste)', 'Solicitação em área pública sem poste cadastrado.', 'Praça da Matriz - Centro, Ipojuca - PE', -8.4005000, -35.0615000, 'Centro', 'Ipojuca', 'PE', 'Poste danificado', 'Alta', 'Em andamento', 'Seed script', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(@cid, NULL, 'Seed Ipojuca — R. do Comércio (sem poste)', 'Morador reporta ponto escuro na calçada.', 'R. do Comércio, 200 - Centro, Ipojuca - PE', -8.4008000, -35.0608000, 'Centro', 'Ipojuca', 'PE', 'Lâmpada piscando', 'Normal', 'Aberto', 'Seed script', DATE_SUB(NOW(), INTERVAL 1 DAY)),
(@cid, NULL, 'Seed Ipojuca — Bairro Novo (sem poste)', 'Trecho de rua sem referência de poste no cadastro.', 'R. Bairro Novo, s/n - Ipojuca - PE', -8.4050000, -35.0550000, 'Bairro Novo', 'Ipojuca', 'PE', 'Fiação exposta', 'Urgente', 'Aberto', 'Seed script', DATE_SUB(NOW(), INTERVAL 6 DAY)),
(@cid, NULL, 'Seed Ipojuca — Acesso Porto (sem poste)', 'Iluminação de entroncamento.', 'Acesso Porto - Ipojuca - PE', -8.3950000, -35.0500000, 'Centro', 'Ipojuca', 'PE', 'Relé queimado', 'Alta', 'Em andamento', 'Seed script', DATE_SUB(NOW(), INTERVAL 8 DAY)),
(@cid, NULL, 'Seed Ipojuca — Estrada para Gaibu (sem poste)', 'Chamado em rodovia municipal.', 'PE-60, km 12 - Ipojuca - PE', -8.3900000, -35.0450000, 'Gaibu', 'Ipojuca', 'PE', 'Lâmpada apagada', 'Normal', 'Aberto', 'Seed script', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(@cid, NULL, 'Seed Ipojuca — Condomínio lateral (sem poste)', 'Área residencial sem poste mapeado.', 'R. Lateral Condomínios - Ipojuca - PE', -8.4020000, -35.0580000, 'Centro', 'Ipojuca', 'PE', 'Braço quebrado', 'Baixa', 'Aberto', 'Seed script', DATE_SUB(NOW(), INTERVAL 5 DAY)),
(@cid, NULL, 'Seed Ipojuca — Escola municipal (sem poste)', 'Pátio escolar com baixa luminosidade.', 'R. da Escola, 10 - Centro, Ipojuca - PE', -8.3995000, -35.0595000, 'Centro', 'Ipojuca', 'PE', 'Iluminação precária', 'Normal', 'Resolvido', 'Seed script', DATE_SUB(NOW(), INTERVAL 12 DAY)),
(@cid, NULL, 'Seed Ipojuca — Mercado municipal (sem poste)', 'Entorno do mercado.', 'Av. do Mercado, 80 - Ipojuca - PE', -8.3980000, -35.0570000, 'Centro', 'Ipojuca', 'PE', 'Timer defeituoso', 'Normal', 'Aberto', 'Seed script', DATE_SUB(NOW(), INTERVAL 7 DAY)),
(@cid, NULL, 'Seed Ipojuca — Ciclovia (sem poste)', 'Trecho de ciclovia sem poste no sistema.', 'Ciclovia Centro - Ipojuca - PE', -8.3975000, -35.0565000, 'Centro', 'Ipojuca', 'PE', 'Lâmpada apagada', 'Alta', 'Em andamento', 'Seed script', DATE_SUB(NOW(), INTERVAL 9 DAY));

SELECT
    ch.id,
    ch.titulo,
    ch.ponto_iluminacao_id,
    p.codigo_poste,
    ch.status,
    ch.prioridade
FROM chamados ch
LEFT JOIN pontos_iluminacao p ON p.id = ch.ponto_iluminacao_id
WHERE ch.titulo LIKE 'Seed Ipojuca —%'
ORDER BY ch.ponto_iluminacao_id IS NULL, ch.id;

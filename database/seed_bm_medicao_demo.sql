-- =============================================================================
-- Dados de demonstração para BM / medição (maio/2026)
-- =============================================================================
-- Ajuste o cliente matriz (ex.: 12) e execute no MySQL/MariaDB.
-- Requisitos: existir pelo menos um utilizador com perfil «operador» ou
-- «gestor»; o cliente_id deve existir em `clientes`.
--
-- Fluxo: catálogo (vários itens) → chamados em maio/2026 → itens utilizado +
-- devolvido → técnico no chamado + tabela chamado_tecnicos → registo de anexo
-- (ficheiro físico: ver nota no fim).
-- =============================================================================

SET NAMES utf8mb4;

-- >>> ALTERE AQUI <<<
SET @cliente_matriz := 12;

-- Operador preferencial; senão gestor; senão primeiro utilizador
SET @operador := (
    SELECT COALESCE(
        (SELECT id FROM usuarios WHERE perfil = 'operador' ORDER BY id LIMIT 1),
        (SELECT id FROM usuarios WHERE perfil = 'gestor' ORDER BY id LIMIT 1),
        (SELECT id FROM usuarios ORDER BY id LIMIT 1)
    )
);

-- ---------------------------------------------------------------------------
-- Itens de catálogo (códigos estilo BM) — só insere se ainda não existirem
-- ---------------------------------------------------------------------------
INSERT INTO cliente_itens (cliente_id, tipo, nome, codigo, unidade, valor_unitario, ativo, ordem)
SELECT @cliente_matriz, 'produto', 'INSTALAÇÃO DE LUMINÁRIA EM BRAÇO DE ATÉ 1,5 METRO', '2.24', 'UN', 450.0000, 1, 224
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM cliente_itens ci WHERE ci.cliente_id = @cliente_matriz AND ci.codigo = '2.24');

INSERT INTO cliente_itens (cliente_id, tipo, nome, codigo, unidade, valor_unitario, ativo, ordem)
SELECT @cliente_matriz, 'produto', 'INSTALAÇÃO DE LUMINÁRIA EM BRAÇO SUPERIOR A 1,5 METRO', '2.25', 'UN', 520.0000, 1, 225
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM cliente_itens ci WHERE ci.cliente_id = @cliente_matriz AND ci.codigo = '2.25');

INSERT INTO cliente_itens (cliente_id, tipo, nome, codigo, unidade, valor_unitario, ativo, ordem)
SELECT @cliente_matriz, 'produto', 'INSTALAÇÃO DE RELÉ', '2.38', 'UN', 35.5000, 1, 238
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM cliente_itens ci WHERE ci.cliente_id = @cliente_matriz AND ci.codigo = '2.38');

INSERT INTO cliente_itens (cliente_id, tipo, nome, codigo, unidade, valor_unitario, ativo, ordem)
SELECT @cliente_matriz, 'produto', 'FECHAMENTO DE CAVA', '2.9', 'UN', 80.0000, 1, 209
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM cliente_itens ci WHERE ci.cliente_id = @cliente_matriz AND ci.codigo = '2.9');

INSERT INTO cliente_itens (cliente_id, tipo, nome, codigo, unidade, valor_unitario, ativo, ordem)
SELECT @cliente_matriz, 'servico', 'DESLOCAMENTO / DESPESAS DE DESLOCAMENTO', 'S-DESL', 'UN', 120.0000, 1, 300
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM cliente_itens ci WHERE ci.cliente_id = @cliente_matriz AND ci.codigo = 'S-DESL');

SELECT id INTO @it224 FROM cliente_itens WHERE cliente_id = @cliente_matriz AND codigo = '2.24' LIMIT 1;
SELECT id INTO @it225 FROM cliente_itens WHERE cliente_id = @cliente_matriz AND codigo = '2.25' LIMIT 1;
SELECT id INTO @it238 FROM cliente_itens WHERE cliente_id = @cliente_matriz AND codigo = '2.38' LIMIT 1;
SELECT id INTO @it29  FROM cliente_itens WHERE cliente_id = @cliente_matriz AND codigo = '2.9' LIMIT 1;
SELECT id INTO @itdesl FROM cliente_itens WHERE cliente_id = @cliente_matriz AND codigo = 'S-DESL' LIMIT 1;

-- ---------------------------------------------------------------------------
-- Chamado 1 — maio/2026, operador, vários materiais + devolução
-- ---------------------------------------------------------------------------
INSERT INTO chamados (
    cliente_id, titulo, descricao, prioridade, status, responsavel, aberto_em,
    tecnico_user_id, servico_id, endereco_completo
) VALUES (
    @cliente_matriz,
    'BM demo — Iluminação Av. Central (maio/2026)',
    'Chamado de teste para boletim: instalação de luminárias e relés; sobra de material devolvido ao estoque.',
    'Normal',
    'Em andamento',
    'Script seed BM',
    '2026-05-08 08:30:00',
    @operador,
    @itdesl,
    'Av. Central, 100 — Centro — maio/2026 (dados fictícios)'
);

SET @ch1 := LAST_INSERT_ID();

INSERT INTO chamado_tecnicos (chamado_id, usuario_id) VALUES (@ch1, @operador)
ON DUPLICATE KEY UPDATE usuario_id = usuario_id;

-- 2.24 utilizado 4 UN
INSERT INTO chamado_itens (chamado_id, item_id, movimento, quantidade, valor_unitario, subtotal, observacao)
VALUES (@ch1, @it224, 'utilizado', 4.0000, 450.0000, 1800.0000, 'seed BM');

-- 2.25 utilizado 6 UN
INSERT INTO chamado_itens (chamado_id, item_id, movimento, quantidade, valor_unitario, subtotal, observacao)
VALUES (@ch1, @it225, 'utilizado', 6.0000, 520.0000, 3120.0000, 'seed BM');

-- 2.38 utilizado 12 UN + devolução 3 UN (material não aplicado)
INSERT INTO chamado_itens (chamado_id, item_id, movimento, quantidade, valor_unitario, subtotal, observacao)
VALUES (@ch1, @it238, 'utilizado', 12.0000, 35.5000, 426.0000, 'seed BM');

INSERT INTO chamado_itens (chamado_id, item_id, movimento, quantidade, valor_unitario, subtotal, observacao)
VALUES (@ch1, @it238, 'devolvido', 3.0000, 35.5000, 106.5000, 'sobra não utilizada');

-- 2.9 utilizado 2
INSERT INTO chamado_itens (chamado_id, item_id, movimento, quantidade, valor_unitario, subtotal, observacao)
VALUES (@ch1, @it29, 'utilizado', 2.0000, 80.0000, 160.0000, 'seed BM');

-- Serviço deslocamento utilizado 1
INSERT INTO chamado_itens (chamado_id, item_id, movimento, quantidade, valor_unitario, subtotal, observacao)
VALUES (@ch1, @itdesl, 'utilizado', 1.0000, 120.0000, 120.0000, 'seed BM');

-- ---------------------------------------------------------------------------
-- Chamado 2 — outro dia em maio/2026, mesmo operador
-- ---------------------------------------------------------------------------
INSERT INTO chamados (
    cliente_id, titulo, descricao, prioridade, status, responsavel, aberto_em,
    tecnico_user_id, servico_id, endereco_completo
) VALUES (
    @cliente_matriz,
    'BM demo — Poste 402 (maio/2026)',
    'Segundo chamado de teste no mesmo mês.',
    'Alta',
    'Em andamento',
    'Script seed BM',
    '2026-05-22 14:15:00',
    @operador,
    @itdesl,
    'Rua das Palmeiras, 402 — maio/2026 (dados fictícios)'
);

SET @ch2 := LAST_INSERT_ID();

INSERT INTO chamado_tecnicos (chamado_id, usuario_id) VALUES (@ch2, @operador)
ON DUPLICATE KEY UPDATE usuario_id = usuario_id;

INSERT INTO chamado_itens (chamado_id, item_id, movimento, quantidade, valor_unitario, subtotal, observacao)
VALUES (@ch2, @it224, 'utilizado', 10.0000, 450.0000, 4500.0000, 'seed BM');

INSERT INTO chamado_itens (chamado_id, item_id, movimento, quantidade, valor_unitario, subtotal, observacao)
VALUES (@ch2, @it225, 'utilizado', 2.0000, 520.0000, 1040.0000, 'seed BM');

INSERT INTO chamado_itens (chamado_id, item_id, movimento, quantidade, valor_unitario, subtotal, observacao)
VALUES (@ch2, @it238, 'devolvido', 1.0000, 35.5000, 35.5000, 'item trocado em campo');

-- ---------------------------------------------------------------------------
-- Anexo fictício (registo na BD — o ficheiro tem de existir em disco)
-- ---------------------------------------------------------------------------
INSERT INTO chamado_anexos (
    chamado_id, resposta_id, nome_original, nome_arquivo, mime, tamanho,
    enviado_por, enviado_tipo
) VALUES (
    @ch1,
    NULL,
    'foto_poste_demo.png',
    'seed_bm_demo.png',
    'image/png',
    70,
    'Operador demo',
    'operador'
);

-- =============================================================================
-- Após o INSERT acima, crie o ficheiro físico (PNG 1×1 transparente de teste):
--
--   mkdir -p uploads/chamados/<ID_DO_CH1>
--   php -r 'file_put_contents("uploads/chamados/<ID>/seed_bm_demo.png", base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=="));'
--
-- Substitua <ID> por @ch1 (valor mostrado no cliente MySQL após o seed), ex.:
--   SELECT @ch1 AS chamado_id_demo;
-- =============================================================================

SELECT @ch1 AS chamado_1_id, @ch2 AS chamado_2_id, @operador AS operador_user_id;

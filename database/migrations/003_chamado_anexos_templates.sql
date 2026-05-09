-- Migration 003: anexos de chamados, notas internas, templates de resposta.

CREATE TABLE IF NOT EXISTS chamado_anexos (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chamado_id     INT UNSIGNED NOT NULL,
    resposta_id    INT UNSIGNED DEFAULT NULL,
    nome_original  VARCHAR(255) NOT NULL,
    nome_arquivo   VARCHAR(255) NOT NULL,
    mime           VARCHAR(100) DEFAULT NULL,
    tamanho        INT UNSIGNED DEFAULT 0,
    enviado_por    VARCHAR(120) DEFAULT NULL,
    enviado_tipo   ENUM('admin','cliente') NOT NULL DEFAULT 'admin',
    enviado_em     DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
    INDEX idx_chanexo_chamado (chamado_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chamado_templates (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titulo     VARCHAR(120) NOT NULL,
    corpo      TEXT NOT NULL,
    ordem      INT NOT NULL DEFAULT 0,
    criado_em  DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE chamado_respostas
    ADD COLUMN IF NOT EXISTS interna TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Se 1, nota interna visível apenas aos admins';

-- Templates padrão (só inserem se a tabela estiver vazia)
INSERT INTO chamado_templates (titulo, corpo, ordem)
SELECT * FROM (
    SELECT 'Abertura — Recebemos seu chamado' AS titulo,
           'Olá {{cliente}},\n\nRecebemos seu chamado e já estamos analisando. Em breve retornaremos com mais informações.\n\nAtenciosamente,\n{{responsavel}}' AS corpo,
           1 AS ordem
    UNION ALL SELECT 'Em andamento', 'Olá {{cliente}},\n\nEstamos trabalhando na resolução do seu chamado. Assim que houver atualizações, enviaremos por aqui.\n\nAtenciosamente,\n{{responsavel}}', 2
    UNION ALL SELECT 'Solicitar mais informações', 'Olá {{cliente}},\n\nPara dar andamento ao seu chamado, precisamos de algumas informações adicionais:\n\n- \n- \n\nAssim que possível, nos envie.\n\nAtenciosamente,\n{{responsavel}}', 3
    UNION ALL SELECT 'Aguardando retorno', 'Olá {{cliente}},\n\nEnviamos uma solicitação e ainda aguardamos sua resposta. Poderia nos dar um retorno?\n\nAtenciosamente,\n{{responsavel}}', 4
    UNION ALL SELECT 'Resolvido', 'Olá {{cliente}},\n\nSeu chamado foi resolvido. Qualquer dúvida, basta responder esta mensagem ou abrir um novo chamado.\n\nAtenciosamente,\n{{responsavel}}', 5
) AS padrao
WHERE (SELECT COUNT(*) FROM chamado_templates) = 0;

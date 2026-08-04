-- Opções dinâmicas dos selects Origem da OS e Problema (Avançado → OS).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS chamado_os_opcoes (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tipo       ENUM('origem', 'problema') NOT NULL,
    nome       VARCHAR(120) NOT NULL,
    ativo      TINYINT(1) NOT NULL DEFAULT 1,
    ordem      INT NOT NULL DEFAULT 0,
    criado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_chamado_os_opcoes_tipo_nome (tipo, nome),
    INDEX idx_chamado_os_opcoes_lista (tipo, ativo, ordem, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO chamado_os_opcoes (tipo, nome, ativo, ordem) VALUES
('origem', 'Telefone', 1, 10),
('origem', 'WhatsApp', 1, 20),
('origem', 'Presencial', 1, 30),
('origem', 'E-mail', 1, 40),
('origem', 'Rede Ipojuca', 1, 50),
('origem', 'Outro', 1, 60),
('problema', 'Ponto Apagado', 1, 10),
('problema', 'Vazamento de Corrente', 1, 20),
('problema', 'Implantação', 1, 30),
('problema', 'Evento', 1, 40),
('problema', 'Serviços Gerais', 1, 50),
('problema', 'Outros', 1, 60);

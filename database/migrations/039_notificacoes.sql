-- Notificações por usuário (mensagens em chamados).

CREATE TABLE IF NOT EXISTS notificacoes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    chamado_id INT UNSIGNED NOT NULL,
    mensagem_id INT UNSIGNED NULL,
    tipo VARCHAR(50) NOT NULL DEFAULT 'chamado_mensagem',
    titulo VARCHAR(255) NOT NULL,
    descricao TEXT NULL,
    lida TINYINT(1) NOT NULL DEFAULT 0,
    data_criacao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_leitura DATETIME NULL,
    INDEX idx_notificacoes_usuario_lida (usuario_id, lida),
    INDEX idx_notificacoes_chamado (chamado_id),
    INDEX idx_notificacoes_mensagem (mensagem_id),
    CONSTRAINT fk_notificacoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_notificacoes_chamado FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
    CONSTRAINT fk_notificacoes_mensagem FOREIGN KEY (mensagem_id) REFERENCES chamado_respostas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

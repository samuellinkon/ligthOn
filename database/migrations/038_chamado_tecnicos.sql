-- Permite vincular múltiplos técnicos/operadores ao mesmo chamado.
-- Mantém chamados.tecnico_user_id como técnico principal/legado para compatibilidade.

CREATE TABLE IF NOT EXISTS chamado_tecnicos (
    chamado_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (chamado_id, usuario_id),
    INDEX idx_chamado_tecnicos_usuario (usuario_id),
    CONSTRAINT fk_chamado_tecnicos_chamado
        FOREIGN KEY (chamado_id) REFERENCES chamados(id) ON DELETE CASCADE,
    CONSTRAINT fk_chamado_tecnicos_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO chamado_tecnicos (chamado_id, usuario_id, created_at)
SELECT id, tecnico_user_id, COALESCE(aberto_em, NOW())
FROM chamados
WHERE tecnico_user_id IS NOT NULL AND tecnico_user_id > 0;

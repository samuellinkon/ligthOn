-- Logs de auditoria (append-only) + módulo no painel interno

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    criado_em          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ator_user_id       INT UNSIGNED NULL DEFAULT NULL,
    ator_nome          VARCHAR(255) NOT NULL DEFAULT '',
    ator_perfil        VARCHAR(32) NOT NULL DEFAULT '',
    acao               VARCHAR(96) NOT NULL,
    entidade_tipo      VARCHAR(64) NULL DEFAULT NULL,
    entidade_id        INT UNSIGNED NULL DEFAULT NULL,
    cliente_id         INT UNSIGNED NULL DEFAULT NULL COMMENT 'Empresa/prefeitura para filtro do gestor',
    ip                 VARCHAR(45) NULL DEFAULT NULL,
    user_agent         VARCHAR(500) NULL DEFAULT NULL,
    payload            JSON NULL,
    INDEX idx_audit_criado (criado_em),
    INDEX idx_audit_acao (acao),
    INDEX idx_audit_entidade (entidade_tipo, entidade_id),
    INDEX idx_audit_cliente (cliente_id, criado_em),
    INDEX idx_audit_ator (ator_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO saas_modulos (grupo, modulo_key, label, habilitado, ordem) VALUES
('super_admin', 'auditoria', 'Auditoria', 1, 95),
('gestor', 'auditoria', 'Auditoria', 1, 58);

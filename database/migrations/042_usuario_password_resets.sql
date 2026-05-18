-- Recuperação de senha por e-mail (token único, expira em 1 hora)
CREATE TABLE IF NOT EXISTS usuario_password_resets (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at     DATETIME NULL DEFAULT NULL,
    INDEX idx_upr_token (token_hash),
    INDEX idx_upr_user (usuario_id),
    INDEX idx_upr_expires (expires_at),
    CONSTRAINT fk_upr_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

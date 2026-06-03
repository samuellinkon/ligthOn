-- Token persistente «Manter conectado» (remember me).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS usuario_remember_tokens (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT UNSIGNED NOT NULL,
    selector    CHAR(24) NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_remember_selector (selector),
    INDEX idx_remember_user (usuario_id),
    INDEX idx_remember_expires (expires_at),
    CONSTRAINT fk_remember_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Imagens do poste (principal + secundárias)

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ponto_iluminacao_imagens (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ponto_iluminacao_id   INT UNSIGNED NOT NULL,
    nome_original         VARCHAR(255) NOT NULL,
    nome_arquivo          VARCHAR(255) NOT NULL,
    mime                  VARCHAR(100) NULL,
    tamanho               INT UNSIGNED NOT NULL DEFAULT 0,
    principal             TINYINT(1) NOT NULL DEFAULT 0,
    ordem                 INT NOT NULL DEFAULT 0,
    enviado_em            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ponto_img_ponto (ponto_iluminacao_id, principal, ordem),
    CONSTRAINT fk_ponto_img_ponto FOREIGN KEY (ponto_iluminacao_id) REFERENCES pontos_iluminacao(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

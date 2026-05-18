-- Modulo de pontos de iluminacao publica (postes).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS pontos_iluminacao (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id            INT UNSIGNED NOT NULL,
    codigo_poste          VARCHAR(80) NOT NULL,
    identificador_externo VARCHAR(120) NULL DEFAULT NULL,
    endereco_completo     TEXT NULL,
    bairro                VARCHAR(120) NULL DEFAULT NULL,
    referencia            VARCHAR(255) NULL DEFAULT NULL,
    latitude              DECIMAL(10,7) NULL,
    longitude             DECIMAL(11,7) NULL,
    status                ENUM('Ativo','Inativo') NOT NULL DEFAULT 'Ativo',
    observacoes           TEXT NULL,
    criado_em             DATETIME DEFAULT CURRENT_TIMESTAMP,
    atualizado_em         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_ponto_cliente_codigo (cliente_id, codigo_poste),
    INDEX idx_ponto_cliente (cliente_id, status),
    INDEX idx_ponto_geo (latitude, longitude),
    CONSTRAINT fk_ponto_cliente FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @db := DATABASE();

SET @sql := IF(
    EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamados')
    AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = @db AND table_name = 'chamados' AND column_name = 'ponto_iluminacao_id'),
    'ALTER TABLE chamados ADD COLUMN ponto_iluminacao_id INT UNSIGNED NULL AFTER cliente_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamados')
    AND NOT EXISTS (SELECT 1 FROM information_schema.statistics WHERE table_schema = @db AND table_name = 'chamados' AND index_name = 'idx_chamados_ponto_iluminacao'),
    'ALTER TABLE chamados ADD INDEX idx_chamados_ponto_iluminacao (ponto_iluminacao_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamados')
    AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = @db AND table_name = 'chamados' AND column_name = 'ponto_iluminacao_id')
    AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints WHERE table_schema = @db AND table_name = 'chamados' AND constraint_name = 'fk_chamado_ponto_iluminacao'),
    'ALTER TABLE chamados ADD CONSTRAINT fk_chamado_ponto_iluminacao FOREIGN KEY (ponto_iluminacao_id) REFERENCES pontos_iluminacao(id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT IGNORE INTO saas_modulos (grupo, modulo_key, label, habilitado, ordem) VALUES
('super_admin', 'pontos_iluminacao', 'Pontos de iluminacao', 1, 45),
('gestor', 'pontos_iluminacao', 'Pontos de iluminacao', 1, 45),
('cliente', 'pontos_iluminacao', 'Pontos de iluminacao', 1, 25);

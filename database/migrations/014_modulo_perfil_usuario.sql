-- Módulos por perfil de usuário (além das regras globais saas_modulos).

ALTER TABLE usuarios ADD COLUMN modulo_perfil VARCHAR(64) NULL DEFAULT NULL AFTER is_super_admin;

CREATE TABLE IF NOT EXISTS perfil_modulos (
    perfil_key VARCHAR(64) NOT NULL,
    grupo      ENUM('admin','cliente') NOT NULL,
    modulo_key VARCHAR(64) NOT NULL,
    habilitado TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (perfil_key, grupo, modulo_key),
    INDEX idx_perfil_grupo (perfil_key, grupo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO perfil_modulos (perfil_key, grupo, modulo_key, habilitado)
SELECT 'padrao', grupo, modulo_key, habilitado FROM saas_modulos;

INSERT IGNORE INTO perfil_modulos (perfil_key, grupo, modulo_key, habilitado)
SELECT
    'financeiro',
    s.grupo,
    s.modulo_key,
    CASE
        WHEN s.grupo = 'admin' AND s.modulo_key IN ('contas', 'relatorio_financeiro', 'configuracoes') THEN s.habilitado
        WHEN s.grupo = 'admin' THEN 0
        ELSE s.habilitado
    END
FROM saas_modulos s;

INSERT IGNORE INTO perfil_modulos (perfil_key, grupo, modulo_key, habilitado)
SELECT
    'operacao',
    s.grupo,
    s.modulo_key,
    CASE
        WHEN s.grupo = 'admin' AND s.modulo_key IN ('clientes', 'chamados', 'os', 'kanban', 'suporte') THEN s.habilitado
        WHEN s.grupo = 'admin' THEN 0
        ELSE s.habilitado
    END
FROM saas_modulos s;

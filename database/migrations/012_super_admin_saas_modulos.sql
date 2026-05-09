-- Super administrador (flag no usuário) + módulos SaaS por instância (sidebar + rotas).

ALTER TABLE usuarios ADD COLUMN is_super_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER perfil;

CREATE TABLE IF NOT EXISTS saas_modulos (
    grupo        ENUM('admin','cliente') NOT NULL,
    modulo_key VARCHAR(64) NOT NULL,
    label        VARCHAR(120) NOT NULL,
    habilitado   TINYINT(1) NOT NULL DEFAULT 1,
    ordem        INT NOT NULL DEFAULT 0,
    PRIMARY KEY (grupo, modulo_key),
    INDEX idx_saas_grupo (grupo, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO saas_modulos (grupo, modulo_key, label, habilitado, ordem) VALUES
('admin', 'clientes', 'Clientes', 1, 10),
('admin', 'usuarios', 'Usuários', 1, 20),
('admin', 'chamados', 'Chamados', 1, 30),
('admin', 'os', 'Ordens de serviço', 1, 40),
('admin', 'kanban', 'Kanban', 1, 50),
('admin', 'contas', 'Contas a receber', 1, 60),
('admin', 'relatorio_financeiro', 'Relatório financeiro', 1, 70),
('admin', 'configuracoes', 'Configurações', 1, 80),
('admin', 'suporte', 'Suporte interno', 1, 90),
('cliente', 'chamados', 'Meus chamados', 1, 10),
('cliente', 'os', 'Minhas OS', 1, 20),
('cliente', 'contas', 'Contas', 1, 30),
('cliente', 'documentos', 'Documentos', 1, 40),
('cliente', 'suporte', 'Suporte', 1, 50);

UPDATE usuarios SET is_super_admin = 1 WHERE id = 1 AND perfil = 'admin' LIMIT 1;

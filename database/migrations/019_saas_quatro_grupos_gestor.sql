-- Quatro grupos de módulos: super_admin, gestor, cliente, operador + perfil usuário "gestor" (empresa).

ALTER TABLE usuarios MODIFY COLUMN perfil ENUM('admin','cliente','operador','gestor') NOT NULL;

-- SaaS: expandir, migrar admin -> super_admin, inserir gestor, remover valor admin
ALTER TABLE saas_modulos MODIFY COLUMN grupo ENUM('admin','cliente','operador','super_admin','gestor') NOT NULL;
UPDATE saas_modulos SET grupo = 'super_admin' WHERE grupo = 'admin';

INSERT IGNORE INTO saas_modulos (grupo, modulo_key, label, habilitado, ordem) VALUES
('gestor', 'chamados', 'Chamados', 1, 10),
('gestor', 'usuarios', 'Usuários e operadores', 1, 20),
('gestor', 'configuracoes', 'Configurações', 1, 30),
('gestor', 'suporte', 'Suporte', 1, 40),
('gestor', 'os', 'Ordens de serviço', 1, 50),
('gestor', 'kanban', 'Kanban', 1, 60);

ALTER TABLE saas_modulos MODIFY COLUMN grupo ENUM('super_admin','gestor','cliente','operador') NOT NULL;

-- perfil_modulos
ALTER TABLE perfil_modulos MODIFY COLUMN grupo ENUM('admin','cliente','operador','super_admin','gestor') NOT NULL;
UPDATE perfil_modulos SET grupo = 'super_admin' WHERE grupo = 'admin';

INSERT IGNORE INTO perfil_modulos (perfil_key, grupo, modulo_key, habilitado)
SELECT pk.perfil_key, 'gestor', m.modulo_key, m.habilitado
FROM (SELECT DISTINCT perfil_key FROM perfil_modulos) AS pk
CROSS JOIN saas_modulos m
WHERE m.grupo = 'gestor';

ALTER TABLE perfil_modulos MODIFY COLUMN grupo ENUM('super_admin','gestor','cliente','operador') NOT NULL;

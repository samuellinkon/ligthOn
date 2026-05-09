-- Dashboard visível no menu do painel interno para gestor (e linha super_admin em bases já existentes).

INSERT IGNORE INTO saas_modulos (grupo, modulo_key, label, habilitado, ordem) VALUES
('super_admin', 'dashboard', 'Dashboard', 1, 3),
('gestor', 'dashboard', 'Dashboard', 1, 3);

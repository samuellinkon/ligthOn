-- Módulo interno de catálogo: produtos e serviços da empresa.

INSERT IGNORE INTO saas_modulos (grupo, modulo_key, label, habilitado, ordem) VALUES
('super_admin', 'catalogo', 'Produtos e serviços', 1, 65),
('gestor', 'catalogo', 'Produtos e serviços', 1, 65);

INSERT IGNORE INTO perfil_modulos (perfil_key, grupo, modulo_key, habilitado) VALUES
('padrao', 'super_admin', 'catalogo', 1),
('padrao', 'gestor', 'catalogo', 1),
('operacao', 'super_admin', 'catalogo', 1),
('operacao', 'gestor', 'catalogo', 1),
('financeiro', 'super_admin', 'catalogo', 0),
('financeiro', 'gestor', 'catalogo', 0);

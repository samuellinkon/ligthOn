-- Módulos do gestor alinhados ao menu admin (clientes, relatório) + perfis de módulos.

INSERT IGNORE INTO saas_modulos (grupo, modulo_key, label, habilitado, ordem) VALUES
('gestor', 'clientes', 'Clientes (empresa)', 1, 5),
('gestor', 'relatorio_financeiro', 'Relatório financeiro', 1, 55);

INSERT IGNORE INTO perfil_modulos (perfil_key, grupo, modulo_key, habilitado) VALUES
('padrao', 'gestor', 'clientes', 1),
('padrao', 'gestor', 'relatorio_financeiro', 1),
('operacao', 'gestor', 'clientes', 1),
('operacao', 'gestor', 'relatorio_financeiro', 1),
('financeiro', 'gestor', 'clientes', 0),
('financeiro', 'gestor', 'relatorio_financeiro', 1);

-- Limita o perfil gestor aos módulos operacionais da empresa licenciada.
-- Permitidos: clientes, usuários, chamados, medição, OS, iluminação e catálogo.

DELETE FROM saas_modulos
WHERE grupo = 'gestor'
  AND modulo_key NOT IN ('clientes', 'usuarios', 'chamados', 'medicao', 'os', 'pontos_iluminacao', 'catalogo', 'auditoria');

INSERT IGNORE INTO saas_modulos (grupo, modulo_key, label, habilitado, ordem) VALUES
('gestor', 'clientes', 'Clientes', 1, 10),
('gestor', 'usuarios', 'Usuários', 1, 20),
('gestor', 'chamados', 'Chamados', 1, 30),
('gestor', 'medicao', 'Medição', 1, 40),
('gestor', 'os', 'OS', 1, 50),
('gestor', 'pontos_iluminacao', 'Pontos de iluminação', 1, 55),
('gestor', 'catalogo', 'Catálogo', 1, 60),
('gestor', 'auditoria', 'Auditoria', 1, 58);

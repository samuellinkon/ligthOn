-- Portal cliente: catálogo e auditoria; desativar módulo «os» no grupo cliente.
INSERT INTO saas_modulos (grupo, modulo_key, label, habilitado, ordem) VALUES
('cliente', 'catalogo', 'Catálogo', 1, 18),
('cliente', 'auditoria', 'Auditoria', 1, 22)
ON DUPLICATE KEY UPDATE label = VALUES(label), habilitado = VALUES(habilitado), ordem = VALUES(ordem);

UPDATE saas_modulos SET habilitado = 0 WHERE grupo = 'cliente' AND modulo_key = 'os';

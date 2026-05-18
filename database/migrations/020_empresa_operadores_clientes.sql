-- Hierarquia: empresa (clientes raiz) -> N operadores (usuarios.empresa_id) e N contas cliente (clientes.empresa_id + usuarios.cliente_id).
-- Operadores e gestores deixam de usar usuarios.cliente_id; passam a usar usuarios.empresa_id.

ALTER TABLE clientes
    ADD COLUMN empresa_id INT UNSIGNED NULL DEFAULT NULL AFTER id,
    ADD INDEX idx_clientes_empresa_pai (empresa_id),
    ADD CONSTRAINT fk_clientes_empresa_pai FOREIGN KEY (empresa_id) REFERENCES clientes(id) ON DELETE SET NULL;

ALTER TABLE usuarios
    ADD COLUMN empresa_id INT UNSIGNED NULL DEFAULT NULL AFTER cliente_id,
    ADD INDEX idx_usuarios_empresa (empresa_id),
    ADD CONSTRAINT fk_usuarios_empresa FOREIGN KEY (empresa_id) REFERENCES clientes(id) ON DELETE SET NULL;

UPDATE usuarios
SET empresa_id = cliente_id
WHERE perfil IN ('operador', 'gestor') AND cliente_id IS NOT NULL;

UPDATE usuarios
SET cliente_id = NULL
WHERE perfil IN ('operador', 'gestor');

-- Tipo de cobrança (setup / mensalidade / OS) e vínculo opcional com ordem de serviço
ALTER TABLE contas ADD COLUMN tipo_cobranca ENUM('setup','mensalidade','os') NOT NULL DEFAULT 'mensalidade';
ALTER TABLE contas ADD COLUMN os_id INT UNSIGNED NULL;
ALTER TABLE contas ADD INDEX idx_contas_os (os_id);
ALTER TABLE contas ADD CONSTRAINT fk_contas_os FOREIGN KEY (os_id) REFERENCES os(id) ON DELETE SET NULL;

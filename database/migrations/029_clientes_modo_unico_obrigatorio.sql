-- Instância única: gestão + prefeitura atendida (sem modo multi-cliente).
UPDATE app_config SET valor = 'unico' WHERE chave = 'clientes_modo';

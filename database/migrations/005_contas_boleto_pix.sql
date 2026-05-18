-- Cobrança: observações, boleto (linha digitável + link) e PIX
ALTER TABLE contas ADD COLUMN observacoes TEXT NULL;
ALTER TABLE contas ADD COLUMN boleto_linha VARCHAR(54) NULL;
ALTER TABLE contas ADD COLUMN boleto_url VARCHAR(512) NULL;
ALTER TABLE contas ADD COLUMN pix_chave VARCHAR(200) NULL;
ALTER TABLE contas ADD COLUMN pix_tipo VARCHAR(30) NULL;

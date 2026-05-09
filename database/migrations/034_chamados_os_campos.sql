-- Campos de formulário estilo Ordem de Serviço (contribuinte, endereço estruturado, classificação).

SET NAMES utf8mb4;

SET @db := DATABASE();

SET @sql := IF(
    EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamados')
    AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = @db AND table_name = 'chamados' AND column_name = 'contribuinte_cpf'),
    'ALTER TABLE chamados
        ADD COLUMN contribuinte_cpf VARCHAR(20) NULL DEFAULT NULL AFTER descricao,
        ADD COLUMN contribuinte_nome VARCHAR(200) NULL DEFAULT NULL AFTER contribuinte_cpf,
        ADD COLUMN contribuinte_telefone VARCHAR(40) NULL DEFAULT NULL AFTER contribuinte_nome,
        ADD COLUMN contribuinte_email VARCHAR(160) NULL DEFAULT NULL AFTER contribuinte_telefone,
        ADD COLUMN data_abertura_os DATE NULL DEFAULT NULL AFTER contribuinte_email,
        ADD COLUMN origem_os VARCHAR(80) NULL DEFAULT NULL AFTER data_abertura_os,
        ADD COLUMN problema_os VARCHAR(120) NULL DEFAULT NULL AFTER origem_os,
        ADD COLUMN tipo_os VARCHAR(80) NULL DEFAULT NULL AFTER problema_os,
        ADD COLUMN ponto_referencia VARCHAR(255) NULL DEFAULT NULL AFTER tipo_os,
        ADD COLUMN os_cep VARCHAR(12) NULL DEFAULT NULL AFTER ponto_referencia,
        ADD COLUMN os_logradouro VARCHAR(255) NULL DEFAULT NULL AFTER os_cep,
        ADD COLUMN os_numero VARCHAR(32) NULL DEFAULT NULL AFTER os_logradouro,
        ADD COLUMN os_complemento VARCHAR(160) NULL DEFAULT NULL AFTER os_numero,
        ADD COLUMN os_bairro VARCHAR(120) NULL DEFAULT NULL AFTER os_complemento,
        ADD COLUMN os_cidade VARCHAR(160) NULL DEFAULT NULL AFTER os_bairro,
        ADD COLUMN os_uf CHAR(2) NULL DEFAULT NULL AFTER os_cidade',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

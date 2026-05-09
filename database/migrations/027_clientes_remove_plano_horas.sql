-- Remove plano comercial, pacote de horas e valor de hora extra do cadastro de clientes.
-- Um ALTER por coluna: se alguma já foi removida, as demais ainda são aplicadas.
ALTER TABLE clientes DROP COLUMN plano;
ALTER TABLE clientes DROP COLUMN horas_contratadas;
ALTER TABLE clientes DROP COLUMN valor_hora_extra;
ALTER TABLE clientes DROP COLUMN dia_renovacao;

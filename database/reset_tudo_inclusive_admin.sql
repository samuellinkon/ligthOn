-- =============================================================================
-- RESET TOTAL — apaga TODOS os dados, inclusive usuarios admin
-- =============================================================================
-- DESTRUTIVO:
--   • apaga clientes, chamados, itens, medições, OS, contas, kanban, anexos,
--     suporte, chaves PIX, templates e TODOS os usuarios, inclusive admin.
--
-- PRESERVA:
--   • app_config
--   • saas_modulos
--
-- NÃO altera arquivos em disco (uploads/clientes/*). Apague manualmente se
-- quiser remover os anexos físicos.
--
-- Uso manual no phpMyAdmin ou linha de comando:
--   mysql -u USUARIO -p NOME_DO_BANCO < database/reset_tudo_inclusive_admin.sql
--
-- ATENÇÃO: depois de rodar, você precisará executar uma migration/seed para
-- recriar um admin ou criar usuário direto no banco.
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM os_pedido_anexos;
DELETE FROM os_pedido_respostas;
DELETE FROM os_pedido_itens;
DELETE FROM os_pedidos;

DELETE FROM medicao_import_linhas;
DELETE FROM medicao_imports;

DELETE FROM chamado_respostas;
DELETE FROM chamado_anexos;
DELETE FROM chamado_itens;
DELETE FROM chamados;
DELETE FROM chamado_templates;

DELETE FROM cliente_anexos;
DELETE FROM cliente_itens;

DELETE FROM contas;
DELETE FROM os;
DELETE FROM kanban_cards;
DELETE FROM suporte;
DELETE FROM pix_chaves_globais;

DELETE FROM usuarios;
DELETE FROM clientes;

ALTER TABLE clientes AUTO_INCREMENT = 1;
ALTER TABLE usuarios AUTO_INCREMENT = 1;
ALTER TABLE chamados AUTO_INCREMENT = 1001;
ALTER TABLE chamado_itens AUTO_INCREMENT = 1;
ALTER TABLE chamado_respostas AUTO_INCREMENT = 1;
ALTER TABLE chamado_anexos AUTO_INCREMENT = 1;
ALTER TABLE chamado_templates AUTO_INCREMENT = 1;
ALTER TABLE cliente_anexos AUTO_INCREMENT = 1;
ALTER TABLE cliente_itens AUTO_INCREMENT = 1;
ALTER TABLE medicao_imports AUTO_INCREMENT = 1;
ALTER TABLE medicao_import_linhas AUTO_INCREMENT = 1;
ALTER TABLE os AUTO_INCREMENT = 2001;
ALTER TABLE os_pedidos AUTO_INCREMENT = 5001;
ALTER TABLE os_pedido_itens AUTO_INCREMENT = 1;
ALTER TABLE os_pedido_respostas AUTO_INCREMENT = 1;
ALTER TABLE os_pedido_anexos AUTO_INCREMENT = 1;
ALTER TABLE kanban_cards AUTO_INCREMENT = 200;
ALTER TABLE contas AUTO_INCREMENT = 901;
ALTER TABLE suporte AUTO_INCREMENT = 501;
ALTER TABLE pix_chaves_globais AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

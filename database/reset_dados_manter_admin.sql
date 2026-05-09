-- =============================================================================
-- RESET DE DADOS — mantém apenas usuários com perfil = 'admin'
-- =============================================================================
-- DESTRUTIVO: apaga clientes, chamados, itens, medições, OS (clássicas e
-- pedidos), contas, kanban, anexos, suporte, chaves PIX globais e todos os
-- usuários que não são admin (cliente, operador, gestor).
--
-- PRESERVA:
--   • app_config (SMTP, debug, clientes_modo, PIX global, etc.)
--   • saas_modulos (menu / módulos por perfil)
--   • usuarios onde perfil = 'admin' (todos os admins; senhas intactas)
--
-- NÃO altera arquivos em disco (uploads/clientes/*). Apague manualmente se
-- quiser pasta vazia.
--
-- Uso: phpMyAdmin (aba SQL) ou linha de comando, apontando para o banco certo:
--   mysql -u root -p NOME_DO_BANCO < database/reset_dados_manter_admin.sql
--
-- Este arquivo NÃO é executado pelo migrate.php (uso manual apenas).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Referências de usuários a clientes (admins costumam ser NULL; gestores não)
UPDATE usuarios SET cliente_id = NULL, empresa_id = NULL WHERE perfil = 'admin';

DELETE FROM usuarios WHERE perfil <> 'admin';

-- Filhos antes dos pais. Usa prepared statements para ignorar tabelas ausentes
-- em bancos que ainda não rodaram todas as migrations.
SET @db := DATABASE();

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'medicao_import_linhas'), 'DELETE FROM medicao_import_linhas', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'medicao_imports'), 'DELETE FROM medicao_imports', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'os_pedido_anexos'), 'DELETE FROM os_pedido_anexos', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'os_pedido_respostas'), 'DELETE FROM os_pedido_respostas', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'os_pedido_itens'), 'DELETE FROM os_pedido_itens', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'os_pedidos'), 'DELETE FROM os_pedidos', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamado_respostas'), 'DELETE FROM chamado_respostas', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamado_anexos'), 'DELETE FROM chamado_anexos', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamado_itens'), 'DELETE FROM chamado_itens', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamados'), 'DELETE FROM chamados', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'cliente_anexos'), 'DELETE FROM cliente_anexos', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'cliente_itens'), 'DELETE FROM cliente_itens', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'contas'), 'DELETE FROM contas', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'os'), 'DELETE FROM os', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'kanban_cards'), 'DELETE FROM kanban_cards', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'suporte'), 'DELETE FROM suporte', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'pix_chaves_globais'), 'DELETE FROM pix_chaves_globais', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'clientes'), 'DELETE FROM clientes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamado_templates'), 'DELETE FROM chamado_templates', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- IDs iniciais alinhados ao schema / seed (opcional, evita “pular” números)
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamados'), 'ALTER TABLE chamados AUTO_INCREMENT = 1001', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'os'), 'ALTER TABLE os AUTO_INCREMENT = 2001', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'kanban_cards'), 'ALTER TABLE kanban_cards AUTO_INCREMENT = 200', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'contas'), 'ALTER TABLE contas AUTO_INCREMENT = 901', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'suporte'), 'ALTER TABLE suporte AUTO_INCREMENT = 501', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'os_pedidos'), 'ALTER TABLE os_pedidos AUTO_INCREMENT = 5001', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

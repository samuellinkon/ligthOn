-- =============================================================================
-- RESET OPERACIONAL — mantém clientes e todos os usuários
-- =============================================================================
-- DESTRUTIVO: apaga chamados, catálogo (cliente_itens), medições, pontos de
-- iluminação, OS, pedidos de OS, contas, kanban, suporte, anexos de cliente,
-- templates, notificações e trilhas de auditoria.
--
-- PRESERVA:
--   • clientes (cadastro das empresas / unidades)
--   • usuarios (admin, gestor, operador, cliente — senhas intactas)
--   • app_config (SMTP, debug, clientes_modo, PIX global, etc.)
--   • saas_modulos (menu / módulos por perfil)
--   • pix_chaves_globais (chaves PIX da empresa)
--
-- NÃO altera arquivos em disco (uploads/*). Apague manualmente se quiser
-- remover anexos, fotos de postes e arquivos de chamados.
--
-- Uso manual (não roda no migrate.php):
--   mysql -u root -p NOME_DO_BANCO < database/reset_operacional_manter_clientes.sql
--
-- Ordem: filhos antes dos pais. Tabelas ausentes são ignoradas (bancos antigos).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

SET @db := DATABASE();

-- Helper: DELETE FROM tabela se existir
-- (repetido via prepared statements abaixo)

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'notificacoes'), 'DELETE FROM notificacoes', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'medicao_import_linhas'), 'DELETE FROM medicao_import_linhas', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'medicao_imports'), 'DELETE FROM medicao_imports', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'medicao_custos'), 'DELETE FROM medicao_custos', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'os_pedido_anexos'), 'DELETE FROM os_pedido_anexos', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'os_pedido_respostas'), 'DELETE FROM os_pedido_respostas', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'os_pedido_itens'), 'DELETE FROM os_pedido_itens', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'os_pedidos'), 'DELETE FROM os_pedidos', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamado_itens'), 'DELETE FROM chamado_itens', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamado_tecnicos'), 'DELETE FROM chamado_tecnicos', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamado_anexos'), 'DELETE FROM chamado_anexos', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamado_respostas'), 'DELETE FROM chamado_respostas', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamados'), 'DELETE FROM chamados', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'contas'), 'DELETE FROM contas', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'os'), 'DELETE FROM os', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'kanban_cards'), 'DELETE FROM kanban_cards', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'suporte'), 'DELETE FROM suporte', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'audit_logs'), 'DELETE FROM audit_logs', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'ponto_iluminacao_imagens'), 'DELETE FROM ponto_iluminacao_imagens', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'pontos_iluminacao'), 'DELETE FROM pontos_iluminacao', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'cliente_itens'), 'DELETE FROM cliente_itens', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'cliente_anexos'), 'DELETE FROM cliente_anexos', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'cliente_servicos'), 'DELETE FROM cliente_servicos', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'chamado_templates'), 'DELETE FROM chamado_templates', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'usuario_password_resets'), 'DELETE FROM usuario_password_resets', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- IDs iniciais alinhados ao schema / seed (opcional)
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
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'cliente_itens'), 'ALTER TABLE cliente_itens AUTO_INCREMENT = 1', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = @db AND table_name = 'pontos_iluminacao'), 'ALTER TABLE pontos_iluminacao AUTO_INCREMENT = 1', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

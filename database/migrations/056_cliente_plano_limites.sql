-- Limites comerciais do plano SaaS por cliente (prefeitura matriz).

SET NAMES utf8mb4;

SET @db := DATABASE();

SET @sql := IF(
    NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = @db AND table_name = 'clientes' AND column_name = 'plano_codigo'),
    "ALTER TABLE clientes
        ADD COLUMN plano_codigo ENUM('padrao','expandido','dedicado','personalizado') NOT NULL DEFAULT 'padrao' AFTER obs,
        ADD COLUMN plano_mensalidade DECIMAL(10,2) NULL DEFAULT NULL AFTER plano_codigo,
        ADD COLUMN limite_pontos INT UNSIGNED NULL DEFAULT NULL AFTER plano_mensalidade,
        ADD COLUMN limite_chamados_mes INT UNSIGNED NULL DEFAULT NULL AFTER limite_pontos,
        ADD COLUMN limite_itens_mes INT UNSIGNED NULL DEFAULT NULL AFTER limite_chamados_mes,
        ADD COLUMN limite_storage_mb INT UNSIGNED NULL DEFAULT NULL AFTER limite_itens_mes,
        ADD COLUMN limite_usuarios INT UNSIGNED NULL DEFAULT NULL AFTER limite_storage_mb",
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Plano 1 (Padrão) para clientes raiz existentes sem limites definidos.
UPDATE clientes
   SET plano_codigo = 'padrao',
       plano_mensalidade = 4000.00,
       limite_pontos = 6000,
       limite_chamados_mes = 300,
       limite_itens_mes = 200,
       limite_storage_mb = 5120,
       limite_usuarios = 12
 WHERE empresa_id IS NULL
   AND (plano_codigo IS NULL OR plano_codigo = 'padrao')
   AND limite_pontos IS NULL;

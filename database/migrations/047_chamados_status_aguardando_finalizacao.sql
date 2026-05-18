-- Renomeia status Aguardando → Aguardando Finalização
-- 1) Incluir o novo valor no ENUM (mantendo o antigo para o UPDATE)
ALTER TABLE chamados
    MODIFY status ENUM(
        'Aberto',
        'Em andamento',
        'Aguardando',
        'Aguardando Finalização',
        'Resolvido',
        'Fechado',
        'Cancelado'
    ) NOT NULL DEFAULT 'Aberto';

-- 2) Migrar registos existentes
UPDATE chamados SET status = 'Aguardando Finalização' WHERE status = 'Aguardando';

-- 3) Remover o valor antigo do ENUM
ALTER TABLE chamados
    MODIFY status ENUM(
        'Aberto',
        'Em andamento',
        'Aguardando Finalização',
        'Resolvido',
        'Fechado',
        'Cancelado'
    ) NOT NULL DEFAULT 'Aberto';

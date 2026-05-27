-- Renomeia Aguardando Finalização → Aguardando Aprovação (fluxo: técnico envia → gestor aprova)
ALTER TABLE chamados
    MODIFY status ENUM(
        'Aberto',
        'Em andamento',
        'Aguardando Finalização',
        'Aguardando Aprovação',
        'Resolvido',
        'Validado',
        'Fechado',
        'Cancelado'
    ) NOT NULL DEFAULT 'Aberto';

UPDATE chamados SET status = 'Aguardando Aprovação' WHERE status = 'Aguardando Finalização';

ALTER TABLE chamados
    MODIFY status ENUM(
        'Aberto',
        'Em andamento',
        'Aguardando Aprovação',
        'Resolvido',
        'Validado',
        'Fechado',
        'Cancelado'
    ) NOT NULL DEFAULT 'Aberto';

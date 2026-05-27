-- Status Validado: confirmação do cliente após Resolvido (entra no BM).
ALTER TABLE chamados
    MODIFY COLUMN status ENUM(
        'Aberto',
        'Em andamento',
        'Aguardando Aprovação',
        'Resolvido',
        'Validado',
        'Fechado',
        'Cancelado'
    ) NOT NULL DEFAULT 'Aberto';

UPDATE chamados SET status = 'Validado' WHERE status = 'Fechado';

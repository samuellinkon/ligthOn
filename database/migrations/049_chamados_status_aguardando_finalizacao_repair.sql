-- Reparo idempotente se 047 falhou no UPDATE (ENUM ainda sem «Aguardando Finalização»)
ALTER TABLE chamados
    MODIFY status ENUM(
        'Aberto',
        'Em andamento',
        'Aguardando',
        'Aguardando Finalização',
        'Aguardando Aprovação',
        'Resolvido',
        'Validado',
        'Fechado',
        'Cancelado'
    ) NOT NULL DEFAULT 'Aberto';

UPDATE chamados SET status = 'Aguardando Finalização' WHERE status = 'Aguardando';

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

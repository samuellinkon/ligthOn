-- Pré-chamado: abertura incompleta pelo técnico, antes de Aguardando Aprovação.
ALTER TABLE chamados
    MODIFY COLUMN status ENUM(
        'Aberto',
        'Em andamento',
        'Pré-chamado',
        'Aguardando Aprovação',
        'Resolvido',
        'Validado',
        'Fechado',
        'Cancelado'
    ) NOT NULL DEFAULT 'Aberto';

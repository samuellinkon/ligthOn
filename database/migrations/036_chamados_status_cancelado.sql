-- Status "Cancelado" em chamados (encerrado, fora de pendentes/urgentes).
-- Expande o ENUM sem remover valores já em uso (reexecução segura em bases já na 050/053).
ALTER TABLE chamados
  MODIFY COLUMN status ENUM(
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

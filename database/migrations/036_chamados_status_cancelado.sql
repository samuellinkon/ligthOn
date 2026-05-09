-- Status "Cancelado" em chamados (encerrado, fora de pendentes/urgentes).
ALTER TABLE chamados
  MODIFY COLUMN status ENUM(
    'Aberto',
    'Em andamento',
    'Aguardando',
    'Resolvido',
    'Fechado',
    'Cancelado'
  ) NOT NULL DEFAULT 'Aberto';

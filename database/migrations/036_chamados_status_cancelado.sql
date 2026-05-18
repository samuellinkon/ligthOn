-- Status "Cancelado" em chamados (encerrado, fora de pendentes/urgentes).
-- Expande o ENUM sem remover valores já em uso (ex.: «Aguardando Finalização» em bases
-- atualizadas antes da 047). A 047 faz o rename Aguardando → Aguardando Finalização.
ALTER TABLE chamados
  MODIFY COLUMN status ENUM(
    'Aberto',
    'Em andamento',
    'Aguardando',
    'Aguardando Finalização',
    'Resolvido',
    'Fechado',
    'Cancelado'
  ) NOT NULL DEFAULT 'Aberto';

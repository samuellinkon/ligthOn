/**
 * Cor e classe CSS do pin de chamado no mapa conforme status.
 * @param {{ status?: string }} pin
 */
(function (global) {
  'use strict';

  var STATUS_KEY_MAP = {
    Aberto: 'open',
    Aberta: 'open',
    'A Fazer': 'open',
    'Em andamento': 'progress',
    Respondendo: 'progress',
    'Em Progresso': 'progress',
    'Em execução': 'progress',
    'Enviada ao cliente': 'progress',
    'Aguardando Aprovação': 'waiting',
    Pendente: 'waiting',
    Normal: 'waiting',
    Revisão: 'waiting',
    Rascunho: 'waiting',
    Resolvido: 'done',
    Validado: 'done',
    Fechado: 'done',
    Pago: 'done',
    Respondida: 'done',
    Ativo: 'done',
    'Concluído': 'done',
    'Concluída': 'done',
    Concluida: 'done',
    'Medição (BM)': 'done',
    Aprovada: 'done',
    Aprovado: 'done',
    Cancelado: 'cancelled',
    Cancelada: 'cancelled',
    Alta: 'urgent',
    Urgente: 'urgent',
    Vencido: 'urgent',
    Rejeitada: 'urgent',
    Rejeitado: 'urgent',
  };

  var COLORS = {
    cancelled: { fill: '#94a3b8', stroke: '#64748b' },
    open: { fill: '#3b82f6', stroke: '#1d4ed8' },
    done: { fill: '#22c55e', stroke: '#15803d' },
    progress: { fill: '#f59e0b', stroke: '#d97706' },
    waiting: { fill: '#a855f7', stroke: '#7e22ce' },
    urgent: { fill: '#ef4444', stroke: '#dc2626' },
    plain: { fill: '#64748b', stroke: '#475569' },
  };

  function statusKey(status) {
    var s = String(status || '').trim();
    if (s === '') {
      return 'plain';
    }
    return STATUS_KEY_MAP[s] || 'plain';
  }

  global.crmChamadoMarkerStatusKey = statusKey;

  global.crmChamadoMarkerClass = function (pin) {
    var key = statusKey(pin && pin.status);
    return 'chamado-marker chamado-marker--' + key;
  };

  global.crmChamadoMarkerIconColors = function (pin) {
    var key = statusKey(pin && pin.status);
    var c = COLORS[key] || COLORS.plain;
    return { fillColor: c.fill, strokeColor: c.stroke };
  };
})(typeof window !== 'undefined' ? window : this);

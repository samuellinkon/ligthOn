/**
 * Classes CSS do marcador de poste conforme status do cadastro e chamados abertos.
 * @param {{ status?: string, chamados_abertos?: number }} pin
 * @returns {string}
 */
window.crmPontoMarkerClass = function (pin) {
  var cls = 'ponto-marker';
  if (String((pin && pin.status) || '') === 'Inativo') {
    return cls + ' ponto-marker--inativo';
  }
  if (Number((pin && pin.chamados_abertos) || 0) > 0) {
    return cls + ' ponto-marker--alert';
  }
  return cls + ' ponto-marker--ativo';
};

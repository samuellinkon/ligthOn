/**
 * Mapa Leaflet no dashboard: pins a partir de window.CHAMADOS_MAP_PINS.
 */
(function () {
  if (!window.CrmDashboardMapChamados || typeof window.CrmDashboardMapChamados.init !== 'function') {
    return;
  }

  window.CrmDashboardMapChamados.init({
    mapElementId: 'chamados-map',
    pins: window.CHAMADOS_MAP_PINS,
    geocodeApi: window.CHAMADOS_MAP_GEOCODE_API || '',
    persistApi: window.CHAMADOS_MAP_PERSIST_API || '',
    emptyMsg: window.CHAMADOS_MAP_EMPTY_MSG || '',
    loadingMsg: window.CHAMADOS_MAP_LOADING_MSG || '',
    statusFilter: document.getElementById('chamados-map-filter-status'),
    clusterToggle: document.getElementById('chamados-map-toggle-cluster'),
    visibleCountEl: document.getElementById('chamados-map-visible-count'),
  });
})();

'use strict';
(() => {
  const catalog = document.body.dataset.vitrineCatalog;
  const tableSlug = document.body.dataset.workspaceTable || 'vyvoz';
  const pageFilters = new URLSearchParams(location.search);
  function url(path, {rowFilters = false, ids, search = pageFilters.get('q')} = {}) {
    const params = new URLSearchParams(catalog ? {catalog} : {table:tableSlug});
    if (catalog === 'entries' && pageFilters.has('id')) params.set('id',pageFilters.get('id'));
    if ((catalog === 'entries' || catalog === 'clients') && pageFilters.has('client')) params.set('client',pageFilters.get('client'));
    if (catalog === 'flight-statuses') params.set('table',pageFilters.get('table') || 'vyvoz');
    if (catalog && rowFilters) {
      if (pageFilters.has('archive')) params.set('archive',pageFilters.get('archive'));
      if (search) params.set('q',search);
    }
    if (ids) params.set('ids', ids.join(','));
    const endpoint = path + '?' + params;
    return window.DltkPaths?.path(endpoint) ?? endpoint;
  }
  window.VitrineGridScope = Object.freeze({tableSlug,url});
})();

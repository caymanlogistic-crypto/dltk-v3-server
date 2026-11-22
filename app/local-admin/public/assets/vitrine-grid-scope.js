'use strict';
(() => {
  const catalog = document.body.dataset.vitrineCatalog;
  const tableSlug = document.body.dataset.workspaceTable || 'vyvoz';
  const pageFilters = new URLSearchParams(location.search);
  function url(path, {rowFilters = false, ids} = {}) {
    const params = new URLSearchParams(catalog ? {catalog} : {table:tableSlug});
    if (catalog === 'entries' && pageFilters.has('id')) params.set('id',pageFilters.get('id'));
    if (catalog === 'flight-statuses') params.set('table',pageFilters.get('table') || 'vyvoz');
    if (catalog && rowFilters) {
      for (const key of ['archive','q']) {
        if (pageFilters.has(key)) params.set(key, pageFilters.get(key));
      }
    }
    if (ids) params.set('ids', ids.join(','));
    const endpoint = path + '?' + params;
    return window.DltkPaths?.path(endpoint) ?? endpoint;
  }
  window.VitrineGridScope = Object.freeze({tableSlug,url});
})();

'use strict';
(() => {
  const catalog = document.body.dataset.vitrineCatalog;
  const tableSlug = document.body.dataset.workspaceTable || 'vyvoz';
  const pageFilters = new URLSearchParams(location.search);
  const immediateFilters=[...document.querySelectorAll('[data-vitrine-filter-param]')].map(node=>node.dataset.vitrineFilterParam);
  function url(path, {rowFilters = false, ids, search = pageFilters.get('q'), fleetDocuments = false, tripDriver = false} = {}) {
    const params = new URLSearchParams(catalog ? {catalog} : {table:tableSlug});
    if (catalog === 'entries' && pageFilters.has('id')) params.set('id',pageFilters.get('id'));
    if (path === '/api/workspace/preferences' && catalog === 'entries' && pageFilters.get('tariffs') === '1') params.set('tariffs','1');
    if ((catalog === 'entries' || catalog === 'clients') && pageFilters.has('client')) params.set('client',pageFilters.get('client'));
    if (catalog === 'flight-statuses') params.set('table',pageFilters.get('table') || 'vyvoz');
    if (rowFilters) {
      if (catalog === 'entries' && pageFilters.has('partyRole')) params.set('partyRole',pageFilters.get('partyRole'));
      if (pageFilters.has('archive')) params.set('archive',pageFilters.get('archive'));
      if ((catalog || tableSlug==='vyvoz') && search) params.set('q',search);
      if (immediateFilters.length) {
        const current=new URLSearchParams(location.search);
        for (const key of immediateFilters) {
          const value=current.get(key);
          if (value) params.set(key,value); else params.delete(key);
        }
      }
    }
    if (ids) params.set('ids', ids.join(','));
    if (fleetDocuments) params.set('fleetDocuments','1');
    if (tripDriver) params.set('tripDriver','1');
    const endpoint = path + '?' + params;
    return window.DltkPaths?.path(endpoint) ?? endpoint;
  }
  window.VitrineGridScope = Object.freeze({tableSlug,url});
})();

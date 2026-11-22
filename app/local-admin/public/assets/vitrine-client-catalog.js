'use strict';
(() => {
  const toolbar = document.querySelector('.vitrine-catalog-toolbar[data-catalog-id]');
  if (!toolbar) return;
  const catalogId = Number(toolbar.dataset.catalogId);
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const path = url => window.DltkPaths?.path(url) ?? url;
  const notice = (message,tone) => window.PlanexNotifications.show({tone,message});
  const catalogUrl = '/api/workspace/catalogs?id='+catalogId;
  const detailsDialog = document.querySelector('[data-directory-details-dialog]');

  async function request(url,body) {
    const options = {credentials:'same-origin'};
    if (body) Object.assign(options,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)});
    const response = await fetch(path(url),options);
    const result = await response.json();
    if (!response.ok || result.error) throw new Error(result.error?.message || 'Справочник недоступен.');
    return result;
  }

  for (const button of toolbar.querySelectorAll('[data-directory-details]')) {
    button.addEventListener('click',async ()=>{
      const unkeyed = button.hasAttribute('data-unkeyed');
      const row = document.querySelector('#vitrine-rows .is-current-cell');
      if (!unkeyed && !row) { notice('Выберите сохранённую строку.','info'); return; }
      button.disabled = true;
      try {
        const result = await request(catalogUrl+(unkeyed ? '&unkeyed=1' : '&entryId='+encodeURIComponent(row.dataset.row)));
        const body = detailsDialog.querySelector('.modal-body');
        body.replaceChildren();
        if (!result.occurrences.length) body.textContent = 'Для записи нет импортированных реквизитов.';
        for (const occurrence of result.occurrences) {
          const heading = document.createElement('strong');
          heading.textContent = 'Строка источника '+occurrence.row;
          const list = document.createElement('dl');
          result.columns.forEach((label,index)=>{
            const term = document.createElement('dt');
            term.textContent = label;
            const value = document.createElement('dd');
            value.textContent = occurrence.values[index] || '—';
            list.append(term,value);
          });
          body.append(heading,list);
        }
        detailsDialog.showModal();
      } catch (error) { notice(error.message,'danger'); }
      finally { button.disabled = false; }
    });
  }
  detailsDialog?.querySelector('[data-close]').addEventListener('click',()=>detailsDialog.close());

  const sync = document.getElementById('client-catalog-sync');
  sync?.addEventListener('click',async ()=>{
    sync.disabled = true;
    try {
      await request('/api/workspace/catalogs',{action:'refresh_catalog_source',catalogId});
      try { sessionStorage.setItem('dltk-v3.directory-row-feedback','Справочник обновлён.'); } catch {}
      location.reload();
    } catch (error) { notice(error.message,'danger'); sync.disabled = false; }
  });
})();

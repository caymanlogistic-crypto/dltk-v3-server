'use strict';
(() => {
  const form = document.querySelector('.vitrine-catalog-search');
  const grid = window.VitrineRows;
  const field = form?.elements.q;
  let timer;
  const visibility = document.querySelector('[data-directory-visibility]');
  if (visibility) {
    const modes = new Map([['Активные',''],['Архив','1']]);
    if (visibility.dataset.allLabel) modes.set(visibility.dataset.allLabel,'all');
    const initial = [...modes].find(([,mode])=>mode===visibility.dataset.directoryVisibility)?.[0] ?? 'Активные';
    const selector = window.VitrineSelect.create([...modes.keys()].map(label=>({value:label,label})),initial);
    selector.field.setAttribute('aria-label',visibility.dataset.filterLabel);
    visibility.append(selector.element);
    selector.field.addEventListener('change',()=>{
      const current = selector.resolveValue();
      if (current===initial) return;
      if (window.VitrineGrid.editing() || document.querySelector('dialog[open]')) {
        selector.field.value = initial;
        window.PlanexNotifications.show({tone:'info',message:'Завершите редактирование перед сменой фильтра.'});
        return;
      }
      clearTimeout(timer);
      const url = new URL(location.href);
      const mode = modes.get(current);
      if (mode) url.searchParams.set('archive',mode); else url.searchParams.delete('archive');
      if (field) {
        const query = field.value.trim();
        if (query) url.searchParams.set('q',query); else url.searchParams.delete('q');
      }
      location.assign(url);
    });
  }
  const partyFilter = document.querySelector('[data-party-role]');
  if (partyFilter) {
    const modes = new Map([['Все роли',''], ...Object.entries(JSON.parse(partyFilter.dataset.partyRoles)).map(([key,label])=>[label,key])]);
    const initial = [...modes].find(([,key])=>key===partyFilter.dataset.partyRole)?.[0] ?? 'Все роли';
    const selector = window.VitrineSelect.create([...modes.keys()].map(label=>({value:label,label})),initial);
    selector.field.setAttribute('aria-label','Фильтр по роли контрагента');
    partyFilter.append(selector.element);
    selector.field.addEventListener('change',()=>{
      const mode = modes.get(selector.resolveValue());
      if (mode===undefined || mode===partyFilter.dataset.partyRole) return;
      if (window.VitrineGrid.editing() || document.querySelector('dialog[open]')) {
        selector.field.value=initial;
        window.PlanexNotifications.show({tone:'info',message:'Завершите редактирование перед сменой фильтра.'});
        return;
      }
      clearTimeout(timer);
      const url = new URL(location.href);
      if (mode) url.searchParams.set('partyRole',mode); else url.searchParams.delete('partyRole');
      const query=field.value.trim();
      if (query) url.searchParams.set('q',query); else url.searchParams.delete('q');
      location.assign(url);
    });
  }
  if (!field || !grid) return;
  async function update() {
    clearTimeout(timer);
    try {
      await grid.filterDirectory(field.value.trim());
    } catch (error) {
      window.PlanexNotifications.show({tone:'danger',message:error.message || 'Не удалось выполнить поиск.'});
    }
  }
  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(update,220);
  }
  field.addEventListener('input',event=>{ if (!event.isComposing) schedule(); });
  field.addEventListener('compositionstart',()=>clearTimeout(timer));
  field.addEventListener('compositionend',schedule);
  form.addEventListener('submit',event=>{
    event.preventDefault();
    update();
  });
})();

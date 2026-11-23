'use strict';
(() => {
  const form = document.querySelector('.vitrine-catalog-search');
  const grid = window.VitrineRows;
  if (!form || !grid) return;
  const field = form.elements.q;
  let timer;
  const visibility = form.querySelector('[data-client-visibility]');
  if (visibility) {
    const modes = new Map([['Все клиенты','all'],['Активные',''],['Архив','1']]);
    const initial = visibility.dataset.clientVisibility;
    const selector = window.VitrineSelect.create([...modes.keys()].map(label=>({value:label,label})),initial);
    selector.field.setAttribute('aria-label','Фильтр клиентов');
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
      const query = field.value.trim();
      if (query) url.searchParams.set('q',query); else url.searchParams.delete('q');
      location.assign(url);
    });
  }
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

'use strict';
(() => {
  const dialog = document.querySelector('.vitrine-trip-driver-dialog');
  if (!dialog) return;
  const content = dialog.querySelector('[data-trip-driver-content]');
  let request;
  let origin;

  document.addEventListener('click', async event => {
    const button = event.target.closest('[data-trip-driver-button]');
    if (!button || dialog.open || window.VitrineGrid?.editing()) return;
    origin = button.closest('td');
    if (!origin) return;
    request = new AbortController();
    const {signal} = request;
    content.textContent = 'Загрузка…';
    content.setAttribute('aria-busy','true');
    dialog.showModal();
    try {
      const url = window.VitrineGridScope.url('/api/vitrine/rows',{ids:[Number(origin.dataset.row)],rowFilters:true,tripDriver:true});
      const response = await fetch(url,{signal,cache:'no-store'});
      const result = await response.json();
      if (!response.ok || result.error) throw new Error(result.error?.message || 'Не удалось открыть карточку водителя.');
      if (signal.aborted) return;
      content.replaceChildren();
      for (const [heading,fields] of Object.entries(result.sections)) {
        const title = document.createElement('h3');
        title.textContent = heading;
        const list = document.createElement('dl');
        for (const [label,value] of Object.entries(fields)) {
          const term = document.createElement('dt');
          term.textContent = label;
          const description = document.createElement('dd');
          description.textContent = value || '—';
          list.append(term,description);
        }
        content.append(title,list);
      }
    } catch (error) {
      if (signal.aborted) return;
      content.replaceChildren();
      window.PlanexNotifications.show({tone:'danger',message:error.message});
    } finally {
      if (!signal.aborted) content.removeAttribute('aria-busy');
    }
  });
  dialog.addEventListener('close', () => {
    request?.abort();
    content.replaceChildren();
    origin?.focus({preventScroll:true});
    origin = null;
  });
})();

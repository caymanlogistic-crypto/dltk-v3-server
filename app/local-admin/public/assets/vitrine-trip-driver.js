'use strict';
(() => {
  const dialog = document.querySelector('.vitrine-trip-driver-dialog');
  if (!dialog) return;
  const content = dialog.querySelector('[data-trip-driver-content]');
  const copyButton = dialog.querySelector('[data-trip-driver-copy]');
  let clipboardText = '';
  let request;
  let origin;

  copyButton.addEventListener('click', async () => {
    if (!clipboardText) return;
    const copiedCard = request;
    try {
      await navigator.clipboard.writeText(clipboardText);
    } catch {
      window.PlanexNotifications.show({tone:'danger',message:'Не удалось скопировать данные в буфер обмена.'});
      return;
    }
    if (dialog.open && request===copiedCard) dialog.close();
    window.PlanexNotifications.show({tone:'success',message:'Скопировано в буфер обмена.'});
  });

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
      let url = window.VitrineGridScope.url('/api/vitrine/rows',{ids:[Number(origin.dataset.row)],rowFilters:true,tripDriver:true});
      if (document.body.dataset.workspaceUser==='preview') {
        url += '&vehicle='+encodeURIComponent(origin.querySelector('[data-trip-vehicle-value]').textContent);
      }
      const response = await fetch(url,{signal,cache:'no-store'});
      const result = await response.json();
      if (!response.ok || result.error) throw new Error(result.error?.message || 'Не удалось открыть карточку водителя.');
      if (signal.aborted) return;
      content.replaceChildren();
      const clipboardSections = [];
      for (const [heading,fields] of Object.entries(result.sections)) {
        const title = document.createElement('h3');
        title.textContent = heading;
        const list = document.createElement('dl');
        const clipboardLines = [];
        for (const [label,value] of Object.entries(fields)) {
          const term = document.createElement('dt');
          term.textContent = label;
          const description = document.createElement('dd');
          description.textContent = value || '—';
          list.append(term,description);
          if (value?.trim()) clipboardLines.push(`${label}: ${value}`);
        }
        content.append(title,list);
        if (clipboardLines.length) clipboardSections.push([heading,...clipboardLines].join('\n'));
      }
      clipboardText = clipboardSections.join('\n\n');
      copyButton.disabled = !clipboardText;
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
    clipboardText = '';
    copyButton.disabled = true;
    content.replaceChildren();
    origin?.focus({preventScroll:true});
    origin = null;
  });
})();

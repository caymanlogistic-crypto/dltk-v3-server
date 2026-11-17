'use strict';
(() => {
  const trigger = document.getElementById('vitrine-open-statuses');
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  let initial = { statuses:[], palette:[] };
  try { initial = JSON.parse(document.getElementById('vitrine-status-data')?.content || '{}'); } catch {}
  window.vitrineStatusCatalog = { statuses:Array.isArray(initial.statuses) ? initial.statuses : [], palette:Array.isArray(initial.palette) ? initial.palette : [] };
  let dialog, body, footer, title, closeButton, opener, formActive = false, saving = false;
  function el(tag, cls, text) {
    const node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function button(text, cls, action) {
    const node = el('button', 'btn ' + cls, text); node.type = 'button';
    node.addEventListener('click', action); return node;
  }
  function notify(text, tone = 'warning') {
    window.PlanexNotifications.show({tone,message:text});
  }

  function publish(result) {
    window.vitrineStatusCatalog = { statuses:result.statuses, palette:result.palette };
    window.dispatchEvent(new CustomEvent('vitrine-statuses-updated', { detail:window.vitrineStatusCatalog }));
  }
  async function request(payload) {
    const options = payload === undefined ? { credentials:'same-origin' } : {
      method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body:JSON.stringify(payload)
    };
    const response = await fetch(window.DltkPaths.path('/api/vitrine/statuses?table='+encodeURIComponent(document.body.dataset.workspaceTable||'vyvoz')),options);
    const result = await response.json();
    if (!response.ok || result.error) throw new Error(result.error?.message || 'Не удалось прочитать справочник.');
    if (!Array.isArray(result.statuses) || !Array.isArray(result.palette)) throw new Error('Сервер вернул неполный справочник.');
    publish(result); return result;
  }
  function close() {
    if (saving) return;
    dialog?.close(); formActive = false; opener?.focus({preventScroll:true});
  }
  function makeDialog() {
    if (dialog) return;
    dialog = el('dialog','vitrine-status-dialog'); dialog.setAttribute('aria-labelledby','vitrine-status-title');
    const head = el('header','modal-head');
    title = el('h2','modal-title','Статусы'); title.id = 'vitrine-status-title';
    closeButton = el('button','modal-close','×'); closeButton.type = 'button'; closeButton.setAttribute('aria-label','Закрыть'); closeButton.addEventListener('click',close);
    head.append(title,closeButton); body = el('div','modal-body vitrine-status-body'); footer = el('footer','modal-foot'); dialog.append(head,body,footer); document.body.append(dialog);
    dialog.addEventListener('cancel',event => { event.preventDefault(); if (saving) return; if (formActive) renderList(); else close(); });
    dialog.addEventListener('keydown',event => {
      if (event.key !== 'Tab') return;
      const nodes = Array.from(dialog.querySelectorAll('button:not(:disabled),input:not(:disabled),[tabindex="0"]')).filter(node => node.getClientRects().length);
      const first = nodes[0], last = nodes[nodes.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    });
  }
  function errorNode(message) {
    const error = el('p','vitrine-status-error',message); error.setAttribute('role','alert'); return error;
  }
  function renderList(message) {
    formActive = false; title.textContent = 'Статусы'; body.replaceChildren();
    if (message) notify(message,'danger');
    const actions = el('div','modal-foot-actions');
    actions.append(button('Добавить статус','btn-primary',() => renderForm(null)),button('Закрыть','btn-secondary',close));
    footer.replaceChildren(actions);
    const list = el('div','vitrine-status-list');
    for (const status of window.vitrineStatusCatalog.statuses) {
      const row = el('button','vitrine-status-row'); row.type = 'button';
      const chip = el('span','vitrine-status-chip'); window.VitrineDisplay.paint(chip,status.color);
      chip.setAttribute('aria-hidden','true');
      row.append(chip,el('span','vitrine-status-row-name',status.name),el('span','vitrine-status-row-edit','Изменить'));
      row.addEventListener('click',() => renderForm(status)); list.append(row);
    }
    body.append(list,el('p','vitrine-status-note','Выберите статус, чтобы изменить название или цвет.'));
    body.querySelector('button')?.focus();
  }
  function renderForm(status) {
    formActive = true; title.textContent = status ? 'Изменить статус' : 'Новый статус'; body.replaceChildren();
    const form = el('form','vitrine-status-form'); form.id = 'vitrine-status-form'; form.noValidate = true;
    const error = errorNode(''); error.hidden = true;
    const label = el('label','vitrine-status-field');
    const name = el('input','field-input vitrine-status-name'); name.name = 'name'; name.required = true; name.maxLength = 100; name.autocomplete = 'off'; name.value = status?.name ?? '';
    label.append(el('span','vitrine-status-label','Название'),name);
    const colors = el('fieldset','vitrine-status-colors'); colors.append(el('legend','vitrine-status-label','Цвет ячейки'));
    const grid = el('div','vitrine-status-color-grid');
    let color = status?.color ?? window.vitrineStatusCatalog.palette[0]?.fill ?? '';
    const preview = el('div','vitrine-status-preview');
    function updatePreview() { preview.textContent = name.value.trim() || 'Пример статуса'; window.VitrineDisplay.paint(preview,color); }
    for (const item of window.vitrineStatusCatalog.palette) {
      const option = el('label','vitrine-status-color-option radio-item'); window.VitrineDisplay.paint(option,item.fill);
      const radio = el('input'); radio.type = 'radio'; radio.name = 'color'; radio.value = item.fill; radio.required = true; radio.checked = item.fill.toLowerCase() === color.toLowerCase();
      radio.addEventListener('change',() => { color = item.fill; updatePreview(); });
      const mark = el('span','vitrine-status-color-check','✓'); mark.setAttribute('aria-hidden','true');
      const radioMark = el('span','radio-mark'); radioMark.setAttribute('aria-hidden','true');
      option.append(radio,radioMark,el('span','',item.name),mark); grid.append(option);
    }
    colors.append(grid); name.addEventListener('input',updatePreview); updatePreview();
    const actions = el('div','modal-foot-actions');
    const save = el('button','btn btn-primary','Сохранить'); save.type = 'submit'; save.setAttribute('form',form.id);
    const cancel = button('Отмена','btn-secondary',() => renderList()); actions.append(save,cancel);
    form.append(error,label,colors,preview); body.append(form); footer.replaceChildren(actions); name.focus(); name.select();
    form.addEventListener('submit',async event => {
      event.preventDefault(); if (saving) return;
      if (!name.value.trim() || !window.vitrineStatusCatalog.palette.some(item => item.fill === color)) { notify('Укажите название статуса и выберите цвет.','danger'); name.focus(); return; }
      saving = true; error.hidden = true; closeButton.disabled = true; cancel.disabled = true;
      Array.from(form.elements).forEach(node => { node.disabled = true; }); save.textContent = 'Сохраняю…';
      try {
        await request({ id:status?.id ?? null, name:name.value.trim(), color, revision:status?.revision ?? 0 });
        saving = false; closeButton.disabled = false; renderList(); notify('Статус сохранён','success');
      } catch (failure) {
        saving = false; closeButton.disabled = false; cancel.disabled = false; Array.from(form.elements).forEach(node => { node.disabled = false; });
        save.textContent = 'Сохранить'; error.textContent = failure instanceof TypeError ? 'Нет связи с сервером. Введённые данные сохранены в форме.' : failure.message;
        notify(error.textContent,'danger'); name.focus();
      }
    });
  }
  trigger?.addEventListener('click',async event => {
    event.preventDefault();
    if (document.querySelector('.vitrine-editor,.vitrine-time-inline')) { notify('Сначала сохраните правку ячейки или нажмите «Отмена».'); return; }
    document.querySelectorAll('.vitrine-folder[open]').forEach(folder => { folder.open = false; });
    makeDialog(); opener = trigger; title.textContent = 'Статусы'; body.replaceChildren(el('p','','Загружаю справочник…')); footer.replaceChildren(); formActive = false;
    if (!dialog.open) dialog.showModal();
    try { await request(); if (dialog.open) renderList(); }
    catch (failure) { if (dialog.open) { notify(failure.message,'danger'); body.replaceChildren(); body.append(button('Повторить','btn-secondary',() => { close(); trigger.click(); })); } }
  });
})();

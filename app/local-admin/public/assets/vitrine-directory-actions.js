'use strict';
(() => {
  const actions = document.querySelector('[data-directory-row-actions]');
  if (!actions) return;
  const add = actions.querySelector('[data-directory-add]');
  const remove = actions.querySelector('[data-directory-delete]');
  const addDialog = document.querySelector('[data-directory-add-dialog]');
  const deleteDialog = document.querySelector('[data-directory-delete-dialog]');
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const palette = JSON.parse(document.getElementById('vitrine-status-data').content).palette;
  const endpoint = window.VitrineGridScope.url('/api/vitrine/cell');
  const table = document.querySelector('.vitrine-table');
  const selection = () => table.querySelector('tbody .is-current-cell');
  table.addEventListener('click', event => {
    const header = event.target.closest('th[scope="row"]');
    const cell = header?.parentElement.querySelector('td[data-edit-cell]:not([hidden])');
    if (cell && !window.VitrineGrid.editing()) window.VitrineGrid.select(cell);
  });
  const feedbackKey = 'dltk-v3.directory-row-feedback';
  let pending = false, target = null;

  const notice = (message,tone) => window.PlanexNotifications.show({message,tone});
  const onError = error => notice(error.message,error.code === 'entry_in_use' ? 'info' : 'danger');
  const update = () => { remove.disabled = pending || !selection(); };
  window.addEventListener('vitrine-cell-selected',update);
  window.addEventListener('vitrine-rows-updated',update);
  update();

  const page = new URL(location.href);
  const focus = page.searchParams.get('focus');
  if (focus && /^\d+$/.test(focus)) {
    const column = document.querySelector('.vitrine-table th[data-column]:not([hidden])')?.dataset.column;
    if (column) window.VitrineRows?.activate({dataset:{row:focus,column}});
    page.searchParams.delete('focus');
    history.replaceState(null,'',page);
  }
  try {
    const feedback = sessionStorage.getItem(feedbackKey);
    sessionStorage.removeItem(feedbackKey);
    if (feedback) notice(feedback,'success');
  } catch {}

  async function request(action, data) {
    const response = await fetch(endpoint, {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({action,...data})
    });
    const result = await response.json();
    if (!response.ok || result.error) {
      throw Object.assign(new Error(result.error?.message || 'Не удалось выполнить действие.'),{code:result.error?.code});
    }
    return result;
  }

  function refresh(message,id) {
    try { sessionStorage.setItem(feedbackKey,message); } catch {}
    const url = new URL(location.href);
    if (id) {
      url.searchParams.delete('q');
      url.searchParams.set('focus',String(id));
    }
    location.assign(url);
  }

  function bindForm(dialog, submitAction) {
    const form = dialog.querySelector('form');
    const submit = form.querySelector('[type=submit]');
    const cancel = form.querySelector('[data-close]');
    cancel.addEventListener('click',()=>dialog.close());
    dialog.addEventListener('cancel',event=>{ if (submit.disabled) event.preventDefault(); });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (submit.disabled) return;
      submit.disabled = cancel.disabled = true;
      try { await submitAction(new FormData(form)); }
      catch (error) {
        if (error.code === 'entry_in_use') dialog.close();
        onError(error);
      } finally { submit.disabled = cancel.disabled = false; }
    });
  }

  const paletteSlot = addDialog.querySelector('[data-directory-palette]');
  const picker = paletteSlot ? window.VitrineSelect.create(palette.map(item=>({value:item.name,label:item.name,color:item.fill}))) : null;
  if (picker) {
    picker.field.setAttribute('aria-labelledby','directory-color-label');
    paletteSlot.append(picker.element);
  }
  add.addEventListener('click', () => {
    addDialog.querySelector('form').reset();
    if (picker) picker.field.value = '';
    addDialog.showModal();
  });
  bindForm(addDialog, async form => {
    if (picker) {
      const color = palette.find(item=>item.name===picker.field.value);
      if (!color) { notice('Выберите цвет из системной палитры.','danger'); picker.field.focus(); return; }
      form.set('color',color.fill);
    }
    const result = await request('add',Object.fromEntries(form));
    refresh('Запись добавлена.',result.id);
  });
  remove.addEventListener('click', async () => {
    const cell = selection();
    if (!cell || pending) return;
    const row = {row:Number(cell.dataset.row),revision:Number(cell.dataset.revision)};
    pending = true; update();
    try {
      const result = await request('check_delete',row);
      target = row;
      deleteDialog.querySelector('[data-directory-delete-text]').textContent = 'Удалить «'+result.name+'»?';
      deleteDialog.showModal();
    } catch (error) {
      onError(error);
    } finally {
      pending = false; update();
    }
  });
  bindForm(deleteDialog, async () => {
    await request('delete',target);
    refresh('Запись удалена.');
  });
})();

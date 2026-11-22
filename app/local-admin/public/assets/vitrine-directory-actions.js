'use strict';
(() => {
  const actions = document.querySelector('[data-directory-row-actions]');
  if (!actions) return;
  const remove = actions.querySelector('[data-directory-delete]');
  const deleteDialog = document.querySelector('[data-directory-delete-dialog]');
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const palette = JSON.parse(document.getElementById('vitrine-status-data').content).palette;
  const endpoint = window.VitrineGridScope.url('/api/vitrine/cell');
  const table = document.querySelector('.vitrine-table');
  const selection = () => table.querySelector('#vitrine-rows .is-current-cell');
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

  function refresh(message) {
    try { sessionStorage.setItem(feedbackKey,message); } catch {}
    location.reload();
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

  const draftRows = Array.from(table.querySelectorAll('[data-draft-slot]'));
  const draftKey = 'dltk-v3.flight-status-drafts.' + document.body.dataset.workspaceUser + '.' + document.body.dataset.workspaceTable;
  const colors = new Map(palette.map(item=>[item.name,item.fill]));
  let stored = {};
  try { stored = JSON.parse(sessionStorage.getItem(draftKey)) || {}; } catch {}
  const drafts = new Map(draftRows.map(row=>{
    const slot = stored[row.dataset.rowNumber];
    return [row,{name:typeof slot?.name === 'string' ? slot.name : '', color:colors.has(slot?.color) ? slot.color : '', saving:false}];
  }));

  function storeDrafts() {
    const entries = [...drafts].filter(([,draft])=>draft.name || draft.color)
      .map(([row,{name,color}])=>[row.dataset.rowNumber,{name,color}]);
    try { sessionStorage.setItem(draftKey,JSON.stringify(Object.fromEntries(entries))); } catch {}
  }

  function showDraft(row) {
    const {name,color} = drafts.get(row);
    const note = row.querySelector('.vitrine-draft-note');
    const nameCell = row.querySelector('[data-column="A"]');
    const colorCell = row.querySelector('[data-column="B"]');
    nameCell.textContent = name;
    nameCell.title = name || 'Щелчок — ввести название.';
    colorCell.textContent = color;
    colorCell.title = color || 'Щелчок — выбрать цвет.';
    if (color) colorCell.style.setProperty('--order-status-fill',colors.get(color));
    else colorCell.style.removeProperty('--order-status-fill');
    note.hidden = !name && !color;
  }
  draftRows.forEach(showDraft);

  window.VitrineDirectoryDrafts = Object.freeze({
    async write(cell,value) {
      const row = cell.closest('[data-draft-slot]');
      const draft = drafts.get(row);
      if (draft.saving) throw new Error('Дождитесь сохранения строки.');
      value = value.trim();
      if (cell.dataset.column === 'A') {
        if ([...value].length > 160 || /[\r\n]/.test(value)) throw new Error('Введите название одной строкой, до 160 символов.');
        draft.name = value;
      } else {
        if (value && !colors.has(value)) throw new Error('Выберите цвет из системной палитры.');
        draft.color = value;
      }
      storeDrafts();
      showDraft(row);
      const result = {draft:true,value,original:'',revision:0,baseHash:'',orderFill:cell.dataset.column === 'B' ? colors.get(value) : undefined};
      if (!draft.name || !draft.color) return result;
      draft.saving = true;
      try {
        const saved = await request('add',{name:draft.name,color:colors.get(draft.color)});
        draft.name = draft.color = '';
        storeDrafts();
        return {...result,draftSaved:saved.id};
      } finally { draft.saving = false; }
    },
    async finish(cell,id) {
      showDraft(cell.closest('[data-draft-slot]'));
      notice('Запись добавлена.','success');
      try {
        await window.VitrineRows.refreshManifest();
        if (!window.VitrineGrid.editing()) await window.VitrineRows.activate({dataset:{row:String(id),column:cell.dataset.column}});
      } catch (error) { onError(error); }
    },
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

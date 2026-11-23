'use strict';
(() => {
  const actions = document.querySelector('[data-directory-row-actions]');
  if (!actions) return;
  const remove = actions.querySelector('[data-directory-delete]');
  const archiveToggle = actions.querySelector('[data-directory-archive-toggle]');
  const related = actions.querySelector('[data-directory-related]');
  const archive = actions.hasAttribute('data-archive');
  const archiveRows = actions.hasAttribute('data-archive-rows');
  const actionFor = row => archive || row.hasAttribute('data-directory-archived') ? 'restore' : archiveRows ? 'archive' : 'delete';
  const deleteDialog = document.querySelector('[data-directory-delete-dialog]');
  const reasonField = deleteDialog?.querySelector('[data-directory-reason-field]');
  const reason = reasonField?.querySelector('textarea');
  const confirm = deleteDialog?.querySelector('[type="submit"]');
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const endpoint = window.VitrineGridScope.url('/api/vitrine/cell');
  const table = document.querySelector('.vitrine-table');
  const triggerSelector = '[data-directory-row-menu]';
  const feedbackKey = 'dltk-v3.directory-row-feedback';
  let pending = false, target = null, trigger = null, returnFocus = null;

  const notice = (message,tone) => window.PlanexNotifications.show({message,tone});
  const onError = error => notice(error.message || 'Не удалось выполнить действие.',error.code === 'entry_in_use' ? 'info' : 'danger');

  function closeMenu(restoreFocus = false) {
    if (!trigger) return;
    actions.hidden = true;
    trigger.setAttribute('aria-expanded','false');
    trigger = null;
    if (restoreFocus && returnFocus?.isConnected) returnFocus.focus({preventScroll:true});
    returnFocus = null;
  }

  function openMenu(button,cell) {
    if (pending || window.VitrineGrid.editing()) return;
    if (trigger === button) { closeMenu(true); return; }
    closeMenu();
    window.VitrineGrid.select(cell);
    trigger = button;
    returnFocus = cell;
    if (archiveToggle) {
      archiveToggle.textContent = actionFor(cell.parentElement)==='restore' ? 'Восстановить строку' : 'Переместить в архив';
    } else if (remove) {
      const action = actionFor(cell.parentElement);
      remove.textContent = {restore:'Восстановить строку',archive:'В архив',delete:'Удалить строку'}[action];
    }
    if (related) {
      const href = cell.parentElement.dataset.relatedUrl;
      related.hidden = !href;
      if (href) related.setAttribute('href',href);
      else related.removeAttribute('href');
    }
    button.setAttribute('aria-expanded','true');
    actions.hidden = false;
    const bounds = button.getBoundingClientRect();
    const x = Math.min(bounds.left,innerWidth-actions.offsetWidth-8);
    const y = bounds.bottom+actions.offsetHeight+8 <= innerHeight ? bounds.bottom : bounds.top-actions.offsetHeight;
    actions.style.left = Math.max(8,x)+'px';
    actions.style.top = Math.max(8,y)+'px';
    actions.querySelector('[role="menuitem"]:not([hidden])')?.focus({preventScroll:true});
  }

  table.addEventListener('click', event => {
    const header = event.target.closest('th[scope="row"]');
    const cell = header?.parentElement.querySelector('td[data-edit-cell]:not([hidden])');
    if (!cell || window.VitrineGrid.editing()) return;
    const button = event.target.closest(triggerSelector);
    if (button) openMenu(button,cell);
    else window.VitrineGrid.select(cell);
  });
  table.addEventListener('keydown', event => {
    if (event.key !== 'ContextMenu' && !(event.shiftKey && event.key === 'F10')) return;
    const cell = event.target.closest('td[data-edit-cell]');
    const button = cell?.parentElement.querySelector(triggerSelector);
    if (!button || window.VitrineGrid.editing()) return;
    event.preventDefault();
    openMenu(button,cell);
  });
  actions.addEventListener('keydown',event=>{
    if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
      event.preventDefault();
      const items = [...actions.querySelectorAll('[role="menuitem"]:not([hidden])')];
      const offset = event.key === 'ArrowDown' ? 1 : -1;
      items[(items.indexOf(document.activeElement)+offset+items.length)%items.length]?.focus();
      return;
    }
    if (event.key !== 'Escape' && event.key !== 'Tab') return;
    event.preventDefault();
    event.stopPropagation();
    closeMenu(true);
  });
  document.addEventListener('pointerdown',event=>{
    if (!actions.contains(event.target) && !event.target.closest(triggerSelector)) closeMenu();
  });
  table.closest('.vitrine-table-scroll').addEventListener('scroll',()=>closeMenu());
  window.addEventListener('resize',()=>closeMenu());
  window.addEventListener('vitrine-rows-updated',()=>{ if (trigger && !trigger.isConnected) closeMenu(); });

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
        if (error.code === 'entry_in_use' && (!reason || reason.disabled)) dialog.close();
        onError(error);
      } finally { submit.disabled = cancel.disabled = false; }
    });
  }

  const draftBody = document.getElementById('vitrine-draft-rows');
  const fields = JSON.parse(draftBody?.dataset.draftFields || '{}');
  const draftKey = ['dltk-v3.directory-drafts', document.body.dataset.workspaceUser,
    document.body.dataset.workspaceTable, draftBody?.dataset.draftVersion].filter(Boolean).join('.');
  const legacyKey = draftKey.replace('directory-drafts','flight-status-drafts');
  const isFlight = document.body.dataset.vitrineCatalog === 'flight-statuses';
  let stored = {};
  try {
    const current = sessionStorage.getItem(draftKey);
    stored = JSON.parse(current || (isFlight ? sessionStorage.getItem(legacyKey) : '{}')) || {};
    if (isFlight && !current) {
      stored = Object.fromEntries(Object.entries(stored).map(([slot,{name,color}])=>[slot,{A:name,B:color}]));
    }
  } catch {}
  const drafts = new Map(Array.from(draftBody?.rows || []).map(row=>{
    const slot = stored[row.dataset.rowNumber];
    const values = Object.fromEntries(Object.keys(fields).map(column=>[
      column,fields[column].type !== 'password' && typeof slot?.[column] === 'string' ? slot[column] : '',
    ]));
    const confirmed = new Set(Object.keys(fields).filter(column=>fields[column].allowEmpty && typeof slot?.[column] === 'string'));
    return [row,{values,confirmed,saving:false}];
  }));

  function storeDrafts() {
    const entries = [];
    for (const [row,{values,confirmed}] of drafts) {
      const publicValues = Object.entries(values).filter(([column,value])=>fields[column].type !== 'password' && (value || confirmed.has(column)));
      if (publicValues.length) entries.push([row.dataset.rowNumber,Object.fromEntries(publicValues)]);
    }
    try {
      sessionStorage.setItem(draftKey,JSON.stringify(Object.fromEntries(entries)));
      if (isFlight) sessionStorage.removeItem(legacyKey);
    } catch {}
  }

  function choice(column,value) {
    return fields[column].choices?.find(item=>(typeof item === 'string' ? item : item.value) === value);
  }

  function showDraft(row) {
    const {values} = drafts.get(row);
    for (const cell of row.querySelectorAll('td[data-edit-cell]')) {
      const column = cell.dataset.column;
      const value = values[column];
      const field = fields[column];
      if (!field) continue;
      const display = field.type === 'password' && value ? 'Введён' : value;
      cell.textContent = display;
      cell.toggleAttribute('data-draft-allow-empty',Boolean(field.allowEmpty));
      cell.title = display || (field.allowEmpty ? 'Заполните поле «'+cell.dataset.label+'» или подтвердите пустое значение.'
        : 'Щелчок — '+(field.type === 'choice' ? 'выбрать' : 'заполнить')+' поле «'+cell.dataset.label+'».');
      const fill = choice(column,value)?.color;
      if (fill) cell.style.setProperty('--order-status-fill',fill);
      else cell.style.removeProperty('--order-status-fill');
      window.VitrineDisplay?.paintCell(cell);
    }
    row.querySelector('.vitrine-draft-note').hidden = !Object.values(values).some(Boolean);
  }
  for (const row of drafts.keys()) showDraft(row);

  window.VitrineDirectoryDrafts = Object.freeze({
    secret(cell) {
      return drafts.get(cell.closest('[data-draft-slot]')).values[cell.dataset.column];
    },
    async write(cell,value) {
      const row = cell.closest('[data-draft-slot]');
      const draft = drafts.get(row);
      if (draft.saving) throw new Error('Дождитесь сохранения строки.');
      const column = cell.dataset.column;
      const field = fields[column];
      if (!field) throw new Error('Сначала добавьте запись в справочник.');
      if (field.type !== 'password') value = value.trim();
      else if (value && (Array.from(value).length < 8 || new TextEncoder().encode(value).length > 72)) {
        throw new Error('Пароль должен содержать от 8 символов и занимать до 72 байт.');
      }
      if (value && field.type === 'choice' && !choice(column,value)) throw new Error('Выберите значение из списка.');
      const maxLength = field.maxLength ?? 160;
      if (field.type === 'text' && (Array.from(value).length > maxLength || (field.singleLine !== false && /[\r\n]/.test(value)))) {
        throw new Error(field.singleLine === false
          ? `Введите не более ${maxLength} символов.`
          : `Введите текст одной строкой, до ${maxLength} символов.`);
      }
      draft.values[column] = value;
      if (field.allowEmpty) draft.confirmed.add(column);
      storeDrafts();
      showDraft(row);
      const result = {draft:true,value:cell.textContent,original:'',revision:0,baseHash:''};
      if (Object.keys(fields).some(key=>fields[key].required && !draft.values[key] && !draft.confirmed.has(key))) return result;
      draft.saving = true;
      try {
        const saved = await request('add',{values:draft.values});
        for (const key of Object.keys(fields)) draft.values[key] = '';
        draft.confirmed.clear();
        storeDrafts();
        return {...result,draftSaved:saved.id};
      } finally { draft.saving = false; }
    },
    async finish(cell) {
      showDraft(cell.closest('[data-draft-slot]'));
      notice('Запись добавлена.','success');
      try {
        await window.VitrineRows.refreshManifest();
        if (cell.classList.contains('is-current-cell') && !window.VitrineGrid.editing()) window.VitrineGrid.reveal(cell);
      } catch (error) { onError(error); }
    },
  });

  async function confirmRow(forcedAction) {
    const cell = trigger?.closest('tr').querySelector('td[data-edit-cell]');
    if (!cell || pending) return;
    const tr = cell.closest('tr');
    const action = forcedAction || actionFor(tr);
    const row = {row:Number(cell.dataset.row),revision:Number(cell.dataset.revision),baseHash:cell.dataset.base};
    const label = tr.dataset.directoryActionLabel || tr.querySelector('[data-column="A"]').textContent ||
      tr.querySelector('[data-column="B"]')?.textContent || '';
    closeMenu(true);
    pending = true;
    try {
      const result = action === 'delete' ? await request('check_delete',row) : {name:label};
      if (window.VitrineGrid.editing()) return;
      target = {action,row};
      const heading = {restore:'Восстановить запись',archive:'Архивировать запись',delete:'Удалить запись'}[action];
      deleteDialog.querySelector('#directory-delete-title').textContent = heading;
      deleteDialog.querySelector('[type="submit"]').textContent =
        {restore:'Восстановить',archive:'В архив',delete:'Удалить'}[action];
      deleteDialog.querySelector('[data-directory-delete-text]').textContent =
        {restore:'Восстановить',archive:'Перенести в архив',delete:'Удалить'}[action]+' «'+result.name+'»?';
      if (reason) {
        reasonField.hidden = reason.disabled = action !== 'delete';
        reason.required = action === 'delete';
        reason.value = '';
        confirm.disabled = !reason.disabled;
      }
      deleteDialog.showModal();
      if (reason && !reason.disabled) reason.focus();
    } catch (error) {
      onError(error);
    } finally {
      pending = false;
    }
  }
  archiveToggle?.addEventListener('click',()=>confirmRow());
  remove?.addEventListener('click',()=>confirmRow(archiveToggle ? 'delete' : null));
  reason?.addEventListener('input',()=>{confirm.disabled=!reason.value.trim();});
  if (deleteDialog) bindForm(deleteDialog, async fields => {
    const payload=reason && !reason.disabled ? {...target.row,reason:fields.get('reason').trim()} : target.row;
    await request(target.action,payload);
    refresh({restore:'Запись восстановлена.',archive:'Запись перенесена в архив.',delete:'Запись удалена.'}[target.action]);
  });
})();

'use strict';
(() => {
  let active = null;
  const catalog = document.body.dataset.vitrineCatalog;
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  const orderStatuses = JSON.parse(document.getElementById('vitrine-order-statuses')?.content || '{}');
  const orderLabels = Object.fromEntries(Object.entries(orderStatuses).map(([code,status])=>[status.label,code]));
  const normalizeContainer = value => value.replace(/[ \t\u00a0\u202f-]+/g,'').toUpperCase();
  function isContainerTypeCell(cell) {
    return cell.dataset.column === 'I' && Number(cell.dataset.catalogId) > 0 &&
      document.body.dataset.workspaceTable === 'vyvoz';
  }
  function hasDraft(editor) {
    return editor.field.value !== editor.initialValue
      || (editor.orderField && editor.orderField.value !== editor.initialOrder)
      || (editor.requestField && editor.requestField.checked !== editor.initialRequest);
  }
  function report(text, tone = 'warning') {
    window.PlanexNotifications.show({ tone, message:text });
  }
  function notifyDerivedCell() {
    report('Данные берутся из справочника. Эту ячейку нельзя редактировать.', 'info');
  }
  const loadError = document.querySelector('meta[name="vitrine-load-error"]')?.content;
  if (loadError) report(loadError,'danger');
  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function close() {
    if (!active || active.saving) return;
    const cell = active.cell;
    active.panel.remove();
    cell.classList.remove('is-editing');
    active = null;
    table?.classList.remove('has-editor');
    table?.classList.add('keyboard-navigation');
    cell.focus({ preventScroll:true });
    window.dispatchEvent(new CustomEvent('vitrine-editor-closed'));
  }
  function position() {
    if (!active) return;
    const rect = active.cell.getBoundingClientRect();
    const panel = active.panel;
    if (active.inline) {
      Object.assign(panel.style,{left:rect.left+'px',top:rect.top+'px',width:rect.width+'px',height:rect.height+'px'});
      return;
    }
    const width = panel.offsetWidth;
    const height = panel.offsetHeight;
    panel.style.left = Math.max(10, Math.min(rect.left, window.innerWidth - width - 10)) + 'px';
    let top = rect.bottom + 4;
    if (top + height > window.innerHeight - 10) top = Math.max(10, rect.top - height - 4);
    panel.style.top = Math.max(10, Math.min(top, window.innerHeight - height - 10)) + 'px';
  }
  function fitEditor() {
    if (!active) return;
    const {panel,field} = active;
    if (field.tagName === 'TEXTAREA') {
      const measure = element('span');
      measure.style.cssText = 'position:fixed;visibility:hidden;white-space:pre;pointer-events:none';
      measure.style.font = getComputedStyle(field).font;
      measure.textContent = field.value;
      document.body.append(measure);
      const desiredWidth = Math.max(380,measure.getBoundingClientRect().width + 50);
      measure.remove();
      panel.style.width = Math.min(desiredWidth,900,window.innerWidth - 20) + 'px';
      field.style.height = 'auto';
      field.style.maxHeight = 'none';
      const chromeHeight = panel.offsetHeight - field.offsetHeight;
      const availableHeight = Math.max(60,window.innerHeight - chromeHeight - 20);
      field.style.minHeight = Math.min(94,availableHeight) + 'px';
      field.style.height = Math.min(field.scrollHeight + 2,availableHeight) + 'px';
    }
    position();
  }
  const pendingCells = new Set();
  const pasteUndo = [];
  let undoing = false;
  function snapshot(cell) {
    return {cell, value:cell.textContent, edited:cell.classList.contains('local-edited'), beforeRevision:Number(cell.dataset.revision), beforeHash:cell.dataset.base};
  }
  function rememberPaste(before, result) {
    pasteUndo.push({...before, revision:result.revision, baseHash:result.baseHash});
  }
  async function undoPaste() {
    if (undoing || pendingCells.size) { report('Дождитесь сохранения ячейки.'); return; }
    const entry = pasteUndo.at(-1);
    if (!entry) return;
    const cell = window.VitrineRows?.resolveCell(entry.cell) || entry.cell;
    if (Number(cell.dataset.revision) !== entry.revision || (catalog === 'employees' && cell.dataset.base !== entry.baseHash)) {
      report('Ячейка изменилась после вставки. Отмена не выполнена.', 'danger'); return;
    }
    undoing = true; pendingCells.add(cell); cell.setAttribute('aria-busy','true');
    try {
      const result = await writeCell(cell,entry.value,!entry.edited,null,'undo');
      applyResult(cell,result); pasteUndo.pop();
      const previous = pasteUndo.findLast(item => item.cell === cell);
      if (previous && previous.revision === entry.beforeRevision &&
          (catalog !== 'employees' || previous.baseHash === entry.beforeHash)) {
        previous.revision = result.revision;
        previous.baseHash = result.baseHash;
      }
      const shown=window.VitrineRows&&!cell.isConnected?await window.VitrineRows.activate(cell):cell;
      if(shown){flash(shown);table?.classList.add('keyboard-navigation');select(shown);shown.focus({preventScroll:true});reveal(shown);}
    } catch(error) {
      report(error instanceof TypeError ? 'Нет связи с сервером. Отмена не выполнена.' : error.message,'danger');
    } finally { undoing = false; pendingCells.delete(cell); cell.removeAttribute('aria-busy'); }
  }
  function applyResult(cell, result) {
    if (Number(cell.dataset.revision || 0) > result.revision) return;
    const slotRequested = cell.dataset.kind === 'slot' && window.VitrineSlot.isRequest(result.value);
    cell.toggleAttribute('data-slot-request',slotRequested);
    const weightMask = cell.dataset.kind === 'weight' ? window.VitrineCompactInput.weight : null;
    const weightDigits = weightMask?.read(result.value);
    cell.textContent = slotRequested ? window.VitrineSlot.requestLabel
      : weightDigits ? weightMask.format(weightDigits) : result.value;
    cell.dataset.original = result.original ?? '';
    cell.dataset.revision = String(result.revision);
    if (catalog) for (const sibling of cell.parentElement.querySelectorAll('td[data-edit-cell]')) {
      sibling.dataset.revision = String(result.revision);
      if (catalog === 'employees') sibling.dataset.base = result.baseHash;
    }
    if(Object.hasOwn(result,'referenceId'))cell.dataset.referenceId=String(result.referenceId||'');
    if(Object.hasOwn(result,'catalogId'))cell.dataset.catalogId=String(result.catalogId||'');
    cell.dataset.base = result.baseHash;
    if (Object.hasOwn(result,'orderStatus')) {
      cell.dataset.orderStatus = result.orderStatus;
      cell.style.setProperty('--order-status-fill',orderStatuses[result.orderStatus]?.color || '');
    }
    if (Object.hasOwn(result,'catalogFill')) {
      if (result.catalogFill) cell.style.setProperty('--catalog-fill',result.catalogFill);
      else cell.style.removeProperty('--catalog-fill');
    }
    if (result.orderFill) cell.style.setProperty('--order-status-fill',result.orderFill);
    if (cell.dataset.kind === 'status') {
      cell.dataset.statusId = result.statusId ?? '';
      cell.dataset.statusColor = result.statusColor ?? '';
      cell.classList.toggle('has-status-color', Boolean(result.statusColor));
      if (result.statusColor) cell.style.setProperty('--status-fill',result.statusColor);
      else cell.style.removeProperty('--status-fill');
    }
    cell.classList.toggle('local-edited', Boolean(result.edited));
    cell.classList.toggle('conflict', Boolean(result.conflict));
    window.VitrineDisplay?.paintCell(cell);
    if (cell.dataset.derivedSource) {
      cell.title = cell.dataset.derivedSource + '. ' + (result.value || cell.dataset.emptyLabel + '.');
      if (result.value) cell.removeAttribute('aria-label');
      else cell.setAttribute('aria-label', cell.dataset.emptyLabel);
      return;
    }
    cell.title = (result.edited ? 'Изменено в БД. ' : '') + 'Щелчок — изменить. ' + result.value;
    if (cell.dataset.orderStatus) cell.title += ' · ' + (orderStatuses[cell.dataset.orderStatus]?.label || '');
    if (cell.dataset.kind === 'time' && !cell.dataset.compactInput) cell.title = 'Наберите 800 или 0800 → 08:00. Enter — сохранить, Esc — отменить, F2 — редактор. ' + result.value;
  }
  async function writeCell(cell, value, reset = false, paste = null, editAction = null, orderStatus = undefined) {
    if (cell.closest('[data-draft-slot]')) return window.VitrineDirectoryDrafts.write(cell,value);
    const response = await fetch(window.VitrineGridScope.url('/api/vitrine/cell'), {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({row:Number(cell.dataset.row),column:cell.dataset.column,value,
        revision:Number(cell.dataset.revision || 0),baseHash:cell.dataset.base,reset,requestId:crypto.randomUUID(),editAction:editAction||(reset?'reset':paste?'paste':'edit'),...(paste ? {paste} : {}),orderStatus})
    });
    const result = await response.json();
    if (!response.ok || result.error) throw new Error(result.error?.message || 'Не удалось сохранить ячейку.');
    if (typeof result.value !== 'string' || !Number.isInteger(result.revision) || typeof result.baseHash !== 'string') throw new Error('Неполный ответ сервера. Обновите страницу.');
    if (result.dependentCell) window.VitrineRows?.patch(result.dependentCell);
    if (result.reauthenticate) location.assign(window.DltkPaths?.path('/login') ?? '/login');
    return result;
  }
  function flash(cell, remote = false) {
    cell.classList.remove('just-saved','just-remote'); void cell.offsetWidth;
    cell.classList.add(remote ? 'just-remote' : 'just-saved');
  }
  function applyRemote(cell, result) {
    const changed = Number(result.revision) > Number(cell.dataset.revision || 0);
    const valueChanged = cell.textContent !== result.value;
    applyResult(cell,result);
    if (!changed || !cell.isConnected) return;
    if (cell.dataset.derivedSource) {
      if (valueChanged) flash(cell);
      return;
    }
    const actorId = Number(result.actorId);
    if (Number.isSafeInteger(actorId) && actorId > 0 && actorId !== Number(document.body.dataset.workspaceUser)) {
      flash(cell,true);
      window.dispatchEvent(new CustomEvent('vitrine-remote-change',{detail:{...result,cell}}));
    }
  }
  function editTime(cell, firstDigit = null) {
    if (active || pendingCells.has(cell)) return;
    select(cell);
    const panel = element('div','vitrine-time-inline');
    const field = element('input','field-input vitrine-time-inline-field');
    field.type = 'text'; field.inputMode = 'numeric'; field.autocomplete = 'off'; field.maxLength = 8;
    field.setAttribute('aria-label','Время');
    field.placeholder = '08:00';
    field.value = firstDigit === null ? cell.textContent : firstDigit;
    const error = element('p'); error.hidden = true;
    const saveButton = element('button');
    panel.append(field); document.body.append(panel);
    cell.classList.add('is-editing'); table?.classList.add('has-editor');
    active = {panel,cell,field,initialValue:cell.textContent,buttons:[],saveButton,error,saving:false,inline:true};
    window.dispatchEvent(new CustomEvent('vitrine-editor-opened',{detail:{row:Number(cell.dataset.row),column:cell.dataset.column}}));
    panel.addEventListener('keydown',event => {
      if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
      if (event.key === 'Enter' && !event.isComposing) { event.preventDefault(); save(); }
      if (event.key === 'Tab') event.preventDefault();
    });
    position(); field.focus(); field.setSelectionRange(field.value.length,field.value.length);
  }
  async function save(reset = false) {
    if (!active || active.saving) return;
    const editor = active;
    if (!reset && editor.cell.dataset.compactInput && !hasDraft(editor)) {
      close();
      return;
    }
    const value = editor.requestField?.checked ? window.VitrineSlot.requestLabel
      : editor.resolveValue ? editor.resolveValue() : editor.field.value;
    if (!reset && value === null) {
      report(isContainerTypeCell(editor.cell)
        ? 'Выберите тип контейнера из подсказок.' : 'Выберите клиента из подсказок.','danger');
      editor.field.focus();
      return;
    }
    editor.saving = true;
    editor.error.hidden = true;
    editor.buttons.forEach(button => { button.disabled = true; });
    editor.field.disabled = true;
    if (editor.orderField) editor.orderField.disabled = true;
    if (editor.requestField) editor.requestField.disabled = true;
    editor.saveButton.textContent = 'Сохраняю…';
    editor.panel.setAttribute('aria-busy', 'true');
    const cell = editor.cell;
    const before = snapshot(cell);
    try {
      const result = await writeCell(cell, value, reset, reset ? null : editor.paste,null,!reset && editor.orderField ? (orderLabels[editor.orderField.value] || '') : undefined);
      applyResult(cell,result);
      if (!reset && editor.paste && !result.draft) rememberPaste(before,result);
      editor.saving = false;
      close();
      if (result.draftSaved) {
        await window.VitrineDirectoryDrafts.finish(cell);
        return;
      }
      if (!result.draft) flash(cell);
      if (!result.draft && (reset || !editor.paste)) report(reset ? 'Исходное значение восстановлено' : 'Локальная правка сохранена', 'success');
    } catch (error) {
      editor.saving = false;
      editor.buttons.forEach(button => { button.disabled = false; });
      editor.field.disabled = false;
      if (editor.orderField) editor.orderField.disabled = false;
      if (editor.requestField) editor.requestField.disabled = false;
      editor.saveButton.textContent = 'Сохранить';
      editor.panel.removeAttribute('aria-busy');
      editor.error.textContent = error instanceof TypeError ? 'Нет связи с сервером. Текст сохранён в поле — повторите попытку.' : error.message;
      report(editor.error.textContent, 'danger');
      position();
      editor.field.focus();
    }
  }
  function open(cell, firstCharacter = null) {
    if (cell.hasAttribute('data-readonly-cell')) {
      report(cell.dataset.readonlyCell, 'info');
      return;
    }
    if (cell.dataset.derivedSource) {
      notifyDerivedCell();
      return;
    }
    if (pendingCells.has(cell)) { report('Дождитесь сохранения ячейки.'); return; }
    if (active) {
      if (active.cell === cell) { active.field.focus(); return; }
      report('Сначала сохраните правку или нажмите «Отмена»');
      active.field.focus();
      return;
    }
    document.querySelectorAll('.vitrine-folder[open]').forEach(folder => { folder.open = false; });
    const panel = element('section', 'vitrine-editor');
    panel.setAttribute('role', 'dialog');
    const isStatus = cell.dataset.kind === 'status';
    const isSlot = cell.dataset.kind === 'slot';
    const mask = isSlot ? window.VitrineSlot : window.VitrineCompactInput[cell.dataset.compactInput];
    const isContainer = cell.dataset.kind === 'container';
    const isSingleLine = isContainer || cell.hasAttribute('data-single-line');
    const pickupEditor = cell.dataset.column === 'E' && Number(cell.dataset.catalogId) > 0 &&
      document.body.dataset.workspaceTable === 'vyvoz';
    const catalogEditor = pickupEditor || (['B', 'I', 'S'].includes(cell.dataset.column) && document.body.dataset.workspaceTable === 'vyvoz');
    const dialogName = cell.dataset.column + cell.dataset.row + (cell.dataset.label ? ' · ' + cell.dataset.label : '');
    if (mask || isSingleLine || isStatus || catalogEditor || catalog) {
      panel.setAttribute('aria-label', dialogName);
    } else {
      const title = element('h2', 'vitrine-editor-title', dialogName);
      title.id = 'vitrine-editor-title';
      panel.setAttribute('aria-labelledby', title.id);
      panel.append(title, element('p', 'vitrine-editor-note', 'Изменения сохраняются в БД'));
    }

    let choices = [];
    try { choices = JSON.parse(cell.dataset.choices || '[]'); } catch {}
    if (!Array.isArray(choices)) choices = [];
    const useCustom = !mask && (catalog || !isSingleLine) && (isStatus || choices.length > 0 || Number(cell.dataset.catalogId) > 0);
    const typeEditor = isContainerTypeCell(cell);
    const searchableCatalog = pickupEditor || typeEditor || cell.hasAttribute('data-order-status');
    let control = null;
    if (useCustom) {
      const required = cell.hasAttribute('data-required-choice');
      const options = required ? [] : [{value:'',label:'Не выбран'}];
      const items = isStatus ? (window.vitrineStatusCatalog?.statuses || []).map(status => ({value:status.name,label:status.name,color:status.color})) : choices.map(option => option && typeof option === 'object' ? option : {value:String(option),label:String(option)});
      if (!required && !typeEditor && cell.textContent && !items.some(item => item.value === cell.textContent)) options.push({value:cell.textContent,label:cell.textContent + ' · вне справочника'});
      options.push(...items.filter(item => item.value !== ''));
      control = window.VitrineSelect.create(options,cell.textContent,{searchable:searchableCatalog});
    }
    const field = control?.field || element(mask || isSingleLine ? 'input' : 'textarea', 'field-input');
    field.classList.add('vitrine-editor-field');
    field.id = 'vitrine-editor-field';
    field.setAttribute('aria-label', 'Значение ячейки');
    const draftType = cell.dataset.draftType;
    let initialValue = draftType === 'password' ? window.VitrineDirectoryDrafts.secret(cell) : cell.textContent;
    let replaceLegacyValue = false;
    if (mask) {
      const digits = mask.read(initialValue);
      replaceLegacyValue = !isSlot && initialValue !== '' && digits === '';
      if (!replaceLegacyValue) initialValue = mask.format(digits);
      mask.bind(field);
    }
    field.autocomplete = draftType === 'password' ? 'new-password' : 'off';
    if (draftType === 'password') field.type = 'password';
    if (draftType === 'email') field.inputMode = 'email';
    if (cell.hasAttribute('data-draft-allow-empty')) field.placeholder = 'Можно оставить пустым';
    field.maxLength = draftType === 'email' ? 254 : draftType === 'password' ? 72 : isSingleLine && catalog ? 160 : 20000;
    field.value = mask && firstCharacter !== null ? mask.format(firstCharacter) : firstCharacter ?? initialValue;
    if (isContainer) {
      const prefix = /^(?:[A-Z]{0,3}|[A-Z]{3}U[0-9]{0,7})$/;
      const stored = normalizeContainer(initialValue);
      replaceLegacyValue = initialValue !== '' && !prefix.test(stored);
      if (!replaceLegacyValue) initialValue = stored;
      field.value = firstCharacter?.toUpperCase() ?? initialValue;
      field.placeholder = 'BICU6658782';
      field.spellcheck = false;
      field.addEventListener('beforeinput',event=>{
        if (event.inputType !== 'insertText') return;
        const typed = event.data ?? '';
        const start = field.selectionStart;
        const end = field.selectionEnd;
        const before = field.value.slice(0,start);
        const after = field.value.slice(end);
        const addCategory = /^[A-Z]{3}$/.test(before) && /^[0-9]+$/.test(typed);
        const inserted = addCategory ? 'U'+typed : typed;
        if (!/^[A-Za-z0-9]+$/.test(typed) || !prefix.test((before+inserted+after).toUpperCase())) {
          event.preventDefault();
          return;
        }
        if (addCategory) {
          event.preventDefault();
          field.setRangeText(inserted,start,end,'end');
          field.dispatchEvent(new InputEvent('input',{inputType:'insertText',bubbles:true}));
        }
      });
      field.addEventListener('input',()=>{
        const position = field.selectionStart;
        field.value = field.value.toUpperCase();
        field.setSelectionRange(position,position);
      });
    }
    if (useCustom) field.dataset.customSelect = 'true';
    let orderField = null;
    let requestField = null;
    const initialRequest = isSlot && window.VitrineSlot.isRequest(cell.textContent);
    if (cell.hasAttribute('data-order-status')) {
      field.setAttribute('aria-label','Клиент');
      const clientGroup = element('div','vitrine-editor-field-group');
      clientGroup.append(element('div','field-label','Клиент'),control?.element || field);
      panel.append(clientGroup);
      const options = [{value:'',label:'Без статуса'},...Object.values(orderStatuses).map(status=>({
        value:status.label,label:status.label,color:status.color
      }))];
      const picker = window.VitrineSelect.create(options,orderStatuses[cell.dataset.orderStatus]?.label || '');
      orderField = picker.field;
      orderField.setAttribute('aria-label','Статус заказа');
      orderField.classList.add('vitrine-editor-field');
      const statusGroup = element('div','vitrine-editor-field-group');
      statusGroup.append(element('div','field-label','Статус заказа'),picker.element);
      panel.append(statusGroup);
    } else if (isSlot) {
      const group = element('div','vitrine-editor-field-group');
      const label = element('label','checkbox-item');
      requestField = element('input');
      requestField.type = 'checkbox';
      requestField.checked = initialRequest && firstCharacter === null;
      const mark = element('span','checkbox-mark');
      mark.setAttribute('aria-hidden','true');
      label.append(requestField,mark,element('span','checkbox-label','Запросить слот'));
      group.append(field,label);
      panel.append(group);
      field.addEventListener('input',event=>{
        if (field.value && event.inputType?.startsWith('insert')) requestField.checked = false;
      });
    } else panel.append(control?.element || field);
    if (!mask && !isSingleLine && !isStatus && !catalog && !orderField) {
      panel.append(element('p', 'vitrine-editor-help', useCustom ? 'Стрелки — выбор · Enter — подтвердить · Esc — отменить' : 'Enter — сохранить · Esc — отменить'));
    }
    const error = element('p', 'vitrine-editor-error');
    error.setAttribute('role', 'alert'); error.hidden = true; panel.append(error);
    const actions = element('div', 'vitrine-editor-actions');
    const saveButton = element('button', 'btn btn-primary', 'Сохранить');
    const cancelButton = element('button', 'btn btn-secondary', 'Отмена');
    const buttons = [saveButton, cancelButton];
    saveButton.type = cancelButton.type = 'button';
    saveButton.addEventListener('click', () => save());
    cancelButton.addEventListener('click', close);
    actions.append(saveButton, cancelButton);
    if (!isStatus && !orderField && cell.classList.contains('local-edited')) {
      const resetButton = element('button', 'btn btn-secondary vitrine-editor-reset', 'Исходное');
      resetButton.type = 'button'; resetButton.addEventListener('click', () => save(true));
      buttons.push(resetButton); actions.append(resetButton);
    }
    panel.append(actions);
    document.body.append(panel);
    cell.classList.add('is-editing');
    select(cell);
    active = { panel, cell, field, initialValue, orderField, initialOrder:orderField?.value, requestField, initialRequest, resolveValue:control?.resolveValue, buttons, saveButton, error, saving:false };
    window.dispatchEvent(new CustomEvent('vitrine-editor-opened',{detail:{row:Number(cell.dataset.row),column:cell.dataset.column}}));
    table?.classList.add('has-editor');
    panel.addEventListener('keydown', event => {
      const directions = {ArrowLeft:[0,-1],ArrowRight:[0,1],ArrowUp:[-1,0],ArrowDown:[1,0]};
      if (!directions[event.key] || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey || event.isComposing || active?.saving) return;
      if (field.dataset.customSelect && (field.getAttribute('aria-expanded') === 'true' || event.key === 'ArrowDown' || event.key === 'ArrowUp')) return;
      if (mask || orderField || isSingleLine || hasDraft(active)) return;
      event.preventDefault(); event.stopImmediatePropagation();
      close(); move(cell,...directions[event.key],false);
    }, true);
    panel.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
      if (event.key === 'Enter' && (event.target === field || event.target === orderField || event.target === requestField) && !event.shiftKey && !event.isComposing) { event.preventDefault(); save(); }
      if (event.key === 'Tab') {
        const focusable = [field, orderField, requestField, ...buttons].filter(node => node && !node.disabled);
        const first = focusable[0], last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });
    field.addEventListener('input',fitEditor);
    fitEditor();
    field.focus();
    if (firstCharacter === null && (searchableCatalog || replaceLegacyValue)) field.select();
    else field.setSelectionRange(field.value.length, field.value.length);
    if (typeEditor && firstCharacter !== null) {
      field.dispatchEvent(new InputEvent('input',{inputType:'insertText',bubbles:true}));
    }
  }
  window.addEventListener('vitrine-statuses-updated', event => {
    const statuses = event.detail.statuses;
    const key = text => text.trim().replace(/\s+/g,' ').toLocaleLowerCase('ru');
    document.querySelectorAll('td[data-column="A"]').forEach(cell => {
      let status = cell.classList.contains('local-edited') ? statuses.find(item => String(item.id) === cell.dataset.statusId) : null;
      if (status) cell.textContent = status.name;
      else status = statuses.find(item => key(item.name) === key(cell.textContent));
      cell.dataset.statusId = status?.id ?? '';
      cell.dataset.statusColor = status?.color ?? '';
      cell.classList.toggle('has-status-color', Boolean(status));
      if (status) cell.style.setProperty('--status-fill',status.color); else cell.style.removeProperty('--status-fill');
      cell.dataset.choices = JSON.stringify(statuses.map(item => item.name));
      window.VitrineDisplay?.paintCell(cell);
    });
  });
  const table = document.querySelector('.vitrine-table');
  table?.addEventListener('pointermove', event => {
    if (event.pointerType === 'touch' || (!event.movementX && !event.movementY) || active) return;
    const cell = event.target.closest('td[data-edit-cell]');
    if (table.classList.contains('keyboard-navigation')) table.classList.remove('keyboard-navigation');
    if (cell !== selected) select(cell);
  });
  table?.addEventListener('mousedown', event => {
    table.classList.remove('keyboard-navigation');
    const cell = event.target.closest('td[data-edit-cell]');
    if (event.button !== 0 || !cell) return;
    event.preventDefault();
    if (!active) cell.focus({ preventScroll:true });
  });
  table?.addEventListener('selectstart', event => event.preventDefault());
  table?.addEventListener('dragstart', event => event.preventDefault());
  table?.addEventListener('animationend', event => {
    if (event.animationName === 'vitrine-cell-saved') event.target.classList.remove('just-saved','just-remote');
  });
  document.addEventListener('click', event => {
    if (!active || active.panel.contains(event.target) || active.cell.contains(event.target)) return;
    if (!active.saving && !hasDraft(active)) {
      close();
      return;
    }
    event.preventDefault();
    event.stopImmediatePropagation();
    if (event.target.closest('td[data-derived-source]')) notifyDerivedCell();
    else report(active.saving ? 'Дождитесь сохранения ячейки.' : 'Сначала сохраните правку или нажмите «Отмена»');
    (active.field.disabled && active.requestField ? active.requestField : active.field).focus();
  }, true);
  document.addEventListener('click', event => {
    const cell = event.target.closest('td[data-edit-cell]');
    if (!cell) return;
    if (cell.dataset.kind === 'time' || cell.dataset.derivedSource || cell.hasAttribute('data-readonly-cell')) {
      select(cell);
      cell.focus({preventScroll:true});
      if (cell.dataset.derivedSource) notifyDerivedCell();
    } else open(cell);
  });
  let cells = Array.from(document.querySelectorAll('td[data-edit-cell]'));
  const visibleRows = () => Array.from(document.querySelectorAll('[data-vitrine-row]')).map(row => Array.from(row.querySelectorAll('td[data-edit-cell]:not([hidden])')));
  let rowCells = visibleRows();
  window.addEventListener('vitrine-columns-updated', () => {
    rowCells = visibleRows();
    if(selected?.hidden) select(Array.from(selected.parentElement.querySelectorAll('td[data-edit-cell]')).find(cell=>!cell.hidden));
  });
  let selected = cells.find(cell=>!cell.hidden) || null;
  table?.setAttribute('role','grid');
  cells.forEach(cell => { cell.tabIndex = cell === selected ? 0 : -1; });
  function select(cell) {
    if (!cell) return;
    if (selected === cell && cell.classList.contains('is-current-cell')) return;
    const previousRow = selected?.parentElement;
    const nextRow = cell.parentElement;
    if (selected && selected !== cell) {
      selected.tabIndex = -1; selected.setAttribute('aria-selected','false');
      selected.classList.remove('is-current-cell');
      if (previousRow !== nextRow) previousRow?.classList.remove('is-current-row');
    }
    selected = cell; cell.tabIndex = 0; cell.setAttribute('aria-selected','true');
    cell.classList.add('is-current-cell');
    if (previousRow !== nextRow || !nextRow.classList.contains('is-current-row')) nextRow.classList.add('is-current-row');
    window.dispatchEvent(new CustomEvent('vitrine-cell-selected'));
  }
  function eventCell(event) {
    if (event.target.closest('.vitrine-derived-icon')) return null;
    return event.target === document.body || event.target.closest('.vitrine-table-scroll') ? selected : null;
  }
  select(selected);
  window.addEventListener('vitrine-rows-updated',()=>{
    cells=Array.from(document.querySelectorAll('td[data-edit-cell]'));rowCells=visibleRows();
    const replacement=selected?cells.find(cell=>cell.dataset.row===selected.dataset.row&&cell.dataset.column===selected.dataset.column):cells.find(cell=>!cell.hidden);
    if(replacement)select(replacement);
  });
  table?.addEventListener('focusin', event => { const cell = event.target.closest('td[data-edit-cell]'); if (cell) select(cell); });
  function reveal(cell) {
    cell.scrollIntoView({block:'nearest',inline:'nearest'});
    const scroll = cell.closest('.vitrine-table-scroll');
    if (!scroll) return;
    if (cell === cell.parentElement.querySelector('td[data-edit-cell]:not([hidden])')) scroll.scrollLeft = 0;
    const rect = cell.getBoundingClientRect();
    const rowHeader = cell.parentElement.querySelector('.vitrine-row-number');
    const header = table.querySelector('thead');
    const left = rowHeader?.getBoundingClientRect().right;
    const top = header?.getBoundingClientRect().bottom;
    if (left !== undefined && rect.left < left) scroll.scrollLeft -= left - rect.left;
    if (top !== undefined && rect.top < top) scroll.scrollTop -= top - rect.top;
  }
  function move(cell, rowOffset, columnOffset, wrap = true) {
    table?.classList.add('keyboard-navigation');
    if(window.VitrineRows)return window.VitrineRows.move(cell,rowOffset,columnOffset,wrap);
    const rowIndex = rowCells.findIndex(row => row.includes(cell));
    if (rowIndex < 0) return false;
    let r = rowIndex + rowOffset, c = rowCells[rowIndex].indexOf(cell) + columnOffset;
    if (!wrap && (c < 0 || c >= rowCells[rowIndex].length)) { reveal(cell); return false; }
    if (columnOffset && c < 0) { r--; c = rowCells[0].length - 1; }
    if (columnOffset && c >= rowCells[0].length) { r++; c = 0; }
    const next = rowCells[r]?.[c];
    if (!next) return false;
    select(next); next.focus({preventScroll:true}); reveal(next); return true;
  }
  document.addEventListener('keydown', event => {
    if (event.key === 'F6' && !document.querySelector('dialog[open]')) {
      event.preventDefault();
      table?.classList.add('keyboard-navigation');
      if (active) { (active.field.disabled && active.requestField ? active.requestField : active.field).focus(); return; }
      if(window.VitrineRows&&selected){window.VitrineRows.activate(selected);return;}
      selected?.focus({preventScroll:true}); if (selected) reveal(selected); return;
    }
    if (active) return;
    if ((event.ctrlKey || event.metaKey) && !event.shiftKey && !event.altKey && event.code === 'KeyZ' && !event.target.closest('input, textarea, [contenteditable="true"], dialog')) {
      event.preventDefault(); undoPaste(); return;
    }
    const cell = eventCell(event);
    if (!cell) return;
    const typedText = cell.dataset.kind === 'text' && cell.hasAttribute('data-single-line') && /^\S$/u.test(event.key);
    const typedContainer = cell.dataset.kind === 'container' && /^[a-z]$/i.test(event.key);
    const typedType = isContainerTypeCell(cell) && /^[\p{L}0-9]$/u.test(event.key);
    if ((typedType || ((typedText || typedContainer) && cell.dataset.choices === '[]'))
      && !cell.hasAttribute('data-required-choice')
      && !cell.hasAttribute('data-readonly-cell') && !event.defaultPrevented && !event.isComposing
      && (!event.ctrlKey && !event.metaKey && !event.altKey || event.getModifierState('AltGraph'))) {
      event.preventDefault();
      const startTyping = target => open(target,event.key);
      if (window.VitrineRows && !cell.isConnected) window.VitrineRows.activate(cell,startTyping);
      else startTyping(cell);
      return;
    }
    if ((cell.dataset.kind === 'slot' || window.VitrineCompactInput[cell.dataset.compactInput]) && /^[0-9]$/.test(event.key) && !cell.hasAttribute('data-readonly-cell')
      && !event.ctrlKey && !event.metaKey && !event.altKey && !event.isComposing) {
      event.preventDefault();
      const start = target=>open(target,event.key);
      if (window.VitrineRows && !cell.isConnected) window.VitrineRows.activate(cell,start);
      else start(cell);
      return;
    }
    if (cell.dataset.kind === 'time' && /^[0-9]$/.test(event.key) && !event.ctrlKey && !event.metaKey && !event.altKey) {
      event.preventDefault(); if(window.VitrineRows&&!cell.isConnected)window.VitrineRows.activate(cell,next=>editTime(next,event.key));else editTime(cell,event.key); return;
    }
    const arrows = {ArrowLeft:[0,-1],ArrowRight:[0,1],ArrowUp:[-1,0],ArrowDown:[1,0]};
    if (arrows[event.key] && !event.ctrlKey && !event.metaKey) { event.preventDefault(); move(cell,...arrows[event.key],false); return; }
    if (event.key === 'Tab' && move(cell,0,event.shiftKey ? -1 : 1)) { event.preventDefault(); return; }
    if (event.key === 'Home' || event.key === 'End') {
      table?.classList.add('keyboard-navigation');
      if(window.VitrineRows){event.preventDefault();window.VitrineRows.edge(cell,event.key==='End',event.ctrlKey);return;}
      event.preventDefault(); const r = rowCells.findIndex(row => row.includes(cell));
      const targetRow = event.ctrlKey ? (event.key === 'Home' ? 0 : rowCells.length-1) : r;
      const next = rowCells[targetRow][event.key === 'Home' ? 0 : rowCells[targetRow].length-1];
      select(next); next.focus({preventScroll:true}); reveal(next); return;
    }
    if (['Enter','F2'].includes(event.key)) { event.preventDefault(); const action=next=>{if(event.key==='Enter'&&next.dataset.kind==='time'&&!next.dataset.compactInput)editTime(next);else open(next);};if(window.VitrineRows&&!cell.isConnected)window.VitrineRows.activate(cell,action);else action(cell); }
  });
  let copied = null;
  document.addEventListener('copy', event => {
    const cell = eventCell(event);
    if (active || !cell || !event.clipboardData) return;
    copied = cell.closest('[data-draft-slot]') ? null : {row:Number(cell.dataset.row),column:cell.dataset.column,revision:Number(cell.dataset.revision),baseHash:cell.dataset.base,value:cell.textContent};
    event.preventDefault(); event.clipboardData.setData('text/plain',cell.textContent);
    if (copied) event.clipboardData.setData('application/x-dltk-cell',JSON.stringify(copied));
  });
  document.addEventListener('paste', async event => {
    let cell = eventCell(event);
    const inEditor = active && event.target === active.field;
    if ((!cell && !inEditor) || !event.clipboardData) return;
    if (inEditor && (active.cell.dataset.kind === 'slot' || window.VitrineCompactInput[active.cell.dataset.compactInput] || active.field.getAttribute('aria-autocomplete') === 'list'
      || active.cell.closest('[data-draft-slot]'))) return;
    event.preventDefault();
    if(cell&&!cell.isConnected&&window.VitrineRows){cell=await window.VitrineRows.activate(cell);if(!cell)return;}
    const target = cell || active.cell;
    if (!inEditor && target.dataset.draftType === 'password') {
      report('Откройте поле пароля для вставки.','info');
      return;
    }
    if (target.dataset.derivedSource) { notifyDerivedCell(); return; }
    if (target.hasAttribute('data-readonly-cell')) { report(target.dataset.readonlyCell, 'info'); return; }
    if (pendingCells.has(target) || active?.saving) { report('Дождитесь сохранения ячейки.'); return; }
    const value = event.clipboardData.getData('text/plain');
    let source = null;
    try { source = JSON.parse(event.clipboardData.getData('application/x-dltk-cell') || 'null'); } catch {}
    if (!source && copied?.value === value) source = copied;
    if (value.length > 20000 || value.includes('\t')) { report('Вставляйте одну ячейку размером до 20 000 символов.','danger'); return; }
    const paste = {source};
    if (inEditor) {
      const editor = active;
      try {
        const response = await fetch(window.VitrineGridScope.url('/api/vitrine/paste-check'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({row:Number(target.dataset.row),column:target.dataset.column,value,paste})});
        const result = await response.json(); if (!response.ok) throw new Error(result.error?.message || 'Не удалось проверить формат.');
        if (active !== editor) return;
        if (target.dataset.kind === 'container') active.field.value = normalizeContainer(value);
        else if (active.field.dataset.customSelect) active.field.value = value;
        else active.field.setRangeText(value,active.field.selectionStart,active.field.selectionEnd,'end');
        active.paste = paste;
        fitEditor();
      } catch(error) { report(error.message || 'Не удалось проверить вставку.','danger'); }
      return;
    }
    if (active) { report('Сначала сохраните правку или нажмите «Отмена»'); return; }
    pendingCells.add(target); target.setAttribute('aria-busy','true');
    const before = snapshot(target);
    try {
      const result = await writeCell(target,value,false,paste);
      applyResult(target,result);
      if (result.draftSaved) await window.VitrineDirectoryDrafts.finish(target);
      if (!result.draft) { flash(target); rememberPaste(before,result); }
    } catch(error) { report(error instanceof TypeError ? 'Нет связи с сервером. Вставка не выполнена.' : error.message,'danger'); }
    finally { pendingCells.delete(target); target.removeAttribute('aria-busy'); target.focus({preventScroll:true}); }
  });
  document.querySelector('.vitrine-table-scroll')?.addEventListener('scroll', position);
  window.addEventListener('resize', fitEditor);
  window.VitrineGrid=Object.freeze({select,reveal,editing:()=>Boolean(active),editingCell:()=>active?.cell||null,applyRemote});
})();

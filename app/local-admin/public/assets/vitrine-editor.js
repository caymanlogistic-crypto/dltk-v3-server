'use strict';
(() => {
  let active = null;
  let clearTarget = null;
  const clearDialog = document.querySelector('[data-clear-cell-dialog]');
  const catalog = document.body.dataset.vitrineCatalog;
  const isTripGrid = !catalog && document.body.dataset.workspaceTable==='vyvoz';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  const orderStatuses = JSON.parse(document.getElementById('vitrine-order-statuses')?.content || '{}');
  const fieldColorLists = JSON.parse(document.getElementById('vitrine-field-color-statuses')?.content || '{}');
  function fieldColorStatus(cell) {
    return Object.hasOwn(fieldColorLists,cell.dataset.column)
      ? JSON.parse(cell.parentElement.dataset.fieldColorStatuses || '{}')[cell.dataset.column] || ''
      : undefined;
  }
  function repaintFieldColor(cell,fill) {
    if (cell.style.getPropertyValue('--order-status-fill').trim()===fill) return;
    if (fill) cell.style.setProperty('--order-status-fill',fill);
    else cell.style.removeProperty('--order-status-fill');
    window.VitrineDisplay?.paintCell(cell);
  }
  function repaintFieldColors(column,statusOptions) {
    for (const row of document.querySelectorAll('tr[data-field-color-statuses]')) {
      const code=JSON.parse(row.dataset.fieldColorStatuses)[column];
      if (!code) continue;
      const cell=row.querySelector('td[data-column="'+column+'"]');
      if (cell) repaintFieldColor(cell,statusOptions[code]?.color || '');
    }
  }
  const fleetGroups = Object.entries(JSON.parse(document.getElementById('vitrine-fleet-edit-groups')?.content || '{}'));
  const sharedOptionsMeta = document.getElementById('vitrine-shared-options');
  const sharedOptions = JSON.parse(sharedOptionsMeta?.content || '{}');
  const sharedOptionCatalogs = JSON.parse(sharedOptionsMeta?.dataset.catalogs || '{}');
  function patchSharedOptions(patch) {
    for (const [column,catalogId] of Object.entries(sharedOptionCatalogs)) {
      if (Number(catalogId)!==Number(patch.catalogId)) continue;
      if (patch.bindings?.length && !patch.bindings.some(binding=>binding.tableSlug==='vyvoz' && binding.fieldKey===column)) continue;
      const aliases = new Set(patch.aliases || [patch.beforeName,patch.name]);
      const options = (sharedOptions[column] || []).filter(option=>!aliases.has(option.value ?? option));
      if (patch.active!==false && patch.active!==0) {
        options.push(['E','T','U','U2'].includes(column)
          ? {value:patch.name,label:patch.name,color:patch.color} : patch.name);
      }
      sharedOptions[column] = options;
    }
  }
  const normalizeContainer = value => value.replace(/[ \t\u00a0\u202f-]+/g,'').toUpperCase();
  function isUnloadingCell(cell) {
    return cell.dataset.column==='K' && Number(cell.dataset.catalogId)>0 && document.body.dataset.workspaceTable==='vyvoz';
  }
  function isContainerTypeCell(cell) {
    return cell.dataset.column === 'I' && Number(cell.dataset.catalogId) > 0 &&
      document.body.dataset.workspaceTable === 'vyvoz';
  }
  const isEdoPartyCell = cell => /^(AA|AC|AE|AG)$/.test(cell.dataset.sharedChoices || '');
  const isDropoffCell = cell => Boolean(window.VitrineDropoff?.supports(cell));
  function hasDraft(editor) {
    return editor.field.value !== editor.initialValue
      || editor.dropoff?.dirty()
      || editor.fleetDriver?.dirty()
      || (editor.orderControl && editor.orderControl.resolveValue() !== editor.initialOrder)
      || (editor.requestField && editor.requestField.checked !== editor.initialRequest);
  }
  function report(text, tone = 'warning') {
    window.PlanexNotifications.show({ tone, message:text });
  }
  function notifyDerivedCell(cell) {
    const margin=!catalog && cell.dataset.column==='Z' && cell.dataset.derivedSource;
    report(margin ? 'Маржа рассчитывается автоматически. Эту ячейку нельзя редактировать.'
      : 'Данные берутся из справочника. Эту ячейку нельзя редактировать.', 'info');
  }
  const loadError = document.querySelector('meta[name="vitrine-load-error"]')?.content;
  if (loadError) report(loadError,'danger');
  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }
  function close(restoreFocus=true) {
    if (!active || active.saving) return;
    const cell = active.cell;
    active.panel.remove();
    cell.classList.remove('is-editing');
    active = null;
    table?.classList.remove('has-editor');
    table?.classList.add('keyboard-navigation');
    if (restoreFocus) cell.focus({ preventScroll:true });
    window.dispatchEvent(new CustomEvent('vitrine-editor-closed'));
  }
  function position() {
    if (!active || active.inlinePassword) return;
    const panel = active.panel;
    if (active.inline) {
      const rect = active.cell.getBoundingClientRect();
      panel.style.setProperty('--vitrine-editor-left',rect.left+'px');
      panel.style.setProperty('--vitrine-editor-top',rect.top+'px');
      panel.style.setProperty('--vitrine-editor-width',rect.width+'px');
      panel.style.setProperty('--vitrine-editor-height',rect.height+'px');
      return;
    }
    positionPanel(panel,active.cell);
  }
  function positionPanel(panel,cell) {
    const rect = cell.getBoundingClientRect();
    const width = panel.offsetWidth;
    const height = panel.offsetHeight;
    panel.style.setProperty('--vitrine-editor-left',Math.max(10,Math.min(rect.left,window.innerWidth-width-10))+'px');
    let top = rect.bottom + 4;
    if (top + height > window.innerHeight - 10) top = Math.max(10, rect.top - height - 4);
    panel.style.setProperty('--vitrine-editor-top',Math.max(10,Math.min(top,window.innerHeight-height-10))+'px');
  }
  function fitEditor() {
    if (!active) return;
    const {panel,field} = active;
    if (field.tagName === 'TEXTAREA') {
      const measure = element('span','vitrine-text-measure');
      measure.style.setProperty('--vitrine-measure-font',getComputedStyle(field).font);
      measure.textContent = field.value;
      document.body.append(measure);
      const desiredWidth = Math.max(380,measure.getBoundingClientRect().width + 50);
      measure.remove();
      panel.style.setProperty('--vitrine-editor-width',Math.min(desiredWidth,900,window.innerWidth-20)+'px');
      field.style.removeProperty('--vitrine-editor-field-height');
      field.setAttribute('data-fit-editor','');
      const chromeHeight = panel.offsetHeight - field.offsetHeight;
      const availableHeight = Math.max(60,window.innerHeight - chromeHeight - 20);
      field.style.setProperty('--vitrine-editor-field-min-height',Math.min(94,availableHeight)+'px');
      field.style.setProperty('--vitrine-editor-field-height',Math.min(field.scrollHeight+2,availableHeight)+'px');
    }
    position();
  }
  const pendingCells = new Set();
  const pasteUndo = [];
  let undoing = false;
  function snapshot(cell) {
    return {cell, value:cell.dataset.formula??cell.textContent, edited:cell.classList.contains('local-edited'), beforeRevision:Number(cell.dataset.revision), beforeHash:cell.dataset.base};
  }
  function rememberPaste(before, result) {
    pasteUndo.push({...before, revision:result.revision, baseHash:result.baseHash});
  }
  function updatePreviousUndo(state) {
    for (let index=pasteUndo.length-1;index>=0;index--) {
      const entry=pasteUndo[index];
      const items=entry.range?.cells??[entry];
      const item=items.find(candidate=>Number(candidate.row??candidate.cell?.dataset.row)===state.row &&
        (candidate.column??candidate.cell?.dataset.column)===state.column);
      if (!item) continue;
      if (item.revision===state.beforeRevision && item.baseHash===state.beforeHash) {
        item.revision=state.revision;
        item.baseHash=state.baseHash;
      }
      return;
    }
  }
  async function undoPaste() {
    if (undoing || pendingCells.size) { report('Дождитесь сохранения ячейки.'); return; }
    const entry = pasteUndo.at(-1);
    if (!entry) return;
    if (entry.range) {
      try {
        const restored=await window.VitrineRangePaste.undo(entry.range);
        pasteUndo.pop();
        for (const state of restored) updatePreviousUndo(state);
      }
      catch(error) { report(error.message || 'Не удалось отменить вставку диапазона.','danger'); }
      return;
    }
    const cell = window.VitrineRows?.resolveCell(entry.cell) || entry.cell;
    if (Number(cell.dataset.revision) !== entry.revision || (catalog === 'employees' && cell.dataset.base !== entry.baseHash)) {
      report('Ячейка изменилась после вставки. Отмена не выполнена.', 'danger'); return;
    }
    undoing = true; pendingCells.add(cell); cell.setAttribute('aria-busy','true');
    try {
      const result = await writeCell(cell,entry.value,!entry.edited,null,'undo');
      applyResult(cell,result); pasteUndo.pop();
      updatePreviousUndo({row:Number(cell.dataset.row),column:cell.dataset.column,
        beforeRevision:entry.beforeRevision,beforeHash:entry.beforeHash,revision:result.revision,baseHash:result.baseHash});
      const shown=window.VitrineRows&&!cell.isConnected?await window.VitrineRows.activate(cell):cell;
      if(shown){flash(shown);table?.classList.add('keyboard-navigation');select(shown);shown.focus({preventScroll:true});reveal(shown);}
    } catch(error) {
      report(error instanceof TypeError ? 'Нет связи с сервером. Отмена не выполнена.' : error.message,'danger');
    } finally { undoing = false; pendingCells.delete(cell); cell.removeAttribute('aria-busy'); }
  }
  function updateDerivedLabel(cell,value) {
    if (value) cell.removeAttribute('aria-label');
    else cell.setAttribute('aria-label',cell.dataset.emptyLabel);
  }
  function applyResult(cell, result) {
    if (Number(cell.dataset.revision || 0) > result.revision) return;
    if ('formula' in result) {
      if (result.formula===null) delete cell.dataset.formula;
      else cell.dataset.formula=result.formula;
    }
    window.VitrineDropoff?.update(cell,result);
    const slotRequested = cell.dataset.kind === 'slot' && window.VitrineSlot.isRequest(result.value);
    cell.toggleAttribute('data-slot-request',slotRequested);
    const integerMask = ['weight','distance','money','cargo-value','cargo-places'].includes(cell.dataset.kind)
      ? window.VitrineCompactInput[cell.dataset.kind] : null;
    const integerDigits = integerMask?.read(result.value);
    const displayValue = slotRequested ? window.VitrineSlot.requestLabel
      : integerDigits ? integerMask.format(integerDigits) : result.value;
    const vehicleValue = cell.querySelector('[data-trip-vehicle-value]');
    if (cell.hasAttribute('data-trip-person-name')) {
      const fullName = String(displayValue ?? '');
      cell.dataset.tripPersonName = fullName;
      cell.textContent = window.VitrinePersonNames.shortNames(fullName);
    } else (vehicleValue ?? cell.querySelector('[data-password-cell-value]') ?? cell).textContent = displayValue;
    if (vehicleValue) cell.querySelector('[data-trip-driver-button]').hidden = !displayValue.trim();
    cell.dataset.original = result.original ?? '';
    cell.dataset.revision = String(result.revision);
    if (result.unloadChoices) {
      const unload=cell.parentElement.querySelector('td[data-column="K"]');
      if (unload) unload.dataset.choices=JSON.stringify(result.unloadChoices);
    }
    if (result.values) for (const sibling of cell.parentElement.querySelectorAll('td[data-edit-cell]')) {
      if (!Object.hasOwn(result.values,sibling.dataset.column)) continue;
      const value=result.values[sibling.dataset.column];
      const changed=sibling.textContent!==value;
      sibling.textContent=value;
      if (sibling.dataset.derivedSource) {
        updateDerivedLabel(sibling,value);
        if (changed) flash(sibling);
      }
    }
    if (result.fleetDocuments) {
      const docs = result.fleetDocuments;
      for (const sibling of cell.parentElement.querySelectorAll('td[data-kind="fleet-document"],td[data-column="P"]')) {
        const birth = sibling.dataset.column === 'P';
        sibling.textContent = docs.values[sibling.dataset.column];
        sibling.dataset.original = sibling.textContent;
        if (birth && !Object.hasOwn(result,'fleetBirthEditable')) continue;
        if (!birth) {
          sibling.dataset.base = docs.baseHash;
          sibling.dataset.revision = String(docs.revision);
        }
        const reason = birth && result.fleetBirthEditable ? '' : docs.readOnly;
        sibling.toggleAttribute('data-readonly-cell',Boolean(reason));
        if (reason) {
          sibling.dataset.readonlyCell = reason;
          sibling.setAttribute('aria-readonly','true');
        } else sibling.removeAttribute('aria-readonly');
      }
    }
    if (catalog && !result.fleetDocumentEdit) for (const sibling of cell.parentElement.querySelectorAll('td[data-edit-cell]:not([data-kind="fleet-document"])')) {
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
    if (Object.hasOwn(result,'fieldColorStatus') && Object.hasOwn(fieldColorLists,cell.dataset.column)) {
      const statuses=JSON.parse(cell.parentElement.dataset.fieldColorStatuses || '{}');
      if (result.fieldColorStatus) statuses[cell.dataset.column]=result.fieldColorStatus;
      else delete statuses[cell.dataset.column];
      if (Object.keys(statuses).length) cell.parentElement.dataset.fieldColorStatuses=JSON.stringify(statuses);
      else delete cell.parentElement.dataset.fieldColorStatuses;
      repaintFieldColor(cell,result.fieldColorFill || '');
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
    if (['I','J'].includes(cell.dataset.column)) window.VitrineContainerRules?.paintRow(cell.parentElement);
    if (cell.dataset.column==='P') window.VitrineRows?.updateVehicleDriver(cell.parentElement);
    if (cell.dataset.derivedSource) {
      updateDerivedLabel(cell,result.value);
      return;
    }
    if (cell.dataset.column==='O') window.VitrineRows?.updateVehicleDriver(cell.parentElement);
  }
  function fleetBirthRevision(cell) {
    return Number(cell.parentElement.querySelector('td[data-column="J"]')?.dataset.revision || 0);
  }
  const tripDraftRequests=new WeakMap();
  async function writeCell(cell, value, reset = false, paste = null, editAction = null, orderStatus = undefined, dropoffDetails = undefined, fleetGroup = undefined, birthRevision = undefined, fieldColorEdit = undefined) {
    const draft=cell.closest('[data-trip-draft]');
    if (!draft && cell.closest('[data-draft-slot]')) return window.VitrineDirectoryDrafts.write(cell,value,fleetGroup);
    if (!fleetGroup && fleetGroups.length && cell.dataset.column==='P') {
      fleetGroup={P:value};
      birthRevision=fleetBirthRevision(cell);
    }
    const payload={row:Number(cell.dataset.row),column:cell.dataset.column,value,
      revision:Number(cell.dataset.revision || 0),baseHash:cell.dataset.base,reset,editAction:editAction||(reset?'reset':paste?'paste':'edit'),
      ...(draft?.dataset.tripDraftAnchor ? {draftAnchor:Number(draft.dataset.tripDraftAnchor)} : {}),
      ...(paste ? {paste} : {}),orderStatus,dropoffDetails,fleetGroup,birthRevision,...fieldColorEdit};
    const signature=draft ? JSON.stringify(payload) : null;
    const previous=tripDraftRequests.get(cell);
    const requestId=previous?.signature===signature ? previous.requestId : crypto.randomUUID();
    if(draft)tripDraftRequests.set(cell,{signature,requestId});
    const response = await fetch(window.VitrineGridScope.url('/api/vitrine/cell'), {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({...payload,requestId})
    });
    const result = await response.json();
    if (!response.ok || result.error) {
      throw Object.assign(new Error(result.error?.message || 'Не удалось сохранить ячейку.'),{code:result.error?.code});
    }
    if (typeof result.value !== 'string' || !Number.isInteger(result.revision) || typeof result.baseHash !== 'string') throw new Error('Неполный ответ сервера. Обновите страницу.');
    if(draft)tripDraftRequests.delete(cell);
    applySavedEffects(cell,result,editAction,true);
    return result;
  }
  function applySavedEffects(cell,result,editAction,followDate=false) {
    if (cell.dataset.column==='F' && !result.createdRow && !result.fieldColorOnly) window.VitrineRows?.refreshTripDates(cell,followDate);
    if (result.dependentCell) window.VitrineRows?.patch(result.dependentCell);
    for (const patch of result.dependentCells ?? []) window.VitrineRows?.patch(patch);
    for (const patch of result.unloadCells ?? []) window.VitrineRows?.patch(patch);
    if (typeof result.fleetDocumentValue==='string') {
      window.VitrineRows?.applyFleetDocuments(result.createdRow ?? cell.dataset.row,result.fleetDocumentValue,editAction==='clear');
    }
    if (result.reauthenticate) location.assign(window.DltkPaths?.path('/login') ?? '/login');
  }
  async function showCreatedTrip(cell,row) {
    try {
      await window.VitrineRows.refreshManifest({
        focus:{row:Number(row),column:cell.dataset.column,origin:cell,action:flash},filledDrafts:[cell.dataset.row]
      });
    } catch(error) {
      report(error.message || 'Рейс сохранён. Обновите таблицу.','danger');
    }
  }
  function flash(cell, remote = false, cleared = false) {
    cell.classList.remove('just-saved','just-remote','just-cleared'); void cell.offsetWidth;
    cell.classList.add(cleared ? 'just-cleared' : remote ? 'just-remote' : 'just-saved');
  }
  function applyRemote(cell, result) {
    const changed = Number(result.revision) > Number(cell.dataset.revision || 0);
    const valueChanged = cell.textContent !== result.value;
    applyResult(cell,result);
    if (!changed || !cell.isConnected) return;
    if (result.action==='clear' && !result.value) {
      if (valueChanged) flash(cell,false,true);
      return;
    }
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
    if (editor.cell.dataset.kind === 'password' && editor.field.value === '') { close(); return; }
    if (!reset && (editor.cell.dataset.compactInput || editor.cell.dataset.kind==='slot') && !hasDraft(editor)) {
      close();
      return;
    }
    const colorList = fieldColorLists[editor.cell.dataset.column];
    const selectedStatus = !reset && editor.orderControl ? editor.orderControl.resolveValue() : undefined;
    const colorChanged = colorList && selectedStatus!==undefined && selectedStatus!==editor.initialOrder;
    const colorOnly = colorChanged && Number(editor.cell.dataset.row)>0 &&
      editor.field.value===editor.initialValue && !editor.dropoff?.dirty() && !editor.paste;
    const fieldColorEdit = colorChanged ? {fieldColorStatus:selectedStatus,...(colorOnly ? {fieldColorOnly:true} : {})} : undefined;
    const value = colorOnly ? editor.cell.textContent : editor.requestField?.checked ? window.VitrineSlot.requestLabel
      : editor.resolveValue ? editor.resolveValue() : editor.field.value;
    if (editor.dropoff && !colorOnly && !value) {
      report('Выберите точку сдачи из списка.','info');
      editor.field.focus();
      return;
    }
    if (!reset && value === null) {
      report(isContainerTypeCell(editor.cell)
        ? 'Выберите тип контейнера из подсказок.'
        : isUnloadingCell(editor.cell) ? 'Выберите точку выгрузки из подсказок.'
          : editor.cell.dataset.column === 'O' ? 'Выберите машину из подсказок.' : 'Выберите клиента из подсказок.','danger');
      editor.field.focus();
      return;
    }
    editor.saving = true;
    editor.error.hidden = true;
    editor.buttons.forEach(button => { button.disabled = true; });
    editor.field.disabled = true;
    if (editor.orderField) editor.orderField.disabled = true;
    if (editor.requestField) editor.requestField.disabled = true;
    editor.dropoff?.fields.forEach(field=>field.disabled=true);
    editor.fleetDriver?.fields.forEach(field=>field.disabled=true);
    if (!editor.inlinePassword) editor.saveButton.textContent = 'Сохраняю…';
    editor.panel.setAttribute('aria-busy', 'true');
    const cell = editor.cell;
    const before = snapshot(cell);
    try {
      const result = await writeCell(cell, value, reset, reset ? null : editor.paste,null,
        colorList ? undefined : selectedStatus,
        colorOnly ? undefined : editor.dropoff?.values(),editor.fleetDriver?.values(),editor.fleetDriver?.birthRevision,fieldColorEdit);
      if(result.createdRow){
        editor.saving=false;
        close(false);
        await showCreatedTrip(cell,result.createdRow);
        return;
      }
      applyResult(cell,result);
      if (!reset && editor.paste && !result.draft) rememberPaste(before,result);
      editor.saving = false;
      close();
      if (result.draftSaved) {
        await window.VitrineDirectoryDrafts.finish(cell);
        return;
      }
      if (!result.draft) flash(cell);
    } catch (error) {
      editor.saving = false;
      editor.buttons.forEach(button => { button.disabled = false; });
      editor.field.disabled = false;
      if (editor.orderField) editor.orderField.disabled = false;
      if (editor.requestField) editor.requestField.disabled = false;
      editor.dropoff?.fields.forEach(field=>field.disabled=false);
      editor.fleetDriver?.fields.forEach(field=>field.disabled=false);
      if (!editor.inlinePassword) editor.saveButton.textContent = 'Сохранить';
      editor.panel.removeAttribute('aria-busy');
      editor.error.textContent = error instanceof TypeError ? 'Нет связи с сервером. Текст сохранён в поле — повторите попытку.' : error.message;
      if (error.code?.startsWith('margin_rule_')) editor.error.hidden = false;
      else report(editor.error.textContent, 'danger');
      position();
      editor.field.focus();
    }
  }
  function fleetGroupFields(panel, cell, field, group) {
    const [title,columns] = group;
    panel.setAttribute('aria-label',title);
    const peers = columns.map(column => cell.parentElement.querySelector('td[data-column="' + column + '"]'))
      .filter(peer => peer && !peer.hasAttribute('data-readonly-cell'));
    const initial = peers.map(peer => peer.textContent);
    const fields = peers.map((peer, index) => {
      const column = peer.dataset.column;
      const input = column === cell.dataset.column ? field : element('input','field-input vitrine-editor-field');
      input.type = 'text';
      input.autocomplete = 'off';
      const calendarKind = {P:'birth-date',O:'passport-issued',L:'license-issued',M:'license-date'}[column];
      input.maxLength = ({A:28,B:160,H:28,I:160,D:65,E:45,F:45,G:100,Q:100,J:50,N:400,K:80})[column] ?? 10;
      const phone = peer.dataset.compactInput === 'phone';
      if (calendarKind) {
        input.dataset.calendarKind=calendarKind;
        input.id ||= 'vitrine-fleet-date-'+column;
        window.VitrineCompactInput.documentDate.bind(input);
      }
      if (input !== field) {
        input.value=initial[index];
        if (phone) window.VitrineCompactInput.phone.bind(input);
      }
      if (column==='B' || column==='I') input.addEventListener('input',()=>{
        const start=input.value.slice(0,input.selectionStart).toUpperCase().length;
        const end=input.value.slice(0,input.selectionEnd).toUpperCase().length;
        input.value=input.value.toUpperCase();
        input.setSelectionRange(start,end);
      });
      const title = peer.dataset.label;
      const label = element('label','vitrine-editor-field-group');
      input.setAttribute('aria-label',title);
      label.append(element('span','field-label',title),input);
      panel.append(label);
      return input;
    });
    return {fields, birthRevision:fleetBirthRevision(cell),
      dirty:() => fields.some((input,index) => input.value !== initial[index]),
      values:() => Object.fromEntries(peers.map((peer,index) => [peer.dataset.column,fields[index].value]))};
  }
  function open(cell, firstCharacter = null) {
    const card = {'unload-contacts':window.VitrineUnloadContacts,'client-unload-points':window.VitrineClientUnloadPoints,
      'client-managers':window.VitrineClientManagers}[cell.dataset.kind];
    if (cell.hasAttribute('data-readonly-cell') && !card) {
      report(cell.dataset.readonlyCell, 'info');
      return;
    }
    if (cell.dataset.derivedSource) {
      notifyDerivedCell(cell);
      return;
    }
    if (pendingCells.has(cell)) { report('Дождитесь сохранения ячейки.'); return; }
    if (active) {
      if (active.cell === cell) { active.field.focus(); return; }
      report('Сначала сохраните правку или нажмите «Отмена»');
      active.field.focus();
      return;
    }
    if (card) {
      select(cell);
      cell.focus({preventScroll:true});
      card.open(cell);
      return;
    }
    document.querySelectorAll('.vitrine-folder[open]').forEach(folder => { folder.open = false; });
    const draftType = cell.dataset.draftType;
    const accountPassword = cell.dataset.kind === 'password';
    const password = accountPassword || draftType === 'password';
    const panel = element('section', password ? 'vitrine-password-inline auth-password' : 'vitrine-editor');
    panel.setAttribute('role', password ? 'group' : 'dialog');
    const isStatus = cell.dataset.kind === 'status';
    const isSlot = cell.dataset.kind === 'slot';
    const mask = isSlot ? window.VitrineSlot : window.VitrineCompactInput[cell.dataset.compactInput];
    const isContainer = cell.dataset.kind === 'container';
    const isSingleLine = isContainer || cell.hasAttribute('data-single-line');
    const pickupEditor = cell.dataset.column === 'E' && Number(cell.dataset.catalogId) > 0 &&
      document.body.dataset.workspaceTable === 'vyvoz';
    const dropoffEditor = isDropoffCell(cell);
    const unloadEditor = isUnloadingCell(cell);
    const fleetEditor = !catalog && cell.dataset.column==='O' && Number(cell.dataset.catalogId)>0;
    const documentStatusEditor = !catalog && ['T','U','U2'].includes(cell.dataset.column) &&
      Number(cell.dataset.catalogId)>0 && document.body.dataset.workspaceTable==='vyvoz';
    const catalogEditor = documentStatusEditor || fleetEditor || dropoffEditor || pickupEditor || (['B', 'I', 'K', 'S'].includes(cell.dataset.column) && document.body.dataset.workspaceTable === 'vyvoz');
    const incomeEditor = !catalog && cell.dataset.column==='X' && document.body.dataset.workspaceTable==='vyvoz';
    const slotApplicationEditor = !catalog && cell.dataset.column==='N' && document.body.dataset.workspaceTable==='vyvoz';
    const commentEditor = !catalog && ['V','AK'].includes(cell.dataset.column) && document.body.dataset.workspaceTable==='vyvoz';
    const dialogName = cell.dataset.column + cell.dataset.row + (cell.dataset.label ? ' · ' + cell.dataset.label : '');
    const compactEditor = mask || isSingleLine || isStatus || catalogEditor || incomeEditor || commentEditor || slotApplicationEditor || catalog;
    if (compactEditor && !unloadEditor) {
      panel.setAttribute('aria-label', dialogName);
    } else {
      const clientName = unloadEditor ? cell.parentElement.querySelector('td[data-column="B"]')?.textContent.trim() : '';
      const title = element('h2', 'vitrine-editor-title', unloadEditor ? 'Выгрузки' : dialogName);
      if (unloadEditor) {
        title.classList.add('vitrine-unload-title');
        if (clientName) title.append(' ',element('strong','',clientName));
      }
      title.id = 'vitrine-editor-title';
      panel.setAttribute('aria-labelledby', title.id);
      panel.append(title);
      if (!compactEditor) panel.append(element('p', 'vitrine-editor-note', 'Изменения сохраняются в БД'));
    }

    let choices = [];
    try {
      const shared=cell.dataset.sharedChoices;
      choices=shared ? sharedOptions[shared]
        : JSON.parse(cell.dataset.choices || '[]');
    } catch {}
    if (!Array.isArray(choices)) choices = [];
    const useCustom = !mask && (catalog || !isSingleLine) && (isStatus || choices.length > 0 || Number(cell.dataset.catalogId) > 0);
    const typeEditor = isContainerTypeCell(cell);
    const unloadClient = catalog==='entries' && cell.dataset.column==='A' && document.getElementById('unload-contacts-dialog');
    const fleetSearchKey = window.VitrineSelect.vehicleSearchKey;
    const searchableCatalog = isEdoPartyCell(cell) || fleetEditor || dropoffEditor || pickupEditor || typeEditor || unloadEditor || unloadClient || cell.hasAttribute('data-order-status');
    const catalogOptions = () => {
      const required = cell.hasAttribute('data-required-choice');
      const options = required ? [] : [{value:'',label:'Не выбран'}];
      const items = isStatus ? (window.vitrineStatusCatalog?.statuses || []).map(status => ({value:status.name,label:status.name,color:status.color})) : choices.map(option => {
        if (!option || typeof option !== 'object') return {value:String(option),label:String(option)};
        if (!fleetEditor) return option;
        const driver = option.driver ?? '';
        const shortName = window.VitrineRows.shortDriverName(driver);
        return {value:option.value, label:shortName ? option.value+' '+shortName : option.value,
          search:option.search+' '+fleetSearchKey(driver)};
      });
      if (!required && !typeEditor && !unloadEditor && !documentStatusEditor && (!dropoffEditor || Number(cell.dataset.referenceId)) && cell.textContent && !items.some(item => item.value === cell.textContent)) options.push({value:cell.textContent,label:cell.textContent + (fleetEditor ? cell.dataset.fleetDriverSuffix ?? '' : ' · вне справочника')});
      options.push(...items.filter(item => item.value !== ''));
      return options;
    };
    const control = useCustom
      ? window.VitrineSelect.create(catalogOptions(),cell.textContent,{searchable:searchableCatalog,
        showColorSwatches:isStatus || typeEditor || ['E','T','U','U2'].includes(cell.dataset.sharedChoices),
        ...(fleetEditor ? {searchKey:fleetSearchKey,showLabels:true} : {})})
      : null;
    const field = control?.field || element(mask || isSingleLine ? 'input' : 'textarea', 'field-input');
    field.classList.add('vitrine-editor-field');
    if (['money','cargo-value'].includes(cell.dataset.compactInput)) field.classList.add('vitrine-money-input');
    field.id = 'vitrine-editor-field';
    field.setAttribute('aria-label', 'Значение ячейки');
    const formula = cell.hasAttribute('data-formula-input');
    let initialValue = accountPassword ? '' : password ? window.VitrineDirectoryDrafts.secret(cell) : (cell.dataset.formula ?? cell.textContent);
    if (fleetEditor && control) initialValue=control.field.value;
    if (dropoffEditor && !Number(cell.dataset.referenceId)) initialValue='';
    if (mask) {
      const digits = mask.read(initialValue);
      const replaceLegacyValue = !isSlot && initialValue !== '' && digits === '';
      if (!replaceLegacyValue && cell.dataset.compactInput !== 'phone' && !cell.hasAttribute('data-formula')) initialValue = mask.format(digits);
      const digitsOnlyPaste = cell.dataset.compactInput === 'date';
      mask.bind(field,{digitsOnlyPaste,formula});
    }
    field.autocomplete = password ? 'new-password' : 'off';
    if (password) {
      field.type = 'password';
      field.setAttribute('aria-label', accountPassword ? 'Новый пароль' : 'Пароль');
    }
    if (accountPassword) field.placeholder = 'Новый пароль';
    if (draftType === 'email') field.inputMode = 'email';
    if (draftType === 'date') field.inputMode = 'numeric';
    if (draftType === 'phone') field.inputMode = 'numeric';
    if (cell.hasAttribute('data-draft-allow-empty')) field.placeholder = 'Можно оставить пустым';
    field.maxLength = draftType === 'email' ? 254 : password ? 72 : isSingleLine && catalog ? 160 : 20000;
    field.value = mask && firstCharacter !== null && !(formula && firstCharacter==='=')
      ? mask.format(firstCharacter) : firstCharacter ?? initialValue;
    if (isContainer) {
      const prefix = /^(?:[A-Z]{0,3}|[A-Z]{3}U[0-9]{0,7})$/;
      const stored = normalizeContainer(initialValue);
      if (prefix.test(stored)) initialValue = stored;
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
    let orderControl = null;
    let requestField = null;
    const initialRequest = isSlot && window.VitrineSlot.isRequest(cell.textContent);
    let dropoff=null;
    let fleetDriver=null;
    const fleetGroup = fleetGroups.find(([,columns]) => columns.includes(cell.dataset.column));
    if (fleetGroup) {
      fleetDriver=fleetGroupFields(panel,cell,field,fleetGroup);
    } else if (dropoffEditor) {
      dropoff=window.VitrineDropoff.attach(panel,control,cell);
    } else if (cell.hasAttribute('data-order-status')) {
      field.setAttribute('aria-label','Клиент');
      const clientGroup = element('div','vitrine-editor-field-group');
      clientGroup.append(element('div','field-label','Клиент'),control?.element || field);
      panel.append(clientGroup);
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
    const fieldColor=fieldColorLists[cell.dataset.column];
    if (fieldColor || cell.hasAttribute('data-order-status')) {
      const statuses=fieldColor ? fieldColor.statuses : orderStatuses;
      const initialStatus=fieldColor ? fieldColorStatus(cell) : cell.dataset.orderStatus;
      const label=fieldColor?.label || 'Статус заказа';
      const options=()=>[{value:'',label:'Без статуса'},...Object.entries(statuses).map(([code,status])=>({
        value:code,label:status.label,color:status.color
      }))];
      const picker=window.VitrineSelect.create(options(),statuses[initialStatus] ? initialStatus : '',
        {showColorSwatches:true,showLabels:true});
      orderControl=picker;
      orderField=picker.field;
      orderField.setAttribute('aria-label',label);
      orderField.classList.add('vitrine-editor-field');
      const statusGroup=element('div','vitrine-editor-field-group vitrine-editor-status-group');
      statusGroup.append(element('div','field-label',label),picker.element);
      panel.append(statusGroup);
      window.VitrineQuickCatalog?.attach(picker,fieldColor ? 'fieldColor'+cell.dataset.column : 'orderStatus',cell,data=>{
        const selected=picker.resolveValue();
        for (const code of Object.keys(statuses)) delete statuses[code];
        Object.assign(statuses,data.orderStatuses);
        if (picker.field.isConnected) picker.replaceOptions(options(),selected);
        if (fieldColor) repaintFieldColors(cell.dataset.column,statuses);
      });
      if (fieldColor) statusGroup.querySelector('[data-quick-open]')?.setAttribute('aria-label','Открыть справочник — '+label);
    }
    if (control) window.VitrineQuickCatalog?.attach(control,cell.dataset.column,cell,data=>{
      if (isStatus) window.vitrineStatusCatalog={...window.vitrineStatusCatalog,statuses:data.statuses};
      else {
        choices=data.choices;
        const shared=cell.dataset.sharedChoices;
        if (shared) sharedOptions[shared]=choices;
        else cell.dataset.choices=JSON.stringify(choices);
        Object.assign(sharedOptions,data.relatedChoices);
      }
      if (data.dropoffOptions) window.VitrineDropoff?.replaceOptions(data.dropoffOptions);
      if (data.unloadOptions) window.VitrineRows?.replaceUnloadChoices(data.unloadOptions,data.unloadClients);
      if (data.containerTypeRules) window.VitrineContainerRules?.replace(data.containerTypeRules);
      if (field.isConnected) {
        control.replaceOptions(catalogOptions());
        if (dropoff) field.dispatchEvent(new Event('change'));
      }
    });
    if (!useCustom && !dropoff && !mask && !isSingleLine && !isStatus && !documentStatusEditor && !incomeEditor && !catalog && !orderField) {
      panel.append(element('p', 'vitrine-editor-help', 'Enter — сохранить · Esc — отменить'));
    }
    const error = element('p', 'vitrine-editor-error');
    error.setAttribute('role', 'alert'); error.hidden = true;
    if (!catalog && cell.dataset.column==='Y') panel.prepend(error);
    else panel.append(error);
    const actions = element('div', 'vitrine-editor-actions');
    const passwordActions = password ? document.getElementById('vitrine-password-actions').content.cloneNode(true) : null;
    const saveButton = passwordActions?.querySelector('[data-password-save]') ?? element('button', 'btn btn-primary', 'Сохранить');
    const cancelButton = passwordActions?.querySelector('[data-password-cancel]') ?? element('button', 'btn btn-secondary', 'Отмена');
    const buttons = [saveButton, cancelButton, ...panel.querySelectorAll('[data-quick-open]')];
    saveButton.type = cancelButton.type = 'button';
    saveButton.addEventListener('click', () => save());
    cancelButton.addEventListener('click', close);
    actions.append(saveButton, cancelButton);
    if (!password && !dropoff && !isStatus && !documentStatusEditor && !cell.hasAttribute('data-order-status') && cell.dataset.compactInput!=='time' && cell.classList.contains('local-edited')) {
      const resetButton = element('button', 'btn btn-secondary vitrine-editor-reset', 'Исходное');
      resetButton.type = 'button'; resetButton.addEventListener('click', () => save(true));
      buttons.push(resetButton); actions.append(resetButton);
    }
    if (password) {
      panel.append(...actions.children);
      const toggle = document.getElementById('vitrine-password-inline-eye')?.content.firstElementChild.cloneNode(true);
      if (toggle && window.DltkPasswordVisibility) {
        panel.append(toggle);
        const setVisible = window.DltkPasswordVisibility(field,toggle);
        const updateToggle = () => {
          toggle.hidden = field.value === '';
          if (toggle.hidden) setVisible(false);
        };
        updateToggle();
        field.addEventListener('input',updateToggle);
        buttons.push(toggle);
      }
      cell.append(panel);
    } else {
      panel.append(actions);
      document.body.append(panel);
    }
    cell.classList.add('is-editing');
    select(cell);
    active = { panel, cell, field, initialValue, inlinePassword:password, orderField, orderControl, initialOrder:orderControl?.resolveValue(), requestField, initialRequest, dropoff, fleetDriver, resolveValue:control?.resolveValue, buttons, saveButton, error, saving:false };
    window.dispatchEvent(new CustomEvent('vitrine-editor-opened',{detail:{row:Number(cell.dataset.row),column:cell.dataset.column,panel,cell,field,dropoffDate:dropoff?.dateField}}));
    table?.classList.add('has-editor');
    panel.addEventListener('keydown', event => {
      const directions = {ArrowLeft:[0,-1],ArrowRight:[0,1],ArrowUp:[-1,0],ArrowDown:[1,0]};
      if (!directions[event.key] || event.ctrlKey || event.metaKey || event.altKey || event.shiftKey || event.isComposing || active?.saving) return;
      if (field.dataset.customSelect && (field.getAttribute('aria-expanded') === 'true' || event.key === 'ArrowDown' || event.key === 'ArrowUp')) return;
      if (dropoff || fleetDriver || mask || orderField || isSingleLine || hasDraft(active)) return;
      event.preventDefault(); event.stopImmediatePropagation();
      close(); move(cell,...directions[event.key],false);
    }, true);
    panel.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
      if (event.key === 'Enter' && (event.target === field || event.target === orderField || event.target === requestField || fleetDriver?.fields.includes(event.target)) && !event.shiftKey && !event.isComposing) { event.preventDefault(); save(); }
      if (event.key === 'Tab') {
        const focusable = [...panel.querySelectorAll('input,textarea,button,select')].filter(node=>
          node.tabIndex>=0 && !node.disabled && !node.closest('[hidden]') && node.getClientRects().length);
        const first = focusable[0], last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      }
    });
    field.addEventListener('input',fitEditor);
    fitEditor();
    field.focus();
    const caret=firstCharacter===null && searchableCatalog ? 0 : field.value.length;
    field.setSelectionRange(caret,caret);
    if ((fleetEditor || dropoffEditor || typeEditor || unloadEditor) && firstCharacter !== null) {
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
    if (event.pointerType === 'touch' || (!event.movementX && !event.movementY) || active ||
        window.VitrineClipboardMenu?.opened() || window.VitrineRange?.blocksHover() || window.VitrineRangePaste?.busy()) return;
    const cell = event.target.closest('td[data-edit-cell]');
    if (table.classList.contains('keyboard-navigation')) table.classList.remove('keyboard-navigation');
    if (cell !== selected) select(cell);
  });
  table?.addEventListener('mousedown', event => {
    table.classList.remove('keyboard-navigation');
    const cell = event.target.closest('td[data-edit-cell]');
    if (!cell) return;
    if (event.button === 2) { event.preventDefault(); return; }
    if (event.button !== 0 || event.target.closest('.vitrine-password-inline')) return;
    event.preventDefault();
    if (!active) cell.focus({ preventScroll:true });
  });
  table?.addEventListener('selectstart', event => {
    if (!event.target.closest('.vitrine-password-inline')) event.preventDefault();
  });
  table?.addEventListener('dragstart', event => {
    if (!event.target.closest('[data-quick-row-drag]')) event.preventDefault();
  });
  table?.addEventListener('animationend', event => {
    if (event.animationName === 'vitrine-cell-saved') event.target.classList.remove('just-saved','just-remote');
  });
  document.addEventListener('click', event => {
    if (!active || active.panel.contains(event.target) || active.cell.contains(event.target)
        || event.target.closest('[data-vitrine-quick-catalog]')) return;
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
  const clickedCell = event => event.target.closest('button, a[href], input, select, textarea')
    ? null : event.target.closest('td[data-edit-cell]');
  table?.addEventListener('click', event => {
    const passwordAction = event.target.closest('[data-password-cell-action]');
    if (passwordAction && !window.VitrineRangePaste?.busy()) {
      open(passwordAction.closest('td[data-edit-cell]'));
      return;
    }
    const cell = clickedCell(event);
    if (!cell || active || window.VitrineRangePaste?.busy()) return;
    select(cell);
    cell.focus({preventScroll:true});
  });
  table?.addEventListener('dblclick', event => {
    const cell = clickedCell(event);
    if (!cell || window.VitrineRangePaste?.busy()) return;
    open(cell);
  });
  let cells = Array.from(document.querySelectorAll('td[data-edit-cell]'));
  const visibleRows = () => Array.from(document.querySelectorAll('[data-vitrine-row]')).map(row => Array.from(row.querySelectorAll('td[data-edit-cell]:not([hidden])')));
  let rowCells = visibleRows();
  window.addEventListener('vitrine-columns-updated', () => {
    rowCells = visibleRows();
    if (!selected?.hidden) return;
    const replacement=Array.from(selected.parentElement.querySelectorAll('td[data-edit-cell]')).find(cell=>!cell.hidden);
    if (isTripGrid && !selected.classList.contains('is-current-cell')) setTabStop(replacement);
    else select(replacement);
  });
  let selected = cells.find(cell=>!cell.hidden) || null;
  table?.setAttribute('role','grid');
  cells.forEach(cell => { cell.tabIndex = cell === selected ? 0 : -1; });
  function setTabStop(cell) {
    if (selected) selected.tabIndex=-1;
    selected=cell;
    if (selected) selected.tabIndex=0;
  }
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
    if (event.target.closest('.vitrine-derived-icon, .display-column-controls, [data-quick-row-drag], .vitrine-clipboard-menu, input, textarea, select')) return null;
    return event.target === document.body || event.target.closest('.vitrine-table-scroll') ? selected : null;
  }
  if (!isTripGrid) select(selected);
  window.addEventListener('vitrine-rows-updated',()=>{
    cells=Array.from(document.querySelectorAll('td[data-edit-cell]'));rowCells=visibleRows();
    const replacement=selected?cells.find(cell=>cell.dataset.row===selected.dataset.row&&cell.dataset.column===selected.dataset.column):cells.find(cell=>!cell.hidden);
    if (replacement && (!isTripGrid || selected?.classList.contains('is-current-cell'))) {
      select(replacement);
    } else if (isTripGrid && !selected?.isConnected) {
      setTabStop(cells.find(cell=>!cell.hidden));
    }
  });
  table?.addEventListener('focusin', event => { const cell = event.target.closest('td[data-edit-cell]'); if (cell) select(cell); });
  function reveal(cell) {
    const scroll = cell.closest('.vitrine-table-scroll');
    if (!scroll) return;
    const frozenCount = Number(table.dataset.frozenCount || 0);
    const frozen = cell.hasAttribute('data-frozen-column') && Number(cell.dataset.frozenColumn)<=frozenCount;
    const previousLeft = scroll.scrollLeft;
    cell.scrollIntoView({block:'nearest',inline:'nearest'});
    if (frozen) scroll.scrollLeft = previousLeft;
    else if (cell === cell.parentElement.querySelector('td[data-edit-cell]:not([hidden])')) scroll.scrollLeft = 0;
    const rect = cell.getBoundingClientRect();
    const rowHeader = cell.parentElement.querySelector('.vitrine-row-number');
    const lastFrozen = cell.parentElement.querySelector(`[data-frozen-column="${frozenCount}"]`);
    const header = table.querySelector('thead');
    const left = (lastFrozen ?? rowHeader)?.getBoundingClientRect().right;
    const top = header?.getBoundingClientRect().bottom;
    if (!frozen && left !== undefined && rect.left < left) scroll.scrollLeft -= left - rect.left;
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
  function askClear(cell) {
    if (cell.dataset.kind === 'password') {
      report('Откройте редактор ячейки для смены пароля.', 'info');
      return;
    }
    if (cell.dataset.derivedSource || cell.dataset.kind==='client-unload-points') {
      notifyDerivedCell(cell);
      return;
    }
    if (cell.hasAttribute('data-readonly-cell')) {
      report(cell.dataset.readonlyCell,'info');
      return;
    }
    if (!clearDialog || clearDialog.open || pendingCells.has(cell)) return;
    if (cell.closest('[data-draft-slot]') && !cell.textContent) return;
    clearTarget=cell;
    clearDialog.querySelector('[data-clear-cell-label]').textContent=cell.dataset.label;
    clearDialog.showModal();
  }

  clearDialog?.querySelector('[data-clear-cell-cancel]').addEventListener('click',()=>clearDialog.close());
  clearDialog?.addEventListener('cancel',event=>{
    if (pendingCells.has(clearTarget)) event.preventDefault();
  });
  clearDialog?.addEventListener('close',()=>{
    const cell=clearTarget;
    clearTarget=null;
    if (cell?.isConnected) cell.focus({preventScroll:true});
    window.dispatchEvent(new CustomEvent('vitrine-editor-closed'));
  });
  clearDialog?.querySelector('form').addEventListener('submit',async event=>{
    event.preventDefault();
    const cell=clearTarget;
    if (!cell || pendingCells.has(cell)) return;
    const previous=new Map(Array.from(cell.parentElement.querySelectorAll('td[data-edit-cell]'),
      peer=>[peer,peer.textContent]));
    const buttons=Array.from(clearDialog.querySelectorAll('button'));
    buttons.forEach(button=>button.disabled=true);
    pendingCells.add(cell);
    cell.setAttribute('aria-busy','true');
    let succeeded=false;
    try {
      if (cell.closest('[data-draft-slot]')) window.VitrineDirectoryDrafts.clear(cell);
      else applyResult(cell,await writeCell(cell,'',false,null,'clear'));
      for (const [peer,before] of previous) {
        if (before && peer.textContent==='' && !peer.classList.contains('just-cleared')) flash(peer,false,true);
      }
      succeeded=true;
    } catch (error) {
      report(error.message || 'Не удалось очистить ячейку.','danger');
    } finally {
      pendingCells.delete(cell);
      cell.removeAttribute('aria-busy');
      buttons.forEach(button=>button.disabled=false);
      if (succeeded) clearDialog.close();
      else buttons.at(-1)?.focus();
    }
  });

  document.addEventListener('keydown', event => {
    if (window.VitrineRangePaste?.busy()) return;
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
    if (event.key === 'Enter' && event.altKey && cell.querySelector('[data-password-cell-action]')) {
      event.preventDefault();
      open(cell);
      return;
    }
    if (event.key === 'Enter' && event.altKey && cell.matches('[data-trip-driver-cell]')) {
      event.preventDefault();
      cell.querySelector('[data-trip-driver-button]:not([hidden])')?.click();
      return;
    }
    if (event.key==='Delete' && !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey
      && !event.isComposing && !event.defaultPrevented && !event.target.closest('input,textarea,[contenteditable="true"]')
      && !document.querySelector('dialog[open]')) {
      event.preventDefault();
      if (event.repeat) return;
      if (window.VitrineRows && !cell.isConnected) window.VitrineRows.activate(cell,askClear);
      else askClear(cell);
      return;
    }
    const typedText = cell.dataset.kind === 'text' && cell.hasAttribute('data-single-line') && /^\S$/u.test(event.key);
    const typedContainer = cell.dataset.kind === 'container' && /^[a-z]$/i.test(event.key);
    const typedType = isContainerTypeCell(cell) && /^[\p{L}0-9]$/u.test(event.key);
    const typedCatalog = (isUnloadingCell(cell) || isDropoffCell(cell) || isEdoPartyCell(cell)) && /^[\p{L}0-9]$/u.test(event.key);
    if ((typedType || typedCatalog || ((typedText || typedContainer) && (cell.dataset.choices ?? '[]') === '[]'))
      && !cell.hasAttribute('data-required-choice')
      && !cell.hasAttribute('data-readonly-cell') && !event.defaultPrevented && !event.isComposing
      && (!event.ctrlKey && !event.metaKey && !event.altKey || event.getModifierState('AltGraph'))) {
      event.preventDefault();
      const startTyping = target => open(target,event.key);
      if (window.VitrineRows && !cell.isConnected) window.VitrineRows.activate(cell,startTyping);
      else startTyping(cell);
      return;
    }
    if ((cell.dataset.kind === 'slot' || window.VitrineCompactInput[cell.dataset.compactInput])
      && (/^[0-9]$/.test(event.key) || (event.key==='=' && cell.hasAttribute('data-formula-input'))) && !cell.hasAttribute('data-readonly-cell')
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
  document.addEventListener('copy', event => {
    const cell = eventCell(event);
    if (active || !cell || !event.clipboardData || !window.VitrineRange) return;
    window.VitrineRange.copy(cell,event).catch(error=>report(error.message || 'Не удалось скопировать диапазон.','danger'));
  });
  document.addEventListener('paste', async event => {
    if (event.defaultPrevented || window.VitrineRangePaste?.busy()) return;
    let cell = eventCell(event);
    const inEditor = active && event.target === active.field;
    if ((!cell && !inEditor) || !event.clipboardData) return;
    if (inEditor && (active.cell.dataset.kind === 'password' || active.cell.dataset.kind === 'slot' || window.VitrineCompactInput[active.cell.dataset.compactInput] || active.field.getAttribute('aria-autocomplete') === 'list'
      || active.cell.closest('[data-draft-slot]'))) return;
    event.preventDefault();
    if(cell&&!cell.isConnected&&window.VitrineRows){cell=await window.VitrineRows.activate(cell);if(!cell)return;}
    const target = cell || active.cell;
    let value = event.clipboardData.getData('text/plain');
    let source = null;
    if (!inEditor && !active && window.VitrineRange) {
      if (!value && !window.VitrineRange.containsMany(target)) {
        report('В буфере обмена нет текста.','info'); return;
      }
      try {
        const data = window.VitrineRange.parse(value);
        if (data.length>1 || data[0].length>1 || window.VitrineRange.containsMany(target)) {
          if (value === '' && !data[0][0].source) {
            report('В буфере обмена нет текста.','info'); return;
          }
          await window.VitrineRangePaste.paste(target,data);
          return;
        }
        value=data[0][0].value;
        source=data[0][0].source??null;
      } catch (error) {
        report(error.message || 'Не удалось вставить диапазон.','danger');return;
      }
    }
    if (target.dataset.kind==='client-managers') { report('Откройте список менеджеров из ячейки.','info'); return; }
    if (target.dataset.kind==='client-unload-points') { report('Откройте точки выгрузки из ячейки.','info'); return; }
    if (target.dataset.kind==='unload-contacts') { report('Откройте редактор контактов из ячейки.','info'); return; }
    if (!inEditor && (target.dataset.draftType === 'password' || target.dataset.kind === 'password')) {
      report('Откройте поле пароля для вставки.','info');
      return;
    }
    if (target.dataset.derivedSource) { notifyDerivedCell(target); return; }
    if (target.hasAttribute('data-readonly-cell')) { report(target.dataset.readonlyCell, 'info'); return; }
    if (pendingCells.has(target) || active?.saving) { report('Дождитесь сохранения ячейки.'); return; }
    if (!source) {
      try { source = JSON.parse(event.clipboardData.getData('application/x-dltk-cell') || 'null'); } catch {}
    }
    if (value.length > 20000) { report('Вставляйте одну ячейку размером до 20 000 символов.','danger'); return; }
    const paste = {source};
    if (inEditor) {
      const editor = active;
      try {
        const response = await fetch(window.VitrineGridScope.url('/api/vitrine/paste-check'),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({row:Number(target.dataset.row),column:target.dataset.column,value,paste,
          revision:Number(target.dataset.revision || 0),baseHash:target.dataset.base,
          ...(fleetGroups.length && target.dataset.column==='P' ? {fleetGroup:{P:value},birthRevision:fleetBirthRevision(target)} : {})})});
        const result = await response.json(); if (!response.ok) throw new Error(result.error?.message || 'Не удалось проверить формат.');
        if (active !== editor) return;
        const editorValue = typeof result.editorValue === 'string' ? result.editorValue : value;
        if (target.dataset.kind === 'container') active.field.value = normalizeContainer(value);
        else if (active.field.dataset.customSelect) active.field.value = value;
        else active.field.setRangeText(editorValue,active.field.selectionStart,active.field.selectionEnd,'end');
        active.paste = paste;
        window.VitrineRange?.pasted();
        fitEditor();
      } catch(error) { report(error.message || 'Не удалось проверить вставку.','danger'); }
      return;
    }
    if (active) { report('Сначала сохраните правку или нажмите «Отмена»'); return; }
    pendingCells.add(target); target.setAttribute('aria-busy','true');
    const before = snapshot(target);
    let created=false;
    try {
      const result = await writeCell(target,value,false,paste);
      window.VitrineRange?.pasted();
      if(result.createdRow){
        created=true;
        await showCreatedTrip(target,result.createdRow);
        return;
      }
      applyResult(target,result);
      if (result.draftSaved) await window.VitrineDirectoryDrafts.finish(target);
      if (!result.draft) { flash(target); rememberPaste(before,result); }
    } catch(error) { report(error instanceof TypeError ? 'Нет связи с сервером. Вставка не выполнена.' : error.message,'danger'); }
    finally { pendingCells.delete(target); target.removeAttribute('aria-busy'); if(!created)target.focus({preventScroll:true}); }
  });
  document.querySelector('.vitrine-table-scroll')?.addEventListener('scroll', position);
  window.addEventListener('resize', fitEditor);
  window.VitrineGrid=Object.freeze({select,reveal,positionPanel,editing:()=>Boolean(active || clearTarget),
    editingCell:()=>active?.cell||clearTarget,applyRemote,patchSharedOptions,fieldColorStatus,
    rangeBusy:()=>Boolean(undoing || pendingCells.size || window.VitrineRangePaste?.busy()),
    rememberRange:range=>pasteUndo.push({range}),
    applyRangeSave(cell,result){
      applySavedEffects(cell,result);
      applyResult(cell,result);
      if (cell.isConnected) flash(cell);
    }
  });
})();

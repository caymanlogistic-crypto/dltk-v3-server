'use strict';
(() => {
  const textField = 'textarea, input:not([type]), input:is([type="text"],[type="search"],[type="tel"],[type="email"],[type="url"],[type="password"],[type="number"])';
  const gridCell = 'td[data-edit-cell]';
  const visibleCell = 'td:not([data-virtual-spacer] > td)';
  let menu = null;
  let source = null;

  const report = message => window.PlanexNotifications.show({tone:'danger',message});

  function close(restoreFocus = false) {
    if (!menu) return;
    const target = source;
    menu.remove();
    menu = source = null;
    if (restoreFocus && target.isConnected) target.focus({preventScroll:true});
  }

  function clipboardEvent(target,type,data) {
    return target.dispatchEvent(new ClipboardEvent(type,{clipboardData:data,bubbles:true,cancelable:true}));
  }

  function copyValue(target) {
    if (target.matches('select')) return target.selectedOptions[0]?.label ?? '';
    if (!target.matches(textField)) return target.textContent.trim();
    return target.value.slice(target.selectionStart,target.selectionEnd) || target.value;
  }

  function pasteValue(target,value) {
    if (target.matches('select')) {
      const query = value.trim();
      const options = Array.from(target.options).filter(option=>!option.disabled &&
        !option.closest('optgroup[disabled]') && (option.value===query || option.label===query));
      if (options.length!==1) throw new Error('Такого варианта нет в списке.');
      target.value = options[0].value;
      target.dispatchEvent(new Event('change',{bubbles:true}));
      return;
    }
    if (target.matches(textField) && target.readOnly) {
      if (!window.VitrineSelect.pasteOption(target,value)) throw new Error('Выберите значение из списка.');
      return;
    }
    if (target.matches(gridCell)) window.VitrineGrid.select(target);
    const data = new DataTransfer();
    data.setData('text/plain',value);
    if (!clipboardEvent(target,'paste',data) || !target.matches(textField)) return;
    const start = target.selectionStart ?? target.value.length;
    const end = target.selectionEnd ?? start;
    const text = target.matches('input') ? value.replace(/[\r\n]/g,'') : value;
    const inserted = target.maxLength>=0 ? text.slice(0,Math.max(0,target.maxLength-target.value.length+end-start)) : text;
    if (target.selectionStart===null) {
      const previous = target.value;
      target.value = previous.slice(0,start)+inserted+previous.slice(end);
      if (target.type==='number' && !target.value && inserted) {
        target.value = previous;
        throw new Error('Вставьте число.');
      }
    } else {
      target.setRangeText(inserted,start,end,'end');
    }
    target.dispatchEvent(new InputEvent('input',{data:inserted,inputType:'insertFromPaste',bubbles:true}));
  }

  function actionsFor(target) {
    const isField = target.matches(textField);
    const isSelect = target.matches('select');
    const copy = !(isField && target.type==='password');
    const edit = !target.matches(':disabled') && (isSelect || (isField &&
      (!target.readOnly || window.VitrineSelect?.canPasteOption(target))));
    const cell = target.matches(gridCell) && !target.matches('[data-readonly-cell],[data-derived-source],[aria-readonly="true"]') &&
      !['client-managers','client-unload-points','unload-contacts'].includes(target.dataset.kind) &&
      target.dataset.draftType!=='password' && target.dataset.kind!=='password' && !window.VitrineRangePaste?.requiresForm(target);
    return {copy, paste:edit || cell};
  }

  function item(label,enabled,run) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'cs-option';
    button.setAttribute('role','menuitem');
    button.textContent = label;
    button.disabled = !enabled;
    button.addEventListener('click',run);
    return button;
  }

  function targetFor(element) {
    const field = element.closest(textField+', select');
    if (!field && (window.VitrineGrid?.editing() || window.VitrineGrid?.rangeBusy())) return null;
    return field || element.closest(visibleCell);
  }

  function open(target,x,y) {
    close();
    if (target.matches(gridCell)) target = window.VitrineRange?.selectContext(target) ?? target;
    source = target;
    menu = document.createElement('div');
    menu.className = 'vitrine-select vitrine-clipboard-menu';
    const dropdown = document.createElement('div');
    dropdown.className = 'cs-dropdown is-open';
    const options = document.createElement('div');
    options.className = 'cs-options';
    options.setAttribute('role','menu');
    options.setAttribute('aria-label','Буфер обмена');
    const actions = actionsFor(target);
    options.append(item('Копировать',actions.copy,async ()=>{
      close(true);
      try {
        if (target.matches(gridCell) && window.VitrineRange) await window.VitrineRange.copy(target);
        else await navigator.clipboard.writeText(copyValue(target));
      }
      catch { report('Не удалось скопировать значение в буфер обмена.'); }
    }),item('Вставить',actions.paste,async ()=>{
      close(true);
      let value;
      try { value = await navigator.clipboard.readText(); }
      catch { report('Не удалось прочитать буфер обмена. Для вставки нажмите Ctrl+V.'); return; }
      if (!target.isConnected || !actionsFor(target).paste) return;
      if (value === '') { report('В буфере обмена нет текста.'); return; }
      try {
        const original = target.value;
        pasteValue(target,value);
        if (original!==undefined && original!==target.value) window.VitrineRange?.pasted();
      }
      catch (error) { report(error.message); }
    }));
    dropdown.append(options);
    menu.append(dropdown);
    const mount = target.closest('dialog[open], .vitrine-editor') || document.body;
    mount.append(menu);
    menu.style.setProperty('--vitrine-menu-left',Math.max(8,Math.min(x,innerWidth-dropdown.offsetWidth-8))+'px');
    menu.style.setProperty('--vitrine-menu-top',Math.max(8,Math.min(y,innerHeight-dropdown.offsetHeight-8))+'px');
    const enabled = Array.from(options.querySelectorAll('button:not(:disabled)'));
    const highlight = button=>enabled.forEach(item=>item.classList.toggle('is-active',item===button));
    enabled.forEach(button=>{
      button.addEventListener('pointermove',()=>highlight(button));
      button.addEventListener('focus',()=>highlight(button));
    });
    enabled[0]?.focus({preventScroll:true});
    menu.addEventListener('keydown',key=>{
      key.stopPropagation();
      if (key.key==='Escape' || key.key==='Tab') {
        key.preventDefault();
        close(true);
      } else if (key.key==='ArrowDown' || key.key==='ArrowUp') {
        key.preventDefault();
        enabled.find(button=>button!==document.activeElement)?.focus({preventScroll:true});
      }
    });
  }
  document.addEventListener('contextmenu',event=>{
    const target=targetFor(event.target);
    if (!target) return;
    event.preventDefault();
    open(target,event.clientX,event.clientY);
  });
  document.addEventListener('keydown',event=>{
    if (event.key!=='ContextMenu' && !(event.key==='F10' && event.shiftKey && !event.altKey)) return;
    const target=targetFor(event.target);
    if (!target) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    const rect=target.getBoundingClientRect();
    open(target,rect.left,rect.bottom);
  },true);
  document.addEventListener('pointerdown',event=>{
    if (menu && !menu.contains(event.target)) close(true);
  },true);
  window.VitrineClipboardMenu=Object.freeze({opened:()=>Boolean(menu?.isConnected)});
  document.addEventListener('scroll',()=>close(),{capture:true,passive:true});
  window.addEventListener('resize',()=>close());
})();

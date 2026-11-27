'use strict';

// The Vitrine time picker also serves hour intervals in the slot editor.
(() => {
  const pad = value => String(value).padStart(2,'0');
  const optionMarkup = (count, valueAt) => Array.from({length:count},(_,index) => {
    const [label,name] = valueAt(index);
    return '<button type="button" class="ui-time-option" role="option" aria-selected="false" '+
      'tabindex="-1" data-value="'+index+'" aria-label="'+name+'">'+label+'</button>';
  }).join('');
  let active;

  function bindGridPreview(grid, show, initial=null) {
    let keyboardIndex = initial;
    grid.addEventListener('pointermove',event => {
      if (event.pointerType==='touch') return;
      const option = event.target.closest('button[data-value]');
      show(option ? Number(option.dataset.value) : keyboardIndex);
    });
    grid.addEventListener('pointerleave',() => show(keyboardIndex));
    return index => {
      keyboardIndex = index;
      show(index);
    };
  }

  function attach(panel, field, slot) {
    const wrapper = document.createElement('span');
    wrapper.className = slot ? 'ui-time-field ui-slot-field' : 'ui-time-field';
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'ui-time-toggle';
    toggle.setAttribute('aria-label',slot ? 'Открыть выбор периода' : 'Открыть выбор времени');
    toggle.setAttribute('aria-haspopup','dialog');
    toggle.setAttribute('aria-expanded','false');
    toggle.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';
    field.before(wrapper);
    wrapper.append(field,toggle);
    let popup;

    function close(refocus=false) {
      if (!popup) return;
      popup.remove();
      popup = null;
      active = null;
      toggle.setAttribute('aria-expanded','false');
      toggle.removeAttribute('aria-controls');
      if (refocus && field.isConnected) field.focus({preventScroll:true});
    }

    function focusOption(option) {
      const list = option.parentElement;
      list.querySelector('[tabindex="0"]')?.setAttribute('tabindex','-1');
      option.tabIndex = 0;
      option.focus({preventScroll:true});
      if (list.scrollHeight>list.clientHeight) option.scrollIntoView({block:'nearest'});
    }

    const mask = slot ? window.VitrineSlot : window.VitrineCompactInput.time;

    function commit(first,second) {
      const suffix = slot ? mask.read(field.value).slice(4) : '';
      field.value = mask.format(pad(first)+pad(second)+suffix);
      field.setSelectionRange(field.value.length,field.value.length);
      field.dispatchEvent(new InputEvent('input',{bubbles:true,inputType:'insertReplacementText'}));
      close(true);
    }

    function slotGrid() {
      // Reserve the same line height before a time is shown and between selections.
      const emptyPreview = '\u00a0';
      const formatHour = hour => pad(hour)+':00';
      popup.innerHTML = '<div class="ui-time-head"><span class="ui-time-heading">Слот:</span>'+
        '<span class="vitrine-sr-only" id="vitrine-slot-stage" aria-live="polite">Начало периода</span>'+
        '<output class="ui-time-preview">'+emptyPreview+'</output></div><div class="form-grid-4" role="listbox" aria-labelledby="vitrine-slot-stage">'+
        optionMarkup(24,hour=>[pad(hour),'Час '+pad(hour)])+'</div>';
      const grid = popup.querySelector('[role="listbox"]');
      const preview = popup.querySelector('output');
      let start = null;
      let visibleStart;
      let visibleEnd;
      let gesture = null;
      let suppressClick = false;

      function showRange(end) {
        const first = gesture?.dragging ? gesture.first : start;
        if (first===visibleStart && end===visibleEnd) return;
        visibleStart = first;
        visibleEnd = end;
        for (const option of grid.children) {
          const hour = Number(option.dataset.value);
          const edge = hour===first || hour===end;
          const between = first!==null && end!==null && (first<=end
            ? hour>first && hour<end
            : hour>first || hour<end);
          option.classList.toggle('is-selected',edge);
          option.classList.toggle('is-in-range',between);
        }
        preview.textContent = first===null
          ? (end===null ? emptyPreview : formatHour(end))
          : 'с '+formatHour(first)+' до '+(end===null ? '…' : formatHour(end));
      }

      const previewOption = bindGridPreview(grid,end => { if (!gesture) showRange(end); });
      function hourAtPointer(event) {
        const option = document.elementFromPoint(event.clientX,event.clientY)?.closest('button[data-value]');
        return option?.parentElement===grid ? Number(option.dataset.value) : null;
      }
      grid.addEventListener('pointerdown',event => {
        suppressClick = false;
        const option = event.target.closest('button[data-value]');
        if (!option || event.pointerType!=='mouse' || event.button!==0) return;
        event.preventDefault();
        gesture = {pointer:event.pointerId,first:Number(option.dataset.value),dragging:false};
        option.setPointerCapture(event.pointerId);
      });
      grid.addEventListener('pointermove',event => {
        if (gesture?.pointer!==event.pointerId) return;
        const hour = hourAtPointer(event);
        if (hour!==null && hour!==gesture.first) gesture.dragging = true;
        if (gesture.dragging) showRange(hour);
      });
      grid.addEventListener('pointerup',event => {
        if (gesture?.pointer!==event.pointerId || event.button!==0) return;
        const {first,dragging} = gesture;
        const end = hourAtPointer(event);
        gesture = null;
        suppressClick = dragging || end!==first;
        if (dragging && end!==null) commit(first,end);
        else if (suppressClick) showRange(null);
      });
      grid.addEventListener('lostpointercapture',event => {
        if (gesture?.pointer!==event.pointerId) return;
        gesture = null;
        showRange(null);
      });
      grid.addEventListener('click',event => {
        if (suppressClick && event.detail!==0) return;
        const option = event.target.closest('button[data-value]');
        if (!option) return;
        const hour = Number(option.dataset.value);
        if (start!==null) {
          commit(start,hour);
          return;
        }
        start = hour;
        option.setAttribute('aria-selected','true');
        popup.querySelector('#vitrine-slot-stage').textContent = 'Окончание периода';
        focusOption(option);
        previewOption(null);
      });
      return {options:grid.children,previewOption};
    }

    function timeGrid(currentTime,currentValue) {
      popup.innerHTML = '<div class="ui-time-head"><span class="ui-time-heading" id="vitrine-time-hours">Время:</span>'+
        '<output class="ui-time-preview"></output></div>'+
        '<div class="form-grid-4 ui-time-grid" role="listbox" aria-labelledby="vitrine-time-hours">'+optionMarkup(48,index=>{
          const label=pad(Math.floor(index/2))+':'+pad(index%2*30);
          return [label,label];
        })+'</div>';
      const grid = popup.querySelector('[role="listbox"]');
      popup.querySelector('output').textContent = currentValue || '\u00a0';
      const selected = grid.children[currentTime];
      if (selected) {
        selected.setAttribute('aria-selected','true');
        selected.classList.add('is-selected');
      }
      let highlighted;
      function showTime(index) {
        const option = grid.children[index];
        if (option===highlighted) return;
        if (highlighted!==selected) highlighted?.classList.remove('is-selected');
        option?.classList.add('is-selected');
        highlighted = option;
      }
      const previewOption = bindGridPreview(grid,showTime);
      grid.addEventListener('click',event => {
        const option = event.target.closest('button[data-value]');
        if (!option) return;
        const index=Number(option.dataset.value);
        commit(Math.floor(index/2),index%2*30);
      });
      return {options:grid.children,previewOption};
    }

    function open() {
      if (!field.isConnected || field.disabled) return;
      if (popup) { close(true); return; }
      active?.close();
      window.dispatchEvent(new Event('vitrine-time-opening'));
      const digits = mask.read(field.value);
      const name = slot ? 'Выбор периода' : 'Выбор времени';
      popup = document.createElement('div');
      popup.id = 'vitrine-time-menu';
      popup.className = 'ui-time-picker';
      popup.setAttribute('role','dialog');
      popup.setAttribute('aria-label',name);
      popup.setAttribute('popover','manual');
      const hour = digits.length>=4 ? Number(digits.slice(0,2)) : null;
      const minute = Number(digits.slice(2,4));
      const validTime = hour!==null && hour<24 && minute<60;
      const time = validTime && [0,30].includes(minute) ? hour*2+minute/30 : null;
      const currentValue = validTime ? mask.format(digits) : '';
      const {options,previewOption} = slot ? slotGrid() : timeGrid(time,currentValue);
      popup.addEventListener('keydown',event => {
        if (event.key==='Escape') {
          event.preventDefault();
          event.stopPropagation();
          close(true);
          return;
        }
        const option = event.target.closest('button[data-value]');
        if (!option) return;
        const list = option.parentElement;
        if (event.key==='Tab') {
          event.stopPropagation();
          close();
          toggle.focus({preventScroll:true});
          return;
        }
        const value = Number(option.dataset.value);
        const last = list.children.length-1;
        const next = {ArrowUp:value-4,ArrowDown:value+4,ArrowLeft:value-1,ArrowRight:value+1,Home:0,End:last}[event.key];
        if (next===undefined) return;
        event.preventDefault();
        event.stopPropagation();
        const targetIndex = Math.max(0,Math.min(last,next));
        focusOption(options[targetIndex]);
        previewOption(targetIndex);
      });
      panel.append(popup);
      active = {close,contains:target=>wrapper.contains(target)||popup?.contains(target)};
      toggle.setAttribute('aria-expanded','true');
      toggle.setAttribute('aria-controls',popup.id);
      popup.showPopover();
      const anchor = wrapper.getBoundingClientRect();
      popup.style.setProperty('--vitrine-picker-left',Math.max(8,Math.min(anchor.left,innerWidth-popup.offsetWidth-8))+'px');
      popup.style.setProperty('--vitrine-picker-top',Math.max(8,anchor.bottom+popup.offsetHeight+5<innerHeight-8
        ? anchor.bottom+5 : anchor.top-popup.offsetHeight-5)+'px');
      focusOption(options[slot ? hour : time??(hour??0)*2] ?? options[0]);
    }

    toggle.addEventListener('click',open);
    if (slot) field.addEventListener('keydown',event => {
      if (event.altKey && event.key==='ArrowUp') {
        event.preventDefault();
        event.stopPropagation();
        open();
      }
    });
  }

  window.addEventListener('vitrine-editor-opened',event => {
    const {panel,field,cell} = event.detail;
    const slot = cell?.dataset.kind==='slot';
    if (field && (slot || cell?.dataset.compactInput==='time')) attach(panel,field,slot);
  });
  window.addEventListener('vitrine-date-opening',()=>active?.close());
  window.addEventListener('vitrine-editor-closed',()=>active?.close());
  window.addEventListener('resize',()=>active?.close());
  document.addEventListener('pointerdown',event => {
    if (active && !active.contains(event.target)) active.close();
  });
})();

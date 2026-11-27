'use strict';
(() => {
  if (window.VitrineSelect) return;
  const instances = new Set();
  const pasteOptions = new WeakMap();
  let sequence = 0;
  document.addEventListener('click', event => {
    for (const instance of instances) if (!instance.element.contains(event.target)) instance.close();
  });
  const observer = new MutationObserver(() => {
    for (const instance of instances) {
      if (instance.element.isConnected) instance.attached = true;
      else if (instance.attached) instances.delete(instance);
    }
  });
  observer.observe(document.documentElement, {childList:true,subtree:true});

  window.VitrineSelect = Object.freeze({
    canPasteOption: field => pasteOptions.has(field),
    pasteOption: (field,value) => pasteOptions.get(field)?.(value) ?? false,
    vehicleSearchKey: value => value.toUpperCase()
      .replace(/^\s*([АВЕІКМНОРСТХABEIKMHOPCTX]{2})\s*\p{Pd}\s*([0-9])\s+([0-9]{4})\s*$/u,'$1 $3-$2')
      .replace(/[АВЕІКМНОРСТУХ]/g,
      letter => 'ABEIKMHOPCTYX'['АВЕІКМНОРСТУХ'.indexOf(letter)]).replace(/[\s\p{Pd}]+/gu,''),
    create(sourceOptions, initialValue = '', {searchable = false, searchKey = value => value, showLabels = false, showColorSwatches = false} = {}) {
      const withSwatches = showColorSwatches || sourceOptions.some(option => Object.hasOwn(option, 'color'));
      const normalizeOptions = items => items.map(option => ({value:String(option.value ?? ''),label:String(option.label ?? option.value ?? ''),color:option.color,search:String(option.search ?? '')}));
      let options = normalizeOptions(sourceOptions);
      const id = 'vitrine-select-' + (++sequence);
      const element = document.createElement('div');
      element.className = 'custom-select vitrine-select';
      const field = document.createElement('input');
      field.type = 'text'; field.readOnly = !searchable;
      field.className = 'cs-trigger vitrine-select-field';
      const displayValue = option => showLabels && option.value !== '' ? option.label : option.value;
      const initialOption = options.find(option=>option.value === String(initialValue));
      const initialDisplay = initialOption ? displayValue(initialOption) : String(initialValue);
      field.value = initialDisplay;
      field.placeholder = options.find(option => option.value === '')?.label || 'Не выбран';
      field.setAttribute('role','combobox'); field.setAttribute('aria-autocomplete',searchable ? 'list' : 'none');
      field.setAttribute('aria-haspopup','listbox'); field.setAttribute('aria-controls',id);
      field.setAttribute('aria-expanded','false'); field.autocomplete = 'off';
      const arrow = document.createElementNS('http://www.w3.org/2000/svg','svg');
      arrow.setAttribute('class','cs-chevron vitrine-select-chevron');
      arrow.setAttribute('width','12'); arrow.setAttribute('height','12'); arrow.setAttribute('viewBox','0 0 16 16'); arrow.setAttribute('aria-hidden','true');
      const path = document.createElementNS('http://www.w3.org/2000/svg','path');
      path.setAttribute('d','m4 6 4 4 4-4'); path.setAttribute('fill','none'); path.setAttribute('stroke','currentColor'); path.setAttribute('stroke-width','1.5'); arrow.append(path);
      const dropdown = document.createElement('div'); dropdown.className = 'cs-dropdown';
      const list = document.createElement('div'); list.className = 'cs-options'; list.id = id;
      list.setAttribute('role','listbox'); list.setAttribute('aria-label','Варианты значения');
      dropdown.append(list); element.append(field,arrow,dropdown);
      const empty = document.createElement('div');
      if (searchable) {
        empty.className = 'vitrine-select-empty';
        empty.setAttribute('role','status');
        empty.textContent = 'Совпадений нет';
        empty.hidden = true;
        dropdown.append(empty);
      }
      const normalize = value => value.trim().replace(/\s+/g,' ').toLocaleLowerCase('ru').replace(/ё/g,'е');
      let opened = false, activeIndex = -1, typed = '', typedAt = 0, filtering = false;
      let visible = options.map((_,index)=>index);
      const renderOptions = () => options.map((option,index) => {
        const node = document.createElement('div'); node.className = 'cs-option'; node.id = id + '-' + index;
        node.setAttribute('role','option'); node.setAttribute('aria-selected',String(displayValue(option) === field.value));
        if (withSwatches) {
          const chip = document.createElement('span');
          chip.className = 'vitrine-select-chip';
          if (/^#[0-9a-f]{6}$/i.test(option.color || '')) window.VitrineDisplay.paint(chip,option.color);
          chip.setAttribute('aria-hidden','true');
          node.append(chip);
        }
        const label = document.createElement('span'); label.className = 'cs-value'; label.textContent = option.label; node.append(label);
        node.addEventListener('mousedown',event => event.preventDefault());
        node.addEventListener('click',event => { event.stopPropagation(); choose(index); });
        node.addEventListener('pointermove',() => activate(index));
        list.append(node); return node;
      });
      let nodes = renderOptions();
      function selectedIndex() { return options.findIndex(option => displayValue(option) === field.value); }
      function activate(index) {
        activeIndex = index;
        nodes.forEach((node,i) => {
          const selected = displayValue(options[i]) === field.value;
          node.classList.toggle('is-selected',selected); node.setAttribute('aria-selected',String(selected));
          node.classList.toggle('is-active',i === index);
        });
        if (nodes[index]) { field.setAttribute('aria-activedescendant',nodes[index].id); nodes[index].scrollIntoView({block:'nearest'}); }
        else field.removeAttribute('aria-activedescendant');
      }
      function close() {
        opened = false; element.classList.remove('is-open'); field.classList.remove('is-open'); dropdown.classList.remove('is-open');
        field.setAttribute('aria-expanded','false'); field.removeAttribute('aria-activedescendant');
      }
      function filter(query) {
        const term = normalize(query);
        const key = normalize(searchKey(query));
        visible = [];
        for (const [index,option] of options.entries()) {
          const match = !term || (option.value !== '' && (normalize(option.label).includes(term) || (key && normalize(option.search).includes(key))));
          nodes[index].hidden = !match;
          if (match) visible.push(index);
        }
        empty.hidden = visible.length > 0;
      }
      function open() {
        if (field.disabled || !options.length) return;
        for (const instance of instances) if (instance.element !== element) instance.close();
        opened = true; element.classList.add('is-open'); field.classList.add('is-open'); dropdown.classList.add('is-open');
        field.setAttribute('aria-expanded','true');
        const rect = field.getBoundingClientRect();
        if (searchable) filter(filtering ? field.value : '');
        const expectedHeight = Math.min(220,Math.max(1,visible.length) * 30 + 2);
        const up = window.innerHeight - rect.bottom < expectedHeight && rect.top > expectedHeight;
        dropdown.classList.toggle('vitrine-select-up',up);
        const selected = selectedIndex();
        activate(visible.includes(selected) ? selected : (visible[0] ?? -1));
      }
      function choose(index) {
        if (!options[index]) return;
        field.value = displayValue(options[index]); filtering = false; activate(index); close();
        if (!searchable) field.dispatchEvent(new Event('input',{bubbles:true})); field.dispatchEvent(new Event('change',{bubbles:true}));
        field.focus({preventScroll:true});
      }
      if (!searchable) pasteOptions.set(field,value => {
        const query = normalize(value);
        const matches = options.filter(option=>normalize(option.label)===query || normalize(option.value)===query);
        if (matches.length!==1) return false;
        choose(options.indexOf(matches[0]));
        return true;
      });
      field.addEventListener('click',event => {
        event.stopPropagation();
        if (!opened) open();
        else if (!searchable) close();
      });
      if (searchable) field.addEventListener('input', () => { filtering = true; open(); });
      field.addEventListener('keydown',event => {
        if (event.isComposing) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
          event.preventDefault(); event.stopPropagation();
          if (!opened) open();
          else {
            const next = visible.indexOf(activeIndex) + (event.key === 'ArrowDown' ? 1 : -1);
            activate(visible[Math.max(0,Math.min(visible.length-1,next))] ?? -1);
          }
        } else if (opened && event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); choose(activeIndex); }
        else if (opened && event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
        else if (event.key === 'Tab') close();
        else if (!searchable && opened && (event.key === 'Home' || event.key === 'End')) { event.preventDefault(); event.stopPropagation(); activate(event.key === 'Home' ? 0 : options.length-1); }
        else if (!searchable && event.key === ' ' && !opened) { event.preventDefault(); event.stopPropagation(); open(); }
        else if (!searchable && event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
          event.preventDefault(); event.stopPropagation();
          const now = performance.now(); typed = now - typedAt < 700 ? typed + event.key : event.key; typedAt = now;
          if (!opened) open();
          const match = options.findIndex(option => option.label.toLocaleLowerCase('ru').startsWith(typed.toLocaleLowerCase('ru')));
          if (match >= 0) activate(match);
        }
      });
      element.addEventListener('focusout',event => { if (!element.contains(event.relatedTarget)) close(); });
      const instance = {element,close,attached:false}; instances.add(instance);
      function resolveValue() {
        if (showLabels) {
          const selected = options[selectedIndex()];
          if (selected) return selected.value;
        }
        if (field.value === initialDisplay) return String(initialValue);
        if (!searchable) return field.value;
        const query = field.value.trim();
        if (!query) return '';
        const exact = options.find(option=>option.value === query);
        if (exact) return exact.value;
        const matches = options.filter(option=>normalize(option.value) === normalize(query));
        return matches.length === 1 ? matches[0].value : null;
      }
      function replaceOptions(items, selectedValue) {
        close();
        options = normalizeOptions(items);
        const selected = options.find(option=>option.value===selectedValue);
        if (selected) field.value = displayValue(selected);
        list.replaceChildren();
        nodes = renderOptions();
        visible = options.map((_,index)=>index);
        field.placeholder = options.find(option => option.value === '')?.label || 'Не выбран';
        empty.hidden = true;
      }
      return {element,field,resolveValue,replaceOptions};
    }
  });
})();

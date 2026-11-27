'use strict';

// Calendar markup and controls follow UIDatePicker in the Vitrine design demo.
(() => {
  const examples = JSON.parse(document.getElementById('vitrine-preview-calendars')?.content || '{}');
  const today = new Intl.DateTimeFormat('sv-SE', {
    timeZone:'Europe/Moscow', year:'numeric', month:'2-digit', day:'2-digit',
  }).format(new Date());
  const months = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
  const weekdays = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
  const pad = value => String(value).padStart(2,'0');
  const fullYear = new Set(['birth-date','passport-issued','license-issued','license-date']);
  const daySteps = {ArrowLeft:-1,ArrowRight:1,ArrowUp:-7,ArrowDown:7};
  let dismiss = null;

  function makeDate(year, month, day) {
    const date = new Date(2000,0,1,12);
    date.setFullYear(year,month,day);
    return date;
  }

  function iso(date) {
    return String(date.getFullYear()).padStart(4,'0')+'-'+pad(date.getMonth()+1)+'-'+pad(date.getDate());
  }

  function parse(value) {
    const parts = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(value);
    if (!parts || Number(parts[1])===0) return null;
    const date = makeDate(Number(parts[1]),Number(parts[2])-1,Number(parts[3]));
    return iso(date)===value ? date : null;
  }

  function format(value, longYear=false) {
    const [year,month,day] = value.split('-');
    return [day,month,longYear ? year : year.slice(-2)].join('.');
  }

  function read(field) {
    const parts = /([0-9]{2})\.([0-9]{2})\.([0-9]{2,4})$/.exec(field.value);
    if (!parts) return '';
    const [,day,month,year] = parts;
    const value = (year.length===2 ? '20'+year : year)+'-'+month+'-'+day;
    return parse(value) ? value : '';
  }

  function attach(panel, field, config) {
    if (!field || field.readOnly || field.disabled) return;
    const slotWrapper = config.kind==='slot' ? field.closest('.ui-slot-field') : null;
    const wrapper = slotWrapper ?? document.createElement('span');
    if (!slotWrapper) wrapper.className = 'ui-date-field';
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'ui-date-toggle';
    toggle.setAttribute('aria-label','Открыть календарь');
    toggle.setAttribute('aria-haspopup','dialog');
    toggle.setAttribute('aria-expanded','false');
    toggle.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M8 15h2M14 15h2"/></svg>';
    const enclosingLabel = field.closest('label');
    if (enclosingLabel) {
      const group = document.createElement('div');
      group.className = enclosingLabel.className;
      enclosingLabel.replaceWith(group);
      field.id ||= 'vitrine-dropoff-date';
      enclosingLabel.htmlFor = field.id;
      enclosingLabel.className = 'field-label';
      group.append(enclosingLabel,wrapper);
    } else if (!slotWrapper) {
      field.before(wrapper);
    }
    if (slotWrapper) wrapper.append(toggle);
    else wrapper.append(field,toggle);

    const isSlot = config.kind==='slot';
    const originalPlaceholder = field.placeholder;
    const slotHours = () => field.value.replace(/[^0-9]/g,'').slice(0,4);
    let pendingDay = '';
    let popup = null;
    let view;
    let bounds = config;

    function update(value, inputType='insertReplacementText') {
      field.value = value;
      field.setSelectionRange(value.length,value.length);
      field.dispatchEvent(new InputEvent('input',{bubbles:true,inputType}));
    }

    if (isSlot) {
      field.addEventListener('input',() => {
        const digits = window.VitrineSlot.read(field.value) || field.value.replace(/[^0-9]/g,'');
        if (!pendingDay || digits.length<4) return;
        const date = pendingDay;
        pendingDay = '';
        field.placeholder = originalPlaceholder;
        if (digits.length===4) update(window.VitrineSlot.format(digits+format(date).replaceAll('.','')));
      });
    }

    function allowed(value) {
      return (!bounds.min || value>=bounds.min) && (!bounds.max || value<=bounds.max);
    }

    function bounded(date) {
      const value = iso(date);
      if (bounds.min && value<bounds.min) return parse(bounds.min);
      if (bounds.max && value>bounds.max) return parse(bounds.max);
      return date;
    }

    function select(value) {
      if (value && !allowed(value)) return;
      if (isSlot) {
        const hours = slotHours();
        pendingDay = value && hours.length<4 ? value : '';
        field.placeholder = pendingDay ? 'Введите часы · '+format(pendingDay) : originalPlaceholder;
        if (!pendingDay) {
          const suffix = value ? format(value).replaceAll('.','') : '';
          update(window.VitrineSlot.format(hours+suffix),value ? 'insertReplacementText' : 'deleteContent');
        }
      } else {
        update(value ? format(value,fullYear.has(config.kind)) : '');
      }
      close(true);
    }

    function close(refocus=false) {
      if (!popup) return;
      popup.remove();
      popup = null;
      dismiss = null;
      toggle.setAttribute('aria-expanded','false');
      toggle.removeAttribute('aria-controls');
      if (refocus && field.isConnected) field.focus({preventScroll:true});
    }

    function position() {
      const anchor = wrapper.getBoundingClientRect();
      const below = anchor.bottom+5;
      const above = anchor.top-popup.offsetHeight-5;
      popup.style.setProperty('--vitrine-picker-left',Math.max(8,Math.min(anchor.left,innerWidth-popup.offsetWidth-8))+'px');
      popup.style.setProperty('--vitrine-picker-top',Math.max(8,below+popup.offsetHeight<innerHeight-8 ? below : above)+'px');
    }

    function draw(focusDay=false) {
      const year = view.getFullYear();
      const month = view.getMonth();
      const selected = pendingDay || read(field);
      const first = makeDate(year,month,1);
      const start = makeDate(year,month,1-((first.getDay()+6)%7));
      popup.querySelector('select').value = String(month);
      popup.querySelector('[aria-label="Год"]').value = String(year);
      const grid = popup.querySelector('.ui-calendar-grid');
      const headings = weekdays.map(day => {
        const caption = document.createElement('span');
        caption.className = 'ui-calendar-weekday';
        caption.textContent = day;
        return caption;
      });
      const days = Array.from({length:42},(_,index) => {
        const date = makeDate(start.getFullYear(),start.getMonth(),start.getDate()+index);
        const value = iso(date);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ui-calendar-day';
        button.classList.toggle('is-outside',date.getMonth()!==month);
        button.classList.toggle('is-selected',value===selected);
        button.classList.toggle('is-today',value===today);
        button.dataset.day = value;
        button.setAttribute('aria-label',format(value,true));
        button.setAttribute('aria-pressed',String(value===selected));
        button.textContent = String(date.getDate());
        button.disabled = !allowed(value);
        button.tabIndex = -1;
        return button;
      });
      grid.replaceChildren(...headings,...days);
      const current = days.find(button => button.dataset.day===iso(view) && !button.disabled);
      if (current) {
        current.tabIndex = 0;
        if (focusDay) current.focus({preventScroll:true});
      }
    }

    function moveMonth(amount, day=view.getDate()) {
      const target = makeDate(view.getFullYear(),view.getMonth()+amount,1);
      const last = makeDate(target.getFullYear(),target.getMonth()+1,0).getDate();
      return makeDate(target.getFullYear(),target.getMonth(),Math.min(last,day));
    }

    function navigate(event) {
      if (event.key==='Escape') {
        event.preventDefault();
        event.stopPropagation();
        close(true);
        return;
      }
      const current = parse(event.target.dataset.day || '');
      if (!current) return;
      const offset = {Home:-((current.getDay()+6)%7),End:6-((current.getDay()+6)%7),...daySteps}[event.key];
      if (offset!==undefined) {
        view = bounded(makeDate(current.getFullYear(),current.getMonth(),current.getDate()+offset));
      } else if (event.key==='PageUp' || event.key==='PageDown') {
        view = bounded(moveMonth(event.key==='PageUp' ? -1 : 1,current.getDate()));
      } else return;
      event.preventDefault();
      event.stopPropagation();
      draw(true);
    }

    function open() {
      if (popup) { close(true); return; }
      if (field.disabled) return;
      dismiss?.close();
      window.dispatchEvent(new Event('vitrine-date-opening'));
      bounds = config;
      if (config.kind==='license-issued' || config.kind==='license-date') {
        const expires=config.kind==='license-date';
        const peer=panel.querySelector('[data-calendar-kind="'+(expires ? 'license-issued' : 'license-date')+'"]');
        const boundary=peer && read(peer);
        if (boundary) bounds={...config,[expires ? 'min' : 'max']:boundary};
      }
      view = bounded(parse(pendingDay || read(field)) || parse(today));
      popup = document.createElement('div');
      popup.id = 'vitrine-date-menu';
      popup.className = 'ui-calendar';
      popup.setAttribute('role','dialog');
      popup.setAttribute('aria-label','Выбор даты');
      popup.setAttribute('popover','manual');
      popup.innerHTML = '<div class="ui-calendar-head"><button class="ui-calendar-nav" type="button" data-month-step="-1" aria-label="Предыдущий месяц">‹</button><select class="field-select" aria-label="Месяц"></select><input class="field-input" type="number" min="1" max="9999" aria-label="Год"><button class="ui-calendar-nav" type="button" data-month-step="1" aria-label="Следующий месяц">›</button></div><div class="ui-calendar-grid" role="group" aria-label="Дни месяца"></div><div class="ui-calendar-foot"><button type="button" data-date-clear>Очистить</button><button type="button" data-date-today>Сегодня</button><button type="button" data-date-close>Закрыть</button></div>';
      for (const [index,month] of months.entries()) popup.querySelector('select').add(new Option(month,String(index)));
      popup.querySelector('[data-date-today]').disabled = !allowed(today);
      popup.querySelector('[data-date-clear]').disabled = config.required===true;
      panel.append(popup);
      toggle.setAttribute('aria-expanded','true');
      toggle.setAttribute('aria-controls',popup.id);
      dismiss = {contains:target=>wrapper.contains(target)||popup?.contains(target),close};
      popup.showPopover();
      draw(true);
      position();
      popup.addEventListener('keydown',navigate);
      popup.addEventListener('click',event => {
        const button = event.target.closest('button:not(:disabled)');
        if (!button) return;
        if (button.dataset.day) select(button.dataset.day);
        else if ('dateClear' in button.dataset) select('');
        else if ('dateToday' in button.dataset) select(today);
        else if ('dateClose' in button.dataset) close(true);
        else {
          view = bounded(moveMonth(Number(button.dataset.monthStep)));
          draw();
        }
      });
      popup.addEventListener('change',event => {
        if (event.target.tagName==='SELECT') {
          view = bounded(makeDate(view.getFullYear(),Number(event.target.value),1));
        } else {
          const year = Number(event.target.value);
          if (!Number.isInteger(year) || year<1 || year>9999) return;
          view = bounded(makeDate(year,view.getMonth(),1));
        }
        draw();
      });
    }

    toggle.addEventListener('click',open);
    field.addEventListener('keydown',event => {
      if (event.altKey && event.key==='ArrowDown') {
        event.preventDefault();
        event.stopPropagation();
        open();
      }
    });
  }

  window.addEventListener('vitrine-editor-opened',event => {
    const {row,panel,cell,field,dropoffDate} = event.detail;
    if (!field) return;
    for (const input of panel.querySelectorAll('[data-calendar-kind]')) {
      const kind=input.dataset.calendarKind;
      const max=['birth-date','passport-issued'].includes(kind) ? today : '9999-12-31';
      attach(panel,input,{kind,min:'1900-01-01',max});
    }
    const shiftToday = days => {
      const day = parse(today);
      day.setDate(day.getDate()+days);
      return iso(day);
    };
    let config = examples[row];
    if (!config && cell.dataset.kind==='slot') config={kind:'slot',min:today,max:shiftToday(30)};
    if (!config && cell.dataset.compactInput==='date') {
      config = !document.body.dataset.vitrineCatalog && cell.dataset.column==='F'
        ? {kind:'date',min:shiftToday(-10),max:shiftToday(60)}
        : {kind:'date',min:'2000-01-01',max:'2099-12-31',required:document.body.dataset.vitrineCatalogKind==='margin-rules'};
    }
    if (!config && dropoffDate) config={kind:'dropoff',min:'2000-01-01',max:'2099-12-31'};
    if (config) attach(panel,config.kind==='dropoff' ? dropoffDate : field,config);
  });
  window.addEventListener('vitrine-time-opening',()=>dismiss?.close());
  window.addEventListener('vitrine-editor-closed',()=>dismiss?.close());
  window.addEventListener('resize',()=>dismiss?.close());
  document.addEventListener('pointerdown',event => {
    if (dismiss && !dismiss.contains(event.target)) dismiss.close();
  });
})();

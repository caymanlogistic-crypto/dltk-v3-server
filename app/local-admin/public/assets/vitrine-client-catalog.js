'use strict';
(() => {
  const toolbar = document.querySelector('.vitrine-catalog-toolbar[data-catalog-id]');
  if (!toolbar) return;
  const catalogId = Number(toolbar.dataset.catalogId);
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const path = url => window.DltkPaths?.path(url) ?? url;
  const notice = (message,tone) => window.PlanexNotifications.show({tone,message});
  const catalogUrl = '/api/workspace/catalogs?id='+catalogId;
  const detailsDialog = document.querySelector('[data-directory-details-dialog]');

  async function request(url,body) {
    const options = {credentials:'same-origin'};
    if (body) Object.assign(options,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)});
    const response = await fetch(path(url),options);
    const result = await response.json();
    if (!response.ok || result.error) throw new Error(result.error?.message || 'Справочник недоступен.');
    return result;
  }

  for (const button of toolbar.querySelectorAll('[data-directory-details]')) {
    button.addEventListener('click',async ()=>{
      const unkeyed = button.hasAttribute('data-unkeyed');
      const row = document.querySelector('#vitrine-rows .is-current-cell');
      if (!unkeyed && !row) { notice('Выберите сохранённую строку.','info'); return; }
      button.disabled = true;
      try {
        const result = await request(catalogUrl+(unkeyed ? '&unkeyed=1' : '&entryId='+encodeURIComponent(row.dataset.row)));
        const body = detailsDialog.querySelector('.modal-body');
        body.replaceChildren();
        if (!result.occurrences.length) body.textContent = 'Для записи нет импортированных реквизитов.';
        for (const occurrence of result.occurrences) {
          const heading = document.createElement('strong');
          heading.textContent = 'Строка источника '+occurrence.row;
          const list = document.createElement('dl');
          result.columns.forEach((label,index)=>{
            const term = document.createElement('dt');
            term.textContent = label;
            const value = document.createElement('dd');
            value.textContent = occurrence.values[index] || '—';
            list.append(term,value);
          });
          body.append(heading,list);
        }
        detailsDialog.showModal();
      } catch (error) { notice(error.message,'danger'); }
      finally { button.disabled = false; }
    });
  }
  detailsDialog?.querySelector('[data-close]').addEventListener('click',()=>detailsDialog.close());

  const cardButton = toolbar.querySelector('[data-standalone-card-open]');
  const standaloneCard = document.querySelector('[data-standalone-card]');
  if (cardButton && standaloneCard) {
    const body = standaloneCard.querySelector('[data-standalone-card-body]');
    const title = standaloneCard.querySelector('h2');
    const form = standaloneCard.querySelector('form');
    const save = form.querySelector('[type="submit"]');
    const close = form.querySelector('[data-close]');
    const lists = {tractors:'Тягачи', trailers:'Полуприцепы'};
    let card, saving = false;
    const make = (tag, text) => {
      const el = document.createElement(tag);
      if (text !== undefined) el.textContent = text;
      return el;
    };
    function details(fields) {
      if (!fields.length) return;
      const list = make('dl');
      for (const {label,value} of fields) list.append(make('dt',label),make('dd',value));
      body.append(list);
    }
    function renderCarrier() {
      details([
        {label:'Официальное наименование',value:card.legalName},
        {label:'Рабочее название',value:card.workingName},
      ].filter(field=>field.value));
      for (const [key,label] of Object.entries(lists)) {
        const section = make('section');
        section.append(make('h3',label));
        const scroller = make('div');
        scroller.className = 'vitrine-client-relations-scroll';
        const table = make('table');
        table.className = 'vitrine-client-relations-table';
        table.setAttribute('aria-label',label);
        const head = table.createTHead().insertRow();
        const actions = make('th');
        actions.scope = 'col';
        actions.setAttribute('aria-label','Действия');
        head.append(actions);
        for (const text of ['Госномер','Марка']) {
          const cell = make('th',text);
          cell.scope = 'col';
          head.append(cell);
        }
        const entries = table.createTBody();
        entries.dataset.transportList = key;
        function add(row, focus = false) {
          const line = entries.insertRow();
          const actions = line.insertCell();
          if (card.canEdit) {
            const remove = make('button','×');
            remove.type = 'button';
            remove.className = 'vitrine-row-menu-trigger';
            remove.setAttribute('aria-label','Удалить');
            remove.addEventListener('click',()=>{
              const next = line.nextElementSibling || line.previousElementSibling;
              line.remove();
              (next?.querySelector('input') || section.querySelector('button:last-child'))?.focus({preventScroll:true});
            });
            actions.append(remove);
          }
          for (const [field,caption,limit] of [['plate','Госномер',40],['brand','Марка',160]]) {
            const cell = line.insertCell();
            if (!card.canEdit) { cell.textContent = row[field] || '—'; continue; }
            const input = make('input');
            input.className = 'field-input';
            input.size = 12;
            input.value = row[field];
            input.maxLength = limit;
            input.name = field;
            input.required = field==='plate';
            input.setAttribute('aria-label',caption);
            cell.append(input);
          }
          if (card.canEdit && focus) line.querySelector('input').focus();
        }
        card[key].forEach(row=>add(row));
        scroller.append(table);
        section.append(scroller);
        if (card.canEdit) {
          const button = make('button',key==='tractors' ? 'Добавить тягач' : 'Добавить полуприцеп');
          button.type = 'button';
          button.className = 'btn btn-secondary';
          button.addEventListener('click',()=>add({plate:'',brand:''},true));
          section.append(button);
        }
        if (!card.canEdit && !card[key].length) section.append(make('p','Список пуст.'));
        body.append(section);
      }
    }
    cardButton.addEventListener('click',async ()=>{
      const row = document.querySelector('#vitrine-rows .is-current-cell')?.dataset.row;
      if (!row || Number(row)<=0) { notice('Выберите сохранённую строку.','info'); return; }
      cardButton.disabled = true;
      try {
        card = await request(catalogUrl+'&carrierFleet='+encodeURIComponent(row));
        title.textContent = card.name;
        body.replaceChildren();
        renderCarrier();
        save.hidden = !card.canEdit;
        standaloneCard.showModal();
      } catch (error) { notice(error.message,'danger'); }
      finally { cardButton.disabled = false; }
    });
    close.addEventListener('click',()=>standaloneCard.close());
    standaloneCard.addEventListener('cancel',event=>{ if (saving) event.preventDefault(); });
    standaloneCard.addEventListener('close',()=>cardButton.focus({preventScroll:true}));
    standaloneCard.addEventListener('keydown',event=>{
      if (event.key==='Tab' && !event.defaultPrevented) window.VitrineClientRelationsKeyboard.moveTab(standaloneCard,event);
    });
    form.addEventListener('submit',async event=>{
      event.preventDefault();
      if (saving || !card?.canEdit) return;
      const payload = {action:'save_carrier_fleet',catalogId,entryId:card.id,revision:card.revision};
      for (const key of Object.keys(lists)) payload[key] = Array.from(body.querySelector('[data-transport-list="'+key+'"]').rows,row=>({
        plate:row.querySelector('[name="plate"]').value,brand:row.querySelector('[name="brand"]').value,
      }));
      saving = save.disabled = close.disabled = true;
      try {
        await request('/api/workspace/catalogs',payload);
        standaloneCard.close();
        notice('Транспорт сохранён.','success');
      } catch (error) { notice(error.message,'danger'); }
      finally { saving = save.disabled = close.disabled = false; }
    });
  }

  const sync = document.getElementById('client-catalog-sync');
  sync?.addEventListener('click',async ()=>{
    sync.disabled = true;
    try {
      await request('/api/workspace/catalogs',{action:'refresh_catalog_source',catalogId});
      try { sessionStorage.setItem('dltk-v3.directory-row-feedback','Справочник обновлён.'); } catch {}
      location.reload();
    } catch (error) { notice(error.message,'danger'); sync.disabled = false; }
  });
})();

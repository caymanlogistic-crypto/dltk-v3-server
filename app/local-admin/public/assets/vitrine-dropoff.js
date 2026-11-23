'use strict';
(() => {
  const catalog = JSON.parse(document.getElementById('vitrine-dropoff-options')?.content || '{}');
  function supports(cell) {
    return cell.dataset.column==='M' && Number(cell.dataset.catalogId)>0 &&
      document.body.dataset.workspaceTable==='vyvoz' && cell.parentElement.hasAttribute('data-dropoff-details');
  }
  function details(cell) {
    return JSON.parse(cell.parentElement.dataset.dropoffDetails || '{}');
  }
  function attach(panel,control,cell) {
    const initial=details(cell);
    const group=document.createElement('div');
    group.className='vitrine-editor-field-group';
    const label=document.createElement('label');
    label.className='field-label';
    label.htmlFor=control.field.id;
    label.textContent='Точка сдачи';
    control.field.setAttribute('aria-label','Точка сдачи');
    const point=document.createElement('div');
    point.className='vitrine-editor-note vitrine-dropoff-profile';
    const showPoint=()=>{
      const key=(control.resolveValue()||'').trim().replace(/\s+/g,' ').toLocaleLowerCase('ru');
      const profile=catalog[key];
      const lines=[profile?.B,profile?.C,profile?.D].filter(Boolean);
      point.textContent=lines.join('\n');
      point.hidden=!lines.length;
    };
    control.field.addEventListener('input',showPoint);
    control.field.addEventListener('change',showPoint);
    group.append(label,control.element,point);
    panel.append(group);
    showPoint();

    const fields={};
    for (const [name,title] of [['release','Релиз сдачи'],['booking','Букинг'],['validUntil','Действует до']]) {
      const wrap=document.createElement('label');
      wrap.className='vitrine-editor-field-group';
      const heading=document.createElement('span');
      heading.className='field-label';
      heading.textContent=title;
      const field=document.createElement('input');
      field.className='field-input vitrine-editor-field';
      field.type='text';
      field.maxLength=name==='validUntil' ? 8 : 160;
      field.value=initial[name]??'';
      field.autocomplete='off';
      if (name==='validUntil') window.VitrineCompactInput.date.bind(field);
      fields[name]=field;
      wrap.append(heading,field);
      panel.append(wrap);
    }
    const legacy=initial.legacy || (!Number(cell.dataset.referenceId) ? cell.textContent : '');
    if (legacy) {
      const previous=document.createElement('details');
      previous.className='vitrine-dropoff-profile';
      const summary=document.createElement('summary');
      summary.textContent='Исходная запись';
      const value=document.createElement('div');
      value.textContent=legacy;
      previous.append(summary,value);
      panel.append(previous);
    }
    return {
      fields:Object.values(fields),
      values:()=>Object.fromEntries(Object.entries(fields).map(([name,field])=>[name,field.value])),
      dirty:()=>Object.entries(fields).some(([name,field])=>field.value!==(initial[name]??'')),
    };
  }
  function update(cell,result) {
    if (!result.dropoffDetails) return;
    const previous=details(cell);
    if (!previous.legacy && !Number(cell.dataset.referenceId) && cell.textContent) previous.legacy=cell.textContent;
    cell.parentElement.dataset.dropoffDetails=JSON.stringify({...previous,...result.dropoffDetails});
  }
  window.VitrineDropoff=Object.freeze({supports,attach,update});
})();

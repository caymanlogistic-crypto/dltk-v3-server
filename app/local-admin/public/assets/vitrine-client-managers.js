'use strict';
(() => {
  const dialog=document.getElementById('client-managers-dialog');
  if (!dialog) return;
  const list=dialog.querySelector('[data-client-manager-list]');
  const picker=dialog.querySelector('[data-client-manager-picker]');
  const save=dialog.querySelector('[data-client-manager-save]');
  const cancel=dialog.querySelector('[data-client-manager-cancel]');
  const title=dialog.querySelector('h2');
  const csrf=document.querySelector('meta[name="csrf-token"]').content;
  let opener,clientId,data,ids,busy=false,restoreFocus=true;
  const changed=()=>ids.join(',')!==data.managerIds.join(',');
  const notice=(message,tone='danger')=>window.PlanexNotifications.show({message,tone});
  function position() {
    if (dialog.open && opener?.isConnected) window.VitrineGrid.positionPanel(dialog,opener);
  }
  async function request(body=null) {
    const path='/api/workspace/catalogs'+(body?'':'?clientManagers='+clientId);
    const options={credentials:'same-origin'};
    if (body) Object.assign(options,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({action:'save_client_managers',clientId,...body})});
    const response=await fetch(window.DltkPaths?.path(path)??path,options);
    const result=await response.json();
    if (!response.ok || result.error) throw new Error(result.error?.message||'Не удалось обновить список менеджеров.');
    return result;
  }
  function render() {
    const byId=new Map(data.options.map(option=>[option.id,option]));
    list.replaceChildren();
    for (const id of ids) {
      const option=byId.get(id);
      if (!option) continue;
      const item=document.createElement('li');
      const label=document.createElement('span');
      label.textContent=option.label+(option.active?'':' (архив)');
      item.append(label);
      if (data.canEdit) {
        const remove=document.createElement('button');
        remove.className='btn btn-secondary';
        remove.type='button';
        remove.textContent='Убрать';
        remove.setAttribute('aria-label','Убрать '+option.label);
        remove.addEventListener('click',()=>{
          if (busy) return;
          ids=ids.filter(value=>value!==id);
          render();
          (picker.querySelector('input')??save).focus({preventScroll:true});
        });
        item.append(remove);
      }
      list.append(item);
    }
    if (!list.children.length) {
      const empty=document.createElement('li');
      empty.textContent='Менеджеры не назначены.';
      list.append(empty);
    }
    picker.replaceChildren();
    const available=data.options.filter(option=>option.active&&!ids.includes(option.id));
    if (data.canEdit && available.length) {
      const control=window.VitrineSelect.create([{value:'',label:'Добавить менеджера'},
        ...available.map(option=>({value:option.id,label:option.label}))],'',{searchable:true});
      control.field.setAttribute('aria-label','Добавить менеджера');
      control.field.addEventListener('change',()=>{
        const id=Number(control.resolveValue());
        if (busy || !available.some(option=>option.id===id)) return;
        ids.push(id);
        render();
        (picker.querySelector('input')??save).focus({preventScroll:true});
      });
      picker.append(control.element);
    }
    save.hidden=!data.canEdit;
    save.disabled=!changed();
    cancel.textContent=data.canEdit?'Отмена':'Закрыть';
    position();
  }
  async function open(cell) {
    if (busy || dialog.open) return;
    clientId=Number(cell.dataset.row);
    if (clientId<=0) { notice('Сначала сохраните клиента.','info');return; }
    opener=cell;
    busy=true;
    try {
      data=await request();
      ids=[...data.managerIds];
      title.textContent='Менеджеры · '+data.client;
      restoreFocus=true;
      render();
      dialog.show();
      position();
      (picker.querySelector('input')??cancel).focus({preventScroll:true});
    } catch (error) { notice(error.message); }
    finally { busy=false; }
  }
  save.addEventListener('click',async()=>{
    if (busy || !changed()) return;
    save.disabled=busy=true;
    try {
      const result=await request({managerIds:ids,revision:data.revision});
      const cell=window.VitrineRows?.resolveCell(opener)??opener;
      for (const sibling of cell.parentElement.querySelectorAll('td[data-edit-cell]')) sibling.dataset.revision=String(result.revision);
      cell.textContent=cell.dataset.original=result.summary;
      cell.title='Щелчок — открыть список менеджеров. '+result.summary;
      dialog.close();
      notice('Менеджеры клиента сохранены.','success');
    } catch (error) { save.disabled=false;notice(error.message); }
    finally { busy=false; }
  });
  cancel.addEventListener('click',()=>{ if (!busy) dialog.close(); });
  dialog.addEventListener('keydown',event=>{
    if (event.key!=='Escape') return;
    event.preventDefault();
    if (!busy) dialog.close();
  });
  dialog.addEventListener('close',()=>{
    const cell=window.VitrineRows?.resolveCell(opener)??opener;
    if (restoreFocus && cell?.isConnected) cell.focus({preventScroll:true});
  });
  document.addEventListener('click',event=>{
    if (!dialog.open || dialog.contains(event.target)) return;
    if (busy || changed()) {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (!busy) notice('Сначала сохраните изменения или нажмите «Отмена».','info');
      return;
    }
    restoreFocus=false;
    dialog.close();
  },true);
  document.querySelector('.vitrine-table-scroll')?.addEventListener('scroll',position);
  window.addEventListener('resize',position);
  window.VitrineClientManagers=Object.freeze({open});
})();

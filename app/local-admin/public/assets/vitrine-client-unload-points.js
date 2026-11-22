'use strict';
(() => {
  const dialog=document.getElementById('client-unload-dialog');
  if (!dialog) return;
  const list=dialog.querySelector('[data-client-unload-list]');
  const rows=dialog.querySelector('[data-client-unload-rows]');
  const add=dialog.querySelector('[data-client-unload-add]');
  const close=dialog.querySelector('[data-client-unload-close]');
  const addressForm=dialog.querySelector('[data-client-unload-form]');
  const address=addressForm.elements.address;
  const deleteForm=dialog.querySelector('[data-client-unload-delete]');
  const confirmLabel=deleteForm.querySelector('[data-client-unload-confirm]');
  const csrf=document.querySelector('meta[name="csrf-token"]').content;
  let opener, clientId, data, selectedPoint, returnFocus;
  let busy=false;

  const notice=(message,tone='danger')=>window.PlanexNotifications.show({message,tone});
  function node(tag,text,cls) {
    const element=document.createElement(tag);
    if (text) element.textContent=text;
    if (cls) element.className=cls;
    return element;
  }
  function action(text,run) {
    const button=node('button',text,'btn btn-secondary');
    button.type='button';
    button.addEventListener('click',()=>run(button));
    return button;
  }
  async function request(body=null) {
    const query=body ? '' : '?clientUnloadPoints='+clientId;
    const path='/api/workspace/catalogs'+query;
    const options={credentials:'same-origin'};
    if (body) Object.assign(options,{method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({clientId,...body})});
    const response=await fetch(window.DltkPaths?.path(path)??path,options);
    const result=await response.json();
    if (!response.ok || result.error) throw new Error(result.error?.message||'Не удалось обновить точки выгрузки.');
    return result;
  }
  function showOnly(section) {
    for (const part of [list,addressForm,deleteForm]) part.hidden=part!==section;
  }
  function edit(point,button) {
    selectedPoint=point;
    returnFocus=button;
    address.value=point?.address??'';
    showOnly(addressForm);
    address.focus();
  }
  function askDelete(point,button) {
    selectedPoint=point;
    returnFocus=button;
    confirmLabel.textContent='Удалить точку «'+point.address+'»?';
    showOnly(deleteForm);
    deleteForm.querySelector('[data-client-unload-cancel]').focus();
  }
  function focusList() {
    (returnFocus?.isConnected ? returnFocus : add.hidden ? close : add).focus();
  }
  function render() {
    dialog.querySelector('h2').textContent='Точки выгрузки · '+data.name;
    add.hidden=!data.canEdit;
    rows.replaceChildren();
    for (const point of data.points) {
      const item=node('article',null,'vitrine-client-unload-point');
      const title=node('strong',point.address);
      const summary=node('p',point.summary==='—' ? 'Контакты не указаны' : point.summary);
      const buttons=node('div',null,'vitrine-client-unload-actions');
      const contacts=action('Контакты',button=>{
        window.VitrineUnloadContacts.openPoint(point.id,button,result=>{
          point.revision=result.revision;
          point.summary=result.summary;
          summary.textContent=result.summary==='—' ? 'Контакты не указаны' : result.summary;
        });
      });
      buttons.append(contacts);
      if (data.canEdit) buttons.prepend(action('Изменить адрес',button=>edit(point,button)));
      if (data.canEdit) buttons.append(action('Удалить',button=>askDelete(point,button)));
      item.append(title,summary,buttons);
      rows.append(item);
    }
    if (!data.points.length) rows.append(node('p','У клиента пока нет точек выгрузки.'));
    const cell=window.VitrineRows?.resolveCell(opener)??opener;
    cell.textContent=data.summary;
    cell.dataset.original=data.summary;
    cell.title='Щелчок — открыть точки выгрузки. '+data.summary;
  }
  async function open(cell) {
    if (busy || dialog.open) return;
    clientId=Number(cell.dataset.row);
    if (clientId<=0) { notice('Сначала сохраните клиента.','info'); return; }
    opener=cell;
    busy=true;
    cell.setAttribute('aria-busy','true');
    try {
      data=await request();
      render();
      showOnly(list);
      dialog.showModal();
    } catch (error) { notice(error.message); }
    finally { busy=false;cell.removeAttribute('aria-busy'); }
  }
  async function submit(form,payload,message) {
    if (busy) return;
    const controls=[...form.querySelectorAll('button, textarea')];
    busy=true;
    controls.forEach(control=>control.disabled=true);
    try {
      data=await request(payload);
      render();
      showOnly(list);
      focusList();
      notice(message,'success');
    } catch (error) { notice(error.message,'info'); }
    finally { busy=false;controls.forEach(control=>control.disabled=false); }
  }
  addressForm.addEventListener('submit',event=>{
    event.preventDefault();
    submit(addressForm,{action:'save_client_unload_point',address:address.value,
      entryId:selectedPoint?.id,revision:selectedPoint?.revision},selectedPoint ? 'Адрес сохранён.' : 'Точка добавлена.');
  });
  deleteForm.addEventListener('submit',event=>{
    event.preventDefault();
    submit(deleteForm,{action:'delete_client_unload_point',entryId:selectedPoint.id,revision:selectedPoint.revision},
      'Точка удалена.');
  });
  add.addEventListener('click',()=>edit(null,add));
  close.addEventListener('click',()=>dialog.close());
  for (const cancel of dialog.querySelectorAll('[data-client-unload-cancel]')) {
    cancel.addEventListener('click',()=>{showOnly(list);focusList();});
  }
  dialog.addEventListener('cancel',event=>{
    if (busy) { event.preventDefault();return; }
    if (!list.hidden) return;
    event.preventDefault();
    showOnly(list);
    focusList();
  });
  dialog.addEventListener('close',()=>{
    const cell=window.VitrineRows?.resolveCell(opener)??opener;
    if (cell?.isConnected) cell.focus({preventScroll:true});
  });
  window.VitrineClientUnloadPoints=Object.freeze({open});
})();

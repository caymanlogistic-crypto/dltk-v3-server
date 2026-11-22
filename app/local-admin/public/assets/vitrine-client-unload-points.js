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
  const phone=addressForm.elements.phone;
  const deleteForm=dialog.querySelector('[data-client-unload-delete]');
  const confirmLabel=deleteForm.querySelector('[data-client-unload-confirm]');
  const menu=dialog.querySelector('[data-client-unload-menu]');
  const csrf=document.querySelector('meta[name="csrf-token"]').content;
  let opener, clientId, data, selectedPoint, returnFocus, menuTrigger, menuPoint;
  let busy=false, restoreFocus=true;

  const notice=(message,tone='danger')=>window.PlanexNotifications.show({message,tone});
  function node(tag,text,cls) {
    const element=document.createElement(tag);
    if (text) element.textContent=text;
    if (cls) element.className=cls;
    return element;
  }
  function action(text,run,cls='vitrine-client-unload-cell') {
    const button=node('button',text,cls);
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
    position();
  }
  function position() {
    if (dialog.open && opener?.isConnected) window.VitrineGrid.positionPanel(dialog,opener);
  }
  function closeMenu(focus=false) {
    if (!menuTrigger) return;
    menuTrigger.setAttribute('aria-expanded','false');
    if (focus) menuTrigger.focus({preventScroll:true});
    menu.hidden=true;
    menuTrigger=null;
  }
  function rowMenu(point,button) {
    if (menuTrigger===button) { closeMenu(true);return; }
    closeMenu();
    menuTrigger=button;
    menuPoint=point;
    button.setAttribute('aria-expanded','true');
    menu.hidden=false;
    window.VitrineGrid.positionPanel(menu,button);
    menu.querySelector('button').focus({preventScroll:true});
  }
  function edit(point,button) {
    selectedPoint=point;
    returnFocus=button;
    address.value=point?.address??'';
    phone.value=point?.phone??'';
    showOnly(addressForm);
    (button.dataset.unloadPhone ? phone : address).focus();
  }
  function askDelete(point,button) {
    selectedPoint=point;
    returnFocus=button;
    confirmLabel.textContent='Удалить точку «'+point.address+'»?';
    showOnly(deleteForm);
    deleteForm.querySelector('[data-client-unload-cancel]').focus();
  }
  function focusList() {
    (returnFocus?.isConnected ? returnFocus : add.hidden ? close : add).focus({preventScroll:true});
  }
  function render() {
    dialog.querySelector('h2').textContent='Точки выгрузки · '+data.name;
    add.hidden=!data.canEdit;
    rows.replaceChildren();
    for (const point of data.points) {
      const row=node('tr');
      const actions=node('td');
      if (data.canEdit) {
        const more=action('⋯',button=>rowMenu(point,button),'vitrine-row-menu-trigger');
        more.setAttribute('aria-label','Действия со строкой');
        more.setAttribute('aria-haspopup','menu');
        more.setAttribute('aria-expanded','false');
        actions.append(more);
      }
      const location=node('td');
      const addressCell=data.canEdit ? action(point.address,button=>edit(point,button))
        : node('span',point.address,'vitrine-client-unload-cell');
      addressCell.title=data.canEdit ? 'Щелчок — изменить адрес и телефон' : point.address;
      location.append(addressCell);
      const contact=node('td');
      const contacts=data.canEdit ? action(point.phone||'—',button=>edit(point,button))
        : node('span',point.phone||'—','vitrine-client-unload-cell');
      if (data.canEdit) {
        contacts.title='Щелчок — изменить адрес и телефон';
        contacts.dataset.unloadPhone='true';
      }
      contact.append(contacts);
      row.append(actions,location,contact);
      rows.append(row);
    }
    if (!data.points.length) {
      const row=node('tr');
      const empty=node('td','У клиента пока нет точек выгрузки.','vitrine-client-unload-empty');
      empty.colSpan=3;
      row.append(empty);
      rows.append(row);
    }
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
      restoreFocus=true;
      render();
      showOnly(list);
      dialog.show();
      position();
    } catch (error) { notice(error.message); }
    finally { busy=false;cell.removeAttribute('aria-busy'); }
  }
  async function submit(form,payload,message) {
    if (busy) return;
    const controls=[...form.querySelectorAll('button, textarea, input')].filter(control=>!control.disabled);
    busy=true;
    controls.forEach(control=>control.disabled=true);
    try {
      data=await request(payload);
      render();
      if (form===deleteForm) dialog.close();
      else { showOnly(list);focusList(); }
      notice(message,'success');
    } catch (error) { notice(error.message,'info'); }
    finally { busy=false;controls.forEach(control=>control.disabled=false); }
  }
  addressForm.addEventListener('submit',event=>{
    event.preventDefault();
    submit(addressForm,{action:'save_client_unload_point',address:address.value,
      phone:phone.value,entryId:selectedPoint?.id,revision:selectedPoint?.revision},selectedPoint ? 'Точка сохранена.' : 'Точка добавлена.');
  });
  deleteForm.addEventListener('submit',event=>{
    event.preventDefault();
    submit(deleteForm,{action:'delete_client_unload_point',entryId:selectedPoint.id,revision:selectedPoint.revision},
      'Точка удалена.');
  });
  add.addEventListener('click',()=>edit(null,add));
  close.addEventListener('click',()=>dialog.close());
  menu.querySelector('[data-client-unload-remove]').addEventListener('click',()=>{
    const button=menuTrigger;
    closeMenu();
    askDelete(menuPoint,button);
  });
  for (const cancel of dialog.querySelectorAll('[data-client-unload-cancel]')) {
    cancel.addEventListener('click',()=>{showOnly(list);focusList();});
  }
  dialog.addEventListener('keydown',event=>{
    if (event.key==='Enter' && event.target===address && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      addressForm.requestSubmit();
      return;
    }
    if (event.key!=='Escape') return;
    event.preventDefault();
    if (busy) return;
    if (menuTrigger) closeMenu(true);
    else if (list.hidden) { showOnly(list);focusList(); }
    else dialog.close();
  });
  dialog.addEventListener('close',()=>{
    closeMenu();
    const cell=window.VitrineRows?.resolveCell(opener)??opener;
    if (restoreFocus && cell?.isConnected) cell.focus({preventScroll:true});
  });
  document.addEventListener('click',event=>{
    if (!dialog.open || dialog.contains(event.target)) {
      if (menuTrigger && !menu.contains(event.target) && !menuTrigger.contains(event.target)) closeMenu();
      return;
    }
    const hasDraft=!addressForm.hidden && (address.value!==(selectedPoint?.address??'') ||
      phone.value!==(selectedPoint?.phone??''));
    if (hasDraft || busy) {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (hasDraft) notice('Сначала сохраните изменения или нажмите «Отмена».','info');
      return;
    }
    restoreFocus=false;
    dialog.close();
  },true);
  dialog.querySelector('[data-client-unload-scroll]').addEventListener('scroll',()=>closeMenu());
  document.querySelector('.vitrine-table-scroll')?.addEventListener('scroll',()=>{closeMenu();position();});
  window.addEventListener('resize',()=>{closeMenu();position();});
  window.VitrineClientUnloadPoints=Object.freeze({open});
})();

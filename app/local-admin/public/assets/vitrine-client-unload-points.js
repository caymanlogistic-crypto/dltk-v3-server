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
  const menu=dialog.querySelector('[data-client-unload-menu]');
  const csrf=document.querySelector('meta[name="csrf-token"]').content;
  let opener, clientId, data, selectedPoint, returnFocus, menuTrigger, menuPoint;
  let busy=false, contactsOpen=false, restoreFocus=true;

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
    (returnFocus?.isConnected ? returnFocus : add.hidden ? close : add).focus({preventScroll:true});
  }
  async function openContacts(point,button) {
    busy=true;
    try {
      contactsOpen=await window.VitrineUnloadContacts.openPoint(point.id,button,result=>{
        point.revision=result.revision;
        point.summary=result.summary;
        button.textContent=result.summary;
      }, {anchor:opener,onClose:()=>{
        contactsOpen=false;
        dialog.style.visibility='';
        position();
      }});
      if (contactsOpen) dialog.style.visibility='hidden';
    } finally { busy=false; }
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
      addressCell.title=data.canEdit ? 'Щелчок — изменить адрес' : point.address;
      location.append(addressCell);
      const contact=node('td');
      const contacts=action(point.summary,button=>openContacts(point,button));
      contacts.title='Щелчок — открыть контакты';
      contacts.setAttribute('aria-haspopup','dialog');
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
    const contactDialog=document.getElementById('unload-contacts-dialog');
    if (contactsOpen && contactDialog.contains(event.target)) return;
    const hasDraft=!addressForm.hidden && address.value!==(selectedPoint?.address??'');
    if (contactsOpen || hasDraft || busy) {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (hasDraft) notice('Сначала сохраните адрес или нажмите «Отмена».','info');
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

'use strict';
(() => {
  const dialog=document.getElementById('client-managers-dialog');
  if (!dialog) return;
  const list=dialog.querySelector('[data-client-manager-list]');
  const rows=dialog.querySelector('[data-client-manager-rows]');
  const add=dialog.querySelector('[data-client-manager-add]');
  const create=dialog.querySelector('[data-client-manager-create]');
  const close=dialog.querySelector('[data-client-manager-close]');
  const form=dialog.querySelector('[data-client-manager-form]');
  const createForm=dialog.querySelector('[data-client-manager-create-form]');
  const picker=dialog.querySelector('[data-client-manager-picker]');
  const removeForm=dialog.querySelector('[data-client-manager-remove-form]');
  const menu=dialog.querySelector('[data-client-manager-menu]');
  const editProfile=menu.querySelector('[data-client-manager-edit-profile]');
  const title=dialog.querySelector('h2');
  const csrf=document.querySelector('meta[name="csrf-token"]').content;
  let opener,clientId,data,selected,returnFocus,menuTrigger,menuManager,menuReturnFocus,control,available,initialValue,profile;
  let busy=false,restoreFocus=true;

  const notice=(message,tone='danger')=>window.PlanexNotifications.show({message,tone});
  function node(tag,text,cls) {
    const element=document.createElement(tag);
    if (text) element.textContent=text;
    if (cls) element.className=cls;
    return element;
  }
  function action(text,run,cls='vitrine-client-relations-cell') {
    const button=node('button',text,cls);
    button.type='button';
    const activate=()=>{if (!busy) run(button);};
    if (cls==='vitrine-client-relations-cell') window.VitrineClientRelationsKeyboard.bindCellActivation(button,activate);
    else button.addEventListener('click',activate);
    return button;
  }
  function position() {
    if (dialog.open && opener?.isConnected) window.VitrineGrid.positionPanel(dialog,opener);
  }
  function show(section) {
    for (const part of [list,form,createForm,removeForm]) part.hidden=part!==section;
    if (section!==form) picker.replaceChildren();
    position();
  }
  function closeMenu(focus=false) {
    if (!menuTrigger) return;
    menuTrigger.setAttribute('aria-expanded','false');
    if (focus) (menuReturnFocus?.isConnected ? menuReturnFocus : menuTrigger).focus({preventScroll:true});
    menu.hidden=true;
    menuTrigger=null;
  }
  function rowMenu(manager,button) {
    if (menuTrigger===button) { closeMenu(true);return; }
    closeMenu();
    menuTrigger=button;
    menuReturnFocus=dialog.contains(document.activeElement) ? document.activeElement : button;
    menuManager=manager;
    button.setAttribute('aria-expanded','true');
    editProfile.hidden=!data.canEditProfiles || !manager.active;
    menu.hidden=false;
    window.VitrineGrid.positionPanel(menu,button);
    menu.querySelector('button:not([hidden])').focus({preventScroll:true});
  }
  function focusList(savedId) {
    if (savedId && keyboard.focusRow(savedId,1)) return;
    if (returnFocus?.isConnected) { returnFocus.focus({preventScroll:true});return; }
    keyboard.focusFirst(add.hidden ? (create.hidden ? close : create) : add);
  }
  async function request(body=null) {
    const path='/api/workspace/catalogs'+(body?'':'?clientManagers='+clientId);
    const options={credentials:'same-origin'};
    if (body) Object.assign(options,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({action:'save_client_managers',clientId,managerSource:data.managerSource,...body})});
    const response=await fetch(window.DltkPaths?.path(path)??path,options);
    const result=await response.json();
    if (!response.ok || result.error) throw new Error(result.error?.message||'Не удалось обновить список менеджеров.');
    return result;
  }
  function edit(manager,button) {
    selected=manager;
    returnFocus=button;
    const choices=data.options.filter(option=>option.active &&
      (option.id===manager?.id || !data.managerIds.includes(option.id)));
    if (!choices.length) { notice('Нет доступных менеджеров для назначения.','info');return; }
    const counts=new Map();
    for (const option of choices) counts.set(option.label,(counts.get(option.label)??0)+1);
    available=choices.map(option=>({...option,value:counts.get(option.label)>1 ? option.label+' ('+option.id+')' : option.label}));
    initialValue=available.find(option=>option.id===manager?.id)?.value??'';
    control=window.VitrineSelect.create([{value:'',label:'Выберите менеджера'},...available],initialValue,{searchable:true});
    control.field.setAttribute('aria-labelledby','client-manager-label');
    control.field.required=true;
    picker.replaceChildren(control.element);
    show(form);
    control.field.focus({preventScroll:true});
  }
  function render() {
    const byId=new Map(data.options.map(option=>[option.id,option]));
    title.textContent='Менеджеры · '+data.client;
    add.hidden=!data.canEdit || !data.options.some(option=>option.active&&!data.managerIds.includes(option.id));
    create.hidden=!data.canCreate;
    rows.replaceChildren();
    for (const id of data.managerIds) {
      const manager=byId.get(id);
      if (!manager) continue;
      const row=node('tr');
      row.dataset.relationId=String(id);
      const actions=node('td');
      if (data.canEdit) {
        const more=action('⋯',button=>rowMenu(manager,button),'vitrine-row-menu-trigger');
        more.setAttribute('aria-label','Действия со строкой');
        more.setAttribute('aria-haspopup','menu');
        more.setAttribute('aria-expanded','false');
        actions.append(more);
      }
      const cell=node('td');
      const unavailable=data.managerSource==='accounts' ? ' (недоступен для назначения)' : ' (архив)';
      const label=manager.label+(manager.legacy ? ' (требуется сопоставление)' : manager.active ? '' : unavailable);
      const name=data.canEdit ? action(label,button=>edit(manager,button)) : node('span',label,'vitrine-client-relations-cell');
      cell.append(name);
      row.append(actions,cell);
      for (const value of [manager.phone,manager.email]) {
        const detail=node('td');
        detail.append(node('span',value||'—','vitrine-client-relations-cell'));
        row.append(detail);
      }
      rows.append(row);
    }
    if (!rows.children.length) {
      const row=node('tr');
      const empty=node('td','Менеджеры не назначены.','vitrine-client-relations-empty');
      empty.colSpan=4;
      row.append(empty);
      rows.append(row);
    }
  }
  async function open(cell) {
    if (busy || dialog.open) return;
    clientId=Number(cell.dataset.row);
    if (clientId<=0) { notice('Сначала сохраните клиента.','info');return; }
    opener=cell;
    busy=true;
    cell.setAttribute('aria-busy','true');
    try {
      data=await request();
      restoreFocus=true;
      returnFocus=null;
      render();
      show(list);
      dialog.show();
      position();
      focusList();
    } catch (error) { notice(error.message); }
    finally { busy=false;cell.removeAttribute('aria-busy'); }
  }
  async function submit(section,payload,savedId=null) {
    if (busy) return;
    const fields=[...section.querySelectorAll('button,input')];
    const previousFocus=document.activeElement;
    busy=true;
    fields.forEach(field=>field.disabled=true);
    try {
      data=await request({...payload,revision:data.revision});
      const cell=window.VitrineRows?.resolveCell(opener)??opener;
      for (const sibling of cell.parentElement.querySelectorAll('td[data-edit-cell]')) sibling.dataset.revision=String(data.revision);
      cell.textContent=cell.dataset.original=data.summary;
      if (section===removeForm) dialog.close();
      else { render();show(list);focusList(data.createdId??savedId); }
      notice('Менеджеры клиента сохранены.','success');
    } catch (error) {
      fields.forEach(field=>field.disabled=false);
      previousFocus.focus({preventScroll:true});
      notice(error.message);
    }
    finally { busy=false;fields.forEach(field=>field.disabled=false); }
  }
  add.addEventListener('click',()=>edit(null,add));
  window.VitrineCompactInput.phone.bind(createForm.elements.phone);
  function openProfile(employee,button) {
    profile=employee;
    returnFocus=button;
    createForm.reset();
    for (const key of ['surname','name','patronymic','phone','email']) {
      createForm.elements[key].value=employee?.[key]??'';
    }
    show(createForm);
    createForm.elements.surname.focus({preventScroll:true});
  }
  create.addEventListener('click',()=>openProfile(null,create));
  editProfile.addEventListener('click',()=>{
    const button=menuReturnFocus??menuTrigger;
    closeMenu();
    openProfile(menuManager,button);
  });
  close.addEventListener('click',()=>dialog.close());
  form.addEventListener('submit',event=>{
    event.preventDefault();
    const option=available.find(item=>item.value===control.resolveValue());
    if (!option) { notice('Выберите менеджера из списка.','info');return; }
    const ids=selected ? data.managerIds.map(id=>id===selected.id ? option.id : id) : [...data.managerIds,option.id];
    submit(form,{managerIds:ids},option.id);
  });
  createForm.addEventListener('submit',event=>{
    event.preventDefault();
    const employee=Object.fromEntries(new FormData(createForm));
    if (profile) Object.assign(employee,{id:profile.id,revision:profile.revision});
    submit(createForm,{employee},profile?.id);
  });
  menu.querySelector('[data-client-manager-remove]').addEventListener('click',()=>{
    selected=menuManager;
    returnFocus=menuReturnFocus??menuTrigger;
    closeMenu();
    removeForm.querySelector('[data-client-manager-confirm]').textContent='Убрать «'+selected.label+'» из списка менеджеров клиента?';
    show(removeForm);
    removeForm.querySelector('[data-client-manager-cancel]').focus({preventScroll:true});
  });
  removeForm.addEventListener('submit',event=>{
    event.preventDefault();
    submit(removeForm,{managerIds:data.managerIds.filter(id=>id!==selected.id)});
  });
  for (const cancel of dialog.querySelectorAll('[data-client-manager-cancel]')) {
    cancel.addEventListener('click',()=>{show(list);focusList();});
  }
  const keyboard=window.VitrineClientRelationsKeyboard.bind(dialog,{
    rows,menu,closeMenu,isBusy:()=>busy,onEscape:()=>{
      if (list.hidden) { show(list);focusList(); }
      else dialog.close();
    },
  });
  dialog.addEventListener('close',()=>{
    closeMenu();
    picker.replaceChildren();
    const cell=window.VitrineRows?.resolveCell(opener)??opener;
    if (restoreFocus && cell?.isConnected) cell.focus({preventScroll:true});
  });
  document.addEventListener('click',event=>{
    if (!dialog.open || dialog.contains(event.target)) {
      if (menuTrigger && !menu.contains(event.target) && !menuTrigger.contains(event.target)) closeMenu();
      return;
    }
    const hasDraft=(!form.hidden && control.field.value!==initialValue) ||
      (!createForm.hidden && [...new FormData(createForm)].some(([key,value])=>value.trim()!==(profile?.[key]??'')));
    if (busy || hasDraft) {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (hasDraft) notice('Сначала сохраните изменения или нажмите «Отмена».','info');
      return;
    }
    restoreFocus=false;
    dialog.close();
  },true);
  dialog.querySelector('[data-client-manager-scroll]').addEventListener('scroll',()=>closeMenu());
  document.querySelector('.vitrine-table-scroll')?.addEventListener('scroll',()=>{closeMenu();position();});
  window.addEventListener('resize',()=>{closeMenu();position();});
  window.VitrineClientManagers=Object.freeze({open});
})();

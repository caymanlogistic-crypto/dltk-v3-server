'use strict';
(() => {
  const catalogs=JSON.parse(document.getElementById('vitrine-quick-catalogs').content);
  const dialog=document.querySelector('[data-vitrine-quick-catalog]');
  const frame=dialog.querySelector('iframe');
  const close=dialog.querySelector('[data-quick-close]');
  let session;
  const notify=(message,tone='danger')=>window.PlanexNotifications.show({message,tone});
  async function options(key,cell) {
    const params=new URLSearchParams({table:window.VitrineGridScope.tableSlug,quickOptions:key,ids:cell.dataset.row});
    const response=await fetch(window.DltkPaths.path('/api/vitrine/rows?'+params),{credentials:'same-origin'});
    const data=await response.json();
    if (!response.ok) throw new Error(data.error?.message || 'Не удалось обновить список.');
    return data;
  }

  function closeCatalog() {
    const child=frame.contentWindow;
    if (child?.document.querySelector('[data-quick-order-saving]')) {
      child.PlanexNotifications.show({message:'Дождитесь сохранения порядка строк.',tone:'warning'});
      return;
    }
    if (child?.VitrineGrid?.editing() || child?.document.querySelector('dialog[open]')) {
      child.PlanexNotifications.show({message:'Сначала сохраните правку или нажмите «Отмена».',tone:'warning'});
      return;
    }
    dialog.close();
  }

  close.addEventListener('click',closeCatalog);
  dialog.addEventListener('cancel',event=>{event.preventDefault();closeCatalog();});
  frame.addEventListener('load',()=>{
    if (!session || !dialog.open) return;
    const child=frame.contentDocument;
    if (!child?.body.hasAttribute('data-vitrine-catalog')) {
      dialog.close();
      notify('Не удалось открыть справочник.');
      return;
    }
    child.addEventListener('keydown',event=>{
      if (event.key==='Escape' && !event.defaultPrevented) closeCatalog();
    });
  });
  dialog.addEventListener('close',async()=>{
    const current=session;
    session=null;
    frame.removeAttribute('src');
    if (!current) return;
    current.control.field.focus({preventScroll:true});
    current.button.disabled=true;
    try {
      current.refresh(await options(current.key,current.cell));
    } catch (error) {
      notify(error.message || 'Не удалось обновить список.');
    } finally {
      current.button.disabled=false;
    }
  });

  function attach(control,key,cell,refresh) {
    const catalog=catalogs[key];
    if (!catalog) return;
    const wrapper=document.createElement('div');
    wrapper.className='vitrine-quick-catalog-field';
    const button=document.createElement('button');
    button.type='button';
    button.className='btn btn-secondary btn-icon vitrine-excel-button';
    button.setAttribute('aria-label','Открыть справочник');
    button.innerHTML='<svg aria-hidden="true" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>';
    button.setAttribute('data-quick-open','');
    button.setAttribute('aria-haspopup','dialog');
    control.element.before(wrapper);
    wrapper.append(control.element,button);
    button.addEventListener('click',async()=>{
      if (control.field.disabled || window.VitrineGrid.editingCell()!==cell) return;
      const params=new URLSearchParams({table:window.VitrineGridScope.tableSlug,catalog:catalog.directory,quickCatalog:key});
      if (catalog.directory==='entries') params.set('id',String(catalog.id));
      if (key==='K') {
        const clientCell=cell.parentElement.querySelector('td[data-column="B"]');
        if (!clientCell?.textContent.trim()) {notify('Сначала выберите клиента.','warning');return;}
        let client=Number(clientCell.dataset.referenceId);
        if (!(client>0)) {
          button.disabled=true;
          try {
            client=Number((await options(key,cell)).unloadClientId);
          } catch (error) {
            notify(error.message);
            return;
          } finally {
            button.disabled=false;
          }
        }
        if (!(client>0)) {notify('Выберите клиента из справочника.','warning');return;}
        if (window.VitrineGrid.editingCell()!==cell) return;
        params.set('client',String(client));
      }
      session={control,key,cell,button,refresh};
      frame.src=window.DltkPaths.path('/vitrine?'+params);
      dialog.showModal();
    });
  }

  window.VitrineQuickCatalog=Object.freeze({attach});
})();

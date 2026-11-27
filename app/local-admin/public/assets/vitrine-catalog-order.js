'use strict';
(() => {
  const body=document.getElementById('vitrine-rows');
  const template=document.getElementById('vitrine-row-drag-template');
  if (!body || !template) return;
  const params=new URLSearchParams(location.search);
  const key=params.get('quickCatalog');
  const csrf=document.querySelector('meta[name="csrf-token"]').content;
  params.set('quickOrder',key);
  params.delete('quickCatalog');
  const path=window.DltkPaths.path('/api/vitrine/cell?'+params);
  let source=null, marker=null, busy=false;

  const activeRows='tr[data-row-number]:not([data-directory-archived])';
  const dragButton='button[data-quick-row-drag]';

  function decorate() {
    for (const row of body.querySelectorAll(activeRows)) {
      const number=row.querySelector('th.vitrine-row-number');
      if (number.querySelector(dragButton)) continue;
      const button=template.content.firstElementChild.cloneNode(true);
      button.setAttribute('aria-label','Переместить строку '+number.firstChild.textContent.trim());
      number.append(button);
    }
  }

  function clearMarker() {
    marker?.removeAttribute('data-quick-drop');
    marker=null;
  }

  function targetFor(event) {
    const row=event.target.closest(activeRows);
    if (!source || !row || row.dataset.rowNumber===source || !body.contains(row)) return null;
    const rect=row.getBoundingClientRect();
    return {row,after:event.clientY>rect.top+rect.height/2};
  }

  body.addEventListener('dragstart',event=>{
    const handle=event.target.closest(dragButton);
    if (!handle) return;
    if (busy || window.VitrineGrid.editing()) {
      event.preventDefault();
      return;
    }
    source=handle.closest('tr').dataset.rowNumber;
    event.dataTransfer.effectAllowed='move';
    event.dataTransfer.setData('text/plain',source);
  });
  body.addEventListener('dragover',event=>{
    const target=targetFor(event);
    if (!target) {clearMarker();return;}
    event.preventDefault();
    event.dataTransfer.dropEffect='move';
    if (marker!==target.row) clearMarker();
    marker=target.row;
    marker.dataset.quickDrop=target.after ? 'after' : 'before';
  });
  body.addEventListener('dragleave',event=>{
    if (!marker?.contains(event.relatedTarget)) clearMarker();
  });
  body.addEventListener('dragend',()=>{source=null;clearMarker();});
  body.addEventListener('drop',event=>{
    const target=targetFor(event);
    if (!target || busy) return;
    event.preventDefault();
    const moved=source;
    source=null;
    clearMarker();
    saveMove(moved,target.row,target.after);
  });
  body.addEventListener('keydown',event=>{
    const button=event.target.closest(dragButton);
    if (!button || !['ArrowUp','ArrowDown'].includes(event.key) || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
    event.preventDefault();
    const row=button.closest('tr');
    const after=event.key==='ArrowDown';
    const target=after ? row.nextElementSibling : row.previousElementSibling;
    if (!busy && !window.VitrineGrid.editing() && target?.matches(activeRows)) {
      saveMove(row.dataset.rowNumber,target,after,button);
    }
  });

  async function saveMove(moved,target,after,button) {
    const move={source:Number(moved),target:Number(target.dataset.rowNumber),after};
    busy=true;
    body.setAttribute('data-quick-order-saving','');
    try {
      const response=await fetch(path,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify(move)});
      const result=await response.json();
      if (!response.ok) throw new Error(result.error?.message || 'Не удалось изменить порядок строк.');
      await window.VitrineRows.refreshManifest();
      if (button && !button.isConnected && document.activeElement===document.body) {
        body.querySelector('tr[data-row-number="'+moved+'"] '+dragButton)?.focus({preventScroll:true});
      }
    } catch (error) {
      window.PlanexNotifications.show({tone:'danger',message:error.message});
    } finally {
      busy=false;
      body.removeAttribute('data-quick-order-saving');
    }
  }
  window.addEventListener('vitrine-rows-updated',decorate);
  decorate();
})();

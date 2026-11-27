'use strict';
(() => {
  const body=document.getElementById('vitrine-rows');
  const template=document.getElementById('vitrine-row-drag-template');
  const scroll=body?.closest('.vitrine-table-scroll');
  const tripOrder=scroll?.hasAttribute('data-trip-row-order');
  if (!body || (!template && !tripOrder)) return;
  const params=new URLSearchParams(location.search);
  const key=params.get('quickCatalog');
  const csrf=document.querySelector('meta[name="csrf-token"]').content;
  if (tripOrder) params.set('tripOrder','1');
  else {
    params.set('quickOrder',key);
    params.delete('quickCatalog');
  }
  const path=window.DltkPaths.path('/api/vitrine/cell?'+params);
  let source=null, marker=null, busy=false;

  const activeRows='tr[data-row-number]:not([data-directory-archived])';
  const dragButton='button[data-quick-row-drag]';
  const findRow=id=>body.querySelector('tr[data-row-number="'+id+'"]');
  const ownMoves=new Set();
  let draggedDate;

  function sameDate(date,row) {
    return !tripOrder || (date!==undefined && date===row.dataset.tripDate);
  }

  function decorate() {
    if (!template) return;
    for (const row of body.querySelectorAll(activeRows)) {
      const number=row.querySelector('th.vitrine-row-number');
      const handle=number.querySelector(dragButton);
      if (tripOrder && !row.hasAttribute('data-trip-date')) {
        handle?.remove();
        continue;
      }
      if (handle) continue;
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
    if (!source || !row || row.dataset.rowNumber===source || !body.contains(row) || !sameDate(draggedDate,row)) return null;
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
    const row=handle.closest('tr');
    source=row.dataset.rowNumber;
    draggedDate=row.dataset.tripDate;
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
    if (!busy && !window.VitrineGrid.editing() && target?.matches(activeRows) && sameDate(row.dataset.tripDate,target)) {
      saveMove(row.dataset.rowNumber,target,after,button);
    }
  });

  async function saveMove(moved,target,after,button) {
    const move={source:Number(moved),target:Number(target.dataset.rowNumber),after};
    if (tripOrder) {
      move.date=target.dataset.tripDate;
      move.requestId=crypto.randomUUID();
      ownMoves.add(move.requestId);
    }
    busy=true;
    body.setAttribute('data-quick-order-saving','');
    try {
      const response=await fetch(path,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify(move)});
      const result=await response.json();
      if (!response.ok) throw new Error(result.error?.message || 'Не удалось изменить порядок строк.');
      await window.VitrineRows.refreshManifest(tripOrder ? {keepViewport:true} : undefined);
      if (button && document.activeElement===document.body) {
        findRow(moved)?.querySelector(dragButton)?.focus({preventScroll:true});
      }
    } catch (error) {
      window.PlanexNotifications.show({tone:'danger',message:error.message});
    } finally {
      if (move.requestId) setTimeout(()=>ownMoves.delete(move.requestId),30000);
      busy=false;
      body.removeAttribute('data-quick-order-saving');
    }
  }
  if (tripOrder) {
    const pendingMoves=new Map(),movingRows=new WeakMap();
    function animateMoves() {
      for (const [id,before] of pendingMoves) {
        const row=findRow(id);
        if (!row || row.getAttribute('aria-rowindex')===before.index) continue;
        pendingMoves.delete(id);
        clearTimeout(movingRows.get(row));
        movingRows.delete(row);
        row.removeAttribute('data-trip-row-reordered');
        row.style.removeProperty('--vitrine-trip-row-shift');
        const rect=row.getBoundingClientRect(),viewport=scroll.getBoundingClientRect();
        if (rect.top>=viewport.bottom || rect.bottom<=scroll.querySelector('thead').getBoundingClientRect().bottom) continue;
        const fallback=before.direction>0 ? -rect.height : rect.height;
        const distance=before.top===null ? fallback : before.top-rect.top-scroll.scrollTop;
        const start=Math.max(-scroll.clientHeight,Math.min(scroll.clientHeight,distance));
        row.style.setProperty('--vitrine-trip-row-shift',start+'px');
        row.setAttribute('data-trip-row-reordered','');
        const timeout=setTimeout(()=>{
          row.removeAttribute('data-trip-row-reordered');
          row.style.removeProperty('--vitrine-trip-row-shift');
          movingRows.delete(row);
        },620);
        movingRows.set(row,timeout);
      }
    }
    window.addEventListener('vitrine-rows-updated',animateMoves);
    window.addEventListener('workspace:event',event=>{
      const move=event.detail?.data;
      if (event.detail?.event!=='trip.row.reordered' || move?.tableSlug!=='vyvoz' || !Number.isSafeInteger(move.row)) return;
      if (ownMoves.delete(move.requestId)) return;
      const row=findRow(move.row);
      const before={index:row?.getAttribute('aria-rowindex'),direction:move.direction,
        top:row ? row.getBoundingClientRect().top+scroll.scrollTop : null};
      pendingMoves.set(move.row,before);
      setTimeout(()=>{if (pendingMoves.get(move.row)===before) pendingMoves.delete(move.row);},5000);
      window.VitrineRows.refreshManifest({keepViewport:true}).catch(error=>{
        window.PlanexNotifications.show({tone:'danger',message:error.message || 'Не удалось обновить порядок рейсов.'});
      });
    });
  }
  window.addEventListener('vitrine-rows-updated',decorate);
  decorate();
})();

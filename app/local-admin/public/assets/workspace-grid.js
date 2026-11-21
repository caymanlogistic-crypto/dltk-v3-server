"use strict";
(() => {
  const slug=document.body.dataset.workspaceTable;
  if(!slug)return;
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  const delayed=new Map();
  const error=message=>window.PlanexNotifications.show({tone:'danger',message});
  const selected=()=>document.querySelector('.vitrine-table td.is-current-cell')||document.querySelector('.vitrine-table td[tabindex="0"]');
  const historyUrl=row=>window.DltkPaths.path('/workspace?section=history&table='+encodeURIComponent(slug)+(row?'&row='+encodeURIComponent(row):''));
  document.getElementById('workspace-row-history')?.addEventListener('click',event=>{
    event.preventDefault();location.href=window.DltkPaths.path(historyUrl(selected()?.dataset.row));
  });
  async function post(path,body){
    const response=await fetch(window.DltkPaths.path(path),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)});
    const result=await response.json();if(!response.ok||result.error)throw new Error(result.error?.message||'Не удалось выполнить действие.');return result;
  }
  document.getElementById('workspace-new-row')?.addEventListener('click',async event=>{
    if(window.VitrineGrid?.editing()){error('Сначала сохраните правку или нажмите «Отмена».');return;}
    const button=event.currentTarget;button.disabled=true;
    try{const result=await post('/api/workspace/row?table='+encodeURIComponent(slug),{action:'create',requestId:crypto.randomUUID()});await window.VitrineRows?.refreshManifest();const row=typeof result.row==='object'?result.row.rowNumber:result.row;const column=document.querySelector('.vitrine-table th[data-column]:not([hidden])')?.dataset.column;if(row&&column)await window.VitrineRows?.activate({dataset:{row:String(row),column}});button.disabled=false;}
    catch(e){error(e.message);button.disabled=false;}
  });
  document.getElementById('workspace-logout')?.addEventListener('click',async()=>{
    if(window.VitrineGrid?.editing()){error('Сначала сохраните правку или нажмите «Отмена».');return;}
    try{await post('/logout',{});for(let i=localStorage.length-1;i>=0;i--){const key=localStorage.key(i);if(key?.startsWith('dltk-v3.vitrine.display.v2.'+document.body.dataset.workspaceUser+'.'))localStorage.removeItem(key);}location.href=window.DltkPaths.path('/login');}catch(e){error(e.message);}
  });
  if (document.body.dataset.vitrineCatalog) return;
  window.addEventListener('workspace:event',event=>{
    const envelope=event.detail,patch=envelope?.data;
    if(!patch)return;
    if(envelope.event==='cell.changed'&&patch.tableSlug===slug){
      const active=window.VitrineGrid?.editingCell();
      if(active&&Number(active.dataset.row)===Number(patch.row)&&active.dataset.column===patch.column){
        const key=patch.row+':'+patch.column,old=delayed.get(key);
        if(!old||Number(old.revision)<Number(patch.revision))delayed.set(key,patch);
      }else window.VitrineRows?.patch(patch);
    }
    if(envelope.event==='row.changed'&&patch.tableSlug===slug)window.VitrineRows?.refreshManifest().catch(e=>error(e.message));
    if(envelope.event==='catalog.changed'){
      window.VitrineRows?.catalogPatch(patch);
      if(patch.tableSlug&&patch.tableSlug!==slug)return;
      fetch(window.DltkPaths.path('/api/vitrine/statuses?table='+encodeURIComponent(slug)),{credentials:'same-origin'}).then(r=>r.ok?r.json():null).then(data=>{if(data?.statuses){window.vitrineStatusCatalog=data;window.dispatchEvent(new CustomEvent('vitrine-statuses-updated',{detail:data}));}}).catch(()=>{});
    }
  });
  window.addEventListener('vitrine-editor-closed',()=>{
    for(const patch of delayed.values())window.VitrineRows?.patch(patch);delayed.clear();
  });
  let menu=null;
  function closeMenu(){menu?.remove();menu=null;}
  document.querySelector('.vitrine-table')?.addEventListener('contextmenu',event=>{
    const cell=event.target.closest('td[data-row][data-column]');if(!cell)return;
    event.preventDefault();closeMenu();menu=document.createElement('div');menu.className='vitrine-editor';
    Object.assign(menu.style,{position:'fixed',left:Math.min(event.clientX,innerWidth-240)+'px',top:Math.min(event.clientY,innerHeight-90)+'px',width:'220px',padding:'8px',zIndex:'100'});
    const link=document.createElement('a');link.className='btn';link.textContent='История строки '+cell.dataset.row;link.href=historyUrl(cell.dataset.row);menu.append(link);document.body.append(menu);link.focus();
  });
  document.addEventListener('pointerdown',event=>{if(menu&&!menu.contains(event.target))closeMenu();});
  document.addEventListener('keydown',event=>{if(event.key==='Escape')closeMenu();});
})();

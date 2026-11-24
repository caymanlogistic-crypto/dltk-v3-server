/* Ephemeral editing indicators. Editing presence never changes cell values or history. */
(() => {
  'use strict';
  const table = document.querySelector('.vitrine-table');
  const scroll = document.querySelector('.vitrine-table-scroll');
  const slug = document.body.dataset.workspaceTable;
  const userId = Number(document.body.dataset.workspaceUser);
  if (!table || !scroll || !slug) return;
  let editors = [], frame = 0;
  const badges = new Map();
  const outlined = new Set();
  const pasteFlashes = new Map();
  function render() {
    frame = 0;
    const groups = new Map();
    for (const editor of editors) {
      if (editor.tableSlug !== slug || Number(editor.userId) === userId || !Number.isInteger(editor.row) || !/^[A-Z]{1,3}$/.test(editor.column)) continue;
      const key = editor.row + ':' + editor.column;
      if (!groups.has(key)) groups.set(key,{row:editor.row,column:editor.column,users:new Map()});
      groups.get(key).users.set(editor.userId,String(editor.userName || 'Пользователь ' + editor.userId));
      groups.get(key).persistent = true;
    }
    for (const [key,flash] of pasteFlashes) {
      if (!groups.has(key)) groups.set(key,{row:flash.row,column:flash.column,users:new Map(),persistent:false});
      groups.get(key).users.set(flash.actorId,flash.actorName);
    }
    for (const [key,node] of badges) if (!groups.has(key)) {node.remove();badges.delete(key);}
    for (const cell of outlined) {
      if (!cell.isConnected || !groups.get(cell.dataset.row+':'+cell.dataset.column)?.persistent) {
        cell.classList.remove('is-remotely-editing');outlined.delete(cell);
      }
    }
    const bounds = scroll.getBoundingClientRect();
    const headerBottom = table.tHead?.getBoundingClientRect().bottom ?? bounds.top;
    const leftEdge = bounds.left + (table.querySelector('.vitrine-row-number')?.getBoundingClientRect().width || 0);
    for (const [key,group] of groups) {
      const cell = table.querySelector('td[data-row="'+group.row+'"][data-column="'+group.column+'"]');
      let node = badges.get(key);
      if (!cell || cell.hidden) {node?.remove();badges.delete(key);continue;}
      if(group.persistent){cell.classList.add('is-remotely-editing');outlined.add(cell);}
      const rect = cell.getBoundingClientRect();
      if (rect.top < headerBottom - 1 || rect.top >= bounds.bottom || rect.right <= leftEdge || rect.left >= bounds.right) {node?.remove();badges.delete(key);continue;}
      if (!node) {
        node = document.createElement('div');
        node.className = 'vitrine-remote-author';
        node.dataset.row = String(group.row);node.dataset.column = group.column;
        document.body.append(node);badges.set(key,node);
      }
      const names = [...group.users.values()].join(', ');
      if (node.textContent !== names) node.textContent = names;
      node.setAttribute('aria-label','Редактирует: ' + names);
      const flash = pasteFlashes.get(key);
      if(flash && !group.persistent && node.dataset.flashToken !== flash.token){
        node.classList.remove('is-paste-flash');void node.offsetWidth;
        node.style.animationDuration=flash.duration+'ms';node.classList.add('is-paste-flash');node.dataset.flashToken=flash.token;
      }else if(group.persistent)node.classList.remove('is-paste-flash');
      node.style.maxWidth = Math.min(240,bounds.width)+'px';
      // The badge's right and bottom edges coincide exactly with the cell's right/top.
      const badgeRect = node.getBoundingClientRect();
      const left = rect.right-badgeRect.width;
      node.style.left = left+'px';
      node.style.top = (rect.top-badgeRect.height)+'px';
      node.style.clipPath = 'inset(0 '+Math.max(0,rect.right-bounds.right)+'px 0 '+Math.max(0,leftEdge-left)+'px)';
    }
  }
  function schedule() {if(!frame)frame=requestAnimationFrame(render);}
  window.addEventListener('workspace:presence',event=>{editors=Array.isArray(event.detail?.editors)?event.detail.editors:[];schedule();});
  window.addEventListener('workspace:connection',event=>{if(event.detail?.state!=='online'){editors=[];schedule();}});
  window.addEventListener('vitrine-remote-change',event=>{
    const change=event.detail;
    if(change.action!=='paste')return;
    const key=change.row+':'+change.column,previous=pasteFlashes.get(key);
    if(previous)clearTimeout(previous.timer);
    const duration=parseFloat(getComputedStyle(change.cell).animationDuration)*1000||700;
    const flash={row:Number(change.row),column:change.column,actorId:change.actorId,actorName:String(change.actorName||'Пользователь '+change.actorId),duration,token:String(change.revision)};
    flash.timer=setTimeout(()=>{if(pasteFlashes.get(key)===flash){pasteFlashes.delete(key);schedule();}},duration);
    pasteFlashes.set(key,flash);schedule();
  });
  window.addEventListener('vitrine-editor-opened',event=>{
    window.WorkspaceLive?.setEditing({tableSlug:slug,row:event.detail.row,column:event.detail.column});
  });
  window.addEventListener('vitrine-editor-closed',()=>window.WorkspaceLive?.setEditing(null));
  for(const name of ['vitrine-rows-updated','vitrine-columns-updated','resize'])window.addEventListener(name,schedule);
  scroll.addEventListener('scroll',schedule,{passive:true});
  const current = window.VitrineGrid?.editingCell();
  if(current)window.WorkspaceLive?.setEditing({tableSlug:slug,row:Number(current.dataset.row),column:current.dataset.column});
})();

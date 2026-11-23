'use strict';
(() => {
  const table=document.querySelector('.vitrine-table'),body=document.getElementById('vitrine-rows'),scroll=document.querySelector('.vitrine-table-scroll');
  if(!table||!body||!scroll)return;
  let ids=[];try{ids=JSON.parse(document.getElementById('vitrine-row-manifest').content);}catch{}
  const drafts=Array.from(document.querySelectorAll('[data-draft-slot]'));
  const empty=document.getElementById('vitrine-empty');
  const directoryRowCount=document.querySelector('[data-directory-row-count]');
  const search=document.querySelector('.vitrine-catalog-search input[type="search"]');
  let appliedSearch=search?.value.trim() ?? '', requestedSearch=appliedSearch;
  const draftIndex=cell=>drafts.indexOf(cell.closest?.('[data-draft-slot]'));
  const rowIndex=cell=>positions.get(cell.dataset.row) ?? ids.length+draftIndex(cell);
  function setCounts() {
    table.dataset.totalRows=String(ids.length);
    if (directoryRowCount && directoryRowCount.textContent!==table.dataset.totalRows) {
      directoryRowCount.textContent=table.dataset.totalRows;
    }
    table.setAttribute('aria-rowcount',String(ids.length+drafts.length+1));
    drafts.forEach((row,index)=>row.setAttribute('aria-rowindex',String(ids.length+index+2)));
    if (empty && search) {
      empty.hidden = ids.length>0 || (drafts.length>0 && !appliedSearch);
      empty.textContent = appliedSearch ? 'По вашему запросу ничего не найдено.' : 'В таблице пока нет строк для отображения.';
    }
  }
  const positions=new Map(ids.map((id,index)=>[String(id),index]));
  const remotePatches=new Map(),catalogPatches=new Map();
  const pageSize=100,cache=new Map(),loaded=new Map(),pending=new Map();
  const initial=Array.from(body.querySelectorAll('[data-vitrine-row]'));
  for(const row of initial)cache.set(Number(row.dataset.rowNumber),row);
  loaded.set(0,true);
  let height=initial[0]?.getBoundingClientRect().height||32,headHeight=table.querySelector('thead').getBoundingClientRect().height,generation=0,frame=0,first=-1,last=-1,navigation=null,navigationGeneration=0,mounts=0,lastScrollTop=scroll.scrollTop,manifestVersion=0;
  const top=document.createElement('tr'),bottom=document.createElement('tr');
  for(const spacer of [top,bottom]){spacer.dataset.virtualSpacer='';spacer.setAttribute('aria-hidden','true');const cell=document.createElement('td');cell.colSpan=table.querySelectorAll('thead th:not([hidden])').length;spacer.append(cell);}
  setCounts();
  table.setAttribute('aria-colcount',String(table.querySelectorAll('thead th').length));
  function report(error){window.PlanexNotifications.show({tone:'danger',message:error.message||'Не удалось загрузить строки. Повторите прокрутку.'});}
  function prune(){
    while(loaded.size>3){const page=loaded.keys().next().value;loaded.delete(page);for(const id of ids.slice(page*pageSize,(page+1)*pageSize)){const row=cache.get(id);if(row&&!row.isConnected)cache.delete(id);}}
    for(const [id,row] of cache)if(!row.isConnected&&!loaded.has(Math.floor(positions.get(String(id))/pageSize)))cache.delete(id);
  }
  async function fetchRows(wanted, search){
    const url=window.VitrineGridScope.url('/api/vitrine/rows',{rowFilters:true,ids:wanted,search});
    const response=await fetch(url,{credentials:'same-origin'});
    const result=await response.json();
    if(!response.ok||result.error)throw new Error(result.error?.message||'Не удалось загрузить строки.');
    if(!Array.isArray(result.rowIds)||typeof result.html!=='string')throw new Error('Не удалось прочитать строки таблицы.');
    const template=document.createElement('template');
    template.innerHTML='<table><tbody>'+result.html+'</tbody></table>';
    return {rowIds:result.rowIds, rows:template.content.querySelectorAll('[data-vitrine-row]')};
  }
  async function exportRows(columns){
    const selectedIds=[...ids], search=appliedSearch, values=new Map(), missing=[];
    function capture(row){
      const cells=new Map(Array.from(row.querySelectorAll('td[data-column]'),cell=>[cell.dataset.column,cell.textContent]));
      values.set(Number(row.dataset.rowNumber),[row.querySelector('th')?.firstChild?.textContent ?? '',...columns.map(column=>cells.get(column) ?? '')]);
    }
    for(const id of selectedIds){
      const row=cache.get(id);
      if(row)capture(row);
      else missing.push(id);
    }
    for(let start=0;start<missing.length;start+=pageSize){
      const result=await fetchRows(missing.slice(start,start+pageSize),search);
      for(const row of result.rows)capture(row);
    }
    if(values.size!==selectedIds.length)throw new Error('Состав таблицы изменился. Скачайте Excel ещё раз.');
    return selectedIds.map(id=>values.get(id));
  }
  async function page(number){
    if(loaded.has(number)){loaded.delete(number);loaded.set(number,true);return;}
    if(pending.has(number))return pending.get(number);
    const task=(async()=>{
      const version=manifestVersion;
      const wanted=ids.slice(number*pageSize,(number+1)*pageSize);
      if(!wanted.length)return;
      const result=await fetchRows(wanted,appliedSearch);
      if(version!==manifestVersion){queue();return;}
      const missing=new Set(wanted.filter(id=>!result.rowIds.includes(id)));
      if(missing.size){
        ids=ids.filter(id=>!missing.has(id));positions.clear();ids.forEach((id,index)=>positions.set(String(id),index));
        for(const id of missing)cache.delete(id);
        loaded.clear();manifestVersion++;generation++;navigationGeneration++;navigation=null;first=last=-1;
        setCounts();table.removeAttribute('aria-busy');
        queue();return;
      }
      for(const row of result.rows){const id=Number(row.dataset.rowNumber);const old=cache.get(id);if(!old?.isConnected){for(const patch of remotePatches.values())if(Number(patch.row)===id)applyPatch(row,patch);for(const patch of catalogPatches.values())applyCatalogPatch(row,patch);cache.set(id,row);}}
      loaded.set(number,true);prune();
    })().finally(()=>{if(pending.get(number)===task)pending.delete(number);});
    pending.set(number,task);return task;
  }
  function stamp(spacer,size){spacer.hidden=size===0;spacer.firstElementChild.style.setProperty('--spacer-height',size+'px');}
  async function render(forceIndex=null){
    if(window.VitrineGrid?.editing())return;
    if(!ids.length){body.replaceChildren(top,bottom);stamp(top,0);stamp(bottom,0);first=last=0;table.removeAttribute('aria-busy');return;}
    const visible=Math.max(1,Math.ceil((scroll.clientHeight-headHeight)/height)),viewportStart=forceIndex??Math.floor(scroll.scrollTop/height),viewportEnd=Math.min(ids.length,viewportStart+visible+1);
    if(first>=0&&(first===0||viewportStart>=first+5)&&(last===ids.length||viewportEnd<=last-5))return;
    const start=Math.max(0,Math.min(ids.length-1,viewportStart-20)),end=Math.min(ids.length,start+visible+40),ticket=++generation;
    table.setAttribute('aria-busy','true');
    try{
      const needed=[];for(let p=Math.floor(start/pageSize);p<=Math.floor((end-1)/pageSize);p++)needed.push(page(p));
      await Promise.all(needed);if(ticket!==generation||window.VitrineGrid?.editing())return;
      const rows=ids.slice(start,end).map(id=>cache.get(id));
      const mountStarted=performance.now();
      window.VitrineDisplay?.prepareRows(rows.filter(row=>!row.isConnected));
      rows.forEach((row,index)=>row.setAttribute('aria-rowindex',String(start+index+2)));
      stamp(top,start*height);stamp(bottom,(ids.length-end)*height);
      if(top.parentElement!==body)body.prepend(top);
      if(bottom.parentElement!==body)body.append(bottom);
      const keep=new Set(rows);
      for(const row of body.querySelectorAll('[data-vitrine-row]'))if(!keep.has(row))row.remove();
      let cursor=top.nextSibling;
      for(const row of rows){if(cursor===row)cursor=cursor.nextSibling;else body.insertBefore(row,cursor||bottom);}
      first=start;last=end;
      window.dispatchEvent(new CustomEvent('vitrine-rows-updated'));
      table.dataset.windowMounts=String(++mounts);table.dataset.lastMountScriptMs=(performance.now()-mountStarted).toFixed(2);
      prune();
      const upward=scroll.scrollTop<lastScrollTop;lastScrollTop=scroll.scrollTop;
      const ahead=upward?Math.floor(start/pageSize)-1:Math.floor((end-1)/pageSize)+1;
      if(ahead>=0&&ahead*pageSize<ids.length&&!loaded.has(ahead))page(ahead).catch(()=>{});
    }catch(error){if(ticket===generation)report(error);}
    finally{if(ticket===generation)table.removeAttribute('aria-busy');}
  }
  function queue(){if(!frame)frame=requestAnimationFrame(()=>{frame=0;render();});}
  scroll.addEventListener('scroll',queue,{passive:true});
  const resize = () => {
    headHeight=table.querySelector('thead').getBoundingClientRect().height;
    first=-1;
    queue();
  };
  new ResizeObserver(resize).observe(scroll);
  window.addEventListener('vitrine-editor-closed',queue);
  window.addEventListener('vitrine-statuses-updated',()=>loaded.clear());
  window.addEventListener('vitrine-cell-selected',()=>{navigation=null;});
  window.addEventListener('vitrine-columns-updated',()=>{for(const spacer of [top,bottom])spacer.firstElementChild.colSpan=visibleColumns().length+1;first=-1;queue();});
  function visibleColumns(){return Array.from(table.querySelectorAll('thead th[data-column]:not([hidden])')).map(node=>node.dataset.column);}
  function ensureVisible(index){
    const available=scroll.clientHeight-headHeight;
    if(index*height<scroll.scrollTop)scroll.scrollTop=index*height;
    else if((index+1)*height>scroll.scrollTop+available)scroll.scrollTop=Math.max(0,(index+1)*height-available);
  }
  async function activate(cell,action){
    if(draftIndex(cell)>=0){
      window.VitrineGrid.select(cell);cell.focus({preventScroll:true});window.VitrineGrid.reveal(cell);action?.(cell);
      return cell;
    }
    const index=positions.get(cell.dataset.row);if(index===undefined)return null;
    const ticket=++navigationGeneration;
    await page(Math.floor(index/pageSize));if(ticket!==navigationGeneration)return null;
    ensureVisible(index);await render();if(ticket!==navigationGeneration)return null;
    const target=cache.get(ids[index])?.querySelector('td[data-column="'+cell.dataset.column+'"]:not([hidden])');
    if(target){window.VitrineGrid.select(target);target.focus({preventScroll:true});target.scrollIntoView({block:'nearest',inline:'nearest'});action?.(target);}
    return target;
  }
  function go(cell,r,c){
    const columns=visibleColumns();if(r<0||r>=ids.length+drafts.length||c<0||c>=columns.length)return false;
    if(r>=ids.length){
      const target=drafts[r-ids.length].querySelector('td[data-column="'+columns[c]+'"]');
      if(!target)return false;
      navigationGeneration++;
      activate(target);
      return true;
    }
    navigation={r,c};const ticket=++navigationGeneration;
    const ready=cache.get(ids[r])?.querySelector('td[data-column="'+columns[c]+'"]');
    if(ready?.isConnected){
      window.VitrineGrid.select(ready);ready.focus({preventScroll:true});ensureVisible(r);
      if(ready.dataset.column!==cell.dataset.column)window.VitrineGrid.reveal(ready);
      const ahead=Math.floor(r/pageSize)+(r%pageSize>60?1:r%pageSize<40?-1:0);
      if(ahead>=0&&ahead*pageSize<ids.length&&!loaded.has(ahead))page(ahead).catch(()=>{});
      return true;
    }
    (async()=>{
      await page(Math.floor(r/pageSize));if(ticket!==navigationGeneration)return;
      ensureVisible(r);await render();if(ticket!==navigationGeneration)return;
      const target=cache.get(ids[r])?.querySelector('td[data-column="'+columns[c]+'"]');
      if(target){window.VitrineGrid.select(target);target.focus({preventScroll:true});window.VitrineGrid.reveal(target);}
    })().catch(report);return true;
  }
  function applyPatch(row,patch){
    const cell=row?.querySelector('td[data-column="'+patch.column+'"]');
    if(cell && Number(cell.dataset.revision||0)<Number(patch.revision))window.VitrineGrid?.applyRemote(cell,patch);
  }
  async function refreshUnloadChoices(row,patch) {
    const client=row?.querySelector('td[data-column="B"]');
    const address=row?.querySelector('td[data-column="K"][data-catalog-id]');
    if (!address || Number(client?.dataset.revision)!==Number(patch.revision)) return;
    address.dataset.choices='[]';
    const response=await fetch(window.VitrineGridScope.url('/api/vitrine/rows',{ids:[Number(patch.row)]}),{credentials:'same-origin'});
    const data=await response.json();
    if (!response.ok || typeof data.html!=='string') return;
    const template=document.createElement('template');
    template.innerHTML='<table><tbody>'+data.html+'</tbody></table>';
    const fresh=template.content.querySelector('td[data-column="K"]');
    const freshClient=template.content.querySelector('td[data-column="B"]');
    if (fresh && freshClient?.dataset.revision===client.dataset.revision) address.dataset.choices=fresh.dataset.choices??'[]';
  }
  const normalizedCatalogName = name => (name ?? '').trim().toLowerCase().replace(/\s+/gu,' ');
  function applyCatalogPatch(row,patch){
    if(!row)return;
    const active=window.VitrineGrid?.editingCell();
    for(const cell of row.querySelectorAll('td[data-catalog-id]')){
      if(String(cell.dataset.catalogId)!==String(patch.catalogId)||cell===active)continue;
      const bindings=patch.bindings||[];
      if(bindings.length&&!bindings.some(binding=>binding.tableSlug===(document.body.dataset.workspaceTable||'vyvoz')&&binding.fieldKey===cell.dataset.column))continue;
      if(cell.dataset.column==='A'||((cell.dataset.column==='K'||cell.dataset.column==='O')&&document.body.dataset.workspaceTable==='vyvoz'))continue;
      let choices=[];try{choices=JSON.parse(cell.dataset.choices||'[]');}catch{}
      choices=choices.filter(value=>!(patch.aliases||[patch.beforeName,patch.name]).includes(value));
      if(patch.active!==false&&patch.active!==0)choices.push(patch.name);
      cell.dataset.choices=JSON.stringify([...new Set(choices)]);
      const matchesReference=String(cell.dataset.referenceId)===String(patch.id);
      const pickup=cell.dataset.column==='E'&&document.body.dataset.workspaceTable==='vyvoz';
      const legacyPickup=pickup&&!Number(cell.dataset.referenceId)&&cell.textContent!==''&&
        [patch.name,patch.beforeName,...(patch.aliases||[])].some(name=>normalizedCatalogName(name)===normalizedCatalogName(cell.textContent));
      if(pickup&&(matchesReference||legacyPickup)){
        if(patch.color)cell.style.setProperty('--catalog-fill',patch.color);
        else cell.style.removeProperty('--catalog-fill');
        window.VitrineDisplay?.paintCell(cell);
        const saved=remotePatches.get(cell.dataset.row+':'+cell.dataset.column);
        if(saved)saved.catalogFill=patch.color;
      }
      if(matchesReference){
        const key='catalogRevision'+patch.id;
        if(Number(cell.dataset[key]||0)>Number(patch.revision||0))continue;
        cell.dataset[key]=String(patch.revision||0);cell.textContent=patch.name;
        cell.title='Щелчок — изменить. '+patch.name;
        const saved=remotePatches.get(cell.dataset.row+':'+cell.dataset.column);
        if(saved&&String(saved.referenceId??cell.dataset.referenceId)===String(patch.id))saved.value=patch.name;
      }
    }
  }
  window.addEventListener('vitrine-editor-closed',()=>{for(const row of cache.values())for(const patch of catalogPatches.values())applyCatalogPatch(row,patch);});
  let refreshTask=null,refreshAgain=false,manifestDeferred=false;
  async function refreshManifest(){
    if(window.VitrineGrid?.editing()){manifestDeferred=true;return;}
    if(refreshTask){refreshAgain=true;return refreshTask;}
    refreshTask=(async()=>{
      do{
        refreshAgain=false;
        const query=requestedSearch;
        const response=await fetch(window.VitrineGridScope.url('/api/workspace/manifest',{rowFilters:true,search:query}),{credentials:'same-origin'});
        if(query!==requestedSearch){refreshAgain=true;continue;}
        const result=await response.json();
        if(query!==requestedSearch){refreshAgain=true;continue;}
        if(!response.ok||result.error)throw new Error(result.error?.message||'Не удалось обновить список строк.');
        if(!Array.isArray(result.rowIds)||result.rowIds.some(id=>!Number.isInteger(id)))throw new Error('Некорректный список строк.');
        if(window.VitrineGrid?.editing()){manifestDeferred=true;return;}
        const next=[...new Set(result.rowIds)];
        const searchChanged=query!==appliedSearch;
        if(!searchChanged && next.join(',')===ids.join(','))continue;
        const anchorIndex=Math.max(0,Math.min(ids.length-1,Math.floor(scroll.scrollTop/height)));
        const anchor=ids[anchorIndex],offset=scroll.scrollTop-anchorIndex*height;
        const chosen=table.querySelector('td.is-current-cell'),chosenRow=chosen?.dataset.row,chosenColumn=chosen?.dataset.column;
        ids=next;positions.clear();ids.forEach((id,index)=>positions.set(String(id),index));
        manifestVersion++;generation++;navigationGeneration++;navigation=null;pending.clear();loaded.clear();first=last=-1;
        const allowed=new Set(ids);
        for(const [id,row] of cache)if(searchChanged||!allowed.has(id)){row.remove();cache.delete(id);}
        if(searchChanged){
          appliedSearch=query;
          const url=new URL(location.href);
          if(query)url.searchParams.set('q',query);else url.searchParams.delete('q');
          history.replaceState(history.state,'',url);
        }
        setCounts();
        const newAnchor=searchChanged?0:positions.get(String(anchor))??Math.min(anchorIndex,Math.max(ids.length-1,0));
        scroll.scrollTop=searchChanged?0:Math.max(0,newAnchor*height+offset);
        await render(newAnchor);
        if(chosenRow&&!chosen?.closest('[data-draft-slot]')&&!positions.has(chosenRow)){
          const candidate=body.querySelector('td[data-column="'+chosenColumn+'"]:not([hidden])')||table.querySelector('tbody td[data-edit-cell]:not([hidden])');
          if(candidate)window.VitrineGrid?.select(candidate);
        }
      }while(refreshAgain);
    })().finally(()=>{refreshTask=null;});
    return refreshTask;
  }
  window.addEventListener('vitrine-editor-closed',()=>{if(manifestDeferred){manifestDeferred=false;refreshManifest().catch(report);}});
  const fleetDocumentRequests=new WeakMap();
  async function refreshFleetDocuments(rowNumber) {
    const row=cache.get(Number(rowNumber));
    const cell=row?.querySelector('td[data-fleet-documents-visible]');
    if (!cell) return;
    const request=Symbol();
    fleetDocumentRequests.set(cell,request);
    const response=await fetch(window.VitrineGridScope.url('/api/vitrine/rows',{ids:[Number(rowNumber)]}),{credentials:'same-origin',cache:'no-store'});
    const data=await response.json();
    if (!response.ok || typeof data.html!=='string') throw new Error(data.error?.message || 'Не удалось обновить ПД.');
    const template=document.createElement('template');
    template.innerHTML='<table><tbody>'+data.html+'</tbody></table>';
    const fresh=template.content.querySelector('td[data-fleet-documents-visible]');
    if (!fresh || cache.get(Number(rowNumber))!==row || fleetDocumentRequests.get(cell)!==request) return;
    if (cell.textContent!==fresh.textContent) {
      cell.textContent=fresh.textContent;
      cell.title=fresh.title;
      cell.classList.remove('just-saved');
      void cell.offsetWidth;
      cell.classList.add('just-saved');
    }
  }

  window.VitrineRows=Object.freeze({
    refreshManifest,
    refreshFleetDocuments,
    exportRows,
    filterDirectory(query){
      requestedSearch=query;
      if(query!==appliedSearch||refreshTask)return refreshManifest();
    },
    catalogPatch(patch){
      if(!patch.catalogId||!patch.id||typeof patch.name!=='string')return;
      const key=patch.catalogId+':'+patch.id,old=catalogPatches.get(key);
      if(old&&Number(old.revision||0)>Number(patch.revision||0))return;
      patch={...patch,aliases:[...new Set([...(old?.aliases||[]),patch.beforeName,patch.name])]};
      catalogPatches.set(key,patch);for(const row of cache.values())applyCatalogPatch(row,patch);
    },
    patch(patch){
      const key=patch.row+':'+patch.column,old=remotePatches.get(key);
      if(!old||Number(old.revision)<Number(patch.revision))remotePatches.set(key,patch);
      const row=cache.get(Number(patch.row));
      applyPatch(row,patch);
      if(patch.column==='B'&&document.body.dataset.workspaceTable==='vyvoz')refreshUnloadChoices(row,patch).catch(report);
      for(const item of catalogPatches.values())applyCatalogPatch(row,item);
    },
    activate:(cell,action)=>activate(cell,action).catch(report),
    resolveCell:cell=>cache.get(Number(cell.dataset.row))?.querySelector('td[data-column="'+cell.dataset.column+'"]')||cell,
    move(cell,dr,dc,wrap){const columns=visibleColumns();let r=(navigation?.r??rowIndex(cell))+dr,c=(navigation?.c??columns.indexOf(cell.dataset.column))+dc;if(wrap&&c<0){r--;c=columns.length-1;}if(wrap&&c>=columns.length){r++;c=0;}return go(cell,r,c);},
    edge(cell,end,full){const columns=visibleColumns();return go(cell,full?(end?ids.length+drafts.length-1:0):rowIndex(cell),end?columns.length-1:0);},
    get total(){return ids.length;}
  });
  render();
})();

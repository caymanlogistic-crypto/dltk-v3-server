'use strict';
(() => {
  const table=document.querySelector('.vitrine-table'),body=document.getElementById('vitrine-rows'),scroll=document.querySelector('.vitrine-table-scroll');
  if(!table||!body||!scroll)return;
  let ids=[];try{ids=JSON.parse(document.getElementById('vitrine-row-manifest').content);}catch{}
  const dateLayout=window.VitrineTripDateGroups?.create(ids);
  const drafts=Array.from(document.querySelectorAll('[data-draft-slot]'));
  const tripDraftBody=document.querySelector('[data-trip-drafts]');
  const empty=document.getElementById('vitrine-empty');
  const directoryRowCount=document.querySelector('[data-directory-row-count]');
  const search=document.querySelector('.vitrine-catalog-search input[type="search"]');
  let appliedSearch=search?.value.trim() ?? '', requestedSearch=appliedSearch;
  const tripFilterKey=params=>JSON.stringify(['tripStatus','tripClient'].map(key=>params.get(key) || ''));
  const noTripFilter=tripFilterKey(new URLSearchParams());
  let appliedTripFilter=tripFilterKey(new URLSearchParams(location.search));
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
  const pageSize=Number(table.dataset.rowPageSize)||100,cache=new Map(),loaded=new Map(),pending=new Map();
  const compactWindow=table.hasAttribute('data-row-page-size');
  const openAtLastRow=table.hasAttribute('data-open-at-last-row');
  const openUnfiltered=openAtLastRow && !appliedSearch && appliedTripFilter===noTripFilter;
  const centerOnLogin=openUnfiltered && table.hasAttribute('data-center-on-login');
  const bufferBefore=compactWindow ? 6 : 20;
  const bufferAfter=compactWindow ? 12 : 20;
  const refillMargin=compactWindow ? 3 : 5;
  const cachedPages=compactWindow ? 5 : 3;
  let windowStartPage=0,windowEndPage=-1;
  const showVehicleDriver=document.body.dataset.workspaceTable==='vyvoz' && !document.body.hasAttribute('data-vitrine-catalog');
  function shortDriverName(driver) {
    const parts=driver.replaceAll('.',' ').trim().split(/\s+/u);
    if (parts.length>3 || !parts.every(part=>/^\p{L}+(?:[-’']\p{L}+)*$/u.test(part))) return '';
    const [surname,...names]=parts;
    const initials=names.map(name=>Array.from(name)[0].toUpperCase()).join('.');
    return initials ? surname+' '+initials : surname;
  }
  function updateVehicleDriver(row) {
    if (!showVehicleDriver) return;
    const vehicle=row.querySelector('td[data-column="O"]:not([data-catalog-id="0"])');
    if (!vehicle) return;
    const value=vehicle.querySelector('[data-trip-vehicle-value]');
    const driver=row.querySelector('td[data-column="P"]')?.textContent.trim() ?? '';
    const shortName=shortDriverName(driver);
    if (!vehicle.textContent.trim() || !shortName) {
      delete vehicle.dataset.fleetDriverSuffix;
      if (value) delete value.dataset.fleetDriverSuffix;
      vehicle.removeAttribute('aria-label');
      return;
    }
    const suffix=' '+shortName;
    vehicle.dataset.fleetDriverSuffix=suffix;
    if (value) value.dataset.fleetDriverSuffix=suffix;
    vehicle.setAttribute('aria-label',vehicle.textContent+suffix);
  }
  const initial=Array.from(body.querySelectorAll('[data-vitrine-row]'));
  for (const row of initial) {
    updateVehicleDriver(row);
    cache.set(Number(row.dataset.rowNumber),row);
  }
  const initialStart=positions.get(initial[0]?.dataset.rowNumber) ?? 0;
  for (let start=initialStart;start<initialStart+initial.length;start+=pageSize) {
    if (!ids.slice(start,start+pageSize).every(id=>cache.has(id))) break;
    loaded.set(start/pageSize,true);
  }
  let height=initial[0]?.getBoundingClientRect().height||32,headHeight=table.querySelector('thead').getBoundingClientRect().height,generation=0,frame=0,first=-1,last=-1,navigation=null,navigationGeneration=0,mounts=0,lastScrollTop=scroll.scrollTop,manifestVersion=0;
  const top=document.createElement('tr'),bottom=document.createElement('tr'),tail=document.createElement('tr');
  for(const spacer of [top,bottom,tail]){spacer.dataset.virtualSpacer='';spacer.setAttribute('aria-hidden','true');const cell=document.createElement('td');cell.colSpan=table.querySelectorAll('thead th:not([hidden])').length;spacer.append(cell);}
  setCounts();
  table.setAttribute('aria-colcount',String(table.querySelectorAll('thead th').length));
  function report(error){window.PlanexNotifications.show({tone:'danger',message:error.message||'Не удалось загрузить строки. Повторите прокрутку.'});}
  function prune(){
    // A zoomed-out viewport can need more pages than the usual cache limit.
    const limit=Math.max(cachedPages,windowEndPage-windowStartPage+2);
    for (const number of loaded.keys()) {
      if (loaded.size<=limit) break;
      if (number<windowStartPage || number>windowEndPage) loaded.delete(number);
    }
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
    const rows=template.content.querySelectorAll('[data-vitrine-row]');
    for (const row of rows) updateVehicleDriver(row);
    return {rowIds:result.rowIds, rows};
  }
  async function exportRows(columns){
    const selectedIds=[...ids], search=appliedSearch, values=new Map(), missing=[];
    function capture(row){
      const cells=new Map(Array.from(row.querySelectorAll('td[data-column]'),cell=>[cell.dataset.column,cell.textContent+(cell.dataset.fleetDriverSuffix ?? '')]));
      values.set(Number(row.dataset.rowNumber),[row.querySelector('th')?.firstChild?.textContent ?? '',...columns.map(column=>cells.get(column) ?? '')]);
    }
    for(const id of selectedIds){
      const row=cache.get(id);
      if(row)capture(row);
      else missing.push(id);
    }
    const exportBatchSize=100;
    for(let start=0;start<missing.length;start+=exportBatchSize){
      const result=await fetchRows(missing.slice(start,start+exportBatchSize),search);
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
      const received=new Set(result.rowIds);
      const missing=new Set(wanted.filter(id=>!received.has(id)));
      if(missing.size){
        const remaining=ids.filter(id=>!missing.has(id));
        dateLayout?.remove(ids,remaining);
        ids=remaining;positions.clear();ids.forEach((id,index)=>positions.set(String(id),index));
        for(const id of missing)cache.delete(id);
        loaded.clear();pending.clear();manifestVersion++;generation++;navigationGeneration++;navigation=null;first=last=-1;viewportPlan=null;
        setCounts();table.removeAttribute('aria-busy');
        queue();return;
      }
      for(const row of result.rows){const id=Number(row.dataset.rowNumber);const old=cache.get(id);if(!old?.isConnected){for(const patch of remotePatches.values())if(Number(patch.row)===id)applyPatch(row,patch);for(const patch of catalogPatches.values())applyCatalogPatch(row,patch);cache.set(id,row);}}
      loaded.set(number,true);prune();
    })().finally(()=>{if(pending.get(number)===task)pending.delete(number);});
    pending.set(number,task);return task;
  }
  let focusLastRow=openUnfiltered && initial.length>0;
  let viewportPlan=null;
  function stamp(spacer,size){spacer.hidden=size===0;spacer.firstElementChild.style.setProperty('--spacer-height',size+'px');}
  const availableHeight=()=>scroll.clientHeight-headHeight;
  // Only the login arrival adds space to center the last row of a short table.
  let endPadding=centerOnLogin ? Math.max(0,(availableHeight()-height)/2) : 0;
  const windowOffset=index=>(dateLayout?.units(index)??index)*height;
  const lastRowUnits=dateLayout?.units(ids.length-1,true)??ids.length-1;
  let startPadding=centerOnLogin ? Math.max(0,availableHeight()/2-(lastRowUnits+.5)*height) : 0;
  const rowOffset=index=>startPadding+(dateLayout?.units(index,true)??index)*height;
  const viewportIndex=(top=scroll.scrollTop)=>dateLayout
    ? dateLayout.indexAt(top-startPadding,height,ids.length)
    : Math.max(0,Math.min(ids.length-1,Math.floor((top-startPadding)/height)));
  function stampEnd(end) {
    stamp(bottom,windowOffset(ids.length)-windowOffset(end)+(tripDraftBody ? 0 : endPadding));
    if(tripDraftBody)stamp(tail,Math.max(0,endPadding-drafts.length*height));
  }
  async function render(){
    if(window.VitrineGrid?.editing())return;
    const plan=viewportPlan;
    if(!ids.length){viewportPlan=null;body.replaceChildren(top,bottom);stamp(top,0);stamp(bottom,0);stamp(tail,0);first=last=0;windowStartPage=0;windowEndPage=-1;prune();table.removeAttribute('aria-busy');return;}
    const displayTop=plan?.top??scroll.scrollTop;
    const visible=Math.max(1,Math.ceil(availableHeight()/height)),viewportStart=viewportIndex(displayTop);
    const viewportEnd=dateLayout ? Math.min(ids.length,viewportIndex(displayTop+availableHeight())+1)
      : Math.min(ids.length,viewportStart+visible+1);
    if(first>=0&&(first===0||viewportStart>=first+refillMargin)&&(last===ids.length||viewportEnd<=last-refillMargin))return;
    const start=Math.max(0,Math.min(ids.length-1,viewportStart-bufferBefore));
    const end=Math.min(ids.length,compactWindow ? viewportEnd+bufferAfter : start+visible+bufferBefore+bufferAfter);
    const ticket=++generation;
    windowStartPage=Math.floor(start/pageSize);
    windowEndPage=Math.floor((end-1)/pageSize);
    table.setAttribute('aria-busy','true');
    try{
      const needed=[];for(let p=windowStartPage;p<=windowEndPage;p++)needed.push(page(p));
      await Promise.all(needed);if(ticket!==generation||window.VitrineGrid?.editing())return;
      const rows=ids.slice(start,end).map(id=>cache.get(id));
      if(rows.some(row=>!row))throw new Error('Не удалось загрузить все строки окна. Повторите прокрутку.');
      const mountStarted=performance.now();
      window.VitrineDisplay?.prepareRows(rows.filter(row=>!row.isConnected));
      rows.forEach((row,index)=>row.setAttribute('aria-rowindex',String(start+index+2)));
      stamp(top,startPadding+windowOffset(start));stampEnd(end);
      if(top.parentElement!==body)body.prepend(top);
      if(bottom.parentElement!==body)body.append(bottom);
      const mounted=dateLayout?.rowsWithDividers(rows,start,body,visibleColumns().length+1)??rows;
      const keep=new Set(mounted);
      for(const row of body.querySelectorAll('[data-vitrine-row],[data-trip-date-divider]'))if(!keep.has(row))row.remove();
      let cursor=top.nextSibling;
      for(const row of mounted){if(cursor===row)cursor=cursor.nextSibling;else body.insertBefore(row,cursor||bottom);}
      first=start;last=end;
      if(plan) {
        viewportPlan=null;
        scroll.scrollTop=plan.top;
      }
      window.dispatchEvent(new CustomEvent('vitrine-rows-updated'));
      if(plan?.focus && (!plan.focus.onClear || search?.value.trim()==='')) {
        const row=cache.get(plan.focus.row);
        const cell=(plan.focus.column && row?.querySelector('td[data-column="'+plan.focus.column+'"]:not([hidden])'))
          || row?.querySelector('td[data-edit-cell]:not([hidden])');
        if(cell?.isConnected) {
          if(plan.focus.onClear) scroll.scrollLeft=0;
          window.VitrineGrid?.select(cell);
          cell.focus({preventScroll:true});
          plan.focus.action?.(cell);
        }
      }
      if(focusLastRow){
        focusLastRow=false;
        const cell=cache.get(ids.at(-1))?.querySelector('td[data-edit-cell]:not([hidden])');
        if(cell?.isConnected && (!document.activeElement || document.activeElement===document.body)){
          window.VitrineGrid?.select(cell);
          cell.focus({preventScroll:true});
        }
      }
      table.dataset.windowMounts=String(++mounts);table.dataset.lastMountScriptMs=(performance.now()-mountStarted).toFixed(2);
      prune();
      const upward=scroll.scrollTop<lastScrollTop;lastScrollTop=scroll.scrollTop;
      const ahead=upward?Math.floor(start/pageSize)-1:Math.floor((end-1)/pageSize)+1;
      if(ahead>=0&&ahead*pageSize<ids.length&&!loaded.has(ahead))page(ahead).catch(()=>{});
    }catch(error){if(ticket===generation)report(error);}
    finally{
      if(ticket===generation){
        if(viewportPlan===plan)viewportPlan=null;
        table.removeAttribute('aria-busy');
      }
    }
  }
  function queue(){if(!frame&&!viewportPlan)frame=requestAnimationFrame(()=>{frame=0;if(!viewportPlan)render();});}
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
  window.addEventListener('vitrine-columns-updated',()=>{for(const spacer of [top,bottom,tail])spacer.firstElementChild.colSpan=visibleColumns().length+1;first=-1;queue();});
  const rangeRows=()=>[...ids.map(String),...drafts.map(row=>row.dataset.rowNumber)];
  function cachedRangeRows(keys){
    return keys.map(key=>Number(key)>0 ? cache.get(Number(key)) : drafts.find(row=>row.dataset.rowNumber===key));
  }
  async function readRangeRows(keys){
    const version=manifestVersion;
    const rows=cachedRangeRows(keys);
    const missing=keys.filter((_,index)=>!rows[index]).map(Number);
    const fresh=new Map();
    for (let offset=0;offset<missing.length;offset+=100) {
      const page=await fetchRows(missing.slice(offset,offset+100),appliedSearch);
      for (const row of page.rows) fresh.set(row.dataset.rowNumber,row);
    }
    if (version!==manifestVersion) throw new Error('Состав таблицы изменился. Выберите диапазон заново.');
    const resolved=cachedRangeRows(keys).map((row,index)=>row??fresh.get(keys[index]));
    if (resolved.some(row=>!row)) throw new Error('Не удалось загрузить диапазон таблицы.');
    return resolved;
  }
  let rangeReveal=0;
  async function revealRangeEnd(key,column){
    const ticket=++rangeReveal;
    const index=positions.get(key);
    if (index!==undefined) {
      ensureVisible(index);
      await render();
    }
    if (ticket!==rangeReveal) return;
    const row=cachedRangeRows([key])[0];
    const cell=row?.querySelector('td[data-column="'+column+'"]');
    if (cell?.isConnected) window.VitrineGrid.reveal(cell);
  }
  function invalidateRangeRows(keys){
    for (const key of keys) {
      const index=positions.get(String(key));
      if (index===undefined || cache.get(Number(key))?.isConnected) continue;
      cache.delete(Number(key));
      loaded.delete(Math.floor(index/pageSize));
    }
  }
  function visibleColumns(){return Array.from(table.querySelectorAll('thead th[data-column]:not([hidden])')).map(node=>node.dataset.column);}
  function ensureVisible(index){
    const available=availableHeight(),offset=rowOffset(index);
    if(offset<scroll.scrollTop)scroll.scrollTop=offset;
    else if(offset+height>scroll.scrollTop+available)scroll.scrollTop=Math.max(0,offset+height-available);
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
    if(target){window.VitrineGrid.select(target);target.focus({preventScroll:true});window.VitrineGrid.reveal(target);action?.(target);}
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
      const offset=r%pageSize;
      const ahead=Math.floor(r/pageSize)+(offset>pageSize*.6?1:offset<pageSize*.4?-1:0);
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
    if(!row || patch.kind==='edo-counterparties')return;
    const active=window.VitrineGrid?.editingCell();
    for(const cell of row.querySelectorAll('td[data-catalog-id]')){
      if(String(cell.dataset.catalogId)!==String(patch.catalogId)||cell===active)continue;
      const bindings=patch.bindings||[];
      if(bindings.length&&!bindings.some(binding=>binding.tableSlug===(document.body.dataset.workspaceTable||'vyvoz')&&binding.fieldKey===cell.dataset.column))continue;
      if(cell.dataset.column==='A'||((cell.dataset.column==='K'||cell.dataset.column==='O')&&document.body.dataset.workspaceTable==='vyvoz'))continue;
      if (!cell.dataset.sharedChoices) {
        let choices=[];try{choices=JSON.parse(cell.dataset.choices||'[]');}catch{}
        choices=choices.filter(value=>!(patch.aliases||[patch.beforeName,patch.name]).includes(value));
        if(patch.active!==false&&patch.active!==0)choices.push(patch.name);
        cell.dataset.choices=JSON.stringify([...new Set(choices)]);
      }
      const matchesReference=String(cell.dataset.referenceId)===String(patch.id);
      const coloredCatalog=['E','T','U','U2'].includes(cell.dataset.column)&&document.body.dataset.workspaceTable==='vyvoz';
      const legacyMatch=coloredCatalog&&!Number(cell.dataset.referenceId)&&cell.textContent!==''&&
        [patch.name,patch.beforeName,...(patch.aliases||[])].some(name=>normalizedCatalogName(name)===normalizedCatalogName(cell.textContent));
      if(coloredCatalog&&(matchesReference||legacyMatch)){
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
        const saved=remotePatches.get(cell.dataset.row+':'+cell.dataset.column);
        if(saved&&String(saved.referenceId??cell.dataset.referenceId)===String(patch.id))saved.value=patch.name;
      }
    }
  }
  window.addEventListener('vitrine-editor-closed',()=>{for(const row of cache.values())for(const patch of catalogPatches.values())applyCatalogPatch(row,patch);});
  let refreshTask=null,refreshAgain=false,manifestDeferred=false,createdFocus=null,keepAbsoluteTop=false;
  async function refreshManifest({focus=null,keepViewport=false}={}){
    keepAbsoluteTop ||= keepViewport;
    if(focus) {
      const headBottom=table.tHead.getBoundingClientRect().bottom;
      const offset=focus.origin.getBoundingClientRect().top-headBottom;
      createdFocus={...focus,offset};
    }
    if(window.VitrineGrid?.editing()){manifestDeferred=true;return;}
    if(refreshTask){refreshAgain=true;return refreshTask;}
    refreshTask=(async()=>{
      do{
        refreshAgain=false;
        const query=requestedSearch;
        const manifestUrl=()=>window.VitrineGridScope.url('/api/workspace/manifest',{rowFilters:true,search:requestedSearch});
        const urlRequested=manifestUrl();
        const response=await fetch(urlRequested,{credentials:'same-origin'});
        if(query!==requestedSearch||urlRequested!==manifestUrl()){refreshAgain=true;continue;}
        const result=await response.json();
        if(query!==requestedSearch||urlRequested!==manifestUrl()){refreshAgain=true;continue;}
        if(search&&search.value.trim()!==query)continue;
        if(!response.ok||result.error)throw new Error(result.error?.message||'Не удалось обновить список строк.');
        if(!Array.isArray(result.rowIds)||result.rowIds.some(id=>!Number.isInteger(id)))throw new Error('Некорректный список строк.');
        if(window.VitrineGrid?.editing()){manifestDeferred=true;return;}
        const next=[...new Set(result.rowIds)];
        const nextDateGroups=result.tripDateGroups??[];
        const searchChanged=query!==appliedSearch;
        const tripFilter=tripFilterKey(new URL(urlRequested,location.href).searchParams);
        const filterChanged=tripFilter!==appliedTripFilter;
        const target=createdFocus && next.includes(createdFocus.row) ? createdFocus : null;
        if(target)createdFocus=null;
        const preserveViewport=keepAbsoluteTop;
        keepAbsoluteTop=false;
        if(!searchChanged && !filterChanged && !target && next.join(',')===ids.join(',')
          && (!dateLayout || dateLayout.agrees(nextDateGroups)))continue;
        const anchorIndex=Math.max(0,Math.min(ids.length-1,viewportIndex()));
        const anchor=ids[anchorIndex],offset=scroll.scrollTop-rowOffset(anchorIndex);
        const chosen=table.querySelector('td.is-current-cell'),chosenRow=chosen?.dataset.row,chosenColumn=chosen?.dataset.column;
        const oldPositions=document.body.hasAttribute('data-vitrine-catalog') ? new Map(positions) : null;
        dateLayout?.replace(next,nextDateGroups);
        ids=next;positions.clear();ids.forEach((id,index)=>positions.set(String(id),index));
        manifestVersion++;generation++;navigationGeneration++;navigation=null;pending.clear();loaded.clear();first=last=-1;
        const allowed=new Set(ids);
        for(const id of cache.keys())if(searchChanged||!allowed.has(id)||oldPositions&&oldPositions.get(String(id))!==positions.get(String(id)))cache.delete(id);
        if(searchChanged){
          appliedSearch=query;
          const url=new URL(location.href);
          if(query)url.searchParams.set('q',query);else url.searchParams.delete('q');
          history.replaceState(history.state,'',url);
        }
        appliedTripFilter=tripFilter;
        setCounts();
        const resetScroll=searchChanged || filterChanged;
        if (resetScroll) startPadding=endPadding=0;
        const returnToEnd=openAtLastRow && resetScroll && !query && ids.length>0
          && (searchChanged || tripFilter===noTripFilter);
        const newAnchor=resetScroll?0:positions.get(String(anchor))??Math.min(anchorIndex,Math.max(ids.length-1,0));
        const top=target
          ? rowOffset(positions.get(String(target.row)))-Math.max(0,Math.min(target.offset,availableHeight()-height))
          : returnToEnd ? rowOffset(ids.length-1)+height-availableHeight()
            : resetScroll ? 0 : preserveViewport ? scroll.scrollTop : rowOffset(newAnchor)+offset;
        viewportPlan={top:Math.max(0,top),focus:target ?? (returnToEnd ? {row:ids.at(-1),onClear:true} : null)};
        await render();
        if(!target&&!returnToEnd&&chosenRow&&!chosen?.closest('[data-draft-slot]')&&!positions.has(chosenRow)){
          const candidate=body.querySelector('td[data-column="'+chosenColumn+'"]:not([hidden])')||table.querySelector('tbody td[data-edit-cell]:not([hidden])');
          if(candidate)window.VitrineGrid?.select(candidate);
        }
      }while(refreshAgain);
    })().finally(()=>{refreshTask=null;createdFocus=null;});
    return refreshTask;
  }
  let tripDateFrame=0;
  function refreshTripDates() {
    if(!dateLayout||tripDateFrame)return;
    tripDateFrame=requestAnimationFrame(()=>{
      tripDateFrame=0;
      refreshManifest().catch(report);
    });
  }
  window.addEventListener('vitrine-editor-closed',()=>{if(manifestDeferred){manifestDeferred=false;refreshManifest().catch(report);}});
  const fleetDocumentRequests=new WeakMap();
  function fleetDocumentCell(rowNumber) {
    return cache.get(Number(rowNumber))?.querySelector('td[data-fleet-documents-visible]');
  }

  function applyFleetDocuments(rowNumber,value,cleared=false) {
    const cell=fleetDocumentCell(rowNumber);
    if (!cell) return;
    fleetDocumentRequests.delete(cell);
    if (cell.textContent===value) return;
    cell.textContent=value;
    if (value) cell.removeAttribute('aria-label');
    else cell.setAttribute('aria-label',cell.dataset.emptyLabel);
    cell.classList.remove('just-saved','just-remote','just-cleared');
    void cell.offsetWidth;
    cell.classList.add(cleared ? 'just-cleared' : 'just-saved');
  }

  async function refreshFleetDocuments(rowNumber,cleared=false) {
    const cell=fleetDocumentCell(rowNumber);
    if (!cell) return;
    const request=Symbol();
    fleetDocumentRequests.set(cell,request);
    const url=window.VitrineGridScope.url('/api/vitrine/rows',{ids:[Number(rowNumber)],rowFilters:true,fleetDocuments:true});
    const response=await fetch(url,{credentials:'same-origin',cache:'no-store'});
    const data=await response.json();
    if (!response.ok || typeof data.value!=='string') throw new Error(data.error?.message || 'Не удалось обновить ПД.');
    if (fleetDocumentCell(rowNumber)===cell && fleetDocumentRequests.get(cell)===request) {
      applyFleetDocuments(rowNumber,data.value,cleared);
    }
  }

  window.VitrineRows=Object.freeze({
    refreshManifest,
    refreshTripDates,
    refreshFleetDocuments,
    applyFleetDocuments,
    exportRows,
    rangeRows,
    cachedRangeRows,
    readRangeRows,
    revealRangeEnd,
    invalidateRangeRows,
    visibleColumns,
    replaceUnloadChoices(groups,clients){
      for (const row of cache.values()) {
        const client=row.querySelector('td[data-column="B"]');
        const address=row.querySelector('td[data-column="K"][data-catalog-id]:not([data-catalog-id="0"])');
        if (!client || !address) continue;
        const name=client.textContent.trim().replace(/\s+/g,' ').toLocaleLowerCase('ru');
        const referenceId=Number(client.dataset.referenceId);
        if (!referenceId && clients===null) continue;
        const id=referenceId || (Object.hasOwn(clients,name) ? clients[name] : null);
        address.dataset.choices=JSON.stringify(groups[id ? 'id:'+id : 'name:'+name]??[]);
      }
    },
    filterDirectory(query,{force=false}={}){
      requestedSearch=query;
      if(force||query!==appliedSearch||refreshTask)return refreshManifest();
    },
    catalogPatch(patch){
      if(!patch.catalogId||!patch.id||typeof patch.name!=='string')return;
      const key=patch.catalogId+':'+patch.id,old=catalogPatches.get(key);
      if(old&&Number(old.revision||0)>Number(patch.revision||0))return;
      patch={...patch,aliases:[...new Set([...(old?.aliases||[]),patch.beforeName,patch.name])]};
      catalogPatches.set(key,patch);window.VitrineGrid?.patchSharedOptions(patch);
      for(const row of cache.values())applyCatalogPatch(row,patch);
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
    updateVehicleDriver,
    shortDriverName,
    resolveCell:cell=>cache.get(Number(cell.dataset.row))?.querySelector('td[data-column="'+cell.dataset.column+'"]')||cell,
    move(cell,dr,dc,wrap){const columns=visibleColumns();let r=(navigation?.r??rowIndex(cell))+dr,c=(navigation?.c??columns.indexOf(cell.dataset.column))+dc;if(wrap&&c<0){r--;c=columns.length-1;}if(wrap&&c>=columns.length){r++;c=0;}return go(cell,r,c);},
    edge(cell,end,full){const columns=visibleColumns();return go(cell,full?(end?ids.length+drafts.length-1:0):rowIndex(cell),end?columns.length-1:0);},
    get total(){return ids.length;}
  });
  if(tripDraftBody)tripDraftBody.append(tail);
  if(dateLayout && initial.length) body.replaceChildren(...dateLayout.rowsWithDividers(initial,initialStart,body,visibleColumns().length+1));
  if(openUnfiltered && initial.length){
    stamp(top,startPadding+windowOffset(initialStart));
    stampEnd(ids.length);
    body.prepend(top);
    body.append(bottom);
    const alignment=centerOnLogin ? (height-availableHeight())/2 : height-availableHeight();
    scroll.scrollTop=Math.max(0,rowOffset(ids.length-1)+alignment);
    lastScrollTop=scroll.scrollTop;
  }
  if (table.hasAttribute('data-center-on-login')) {
    const url=new URL(location.href);
    url.searchParams.delete('from');
    url.searchParams.delete('viewport');
    history.replaceState(history.state,'',url);
  }
  render();
})();

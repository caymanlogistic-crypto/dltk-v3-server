'use strict';
(() => {
  const grid = window.VitrineGrid;
  const rows = window.VitrineRows;
  const table = document.querySelector('.vitrine-table');
  if (!grid || !rows || !table) return;
  const scroll = table.closest('.vitrine-table-scroll');
  const cellSelector = 'td[data-edit-cell]:not([hidden])';
  const rowKey = cell => cell.closest('tr').dataset.rowNumber;
  const point = cell => ({row:rowKey(cell),column:cell.dataset.column});
  const same = (a,b) => a?.row===b?.row && a?.column===b?.column;
  const report = error => window.PlanexNotifications.show({tone:'danger',message:error.message || 'Не удалось обработать диапазон.'});
  let range = null;
  let copiedRange = null;
  let copyCheck = null;
  const invalidCopies = new Set();
  let copiedSelection = null;
  let singleSelection = null;
  let clipboard = null;
  let drag = null;
  let scrollFrame = 0;
  let swallowClick = false;
  let painted = new Set();

  function span(values,first,last) {
    const a=values.indexOf(first), b=values.indexOf(last);
    return a<0 || b<0 ? [] : values.slice(Math.min(a,b),Math.max(a,b)+1);
  }

  function bounds() {
    if (!range) return null;
    const rowKeys = span(rows.rangeRows(),range.anchor.row,range.end.row);
    const columns = span(rows.visibleColumns(),range.anchor.column,range.end.column);
    return rowKeys.length && columns.length ? {rowKeys,columns} : null;
  }

  function addresses(cell) {
    const selected = bounds();
    if (selected?.rowKeys.includes(rowKey(cell)) && selected.columns.includes(cell.dataset.column)) return selected;
    return {rowKeys:[rowKey(cell)],columns:[cell.dataset.column]};
  }

  function draw() {
    const visible = new Map(Array.from(table.querySelectorAll('tbody tr[data-row-number]'),row=>[row.dataset.rowNumber,row]));
    const marks = new Map();
    function mark(area,kind) {
      if (!area) return;
      for (const [r,id] of area.rowKeys.entries()) {
        const row = visible.get(id);
        if (!row) continue;
        for (const [c,column] of area.columns.entries()) {
          const cell = row.querySelector('td[data-column="'+column+'"]:not([hidden])');
          if (!cell) continue;
          const edges = [r===0?'top':'',r===area.rowKeys.length-1?'bottom':'',
            c===0?'left':'',c===area.columns.length-1?'right':''].filter(Boolean).join(' ');
          marks.set(cell,{kind,edges});
          if (kind==='selected') cell.setAttribute('aria-selected','true');
        }
      }
    }
    for (const cell of painted) {
      cell.removeAttribute('data-vitrine-range');
      cell.removeAttribute('data-vitrine-range-edges');
      cell.removeAttribute('data-vitrine-copy-error');
      if (!cell.classList.contains('is-current-cell')) cell.setAttribute('aria-selected','false');
    }
    const selected = bounds() ?? (singleSelection ? {rowKeys:[singleSelection.row],columns:[singleSelection.column]} : null);
    if (copiedRange && (range!==copiedSelection || singleSelection)) {
      mark(copiedRange,'copied');
      mark(selected,'selected');
    } else {
      mark(selected,'selected');
      mark(copiedRange,'copied');
    }
    for (const [cell,{kind,edges}] of marks) {
      cell.dataset.vitrineRange=kind;
      cell.dataset.vitrineRangeEdges=edges;
      if (kind==='copied' && invalidCopies.has(rowKey(cell)+':'+cell.dataset.column)) {
        cell.setAttribute('data-vitrine-copy-error','');
      }
    }
    painted = new Set(marks.keys());
  }

  function clearSelection() {
    if (!range) return;
    range=null;
    draw();
  }

  function extend(anchor,end) {
    if (same(anchor,end)) clearSelection();
    else {
      if (same(range?.anchor,anchor) && same(range?.end,end)) return;
      range={anchor,end};
      draw();
    }
  }

  table.setAttribute('aria-multiselectable','true');
  table.addEventListener('pointerdown',event=>{
    swallowClick=false;
    if (event.button!==0 || grid.editing() || grid.rangeBusy() || event.target.closest('button, a, input, select, textarea')) return;
    const cell = event.target.closest(cellSelector);
    if (!cell) return;
    const current = table.querySelector('.is-current-cell');
    if (event.shiftKey && current) {
      event.preventDefault();
      event.stopPropagation();
      extend(range?.anchor??point(current),point(cell));
      swallowClick=true;
      return;
    }
    clearSelection();
    drag={pointer:event.pointerId,anchor:point(cell),started:false,x:event.clientX,y:event.clientY};
    grid.select(cell);
    if (copiedRange) {
      singleSelection=drag.anchor;
      draw();
    }
  },true);

  function updateDrag() {
    if (!drag) return;
    const rect = scroll.getBoundingClientRect();
    const bodyTop = Math.min(rect.bottom-2,Math.max(rect.top,table.tHead.getBoundingClientRect().bottom));
    const x = Math.max(rect.left+1,Math.min(rect.right-2,drag.x));
    const y = Math.max(bodyTop+1,Math.min(rect.bottom-2,drag.y));
    const cell = document.elementFromPoint(x,y)?.closest(cellSelector);
    if (cell && table.contains(cell) && !same(drag.anchor,point(cell))) drag.started=true;
    if (drag.started && cell && table.contains(cell)) extend(drag.anchor,point(cell));
    if (!drag.started) return;
    const dx = drag.x<rect.left+16 ? -16 : drag.x>rect.right-16 ? 16 : 0;
    const dy = drag.y<bodyTop+12 ? -16 : drag.y>rect.bottom-16 ? 16 : 0;
    const left=scroll.scrollLeft, top=scroll.scrollTop;
    if (dx || dy) scroll.scrollBy(dx,dy);
    if (!scrollFrame && (left!==scroll.scrollLeft || top!==scroll.scrollTop)) {
      scrollFrame=requestAnimationFrame(()=>{scrollFrame=0;updateDrag();});
    }
  }

  window.addEventListener('pointermove',event=>{
    if (drag?.pointer!==event.pointerId || !(event.buttons&1)) return;
    drag.x=event.clientX;
    drag.y=event.clientY;
    updateDrag();
  });
  function finishDrag() {
    swallowClick=drag?.started??swallowClick;
    drag=null;
    cancelAnimationFrame(scrollFrame);
    scrollFrame=0;
  }
  window.addEventListener('pointerup',event=>{if (drag?.pointer===event.pointerId) finishDrag();});
  window.addEventListener('pointercancel',finishDrag);
  window.addEventListener('blur',finishDrag);
  table.addEventListener('click',event=>{
    if (!swallowClick) return;
    swallowClick=false;
    event.preventDefault();
    event.stopImmediatePropagation();
  },true);
  window.addEventListener('vitrine-cell-selected',()=>{
    const active=table.querySelector('.is-current-cell');
    if (!active) return;
    const previousSingle=singleSelection;
    if (copiedRange && table.classList.contains('keyboard-navigation')) singleSelection=point(active);
    else if (singleSelection && !same(point(active),singleSelection)) singleSelection=null;
    if (range && !same(point(active),range.anchor)) clearSelection();
    else if (!same(previousSingle,singleSelection)) draw();
  });
  for (const name of ['vitrine-rows-updated','vitrine-columns-updated']) {
    window.addEventListener(name,()=>{if (painted.size || range || copiedRange) draw();});
  }

  document.addEventListener('keydown',event=>{
    const cell=event.target.closest(cellSelector);
    const focusLeftViewport=range && (event.target===document.body || event.target===scroll);
    if ((!cell && !focusLeftViewport) || grid.editing() || grid.rangeBusy() || window.VitrineClipboardMenu?.opened()) return;
    if (event.key==='Escape' && range) {
      event.preventDefault();
      clearSelection();
      return;
    }
    const movement = {ArrowLeft:[0,-1],ArrowRight:[0,1],ArrowUp:[-1,0],ArrowDown:[1,0]}[event.key];
    if (!event.shiftKey || !movement || event.altKey || event.ctrlKey || event.metaKey) return;
    event.preventDefault();
    event.stopPropagation();
    const anchor=range?.anchor??point(cell), end=range?.end??anchor;
    const rowKeys=rows.rangeRows(), columns=rows.visibleColumns();
    const row=rowKeys[rowKeys.indexOf(end.row)+movement[0]], column=columns[columns.indexOf(end.column)+movement[1]];
    if (!row || !column) return;
    extend(anchor,{row,column});
    rows.revealRangeEnd(row,column).catch(report);
  },true);

  function selectContext(cell) {
    const area=bounds(), location=point(cell);
    if (area?.rowKeys.includes(location.row) && area.columns.includes(location.column)) return table.querySelector('.is-current-cell')??cell;
    clearSelection();
    grid.select(cell);
    cell.focus({preventScroll:true});
    return cell;
  }

  function cellText(cell) {
    return cell.querySelector('[data-trip-vehicle-value],[data-password-cell-value]')?.textContent ?? cell.textContent;
  }

  function copyData(area,mountedRows) {
    const values = mountedRows.map(row=>area.columns.map(column=>{
      const cell=row.querySelector('td[data-column="'+column+'"]');
      const value=cellText(cell);
      const ref=Number(cell.dataset.row)>0 ? {row:Number(cell.dataset.row),column,revision:Number(cell.dataset.revision),
        baseHash:cell.dataset.base,value} : null;
      return {value,source:ref};
    }));
    const one=values.length===1 && values[0].length===1;
    const escape=value=>/["\t\n\r]/.test(value) ? '"'+value.replaceAll('"','""')+'"' : value;
    const text=one ? values[0][0].value : values.map(row=>row.map(cell=>escape(cell.value)).join('\t')).join('\r\n');
    return {text,values};
  }

  function resetCopyCheck() {
    copyCheck?.abort();
    invalidCopies.clear();
  }

  async function checkCopiedFormats(area) {
    const ids=area.rowKeys.map(Number).filter(id=>id>0);
    const controller=new AbortController();
    copyCheck=controller;
    const url=window.VitrineGridScope.url('/api/vitrine/paste-check',{rowFilters:true});
    const csrf=document.querySelector('meta[name="csrf-token"]').content;
    for (let i=0; i<ids.length; i+=100) {
      const response=await fetch(url,{
        method:'POST',credentials:'same-origin',signal:controller.signal,
        headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify({action:'copyDiagnostics',rows:ids.slice(i,i+100),columns:area.columns})
      });
      if (!response.ok) return;
      const {invalid}=await response.json();
      if (controller.signal.aborted || !Array.isArray(invalid)) return;
      for (const {row,column} of invalid) invalidCopies.add(row+':'+column);
      if (invalid.length) draw();
    }
  }

  function copied(area,data,selection) {
    resetCopyCheck();
    clipboard=data;
    copiedRange=area;
    copiedSelection=selection;
    singleSelection=null;
    draw();
    if (document.body.hasAttribute('data-copy-diagnostics')) checkCopiedFormats(area).catch(()=>{});
  }

  async function copy(cell,event=null) {
    const area=addresses(cell);
    const selection=range;
    const mountedRows=rows.cachedRangeRows(area.rowKeys);
    event?.preventDefault();
    if (mountedRows.every(Boolean)) {
      const data=copyData(area,mountedRows);
      if (event) {
        event.clipboardData.setData('text/plain',data.text);
        if (data.values.length===1 && data.values[0].length===1 && data.values[0][0].source) {
          event.clipboardData.setData('application/x-dltk-cell',JSON.stringify(data.values[0][0].source));
        }
      } else await navigator.clipboard.writeText(data.text);
      copied(area,data,selection);
      return;
    }
    const data=rows.readRangeRows(area.rowKeys).then(complete=>copyData(area,complete));
    if (window.ClipboardItem && navigator.clipboard?.write) {
      await navigator.clipboard.write([new ClipboardItem({'text/plain':data.then(item=>new Blob([item.text],{type:'text/plain'}))})]);
    } else await navigator.clipboard.writeText((await data).text);
    copied(area,await data,selection);
  }

  function parse(text) {
    if (clipboard?.text.replace(/\r\n/g,'\n')===text.replace(/\r\n/g,'\n')) return clipboard.values;
    if (!/[\t\r\n]/.test(text)) return [[{value:text}]];
    const input=text.replace(/^\uFEFF/,'').replace(/\r\n?/g,'\n');
    const result=[];
    let line=[], value='', quoted=false, lastBreak=false;
    const add=()=>{line.push({value});value='';};
    for (let i=0;i<input.length;i++) {
      const char=input[i], next=input[i+1];
      lastBreak=false;
      if (quoted) {
        if (char==='"' && next==='"') {value+='"';i++;}
        else if (char==='"' && (!next || next==='\t' || next==='\n')) quoted=false;
        else value+=char;
      } else if (char==='"' && value==='') quoted=true;
      else if (char==='\t') add();
      else if (char==='\n') {add();result.push(line);line=[];lastBreak=true;}
      else value+=char;
    }
    if (quoted) throw new Error('В буфере не завершено значение в кавычках.');
    if (!lastBreak) {add();result.push(line);}
    if (!result.every(row=>row.length===result[0].length)) throw new Error('Вставьте прямоугольный диапазон с одинаковым числом столбцов в каждой строке.');
    return result;
  }

  function destination(cell,source) {
    const selected=addresses(cell);
    const height=source.length, width=source[0].length;
    if (selected.rowKeys.length*selected.columns.length>1) {
      if (selected.rowKeys.length%height===0 && selected.columns.length%width===0) return selected;
      throw new Error('Размер вставляемого диапазона не совпадает с выделением.');
    }
    const rowKeys=rows.rangeRows(), columns=rows.visibleColumns();
    const r=rowKeys.indexOf(rowKey(cell)), c=columns.indexOf(cell.dataset.column);
    if (r<0 || c<0 || r+height>rowKeys.length || c+width>columns.length) throw new Error('Диапазон выходит за границы таблицы.');
    return {rowKeys:rowKeys.slice(r,r+height),columns:columns.slice(c,c+width)};
  }

  document.addEventListener('input',event=>{
    if (event.inputType==='insertFromPaste') window.VitrineRange.pasted();
  });
  window.VitrineRange=Object.freeze({
    blocksHover:()=>Boolean(drag || range), clearSelection, selectContext,
    copy, parse, cellText, destination,
    containsMany:cell=>{const area=addresses(cell);return area.rowKeys.length*area.columns.length>1;},
    pasted(){if (copiedRange) {resetCopyCheck();copiedRange=null;copiedSelection=null;singleSelection=null;draw();}}
  });
})();

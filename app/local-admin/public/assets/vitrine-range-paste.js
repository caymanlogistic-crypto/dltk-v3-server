'use strict';
(() => {
  const grid=window.VitrineGrid, range=window.VitrineRange, rows=window.VitrineRows;
  if (!grid || !range || !rows) return;
  const csrf=document.querySelector('meta[name="csrf-token"]').content;
  let busy=false;
  let retry=null;

  async function transaction(work) {
    if (busy) throw new Error('Дождитесь сохранения диапазона.');
    busy=true;
    try { return await work(); }
    finally { busy=false; }
  }

  function payload(cell,value) {
    return {row:Number(cell.dataset.row),column:cell.dataset.column,value,
      revision:Number(cell.dataset.revision),baseHash:cell.dataset.base};
  }

  function cellAt(row,column) {
    return row?.querySelector('td[data-column="'+column+'"]');
  }

  function requiresForm(cell) {
    return !document.body.dataset.vitrineCatalog && document.body.dataset.workspaceTable==='vyvoz' &&
      cell.dataset.column==='M' && Number(cell.dataset.catalogId)>0;
  }

  function check(cell) {
    if (!cell || cell.matches('[data-readonly-cell],[data-derived-source],[aria-readonly="true"]') ||
        ['client-managers','client-unload-points','unload-contacts'].includes(cell.dataset.kind) ||
        cell.dataset.draftType==='password') {
      const address=cell ? cell.dataset.column+(cell.closest('tr').querySelector('th')?.firstChild?.textContent??'') : '';
      throw new Error([address,'Ячейка недоступна для вставки.'].filter(Boolean).join(' · '));
    }
    if (Number(cell.dataset.row)<1 && !cell.closest('[data-trip-draft]')) {
      throw new Error('Вставьте диапазон в сохранённые строки справочника.');
    }
  }

  function rememberBefore(cell) {
    return {cell,before:range.cellText(cell),reset:!cell.classList.contains('local-edited'),
      initial:payload(cell,'')};
  }

  async function send(action,changes) {
    const cells=changes.map(item=>item.payload);
    const signature=JSON.stringify({action,cells});
    const requestId=retry?.signature===signature ? retry.id : crypto.randomUUID();
    const body=JSON.stringify({action,cells,requestId});
    if (new TextEncoder().encode(body).length>99000) throw new Error('Диапазон слишком большой. Вставьте его частями.');
    retry={signature,id:requestId};
    const response=await fetch(window.VitrineGridScope.url('/api/vitrine/cell'),{
      method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body
    });
    const data=await response.json();
    if (!response.ok || data.error) throw new Error(data.error?.message || 'Не удалось сохранить диапазон.');
    if (!Array.isArray(data.cells) || data.cells.length!==changes.length) throw new Error('Не удалось подтвердить сохранение диапазона. Обновите таблицу.');
    const keys=new Set();
    for (const [i,item] of data.cells.entries()) {
      const {cell,payload:target}=changes[i];
      if (item.row!==target.row || item.column!==target.column) throw new Error('Изменился состав диапазона. Обновите таблицу.');
      if (target.row<0) continue;
      grid.applyRangeSave(window.VitrineRows.resolveCell(cell),item.result);
      keys.add(String(item.row));
    }
    retry=null;
    rows.invalidateRangeRows(keys);
    const saved=new Map(data.cells.map((item,index)=>[
      item.row+':'+item.column,{cell:rows.resolveCell(changes[index].cell),result:item.result}
    ]));
    return {saved,createdRows:data.createdRows??{}};
  }

  async function paste(anchor,source) {
    if (grid.rangeBusy() || grid.editing()) throw new Error('Завершите текущую правку перед вставкой.');
    return transaction(async ()=>{
      const {rowKeys,columns}=range.destination(anchor,source);
      const loaded=await rows.readRangeRows(rowKeys);
      const changes=[];
      const addressSnapshots=new Map();
      const priorOrderStatus=new Map();
      const byTarget=new Set();
      for (const [r,row] of loaded.entries()) {
        const copiedRow=source[r%source.length];
        for (const [c,column] of columns.entries()) {
          const cell=cellAt(row,column);
          const {value,source:ref}=copiedRow[c%copiedRow.length];
          if (row.hasAttribute('data-trip-draft') && value.trim()==='') continue;
          if (ref?.column===column && cell?.dataset.derivedFrom?.split(' ').some(primary=>{
            const offset=columns.indexOf(primary);
            return offset>=0 && copiedRow[offset%copiedRow.length].source?.column===primary;
          })) continue;
          check(cell);
          const change=rememberBefore(cell);
          if (value!=='' && requiresForm(cell)) throw new Error('Откройте форму «Сдача» для выбора точки.');
          const key=change.initial.row+':'+column;
          byTarget.add(key);
          if (column==='B' && cell.hasAttribute('data-order-status')) {
            priorOrderStatus.set(key,cell.dataset.orderStatus);
          }
          if (column==='B' && !document.body.dataset.vitrineCatalog) {
            const address=cellAt(row,'K');
            if (address && range.cellText(address)!=='') addressSnapshots.set(key,rememberBefore(address));
          }
          changes.push({...change,payload:{...payload(cell,value),...(ref ? {source:ref} : {})}});
        }
      }
      if (!changes.length) throw new Error('В буфере нет данных для новых строк.');
      const {saved,createdRows}=await send('pasteRange',changes);
      const history=[];
      let blocked=false;
      for (const change of changes) {
        const {initial,before,reset}=change;
        const key=initial.row+':'+initial.column;
        const {cell,result}=saved.get(key);
        const createdRow=createdRows[initial.row];
        if (createdRow) {
          history.push({row:createdRow,column:initial.column,value:'',reset:false,
            revision:result.revision,baseHash:result.baseHash,beforeRevision:0,beforeHash:result.baseHash});
          continue;
        }
        const orderStatus=priorOrderStatus.get(key);
        history.push({...payload(cell,before),reset,beforeRevision:initial.revision,beforeHash:initial.baseHash,
          ...(orderStatus!==undefined && orderStatus!==cell.dataset.orderStatus && before!=='' ? {orderStatus} : {})});
        if (requiresForm(cell) && before!=='' && change.payload.value==='') blocked=true;
        const address=addressSnapshots.get(key);
        if (result.unloadChanged && !byTarget.has(initial.row+':K')) {
          const patch=result.unloadCells?.find(item=>item.column==='K');
          if (!patch || !address || address.cell.hasAttribute('data-readonly-cell')) blocked=true;
          else history.push({row:patch.row,column:patch.column,revision:patch.revision,baseHash:patch.baseHash,
            value:address.before,reset:address.reset,beforeRevision:address.initial.revision,beforeHash:address.initial.baseHash});
        }
      }
      grid.rememberRange({cells:history,blocked});
      range.pasted();
      const firstCreated=Object.values(createdRows)[0];
      if (firstCreated) {
        try {
          await rows.refreshManifest();
          if (Number(anchor.dataset.row)<0) {
            const target=createdRows[anchor.dataset.row]??firstCreated;
            await rows.activate({dataset:{row:String(target),column:anchor.dataset.column}});
          }
        } catch (error) {
          window.PlanexNotifications.show({tone:'danger',message:'Рейсы сохранены. Обновите таблицу, чтобы их увидеть.'});
        }
      }
    });
  }

  function undo(history) {
    if (history.blocked) throw new Error('Связанные поля требуют проверки. Отмена вставки не выполнена.');
    const snapshot=history.cells;
    return transaction(async ()=>{
      const keys=[...new Set(snapshot.map(cell=>String(cell.row)))];
      const loaded=await rows.readRangeRows(keys);
      const byId=new Map(loaded.map(row=>[row.dataset.rowNumber,row]));
      const changes=snapshot.map(item=>{
        const cell=cellAt(byId.get(String(item.row)),item.column);
        check(cell);
        if (Number(cell.dataset.revision)!==item.revision || cell.dataset.base!==item.baseHash) {
          throw new Error('После вставки диапазон изменился. Отмена не выполнена.');
        }
        return {cell,payload:item};
      });
      const {saved:restored}=await send('undoRange',changes);
      return snapshot.map(item=>({row:item.row,column:item.column,beforeRevision:item.beforeRevision,beforeHash:item.beforeHash,
        ...payload(restored.get(item.row+':'+item.column).cell,item.value)}));
    });
  }
  window.VitrineRangePaste=Object.freeze({busy:()=>busy,paste,undo,requiresForm});
})();

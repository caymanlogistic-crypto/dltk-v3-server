'use strict';
(() => {
  const dateKey=/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/;

  function create(ids,withDrafts=false) {
    let markers=[];
    let starts=new Map(), before=[], keys=[];
    let dayEnds=new Map(), slots=new Map(), rowKeys=[], rowPositions=new Map();

    function replace(rowIds,nextMarkers) {
      markers=nextMarkers;
      const byId=new Map(markers.map(item=>[item.rowId,item]));
      starts=new Map();
      before=[0];
      keys=[];
      dayEnds=new Map();
      slots=new Map();
      rowKeys=[];
      let group=null;
      rowIds.forEach((id,index)=>{
        const marker=byId.get(id);
        if (marker) {
          starts.set(index,marker);
          group=marker;
        }
        keys.push(group?.key ?? '');
        rowKeys.push(String(id));
        const end=index===rowIds.length-1 || byId.has(rowIds[index+1]);
        if (withDrafts && end && /^20[0-9]{2}-[0-9]{2}-[0-9]{2}$/.test(group?.key ?? '')) {
          const base=Number(group.key.replaceAll('-',''))*10;
          const draftKeys=[String(-base-1),String(-base-2)];
          dayEnds.set(index,draftKeys);
          draftKeys.forEach((key,slot)=>slots.set(key,{key:group.key,anchor:group.rowId,index,slot}));
          rowKeys.push(...draftKeys);
        }
        before.push(before[index]+Number(Boolean(marker))+(dayEnds.get(index)?.length ?? 0));
      });
      rowPositions=new Map(rowKeys.map((key,index)=>[key,index]));
    }

    function remove(previous,remaining) {
      const kept=new Set(remaining);
      const next=[];
      let pending=null;
      previous.forEach((id,index)=>{
        pending=starts.get(index) ?? pending;
        if (pending && kept.has(id)) {
          next.push({...pending,rowId:id});
          pending=null;
        }
      });
      replace(remaining,next);
    }

    function rowsWithDividers(rows,start,body,colspan,draftRow) {
      const mounted=new Map(Array.from(body.querySelectorAll('[data-trip-date-divider]'), row=>[row.dataset.tripDateDivider,row]));
      const result=[];
      rows.forEach((row,offset)=>{
        const index=start+offset, marker=starts.get(index);
        const key=keys[index];
        if (dateKey.test(key)) row.dataset.tripDate=key;
        else delete row.dataset.tripDate;
        if (marker) {
          const markerId=String(marker.rowId);
          let divider=mounted.get(markerId);
          if (!divider) {
            divider=document.createElement('tr');
            divider.dataset.tripDateDivider=markerId;
            divider.setAttribute('aria-hidden','true');
            const span=document.createElement('span');
            divider.insertCell().append(span);
          }
          divider.firstElementChild.colSpan=colspan;
          divider.firstElementChild.firstElementChild.textContent=marker.label;
          result.push(divider);
        }
        result.push(row);
        for (const key of dayEnds.get(index) ?? []) result.push(draftRow(key));
      });
      return result;
    }

    let initial=[];
    try { initial=JSON.parse(document.getElementById('vitrine-trip-date-groups').content); } catch {}
    replace(ids,initial);
    return {
      replace,remove,rowsWithDividers,
      get rowKeys(){return rowKeys;},
      rowIndex:key=>rowPositions.get(key),
      draft:key=>slots.get(key),
      agrees: next=>JSON.stringify(next)===JSON.stringify(markers),
      units: (index,includeDivider=false)=>index+(before[index]??0)+Number(includeDivider && starts.has(index)),
      draftUnits(key) {
        const draft=slots.get(key);
        return draft.index+before[draft.index]+Number(starts.has(draft.index))+1+draft.slot;
      },
      indexAt(offset,height,length) {
        let lo=0,hi=length-1;
        while (lo<hi) {
          const mid=Math.ceil((lo+hi)/2);
          if ((mid+before[mid])*height<=offset) lo=mid;
          else hi=mid-1;
        }
        return lo;
      }
    };
  }
  window.VitrineTripDateGroups=Object.freeze({create});
})();

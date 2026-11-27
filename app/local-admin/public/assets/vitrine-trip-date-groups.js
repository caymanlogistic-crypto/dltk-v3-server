'use strict';
(() => {
  const dateKey=/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/;

  function create(ids) {
    let markers=[];
    let starts=new Map(), before=[], keys=[];

    function replace(rowIds,nextMarkers) {
      markers=nextMarkers;
      const byId=new Map(markers.map(item=>[item.rowId,item]));
      starts=new Map();
      before=[0];
      keys=[];
      let key='';
      rowIds.forEach((id,index)=>{
        const marker=byId.get(id);
        if (marker) {
          starts.set(index,marker);
          key=marker.key;
        }
        keys.push(key);
        before.push(before[index]+Number(Boolean(marker)));
      });
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

    function rowsWithDividers(rows,start,body,colspan) {
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
      });
      return result;
    }

    let initial=[];
    try { initial=JSON.parse(document.getElementById('vitrine-trip-date-groups').content); } catch {}
    replace(ids,initial);
    return {
      replace,remove,rowsWithDividers,
      agrees: next=>JSON.stringify(next)===JSON.stringify(markers),
      units: (index,includeDivider=false)=>index+(before[index]??0)+Number(includeDivider && starts.has(index)),
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

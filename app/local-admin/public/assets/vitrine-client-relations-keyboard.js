'use strict';
(() => {
  const visible=(element)=>!element.disabled && element.getClientRects().length>0;
  const focus=(element)=>{
    if (!element) return false;
    element.focus({preventScroll:true});
    if (element.closest('tbody')) element.scrollIntoView({block:'nearest',inline:'nearest'});
    return true;
  };
  const rowControls=row=>[...row.querySelectorAll('td button')].filter(visible);

  function bindCellActivation(button,activate) {
    button.addEventListener('click',event=>{
      if (event.detail===0) activate();
    });
    button.addEventListener('dblclick',activate);
  }

  function moveTab(dialog,event,hadMenu=false) {
    const controls=[...dialog.querySelectorAll('button,input,textarea,select,a[href],[tabindex]')]
      .filter(element=>element.tabIndex>=0 && visible(element));
    if (!controls.length) return;
    const current=controls.indexOf(document.activeElement);
    const wraps=event.shiftKey ? current<=0 : current===controls.length-1;
    if (!hadMenu && current>=0 && !wraps) return;
    event.preventDefault();
    const index=current<0 ? (event.shiftKey ? controls.length-1 : 0)
      : (current+(event.shiftKey?-1:1)+controls.length)%controls.length;
    focus(controls[index]);
  }

  function bind(dialog,{rows,menu,closeMenu,isBusy,onEscape}) {
    const list=rows.closest('section');
    const listActions=()=>[...list.querySelectorAll('.vitrine-editor-actions button')].filter(visible);
    function focusRow(id,column=1) {
      const row=[...rows.rows].find(item=>item.dataset.relationId===String(id));
      const preferred=row?.cells[column]?.querySelector('button');
      const control=preferred && visible(preferred) ? preferred : row && rowControls(row)[0];
      return focus(control);
    }
    function focusFirst(fallback) {
      const cells=[...rows.querySelectorAll('td:not(:first-child) button')].filter(visible);
      return focus(cells[0]??fallback);
    }
    function moveInRows(event,row,cell) {
      const column=cell.cellIndex;
      if (event.key==='ArrowLeft' || event.key==='ArrowRight') {
        const controls=rowControls(row);
        const next=controls.indexOf(event.target)+(event.key==='ArrowLeft'?-1:1);
        focus(controls[next]);
        return;
      }
      const relevant=[...rows.rows].filter(item=>rowControls(item).length);
      const index=relevant.indexOf(row);
      const next=event.key==='Home' ? 0 : event.key==='End' ? relevant.length-1 :
        index+(event.key==='ArrowUp'?-1:1);
      if (relevant[next]) focusRow(relevant[next].dataset.relationId,column);
      else if (event.key==='ArrowDown') focus(listActions()[0]);
    }
    dialog.addEventListener('keydown',event=>{
      if (event.defaultPrevented || event.isComposing) return;
      if (event.key==='Escape') {
        event.preventDefault();
        if (isBusy()) return;
        if (!menu.hidden) closeMenu(true);
        else onEscape();
        return;
      }
      if (event.key==='Tab') {
        const hadMenu=!menu.hidden;
        if (hadMenu) closeMenu(true);
        moveTab(dialog,event,hadMenu);
        return;
      }
      if (isBusy() || event.ctrlKey || event.metaKey || event.altKey) return;
      if (menu.contains(event.target) && ['ArrowUp','ArrowDown','Home','End'].includes(event.key)) {
        const items=[...menu.querySelectorAll('[role="menuitem"]')].filter(visible);
        const index=items.indexOf(event.target);
        const next=event.key==='Home' ? 0 : event.key==='End' ? items.length-1 :
          (index+(event.key==='ArrowDown'?1:-1)+items.length)%items.length;
        event.preventDefault();
        focus(items[next]);
        return;
      }
      const actions=listActions();
      if (actions.includes(event.target) && !event.shiftKey) {
        if (event.key==='ArrowUp') {
          const last=[...rows.rows].findLast(row=>rowControls(row).length);
          if (last) { event.preventDefault();focusRow(last.dataset.relationId); }
        } else if (event.key==='ArrowLeft' || event.key==='ArrowRight') {
          const next=actions.indexOf(event.target)+(event.key==='ArrowLeft'?-1:1);
          event.preventDefault();
          focus(actions[next]);
        }
      }
      const cell=event.target.closest('td');
      const row=cell?.parentElement;
      if (row?.parentElement!==rows) return;
      if (event.key==='ContextMenu' || (event.shiftKey && event.key==='F10')) {
        const trigger=row.querySelector('.vitrine-row-menu-trigger');
        if (!trigger) return;
        event.preventDefault();
        trigger.click();
      } else if (event.key==='F2' && event.target.matches('.vitrine-client-relations-cell')) {
        event.preventDefault();
        event.target.click();
      } else if (!event.shiftKey && ['ArrowUp','ArrowDown','ArrowLeft','ArrowRight','Home','End'].includes(event.key)) {
        event.preventDefault();
        moveInRows(event,row,cell);
      }
    });
    return {focusFirst,focusRow};
  }
  window.VitrineClientRelationsKeyboard=Object.freeze({bind,moveTab,bindCellActivation});
})();

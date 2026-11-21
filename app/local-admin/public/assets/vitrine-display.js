'use strict';
(() => {
  const {tableSlug,url}=window.VitrineGridScope;
  const userId=document.body.dataset.workspaceUser||'anonymous';
  const key='dltk-v3.vitrine.display.v2.'+userId+'.'+tableSlug;
  let serverPreferences={};try{serverPreferences=JSON.parse(document.getElementById('workspace-preferences')?.content||'{}');}catch{}
  let preferenceVersion=Number(serverPreferences.version||0),saveTimer=0,inFlight=false,pendingChanges={},lastLocalSnapshot=null,bufferedRemote=null;
  const csrf=document.querySelector('meta[name=csrf-token]')?.content||'';
  const copy=value=>JSON.parse(JSON.stringify(value));
  function mergeChanges(target,changes){
    for(const [key,value] of Object.entries(changes))if(key==='widths')target.widths={...(target.widths||{}),...value};else target[key]=value;
    return target;
  }
  function persist(settings){
    const before=lastLocalSnapshot||settings,changes={};
    for(const key of ['order','hidden','preset','lines','layoutWidth'])if(JSON.stringify(before[key])!==JSON.stringify(settings[key]))changes[key]=copy(settings[key]);
    for(const letter of new Set([...Object.keys(before.widths||{}),...Object.keys(settings.widths||{})]))if(before.widths?.[letter]!==settings.widths?.[letter]){changes.widths||={};changes.widths[letter]=settings.widths?.[letter]??null;}
    lastLocalSnapshot=copy(settings);mergeChanges(pendingChanges,changes);
    clearTimeout(saveTimer);saveTimer=setTimeout(()=>flushPreferences(),250);
  }
  async function flushPreferences(keepalive=false){
    clearTimeout(saveTimer);saveTimer=0;
    if(inFlight||!Object.keys(pendingChanges).length)return;
    const changes=pendingChanges;pendingChanges={};inFlight=true;
    try{
      const response=await fetch(url('/api/workspace/preferences'),{method:'POST',credentials:'same-origin',keepalive,headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({changes})});
      const result=await response.json();if(!response.ok||result.error)throw new Error(result.error?.message||'Не удалось сохранить личные настройки.');
      queueRemote(result);
    }catch(error){
      pendingChanges=mergeChanges(mergeChanges({},changes),pendingChanges);
      window.PlanexNotifications.show({tone:'danger',message:error.message});
      return;
    }finally{inFlight=false;}
    if(Object.keys(pendingChanges).length)flushPreferences(keepalive);else applyBuffered();
  }
  function queueRemote(result){
    if(!result.settings||Number(result.version)<preferenceVersion)return;
    if(!bufferedRemote||Number(result.version)>=Number(bufferedRemote.version))bufferedRemote=result;
    applyBuffered();
  }
  function applyBuffered(){
    if(!bufferedRemote||inFlight||Object.keys(pendingChanges).length||document.querySelector('.vitrine-display-dialog[open],.vitrine-editor,.vitrine-time-inline')||document.body.classList.contains('is-column-resizing'))return;
    const incoming=bufferedRemote;bufferedRemote=null;
    if(Number(incoming.version)<preferenceVersion)return;
    preferenceVersion=Number(incoming.version);const saved=incoming.settings;
    const next={order:validOrder(saved.order),hidden:validHidden(saved.hidden),widths:validWidths(saved.widths),preset:presets.some(p=>p.id===saved.preset)?saved.preset:'base',lines:linePresets.some(p=>p.id===saved.lines)?saved.lines:'base',layoutWidth:validWidthMode(saved.layoutWidth)};
    if(JSON.stringify(state)!==JSON.stringify(next)){
      state=next;applyWidths(state.widths);applyLines();applyLayoutWidth();applyOrder(state.order);applyVisibility(state.hidden);paintAll();
    }
    lastLocalSnapshot=copy(state);try{localStorage.setItem(key,JSON.stringify(state));}catch{}
  }
  window.addEventListener('workspace:event',event=>{
    const item=event.detail,data=item?.data;
    if(item?.event==='preferences.changed'&&data?.tableSlug===tableSlug&&(!data.userId||String(data.userId)===userId))queueRemote(data);
  });
  window.addEventListener('pagehide',()=>flushPreferences(true));
  document.addEventListener('visibilitychange',()=>{if(document.hidden)flushPreferences(true);else applyBuffered();});
  window.addEventListener('vitrine-editor-closed',applyBuffered);
  setInterval(applyBuffered,500);
  const table = document.querySelector('.vitrine-table');
  if (!table) {
    window.VitrineDisplay = Object.freeze({paint:(node,fill)=>{node.style.backgroundColor=fill;},paintCell:()=>{}});
    return;
  }
  const region = table.closest('.vitrine-grid-region');
  const defaultWidth = region.dataset.gridDefaultWidth;
  const columns = Array.from(table.querySelectorAll('thead th[data-column]')).map(node => ({letter:node.dataset.column,label:node.querySelector('.vitrine-column-label').textContent}));
  const originalOrder = columns.map(column => column.letter);
  let catalog = {};
  try { catalog = JSON.parse(document.getElementById('vitrine-status-data')?.content || '{}'); } catch {}
  const base = (catalog.palette || []).map(item => item.fill.toUpperCase());
  const presets = [
    {id:'base',name:'Базовый',note:'Текущая мягкая палитра',colors:base},
    {id:'saturated',name:'Насыщенный',note:'Те же девять цветовых групп, более насыщенные',colors:['#E6ABAD','#EDBC8B','#EDE19A','#B2D1A0','#94CFC3','#A9C8E6','#B8AFE0','#DCAACD','#C9C9C9']},
    {id:'acid',name:'Кислотный',note:'Яркие базовые RGB-цвета, как в примере',colors:['#FF0000','#FF8000','#FFFF00','#00FF00','#00FFFF','#0000FF','#8000FF','#FF00FF','#C0C0C0']}
  ];
  const linePresets=[{id:'base',name:'Текущие',color:null},{id:'dark',name:'Потемнее',color:'#A0988C'},{id:'black',name:'Чёрные',color:'#000000'}];
  const widthModes = [
    {id:'full',name:'По ширине области'},
    {id:'compact',name:'Компактно, до 960 пикселей'},
  ];
  function validWidthMode(value) {
    return widthModes.some(item=>item.id===value) ? value : defaultWidth;
  }
  function applyLayoutWidth() {
    region.dataset.gridWidth=state.layoutWidth;
  }
  function validOrder(value) {
    const known = new Set(originalOrder);
    const result = Array.isArray(value) ? [...new Set(value.filter(letter => known.has(letter)))] : [];
    return result.concat(originalOrder.filter(letter => !result.includes(letter)));
  }
  function validHidden(value) {
    const hidden = Array.isArray(value) ? [...new Set(value.filter(letter=>originalOrder.includes(letter)))] : [];
    return hidden.length === originalOrder.length ? hidden.slice(1) : hidden;
  }
  function validWidths(value) {
    const widths={};
    if(value && typeof value==='object') for(const letter of originalOrder) if(Number.isFinite(value[letter])) widths[letter]=Math.max(44,Math.min(1200,Math.round(value[letter])));
    return widths;
  }
  let state = {order:[...originalOrder],hidden:[],widths:{},preset:'base',lines:'base',layoutWidth:defaultWidth};
  try {
    const saved = serverPreferences.settings && Object.keys(serverPreferences.settings).length ? serverPreferences.settings : JSON.parse(localStorage.getItem(key) || 'null');
    if (saved) state = {order:validOrder(saved.order),hidden:validHidden(saved.hidden),widths:validWidths(saved.widths),preset:presets.some(p => p.id === saved.preset) ? saved.preset : 'base',lines:linePresets.some(p=>p.id===saved.lines)?saved.lines:'base',layoutWidth:validWidthMode(saved.layoutWidth)};
  } catch {}
  lastLocalSnapshot=copy(state);
  function color(fill) {
    const index = base.indexOf(String(fill).toUpperCase());
    return index < 0 ? fill : presets.find(p => p.id === state.preset).colors[index];
  }
  function ink(fill) {
    if (!/^#[0-9a-f]{6}$/i.test(fill || '')) return '#131210';
    const rgb = [1,3,5].map(i => { const x = parseInt(fill.slice(i,i+2),16)/255; return x <= .04045 ? x/12.92 : ((x+.055)/1.055)**2.4; });
    const luminance = rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722;
    return luminance < .179 ? '#FFFFFF' : '#131210';
  }
  function paintCell(cell) {
    const fill = cell.dataset.statusColor || cell.style.getPropertyValue('--status-fill').trim();
    if (!fill || !cell.classList.contains('has-status-color')) { cell.style.removeProperty('--status-display-fill'); cell.style.removeProperty('--status-display-text'); return; }
    const shown = color(fill);
    cell.style.setProperty('--status-display-fill',shown); cell.style.setProperty('--status-display-text',ink(shown));
  }
  function paint(node,fill) {
    node.dataset.displayColor = fill; node.style.backgroundColor = color(fill); node.style.color = ink(color(fill));
  }
  function paintAll() {
    table.querySelectorAll('td.has-status-color').forEach(paintCell);
    document.querySelectorAll('[data-display-color]').forEach(node => paint(node,node.dataset.displayColor));
  }
  function applyOrder(order) {
    if (Array.from(table.querySelectorAll('thead th[data-column]')).map(node=>node.dataset.column).join(',') === order.join(',')) return;
    const containers = [table.querySelector('colgroup'),table.querySelector('thead tr'),...table.querySelectorAll('[data-vitrine-row]')];
    for (const container of containers) {
      const nodes = new Map(Array.from(container.children).filter(node => node.dataset.column).map(node => [node.dataset.column,node]));
      const fragment = document.createDocumentFragment();
      for (const letter of order) if (nodes.has(letter)) fragment.append(nodes.get(letter));
      container.append(fragment);
    }
  }
  function applyVisibility(hidden) {
    const hiddenColumns = new Set(hidden);
    table.querySelectorAll('[data-column]').forEach(node=>{node.hidden=hiddenColumns.has(node.dataset.column);});
    window.dispatchEvent(new CustomEvent('vitrine-columns-updated'));
  }
  const widthRules=document.createElement('style');
  widthRules.textContent=columns.map(column=>'.vitrine .vitrine-table [data-column="'+column.letter+'"] { --column-width:var(--vitrine-width-'+column.letter+','+(table.querySelector('th[data-column="'+column.letter+'"]').classList.contains('vitrine-time-column')?'var(--time-column-width,64px)':'var(--vitrine-default-width-'+column.letter+',190px)')+'); width:var(--column-width); min-width:var(--column-width); max-width:var(--column-width); }').join('\n');
  document.head.append(widthRules);
  function applyWidths(widths) {
    for(const letter of originalOrder){
      if(widths[letter]!==undefined)table.style.setProperty('--vitrine-width-'+letter,widths[letter]+'px');
      else table.style.removeProperty('--vitrine-width-'+letter);
    }
  }
  function applyLines() {
    table.dataset.linePreset=state.lines;
    const preset=linePresets.find(item=>item.id===state.lines);
    if(preset.color)table.style.setProperty('--vitrine-grid-line',preset.color);else table.style.removeProperty('--vitrine-grid-line');
  }
  function commitWidth(letter,width) {
    const next={...state,widths:{...state.widths}};
    if(width===null)delete next.widths[letter];else next.widths[letter]=width;
    try{localStorage.setItem(key,JSON.stringify(next));state=next;applyWidths(state.widths);persist(state);}
    catch{applyWidths(state.widths);window.PlanexNotifications.show({tone:'danger',message:'Не удалось сохранить ширину столбца в этом браузере.'});}
  }
  for(const header of table.querySelectorAll('thead th[data-column]')){
    const letter=header.dataset.column;
    const grip=document.createElement('span');grip.className='display-column-resize';grip.tabIndex=-1;grip.setAttribute('role','separator');grip.setAttribute('aria-orientation','vertical');grip.setAttribute('aria-label','Ширина столбца '+letter);grip.setAttribute('aria-valuemin','44');grip.setAttribute('aria-valuemax','1200');grip.title='Потяните для изменения ширины. Двойной щелчок — стандартная ширина.';
    grip.addEventListener('focus',()=>grip.setAttribute('aria-valuenow',String(Math.round(header.getBoundingClientRect().width))));
    let drag=null,frame=0;
    function preview(){frame=0;if(drag){table.style.setProperty('--vitrine-width-'+letter,drag.width+'px');grip.setAttribute('aria-valuenow',String(drag.width));}}
    function finish(event,cancel=false){
      if(!drag)return;
      if(frame){cancelAnimationFrame(frame);frame=0;}
      if(!cancel)drag.width=Math.max(44,Math.min(1200,Math.round(drag.startWidth+event.clientX-drag.x)));
      const width=drag.width;drag=null;document.body.classList.remove('is-column-resizing');
      if(cancel)applyWidths(state.widths);else commitWidth(letter,width);
    }
    grip.addEventListener('pointerdown',event=>{
      if(event.button!==0)return;event.preventDefault();event.stopPropagation();
      if(document.querySelector('.vitrine-editor,.vitrine-time-inline')){window.PlanexNotifications.show({tone:'warning',message:'Сначала сохраните правку ячейки или нажмите «Отмена».'});return;}
      drag={x:event.clientX,startWidth:header.getBoundingClientRect().width,width:Math.round(header.getBoundingClientRect().width)};grip.setPointerCapture(event.pointerId);document.body.classList.add('is-column-resizing');
    });
    grip.addEventListener('pointermove',event=>{if(!drag)return;event.stopPropagation();drag.width=Math.max(44,Math.min(1200,Math.round(drag.startWidth+event.clientX-drag.x)));if(!frame)frame=requestAnimationFrame(preview);});
    grip.addEventListener('pointerup',event=>finish(event));
    grip.addEventListener('pointercancel',event=>finish(event,true));
    grip.addEventListener('lostpointercapture',event=>finish(event,true));
    grip.addEventListener('click',event=>event.stopPropagation());
    grip.addEventListener('dblclick',event=>{event.preventDefault();event.stopPropagation();commitWidth(letter,null);});
    grip.addEventListener('keydown',event=>{
      if(!['ArrowLeft','ArrowRight','Home'].includes(event.key))return;event.preventDefault();event.stopPropagation();
      if(event.key==='Home')commitWidth(letter,null);else commitWidth(letter,Math.max(44,Math.min(1200,Math.round(header.getBoundingClientRect().width)+(event.key==='ArrowLeft'?-1:1)*(event.shiftKey?1:10))));
    });
    header.append(grip);
  }
  function prepareRows(rows) {
    for(const row of rows){
      const nodes=new Map(Array.from(row.querySelectorAll('td[data-column]')).map(node=>[node.dataset.column,node]));
      const fragment=document.createDocumentFragment();
      for(const letter of state.order){const cell=nodes.get(letter);if(!cell)continue;cell.hidden=state.hidden.includes(letter);paintCell(cell);fragment.append(cell);}
      row.append(fragment);
    }
  }
  window.VitrineDisplay = Object.freeze({color,paint,paintCell,prepareRows});
  applyWidths(state.widths); applyOrder(state.order); applyVisibility(state.hidden); applyLines();applyLayoutWidth(); paintAll();
  let dialog,body,foot,title,opener,draft,mode,dragged;
  const el = (tag,cls,text) => { const node=document.createElement(tag); if(cls) node.className=cls; if(text!==undefined) node.textContent=text; return node; };
  function button(text,action,primary=false) { const node=el('button','btn '+(primary?'btn-primary':'btn-secondary'),text); node.type='button'; node.addEventListener('click',action); return node; }
  function close() { dialog.close(); opener?.focus({preventScroll:true}); }
  function createDialog() {
    if (dialog) return;
    dialog=el('dialog','vitrine-status-dialog vitrine-display-dialog'); dialog.setAttribute('aria-labelledby','display-dialog-title');
    const head=el('header','modal-head'); title=el('h2','modal-title'); title.id='display-dialog-title';
    const x=el('button','modal-close','×'); x.type='button'; x.setAttribute('aria-label','Закрыть'); x.addEventListener('click',close);
    head.append(title,x); body=el('div','modal-body vitrine-status-body'); foot=el('footer','modal-foot'); dialog.append(head,body,foot); document.body.append(dialog);
    dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
  }
  function renderOrder(focusLetter=null,focusDirection=null) {
    body.replaceChildren(el('p','display-description','Галочка показывает столбец. Снимите её, чтобы скрыть. Порядок меняйте ручкой или кнопками ↑ и ↓. Данные сохраняются.'));
    const list=el('div','display-order-list'); list.setAttribute('role','list');
    function move(from,to,focusDirection) {
      if (to<0 || to>=draft.order.length || from===to) return;
      const letter=draft.order.splice(from,1)[0]; draft.order.splice(to,0,letter); renderOrder(letter,focusDirection);
    }
    draft.order.forEach((letter,index)=>{
      const column=columns.find(item=>item.letter===letter);
      const row=el('div','display-order-row'); row.dataset.letter=letter; row.setAttribute('role','listitem');
      const handle=el('span','display-drag-handle','⠿'); handle.title='Перетащить столбец'; handle.setAttribute('aria-hidden','true');
      handle.addEventListener('pointerdown',event=>{
        if(event.button!==0)return;event.preventDefault();dragged=letter;handle.setPointerCapture(event.pointerId);row.classList.add('is-dragging');
      });
      handle.addEventListener('pointermove',event=>{
        if(dragged!==letter)return;
        list.querySelectorAll('.is-drop-target').forEach(node=>node.classList.remove('is-drop-target'));
        const target=document.elementFromPoint(event.clientX,event.clientY)?.closest('.display-order-row');
        if(target&&target!==row)target.classList.add('is-drop-target');
        const bounds=body.getBoundingClientRect();if(event.clientY>bounds.bottom-30)body.scrollTop+=20;else if(event.clientY<bounds.top+30)body.scrollTop-=20;
      });
      handle.addEventListener('pointerup',event=>{
        if(dragged!==letter)return;
        const target=document.elementFromPoint(event.clientX,event.clientY)?.closest('.display-order-row');
        const from=draft.order.indexOf(letter),to=target?draft.order.indexOf(target.dataset.letter):-1;
        dragged=null;list.querySelectorAll('.is-dragging,.is-drop-target').forEach(node=>node.classList.remove('is-dragging','is-drop-target'));
        if(from>=0&&to>=0)move(from,to);
      });
      handle.addEventListener('pointercancel',()=>{dragged=null;list.querySelectorAll('.is-dragging,.is-drop-target').forEach(node=>node.classList.remove('is-dragging','is-drop-target'));});
      const label=el('span','display-column-name',letter+' · '+(column.label||'Без названия'));
      const visible=el('input','display-column-visible');visible.type='checkbox';visible.checked=!draft.hidden.includes(letter);visible.setAttribute('aria-label','Показывать столбец '+letter);visible.title='Показывать столбец';
      row.classList.toggle('is-column-hidden',!visible.checked);
      visible.addEventListener('change',()=>{draft.hidden=visible.checked?draft.hidden.filter(item=>item!==letter):[...draft.hidden,letter];row.classList.toggle('is-column-hidden',!visible.checked);});
      const up=button('↑',()=>move(index,index-1,'up')); up.dataset.direction='up'; up.disabled=index===0; up.setAttribute('aria-label','Переместить '+letter+' вверх');
      const down=button('↓',()=>move(index,index+1,'down')); down.dataset.direction='down'; down.disabled=index===draft.order.length-1; down.setAttribute('aria-label','Переместить '+letter+' вниз');
      row.append(handle,visible,label,up,down);list.append(row);
    });
    body.append(list);
    if(focusLetter){const row=list.querySelector('[data-letter="'+focusLetter+'"]');(row.querySelector('[data-direction="'+(focusDirection||'up')+'"]:not(:disabled)')||row.querySelector('button:not(:disabled)'))?.focus({preventScroll:true});row.scrollIntoView({block:'nearest'});}
  }
  function renderColors() {
    body.replaceChildren(el('p','display-description','Пресет меняет отображение девяти цветов во всей витрине. Назначенные цвета и значения ячеек сохраняются.'));
    const list=el('div','display-preset-list');
    for(const preset of presets){
      const card=el('label','display-preset radio-item'); const name=el('span','display-preset-title'); const radio=el('input');radio.type='radio';radio.name='display-preset';radio.value=preset.id;radio.setAttribute('aria-label',preset.name);radio.checked=draft.preset===preset.id;radio.addEventListener('change',()=>draft.preset=preset.id);const mark=el('span','radio-mark');mark.setAttribute('aria-hidden','true');name.append(radio,mark,el('span','radio-label',preset.name));
      const swatches=el('span','display-swatches');preset.colors.forEach((fill,i)=>{const swatch=el('span','display-swatch');swatch.style.backgroundColor=fill;swatch.title=catalog.palette[i]?.name||'';swatches.append(swatch);});
      card.append(name,swatches,el('span','display-preset-note',preset.note));list.append(card);
    }
    body.append(list);
  }
  function renderLines() {
    body.replaceChildren(el('p','display-description','Выберите контраст горизонтальных и вертикальных линий. Толщина линий и заливки ячеек сохраняются.'));
    const list=el('div','display-preset-list');
    for(const preset of linePresets){
      const card=el('label','display-preset radio-item');const name=el('span','display-preset-title');const radio=el('input');radio.type='radio';radio.name='display-lines';radio.value=preset.id;radio.setAttribute('aria-label',preset.name);radio.checked=draft.lines===preset.id;radio.addEventListener('change',()=>draft.lines=preset.id);const mark=el('span','radio-mark');mark.setAttribute('aria-hidden','true');name.append(radio,mark,el('span','radio-label',preset.name));
      const sample=el('span','display-line-sample');sample.setAttribute('aria-hidden','true');if(preset.color)sample.style.setProperty('--preview-line',preset.color);
      ['А','Б','В','08:00','В пути','Данные'].forEach((text,index)=>sample.append(el('span',index<3?'display-line-heading':'',text)));
      card.append(name,sample);list.append(card);
    }
    body.append(list);
  }
  function renderWidth() {
    const list=el('div','display-preset-list');
    for (const option of widthModes) {
      const card=el('label','display-preset radio-item');
      const name=el('span','display-preset-title');
      const radio=el('input');
      radio.type='radio';
      radio.name='display-width';
      radio.value=option.id;
      radio.checked=draft.layoutWidth===option.id;
      radio.addEventListener('change',()=>draft.layoutWidth=option.id);
      const mark=el('span','radio-mark');
      mark.setAttribute('aria-hidden','true');
      name.append(radio,mark,el('span','radio-label',option.name));
      card.append(name);
      list.append(card);
    }
    body.replaceChildren(list);
  }
  function save() {
    if(draft.hidden.length>=originalOrder.length){window.PlanexNotifications.show({tone:'danger',message:'Оставьте видимым хотя бы один столбец.'});return;}
    try { localStorage.setItem(key,JSON.stringify(draft)); }
    catch { window.PlanexNotifications.show({tone:'danger',message:'Не удалось сохранить настройки в этом браузере.'});return; }
    const orderChanged=state.order.join(',')!==draft.order.join(',');
    state={order:[...draft.order],hidden:[...draft.hidden],widths:{...draft.widths},preset:draft.preset,lines:draft.lines,layoutWidth:draft.layoutWidth};
    applyWidths(state.widths);
    applyLines();applyLayoutWidth();
    if(orderChanged) applyOrder(state.order);
    applyVisibility(state.hidden);
    paintAll();persist(state);close();
  }
  const dialogModes={
    columns:{title:'Столбцы: порядок и видимость',render:renderOrder,resetLabel:'Исходный порядок',reset:()=>{draft.order=[...originalOrder];}},
    lines:{title:'Линии таблицы',render:renderLines,resetLabel:'Текущие линии',reset:()=>{draft.lines='base';}},
    colors:{title:'Цветовые пресеты',render:renderColors,resetLabel:'Базовый пресет',reset:()=>{draft.preset='base';}},
    width:{title:'Ширина блока',render:renderWidth,resetLabel:'По умолчанию',reset:()=>{draft.layoutWidth=defaultWidth;}},
  };
  document.querySelectorAll('[data-display-open]').forEach(trigger=>trigger.addEventListener('click',event=>{
    event.preventDefault();
    if(document.querySelector('.vitrine-editor,.vitrine-time-inline')){window.PlanexNotifications.show({tone:'warning',message:'Сначала сохраните правку ячейки или нажмите «Отмена».'});return;}
    document.querySelectorAll('.vitrine-folder[open]').forEach(folder=>folder.open=false);
    createDialog();opener=trigger;mode=trigger.dataset.displayOpen;draft=copy(state);
    const options=dialogModes[mode];
    title.textContent=options.title;foot.replaceChildren();
    const reset=button(options.resetLabel,()=>{options.reset();options.render();});
    const actions=el('div','modal-foot-actions display-actions');actions.append(button('Сохранить',save,true),button('Отмена',close));foot.append(reset);
    if(mode==='columns')foot.append(button('Показать все',()=>{draft.hidden=[];renderOrder();}));
    if(mode==='columns')foot.append(button('Сбросить ширину',()=>{draft.widths={};}));
    foot.append(actions);
    options.render();dialog.showModal();
  }));
})();

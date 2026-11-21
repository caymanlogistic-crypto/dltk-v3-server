(() => {
 'use strict';
 function create({root,api,el,button,input,check,table,modal}) {
  let boot;
   async function catalogs(){boot=await api('bootstrap');root.replaceChildren();root.append(button('Новый справочник',createCatalog,true),table(['Справочник','Область применения',''],boot.catalogs.map(c=>[c.title,c.scope==='shared'?'Общий · '+tableTitles(c.tableIds):tableTitles(c.tableIds),button('Открыть',()=>catalog(c.id))])));}
   function tableTitles(ids){return boot.tables.filter(t=>(ids||[]).map(Number).includes(Number(t.id))).map(t=>t.title).join(', ')||'Все таблицы';}
   function createCatalog(){const m=modal('Новый справочник'),title=input('Название'),checks=boot.tables.map(t=>({id:t.id,...check(t.title,false)})),group=el('div','ws-checks');checks.forEach(c=>group.append(c.wrap));m.b.append(title.wrap,el('p','ws-muted','Выберите одну таблицу для отдельного справочника или несколько для общего. Без выбора — общий для всех.'),group);m.f.append(button('Отмена',()=>m.d.close()),button('Создать',async()=>{const ids=checks.filter(c=>c.field.checked).map(c=>Number(c.id));await api('catalogs',{action:'create_catalog',title:title.field.value,tableIds:ids,scope:ids.length===1?'local':'shared'});m.d.close();await catalogs();},true));title.field.focus();}
   async function catalog(id) {
     const path = 'catalogs?id=' + id;
     const data = await api(path);
     const dialog = modal(data.catalog.title);
     async function refresh() { render(await api(path)); }
     function render(d) {
       const headers = ['Цвет', 'Название', 'Состояние', ''];
       if (d.hasDetails) headers.push('');
       const rows = d.entries.map(entry => {
         const chip = el('span', 'ws-badge', ' ');
         if (/^#[0-9a-f]{6}$/i.test(entry.color)) chip.style.backgroundColor = entry.color;
         const row = [chip, entry.name, entry.active ? 'Активна' : 'Архив', button('Изменить', () => editEntry(entry, d, refresh))];
         if (d.hasDetails) row.push(button('Реквизиты', () => catalogDetails(path, entry)));
         return row;
       });
       const scope = d.catalog.scope === 'shared' ? 'Общий справочник' : 'Отдельный справочник';
       const actions = el('div', 'ws-toolbar');
       actions.append(button('Добавить запись', () => editEntry(null, d, refresh), true));
       if (d.unkeyedCount) actions.append(button('Строки без названия (' + d.unkeyedCount + ')', () => catalogDetails(path)));
       dialog.b.replaceChildren(el('p', 'ws-muted', scope + ' · ' + tableTitles(d.catalog.tableIds)), actions, table(headers, rows));
     }
     if (data.sourceSyncLabel) {
       const syncButton = button(data.sourceSyncLabel, async () => {
         syncButton.disabled = true;
         syncButton.textContent = 'Синхронизирую…';
         try {
           await api('catalogs', { action:'refresh_catalog_source', catalogId:id });
           if (dialog.d.open) await refresh();
           window.PlanexNotifications.show({ tone:'success', message:'Справочник обновлён.' });
         } finally {
           syncButton.disabled = false;
           syncButton.textContent = data.sourceSyncLabel;
         }
       });
       dialog.f.append(syncButton);
     }
     dialog.f.append(button('Закрыть', () => dialog.d.close()));
     render(data);
   }
   async function catalogDetails(path, entry = null) {
     const data = await api(path + (entry ? '&entryId=' + entry.id : '&unkeyed=1'));
     const dialog = modal(entry?.name || 'Строки без названия');
     for (const occurrence of data.occurrences) {
       if (!entry || data.occurrences.length > 1) dialog.b.append(el('h3', '', 'Строка источника ' + occurrence.row));
       dialog.b.append(table(['Поле', 'Значение'], data.columns.map((label, index) => [label, occurrence.values[index] || '—'])));
     }
     if (!data.occurrences.length) dialog.b.append(el('p', 'ws-muted', 'Для записи нет импортированных реквизитов.'));
     dialog.f.append(button('Закрыть', () => dialog.d.close()));
   }
   function editEntry(entry,data,refresh){const m=modal(entry?'Изменить запись':'Новая запись'),name=input('Название','text',entry?.name||''),active=check('Активная запись',entry?.active??true);let color=entry?.color||data.palette?.[0]?.hex||'';const colors=el('div','ws-palette');for(const p of data.palette||[]){const hex=p.hex||p.color||p.value;if(!/^#[0-9a-f]{6}$/i.test(hex||''))continue;const b=button('',()=>{color=hex;for(const n of colors.children)n.setAttribute('aria-pressed',String(n===b));});b.title=p.name||p.label||hex;b.setAttribute('aria-label',b.title);b.setAttribute('aria-pressed',String(hex===color));b.style.backgroundColor=hex;colors.append(b);}m.b.append(name.wrap,colors,active.wrap);m.f.append(button('Отмена',()=>m.d.close()),button('Сохранить',async()=>{await api('catalogs',{catalogId:data.catalog.id,id:entry?.id??null,name:name.field.value,color,revision:entry?.revision??0,active:active.field.checked});m.d.close();await refresh();},true));name.field.focus();}
  return catalogs;
 }
 window.WorkspaceCatalogs = Object.freeze({create});
})();

(() => {
 'use strict';
 function create({root,api,el,button,input,check,table,modal}) {
   let boot;
   const appPath = path => window.DltkPaths?.path(path) ?? path;
   async function catalogs(){
     boot=await api('bootstrap');
     root.replaceChildren();
     root.append(button('Новый справочник',createCatalog,true),table(['Справочник','Область применения',''],boot.catalogs.map(c=>{
       const link=el('a','btn','Открыть');
       link.href=appPath('/vitrine?catalog=entries&id='+encodeURIComponent(c.id));
       return [c.title,c.scope==='shared'?'Общий · '+tableTitles(c.tableIds):tableTitles(c.tableIds),link];
     })));
   }
   function tableTitles(ids){return boot.tables.filter(t=>(ids||[]).map(Number).includes(Number(t.id))).map(t=>t.title).join(', ')||'Все таблицы';}
   function createCatalog(){
     const m=modal('Новый справочник'),title=input('Название'),checks=boot.tables.map(t=>({id:t.id,...check(t.title,false)})),group=el('div','ws-checks');
     checks.forEach(c=>group.append(c.wrap));
     m.b.append(title.wrap,el('p','ws-muted','Выберите одну таблицу для отдельного справочника или несколько для общего. Без выбора — общий для всех.'),group);
     m.f.append(button('Отмена',()=>m.d.close()),button('Создать',async()=>{
       const ids=checks.filter(c=>c.field.checked).map(c=>Number(c.id));
       await api('catalogs',{action:'create_catalog',title:title.field.value,tableIds:ids,scope:ids.length===1?'local':'shared'});
       m.d.close();
       await catalogs();
     },true));
     title.field.focus();
   }
   return catalogs;
 }
 window.WorkspaceCatalogs = Object.freeze({create});
})();

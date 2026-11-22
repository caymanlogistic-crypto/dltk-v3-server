'use strict';
(() => {
  const opener = document.querySelector('[data-unload-contacts]');
  const dialog = document.getElementById('unload-contacts-dialog');
  if (!opener || !dialog) return;
  const catalogId = Number(opener.closest('[data-catalog-id]').dataset.catalogId);
  const form = dialog.querySelector('form');
  const list = dialog.querySelector('[data-unload-contact-list]');
  const add = dialog.querySelector('[data-unload-contact-add]');
  const save = dialog.querySelector('button[type="submit"]');
  const close = dialog.querySelector('[data-close]');
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  let entryId, revision;
  const notice = (message,tone='danger') => window.PlanexNotifications.show({message,tone});
  const node = (tag, cls, text) => {
    const result = document.createElement(tag);
    if (cls) result.className = cls;
    if (text) result.textContent = text;
    return result;
  };
  function identifyPrimary() {
    const primary = list.querySelectorAll('input[type="radio"]');
    if (primary.length && !Array.from(primary).some(input=>input.checked)) primary[0].checked=true;
  }
  function row(contact) {
    const section = node('fieldset');
    section.dataset.contactId = contact.id ?? '';
    const fields = [['name','Имя','text',160],['phone','Телефон','tel',100],['email','E-mail','email',254]];
    for (const [key,label,type,max] of fields) {
      const line = node('label','field');
      const input = node('input','field-input');
      input.dataset.contactField=key; input.type=type; input.maxLength=max;
      input.value=contact[key]??''; input.readOnly=!save;
      line.append(node('span','field-label',label),input);
      section.append(line);
    }
    const preferred = node('label','checkbox-item');
    const radio = node('input');
    radio.type='radio'; radio.name='primary-contact'; radio.disabled=!save;
    radio.checked=Boolean(Number(contact.is_primary));
    preferred.append(radio,node('span',null,'Основной контакт'));
    section.append(preferred);
    if (contact.original) {
      const details = node('details');
      details.append(node('summary',null,'Запись из исходных данных'),node('p',null,contact.original));
      section.append(details);
    }
    if (save) {
      const remove = node('button','btn btn-secondary','Убрать контакт');
      remove.type='button';
      remove.addEventListener('click',()=>{section.remove();identifyPrimary();});
      section.append(remove);
    }
    return section;
  }
  async function request(query='',body=null) {
    const path='/api/workspace/catalogs'+query;
    const options={credentials:'same-origin'};
    if (body) Object.assign(options,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)});
    const response=await fetch(window.DltkPaths?.path(path)??path,options);
    const result=await response.json();
    if (!response.ok || result.error) throw new Error(result.error?.message||'Не удалось открыть контакты точки.');
    return result;
  }
  opener.addEventListener('click',async ()=>{
    const cell=document.querySelector('#vitrine-rows td.is-current-cell');
    entryId=Number(cell?.dataset.row);
    if (!entryId) { notice('Выберите сохранённую точку выгрузки.','info'); return; }
    opener.disabled=true;
    try {
      const data=await request('?id='+catalogId+'&unloadContacts='+entryId);
      revision=data.revision;
      dialog.querySelector('h2').textContent='Контакты точки · '+data.point;
      list.replaceChildren(...data.contacts.map(row));
      if (!data.contacts.length && !save) list.append(node('p',null,'Контакты не указаны.'));
      dialog.showModal();
    } catch (error) { notice(error.message); }
    finally { opener.disabled=false; }
  });
  add?.addEventListener('click',()=>{
    const section=row({});
    list.append(section);
    identifyPrimary();
    section.querySelector('[data-contact-field]').focus();
  });
  close.addEventListener('click',()=>dialog.close());
  form.addEventListener('submit',async event=>{
    event.preventDefault();
    if (!save || save.disabled) return;
    const contacts=Array.from(list.querySelectorAll('fieldset'),section=>{
      const values=Object.fromEntries(Array.from(section.querySelectorAll('[data-contact-field]'),input=>[input.dataset.contactField,input.value]));
      return {id:Number(section.dataset.contactId)||null,primary:section.querySelector('input[type="radio"]').checked,...values};
    });
    save.disabled=true;
    try {
      const result=await request('',{action:'save_unload_contacts',catalogId,entryId,revision,contacts});
      document.querySelectorAll('td[data-row="'+entryId+'"]').forEach(cell=>cell.dataset.revision=String(result.revision));
      dialog.close();
      notice('Контакты сохранены.','success');
    } catch (error) { notice(error.message); }
    finally { save.disabled=false; }
  });
})();

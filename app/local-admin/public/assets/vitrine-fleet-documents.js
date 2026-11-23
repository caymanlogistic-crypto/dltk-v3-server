'use strict';
(() => {
  const dialog = document.getElementById('fleet-documents-dialog');
  if (!dialog) return;
  const form = dialog.querySelector('form');
  const submit = form.querySelector('[type="submit"]');
  const fields = ['passport','license_number','license_issued','license_expires'];
  const notify = (message,tone = 'danger') => window.PlanexNotifications.show({message,tone});
  let cell, target, busy = false;
  const url = source => window.DltkPaths.path('/api/vitrine/fleet-documents?vehicle=' + encodeURIComponent(source.dataset.row));

  async function request(source, body) {
    const response = await fetch(url(source), body ? {
      method:'POST', headers:{'Content-Type':'application/json',
        'X-CSRF-Token':document.querySelector('meta[name="csrf-token"]').content},
      body:JSON.stringify(body), cache:'no-store'
    } : {cache:'no-store'});
    const data = await response.json();
    if (!response.ok) throw new Error(data.error?.message || 'Не удалось открыть документы.');
    return data;
  }
  window.VitrineFleetDocuments = {
    async open(source) {
      if (busy || dialog.open) return;
      busy = true;
      try {
        const data = await request(source);
        cell = source;
        target = data;
        dialog.querySelector('.modal-title').textContent = 'Документы водителя · ' + data.name;
        for (const name of fields) {
          form.elements[name].value = data.documents[name] ?? '';
          form.elements[name].readOnly = !data.editable;
        }
        submit.hidden = !data.editable;
        dialog.showModal();
      } catch (error) {
        notify(error.message);
      } finally {
        busy = false;
      }
    }
  };
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || !target?.editable) return;
    busy = submit.disabled = true;
    try {
      const body = Object.fromEntries(fields.map(name => [name,form.elements[name].value]));
      await request(cell,{...body,driverId:target.driverId,revision:Number(target.documents.revision)});
      dialog.close();
      notify('Документы водителя сохранены.','success');
    } catch (error) {
      notify(error.message);
    } finally {
      busy = submit.disabled = false;
    }
  });
  dialog.querySelector('[data-close]').addEventListener('click',()=>{if (!busy) dialog.close();});
  dialog.addEventListener('cancel',event=>{if (busy) event.preventDefault();});
  dialog.addEventListener('close',()=>{
    form.reset();
    target = null;
    dialog.querySelector('.modal-title').textContent = 'Документы водителя';
    cell?.focus({preventScroll:true});
    cell = null;
  });
})();

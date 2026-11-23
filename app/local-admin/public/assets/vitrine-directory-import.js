'use strict';
(() => {
  const button = document.querySelector('[data-directory-import]');
  const dialog = document.querySelector('[data-directory-import-dialog]');
  if (!button || !dialog) return;

  const form = dialog.querySelector('form');
  const submit = dialog.querySelector('[data-import-submit]');
  const pick = dialog.querySelector('[data-import-pick]');
  const filename = dialog.querySelector('[data-import-filename]');
  const fileField = form.elements.workbook;
  const close = dialog.querySelector('[data-import-close]');
  const output = dialog.querySelector('[data-import-result]');
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const url = window.VitrineGridScope.url('/api/vitrine/directory-excel');
  let token = '', busy = false, saved = false;

  function setBusy(value) {
    busy = value;
    for (const control of [pick,close,fileField,form.elements.replace]) control.disabled = value;
    submit.disabled = value || !fileField.files.length;
    dialog.setAttribute('aria-busy',String(value));
  }
  function resetReview() {
    token = '';
    output.hidden = true;
    submit.textContent = 'Проверить файл';
    submit.disabled = !fileField.files.length;
  }
  function updateFilename() {
    const name = fileField.files[0]?.name;
    filename.textContent = name ?? 'Файл не выбран';
    filename.title = name ?? '';
  }
  async function request(options) {
    const response = await fetch(url,options);
    const result = await response.json().catch(()=>null);
    if (!response.ok || !result) throw new Error(result?.error?.message || 'Не удалось обработать Excel.');
    return result;
  }
  function report(error) {
    window.PlanexNotifications.show({tone:'danger',message:error.message});
  }

  button.addEventListener('click',()=>{
    if (window.VitrineGrid.editing()) {
      window.PlanexNotifications.show({tone:'info',message:'Завершите редактирование ячейки перед загрузкой.'});
      return;
    }
    form.reset();
    updateFilename();
    resetReview();
    dialog.showModal();
  });
  close.addEventListener('click',()=>dialog.close());
  dialog.addEventListener('cancel',event=>{ if (busy) event.preventDefault(); });
  dialog.addEventListener('close',()=>{ if (saved) location.reload(); });
  pick.addEventListener('click',()=>fileField.click());
  fileField.addEventListener('change',updateFilename);
  form.addEventListener('change',resetReview);
  form.addEventListener('submit',async event=>{
    event.preventDefault();
    if (busy || saved) return;
    const workbook = fileField.files[0];
    if (!workbook || !/\.xlsx$/i.test(workbook.name) || workbook.size>1_900_000) {
      report(new Error('Выберите файл .xlsx размером до 1,9 МБ.'));
      return;
    }
    const data = new FormData(form);
    if (form.elements.replace.checked) data.set('replace','1');
    if (token) data.set('token',token);
    setBusy(true);
    try {
      const result = await request({method:'POST',headers:{'X-CSRF-Token':csrf},body:data});
      const counts = result.counts;
      output.textContent = 'Новых — ' + counts.added + ', изменённых — ' + counts.updated
        + ', в архив — ' + counts.archived + ', без изменений — ' + counts.unchanged + '.';
      output.hidden = false;
      if (token) {
        saved = true;
        output.textContent = 'Справочник обновлён. ' + output.textContent;
        close.textContent = 'Готово';
      } else {
        token = result.token;
        submit.textContent = 'Применить изменения';
      }
    } catch (error) {
      resetReview();
      report(error);
    } finally {
      setBusy(false);
      if (saved) {
        submit.hidden = true;
        fileField.disabled = true;
        form.elements.replace.disabled = true;
        pick.disabled = true;
        close.focus();
      }
    }
  });
})();

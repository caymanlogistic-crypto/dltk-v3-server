'use strict';
(() => {
  const form = document.querySelector('.vitrine-catalog-search');
  const grid = window.VitrineRows;
  if (!form || !grid) return;
  const field = form.elements.q;
  let timer;
  async function update() {
    clearTimeout(timer);
    try {
      await grid.filterDirectory(field.value.trim());
    } catch (error) {
      window.PlanexNotifications.show({tone:'danger',message:error.message || 'Не удалось выполнить поиск.'});
    }
  }
  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(update,220);
  }
  field.addEventListener('input',event=>{ if (!event.isComposing) schedule(); });
  field.addEventListener('compositionstart',()=>clearTimeout(timer));
  field.addEventListener('compositionend',schedule);
  form.addEventListener('submit',event=>{
    event.preventDefault();
    update();
  });
})();

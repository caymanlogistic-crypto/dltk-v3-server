'use strict';
(() => {
  const metadata=document.getElementById('vitrine-container-type-rules');
  const body=document.getElementById('vitrine-rows');
  if (!metadata || !body) return;
  let {catalogId,names,rules}=JSON.parse(metadata.content);
  const normalize=value=>value.trim().replace(/\s+/gu,' ').toLocaleLowerCase('ru');

  function fill(cell,color) {
    if (!cell || cell.style.getPropertyValue('--catalog-fill')===(color??'')) return;
    if (color) cell.style.setProperty('--catalog-fill',color);
    else cell.style.removeProperty('--catalog-fill');
    window.VitrineDisplay?.paintCell(cell);
  }

  function paintRow(row) {
    const type=row?.querySelector('td[data-column="I"]');
    if (!type) return;
    const weight=row.querySelector('td[data-column="J"]');
    const id=Number(type.dataset.referenceId)||names[normalize(type.textContent)];
    const rule=rules[id];
    fill(type,rule?.typeColor);
    if (!weight) return;
    const amount=weight.textContent.trim().replace(/[\u00a0\u202f ]/gu,'').replace(',','.');
    const over=rule?.threshold!=null && /^\d+(?:\.\d+)?$/.test(amount) && Number(amount)>=rule.threshold;
    fill(weight,over ? rule.warningColor : null);
  }

  function paintMounted() {
    for (const row of body.querySelectorAll('[data-vitrine-row]')) paintRow(row);
  }

  function replace(data) {
    if (data.catalogId!==catalogId) return;
    ({names,rules}=data);
    paintMounted();
  }
  window.addEventListener('vitrine-rows-updated',paintMounted);
  window.addEventListener('workspace:event',event=>{
    const patch=event.detail?.data;
    if (event.detail?.event!=='catalog.changed' || patch?.catalogId!==catalogId || !patch.containerRule) return;
    rules[patch.id]=patch.containerRule;
    names[normalize(patch.name)]=patch.id;
    if (patch.beforeName) names[normalize(patch.beforeName)]=patch.id;
    paintMounted();
  });
  window.VitrineContainerRules=Object.freeze({paintRow,replace});
  paintMounted();
})();

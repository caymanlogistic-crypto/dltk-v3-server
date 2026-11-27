'use strict';

(() => {
  const editorTypes = JSON.parse(document.getElementById('vitrine-preview-presentation').content);
  let demoPassword = 'Учебный-123';
  const cellFor = row => document.querySelector('tr[data-row-number="' + row + '"] td[data-column="A"]');

  window.addEventListener('vitrine-editor-opened', event => {
    const panel = document.querySelector('.vitrine-editor');
    panel.querySelectorAll(':scope > .vitrine-editor-note, :scope > .vitrine-editor-help').forEach(node => node.remove());
    const title = panel.querySelector(':scope > .vitrine-editor-title');
    if (title) {
      panel.setAttribute('aria-label',title.textContent);
      panel.removeAttribute('aria-labelledby');
      title.remove();
    }
    if (editorTypes[event.detail.row]==='password') {
      const field = panel.querySelector('#vitrine-editor-field');
      const wrapper = document.createElement('div');
      wrapper.className = 'auth-password';
      const toggle = document.getElementById('vitrine-preview-password-eye').content.firstElementChild.cloneNode(true);
      field.before(wrapper);
      field.type = 'password';
      field.autocomplete = 'new-password';
      field.setAttribute('aria-label','Пароль');
      field.value = demoPassword;
      wrapper.append(field,toggle);
      window.DltkPasswordVisibility(field,toggle);
      const cell = cellFor(event.detail.row);
      const revision=cell.dataset.revision;
      window.addEventListener('vitrine-editor-closed', () => {
        if (cell.dataset.revision!==revision) demoPassword=field.value;
        field.value='';
      },{once:true});
    }
    if (editorTypes[event.detail.row]==='vehicle-brand') {
      const field = panel.querySelector('#vitrine-editor-field');
      field.addEventListener('input', () => {
        if (field.value === field.value.toUpperCase()) return;
        const {selectionStart,selectionEnd} = field;
        const start = field.value.slice(0,selectionStart).toUpperCase().length;
        const end = field.value.slice(0,selectionEnd).toUpperCase().length;
        field.value = field.value.toUpperCase();
        field.setSelectionRange(start,end);
      });
    }
  });

  const select = window.VitrineSelect;
  window.VitrineSelect = Object.freeze({
    ...select,
    create(options, initialValue, settings = {}) {
      const vehicle = options.some(option => option.previewLabels);
      const choices = vehicle ? options.map(option => ({
        ...option, search:select.vehicleSearchKey(option.value)+' '+(option.search??''),
      })) : options;
      return select.create(choices, initialValue, {
        ...settings,
        searchable: settings.searchable || options.some(option => option.previewSearch),
        showLabels: settings.showLabels || vehicle,
        searchKey: settings.searchKey ?? (vehicle ? select.vehicleSearchKey : undefined),
        showColorSwatches: settings.showColorSwatches || options.some(option => option.color),
      });
    },
  });

  const dropoff = window.VitrineDropoff;
  window.VitrineDropoff = Object.freeze({
    ...dropoff,
    supports: cell => cell.dataset.column === 'A'
      && cell.parentElement.hasAttribute('data-dropoff-details'),
  });
})();

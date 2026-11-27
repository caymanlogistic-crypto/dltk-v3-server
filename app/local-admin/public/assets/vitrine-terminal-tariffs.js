'use strict';
(() => {
  const form = document.querySelector('[data-terminal-tariff-filters]');
  if (!form) return;
  const search = form.elements.tariffQuery;
  const focusKey = 'vitrine-tariff-search-focus';
  let timer;
  try {
    const caret = sessionStorage.getItem(focusKey);
    if (caret !== null) {
      sessionStorage.removeItem(focusKey);
      search.focus({preventScroll:true});
      search.setSelectionRange(Number(caret),Number(caret));
    }
  } catch {}
  function apply(restoreFocus = false) {
    clearTimeout(timer);
    if (!form.checkValidity()) return;
    if (restoreFocus && document.activeElement === search) {
      try { sessionStorage.setItem(focusKey,String(search.selectionStart)); } catch {}
    }
    form.requestSubmit();
  }
  for (const filter of form.querySelectorAll('[data-tariff-filter]')) {
    const hidden = filter.querySelector('input');
    const options = JSON.parse(filter.dataset.options);
    const selector = window.VitrineSelect.create(options, hidden.value,
      { searchable: filter.dataset.searchable === '1', showLabels: true });
    selector.field.setAttribute('aria-label', filter.dataset.label);
    filter.append(selector.element);
    selector.field.addEventListener('change', () => {
      const selected = selector.resolveValue();
      if (selected === hidden.value || !options.some(option => option.value === selected)) return;
      hidden.value = selected;
      apply();
    });
  }
  function schedule(event) {
    if (event.isComposing) return;
    clearTimeout(timer);
    timer = setTimeout(() => apply(true), 350);
  }
  form.addEventListener('submit', () => clearTimeout(timer));
  form.elements.tariffDate.addEventListener('change', () => apply());
  search.addEventListener('input',schedule);
  search.addEventListener('compositionstart',() => clearTimeout(timer));
  search.addEventListener('compositionend',schedule);
})();

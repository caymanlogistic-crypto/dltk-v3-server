'use strict';
(() => {
  const root = document.documentElement;
  const key = 'dltk-v3.vitrine.last-color-preset';

  function apply(preset) {
    root.dataset.colorPreset = preset === 'night' ? 'night' : 'light';
    try { localStorage.setItem(key, root.dataset.colorPreset); } catch {}
  }

  if (root.dataset.colorPreset) {
    apply(root.dataset.colorPreset);
  } else {
    try { root.dataset.colorPreset = localStorage.getItem(key) === 'night' ? 'night' : 'light'; } catch {}
  }
  window.addEventListener('pageswap', event => {
    if (!event.viewTransition || !event.activation) return;
    const next = new URL(event.activation.entry.url);
    const current = new URL(location.href);
    const login = (window.DltkPaths?.path('/login') ?? '/login');
    const vitrine = (window.DltkPaths?.path('/vitrine') ?? '/vitrine');
    if (current.pathname!==login || next.pathname!==vitrine || next.searchParams.get('from')!=='login') {
      event.viewTransition.skipTransition();
    }
  });
  window.VitrineTheme = Object.freeze({apply});
})();

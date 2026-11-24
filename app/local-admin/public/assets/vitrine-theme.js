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
  window.VitrineTheme = Object.freeze({apply});
})();

/* Deployment paths: explicit use at fetch/navigation call sites. */
(() => {
  'use strict';
  const raw = document.querySelector('meta[name="app-base-path"]')?.content || '';
  const base = raw === '/' ? '' : raw.replace(/\/$/, '');
  if (base && !/^\/(?:[a-zA-Z0-9_-]+\/?)+$/.test(base)) throw new Error('Invalid application base path');
  function path(value) {
    if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//')) return value;
    if (!base || value === base || value.startsWith(base + '/') || value.startsWith(base + '?') || value.startsWith(base + '#')) return value;
    return base + value;
  }
  window.DltkPaths = Object.freeze({base, path});
})();

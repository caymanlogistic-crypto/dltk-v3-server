'use strict';
(() => {
  function shortName(name) {
    const suffix = name.match(/ · №[0-9]+$/u)?.[0] ?? '';
    const parts = name.slice(0,name.length-suffix.length).trim().split(/\s+/u);
    if (parts.length < 2 || parts.length > 3 || !parts.every(part => /^\p{L}+(?:[-'’]\p{L}+)*$/u.test(part))) return name;
    return parts[1] + ' ' + Array.from(parts[0])[0].toUpperCase() + '.' + suffix;
  }

  window.VitrinePersonNames = Object.freeze({
    shortName,
    shortNames: names => names.split(', ').map(shortName).join(', '),
  });
})();

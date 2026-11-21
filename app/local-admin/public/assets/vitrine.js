'use strict';
function fitTimeColumn() {
  const header = document.querySelector('th.vitrine-time-column');
  if (!header || header.hidden) return;
  const style = getComputedStyle(header);
  const label = header.querySelector('.vitrine-column-label');
  const width = Math.ceil(label.getBoundingClientRect().width + parseFloat(style.paddingLeft) + parseFloat(style.paddingRight) + parseFloat(style.borderLeftWidth) + parseFloat(style.borderRightWidth));
  header.closest('table').style.setProperty('--time-column-width',width + 'px');
}
fitTimeColumn();
document.fonts.ready.then(fitTimeColumn);
window.addEventListener('vitrine-columns-updated',fitTimeColumn);
const folders = Array.from(document.querySelectorAll('.vitrine-folder'));
folders.forEach(folder => folder.addEventListener('toggle', () => {
  if (folder.open) folders.forEach(other => { if (other !== folder) other.open = false; });
}));
document.addEventListener('click', event => {
  folders.forEach(folder => { if (folder.open && !folder.contains(event.target)) folder.open = false; });
});
document.addEventListener('keydown', event => {
  if (event.key !== 'Escape') return;
  folders.forEach(folder => { if (folder.open) { folder.open = false; folder.querySelector('summary').focus(); } });
});
window.addEventListener('resize', () => folders.forEach(folder => { folder.open = false; }));

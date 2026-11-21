'use strict';
(() => {
  const toolbar = document.querySelector('.vitrine-catalog-toolbar');
  if (!toolbar) return;
  const table = document.querySelector('.vitrine-table');
  const archive = toolbar.hasAttribute('data-archive');
  const catalogId = Number(toolbar.dataset.catalogId);
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const path = url => window.DltkPaths?.path(url) ?? url;
  const notice = (message, tone) => window.PlanexNotifications.show({tone, message});
  const feedbackKey = 'dltk-v3.client-catalog-feedback';
  const action = document.getElementById('client-catalog-row-action');
  const currentCell = () => table.querySelector('.is-current-cell');
  const focusEntry = new URL(location.href).searchParams.get('focus');

  if (/^\d+$/.test(focusEntry || '')) {
    const cell = table.querySelector('td[data-row="' + focusEntry + '"][data-column="A"]');
    if (cell) {
      window.VitrineGrid.select(cell);
      cell.focus({preventScroll:true});
      window.VitrineGrid.reveal(cell);
    }
  }
  let feedback;
  try { feedback = sessionStorage.getItem(feedbackKey); sessionStorage.removeItem(feedbackKey); } catch {}
  if (feedback) {
    notice(feedback, 'success');
  }

  async function change(payload) {
    const response = await fetch(path('/api/workspace/catalogs'), {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body:JSON.stringify({catalogId,...payload})
    });
    const result = await response.json();
    if (!response.ok || result.error) throw new Error(result.error?.message || 'Не удалось изменить справочник.');
    return result;
  }

  function refresh(message, entryId = 0) {
    const url = new URL(location.href);
    url.searchParams.delete('focus');
    if (entryId) {
      url.searchParams.delete('archive');
      url.searchParams.delete('q');
      url.searchParams.set('focus', String(entryId));
    }
    try { sessionStorage.setItem(feedbackKey, message); } catch {}
    location.assign(url.href);
  }

  function setupDialog(dialog, submit) {
    const form = dialog.querySelector('form');
    const buttons = [...form.querySelectorAll('button')];
    const close = dialog.querySelector('[data-close]');
    let busy = false;
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (busy) return;
      busy = true;
      buttons.forEach(button => { button.disabled = true; });
      try {
        await submit(Object.fromEntries(new FormData(form)));
      } catch (error) {
        notice(error.message, 'danger');
      } finally {
        busy = false;
        buttons.forEach(button => { button.disabled = false; });
      }
    });
  }

  const add = document.getElementById('client-catalog-add');
  if (add) {
    const dialog = document.getElementById('client-catalog-add-dialog');
    add.addEventListener('click', () => dialog.showModal());
    dialog.addEventListener('close', () => dialog.querySelector('form').reset());
    setupDialog(dialog, async fields => {
      const result = await change({...fields, active:true});
      refresh('Клиент добавлен.', result.id);
    });
  }

  if (action) {
    const dialog = document.getElementById('client-catalog-row-dialog');
    let selectedEntry;
    const enableAction = () => { action.disabled = !currentCell(); };
    window.addEventListener('vitrine-cell-selected', enableAction);
    table.addEventListener('focusin', enableAction);
    table.addEventListener('click', enableAction);
    action.addEventListener('click', () => {
      const cell = currentCell();
      const name = cell?.parentElement.querySelector('[data-column="A"]').textContent;
      if (!cell || !name) return;
      selectedEntry = {id:Number(cell.dataset.row), revision:Number(cell.dataset.revision), name};
      dialog.querySelector('[data-client-name]').textContent = name;
      dialog.showModal();
    });
    setupDialog(dialog, async () => {
      await change({...selectedEntry, active:archive});
      refresh(archive ? 'Клиент восстановлен.' : 'Клиент убран в архив.', archive ? selectedEntry.id : 0);
    });
  }

  const sync = document.getElementById('client-catalog-sync');
  sync?.addEventListener('click', async () => {
    const label = sync.textContent;
    sync.disabled = true;
    sync.textContent = 'Синхронизирую…';
    try {
      await change({action:'refresh_catalog_source'});
      refresh('Справочник обновлён.');
    } catch (error) {
      notice(error.message, 'danger');
      sync.disabled = false;
      sync.textContent = label;
    }
  });
})();

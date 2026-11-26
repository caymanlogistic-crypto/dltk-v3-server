'use strict';
(() => {
  const dialog = document.getElementById('employee-dialog');
  if (!dialog) return;
  const form = dialog.querySelector('form');
  const fields = form.elements;
  const errorLine = form.querySelector('[data-employee-error]');
  const submit = form.querySelector('[type=submit]');
  const roles = JSON.parse(dialog.dataset.roles);
  const viewerId = Number(document.body.dataset.workspaceUser);
  const csrf = document.querySelector('meta[name="csrf-token"]').content;
  const feedbackKey = 'dltk-v3.employees-feedback';
  const path = url => window.DltkPaths?.path(url) ?? url;
  const notice = (message,tone) => window.PlanexNotifications.show({message,tone});
  let employee, roleField, editColumn, saving = false, loading = false;
  const directoryUrl = new URL(location.href);
  const focusRow = directoryUrl.searchParams.get('focusRow');
  const focusColumn = directoryUrl.searchParams.get('focusColumn');
  if (/^[0-9]+$/.test(focusRow || '') && /^[A-F]$/.test(focusColumn || '')) {
    window.VitrineRows.activate({dataset:{row:focusRow,column:focusColumn}});
    directoryUrl.searchParams.delete('focusRow');
    directoryUrl.searchParams.delete('focusColumn');
    history.replaceState(history.state,'',directoryUrl);
  }

  try {
    const message = sessionStorage.getItem(feedbackKey);
    sessionStorage.removeItem(feedbackKey);
    if (message) notice(message,'success');
  } catch {}

  async function request(query='', body) {
    const options = {credentials:'same-origin'};
    if (body) Object.assign(options,{method:'POST',headers:{
      'Content-Type':'application/json','X-CSRF-Token':csrf
    },body:JSON.stringify(body)});
    const response = await fetch(path('/api/workspace/users'+query),options);
    const data = await response.json();
    if (!response.ok || data.error) throw new Error(data.error?.message || 'Не удалось выполнить действие.');
    return data;
  }

  function showError(message) {
    errorLine.textContent = message;
    errorLine.hidden = !message;
    if (message) notice(message,'danger');
  }

  function open(person,column) {
    employee = person;
    editColumn = column;
    const self = person?.id === viewerId;
    form.reset();
    showError('');
    dialog.querySelector('h2').textContent = person ? 'Изменить сотрудника' : 'Добавить сотрудника';
    fields.name.value = person?.name || '';
    fields.login.value = person?.login || '';
    fields.email.value = person?.email || '';
    fields.password.required = !person;
    document.getElementById('employee-password-label').textContent = person ? 'Новый пароль' : 'Пароль';
    if (person) fields.password.placeholder = 'Пустое поле сохраняет текущий пароль';
    else fields.password.removeAttribute('placeholder');
    fields.active.checked = person?.active ?? true;
    fields.active.disabled = self;
    const current = person?.roles.map(role=>role.name).join(', ') || '';
    const options = roles.map(role=>({value:role.name,label:role.name}));
    if (current && !options.some(option=>option.value===current)) options.unshift({value:current,label:current});
    const picker = window.VitrineSelect.create(options,current);
    roleField = picker.field;
    roleField.disabled = self;
    roleField.setAttribute('aria-labelledby','employee-role-label');
    form.querySelector('[data-employee-role]').replaceChildren(picker.element);
    dialog.showModal();
  }

  window.addEventListener('vitrine-employee-card', async ({detail:{row:id,column}}) => {
    if (loading) return;
    loading = true;
    try {
      const result = await request('?id='+encodeURIComponent(id));
      open(result.employee,column);
    } catch (error) {
      notice(error.message,'danger');
    } finally {
      loading = false;
    }
  });
  form.querySelector('[data-close]').addEventListener('click',()=>{ if (!saving) dialog.close(); });
  dialog.addEventListener('cancel',event=>{ if (saving) event.preventDefault(); });
  dialog.addEventListener('close',()=>{ fields.password.value=''; });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (saving) return;
    const originalRole = employee?.roles.map(role=>role.name).join(', ') || '';
    const role = roles.find(option=>option.name===roleField.value);
    if (!employee && !role) {
      showError('Выберите роль сотрудника.');
      roleField.focus();
      return;
    }
    const roleIds = role && roleField.value !== originalRole ? [role.id] : [];
    const payload = {
      id:employee?.id ?? null, revision:employee?.revision, roleIds,
      name:fields.name.value, login:fields.login.value, email:fields.email.value,
      password:fields.password.value, active:fields.active.checked
    };
    saving = true;
    submit.disabled = true;
    showError('');
    try {
      await request('',payload);
      if (employee?.id === viewerId && (payload.password || payload.login.trim().toLowerCase() !== employee.login)) {
        location.assign(path('/login'));
        return;
      }
      try { sessionStorage.setItem(feedbackKey,employee ? 'Сотрудник обновлён.' : 'Сотрудник добавлен.'); } catch {}
      const destination = new URL(location.href);
      const filter = destination.searchParams.get('archive');
      if (filter === '1' && payload.active || filter === 'active' && !payload.active) destination.searchParams.delete('archive');
      if (employee && editColumn) {
        destination.searchParams.set('focusRow',String(employee.id));
        destination.searchParams.set('focusColumn',editColumn);
      }
      location.assign(destination);
    } catch (error) {
      showError(error.message);
      saving = false;
      submit.disabled = false;
    }
  });
})();

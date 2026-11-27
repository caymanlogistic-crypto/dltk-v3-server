'use strict';
(() => {
  function passwordVisibility(password,toggle) {
    function setVisible(visible) {
      password.type = visible ? 'text' : 'password';
      toggle.setAttribute('aria-label',visible ? 'Скрыть пароль' : 'Показать пароль');
    }
    toggle.addEventListener('click',() => setVisible(password.type === 'password'));
    toggle.closest('dialog')?.addEventListener('close',() => setVisible(false));
    toggle.hidden = false;
    return setVisible;
  }
  window.DltkPasswordVisibility = passwordVisibility;

  const form = document.getElementById('workspace-login');
  if (!form) {
    for (const wrapper of document.querySelectorAll('.auth-password')) {
      const input = wrapper.querySelector('input');
      const toggle = wrapper.querySelector('.auth-password-toggle');
      if (input && toggle) passwordVisibility(input,toggle);
    }
    return;
  }

  const button = form.querySelector('button[type="submit"]');
  const spinner = button.querySelector('.auth-submit-spinner');
  const csrf = document.querySelector('meta[name="csrf-token"]').content;

  const password = form.querySelector('#workspace-password');
  const passwordToggle = form.querySelector('.auth-password-toggle');

  const setPasswordVisible = passwordVisibility(password,passwordToggle);

  function setPending(pending) {
    button.disabled = pending;
    button.setAttribute('aria-busy', String(pending));
    spinner.hidden = !pending;
  }

  window.addEventListener('pageshow', event => {
    if (event.persisted) {
      setPending(false);
      setPasswordVisible(false);
    }
  });

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (button.disabled) return;
    setPending(true);

    try {
      const response = await fetch(form.action, {
        method:'POST',
        headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body:JSON.stringify(Object.fromEntries(new FormData(form)))
      });
      const data = await response.json();
      if (!response.ok || typeof data.redirect !== 'string' || !data.redirect) {
        throw new Error(data.error?.message || 'Не удалось войти.');
      }
      const destination = new URL(window.DltkPaths?.path(data.redirect) ?? data.redirect, location.href);
      const vitrine = window.DltkPaths?.path('/vitrine') ?? '/vitrine';
      if (destination.origin === location.origin && destination.pathname === vitrine) {
        destination.searchParams.set('from', 'login');
        destination.searchParams.set('viewport', String(Math.ceil(window.innerHeight)));
      }
      location.assign(destination);
    } catch (error) {
      setPending(false);
      window.PlanexNotifications?.show({tone:'danger',message:error.message});
    }
  });
})();

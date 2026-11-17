(() => {
  'use strict';
  if (window.PlanexNotifications) return;

  const labels = {
    info: {title: 'Информация', icon: 'circle-check'},
    success: {title: 'Готово', icon: 'circle-check'},
    warning: {title: 'Внимание', icon: 'alert-triangle'},
    danger: {title: 'Ошибка', icon: 'circle-x'}
  };
  // Tabler Outline3.48.0, verified against the approved SHA256 manifest; MIT.
  const iconData = {"circle-check":{"tag":"svg","attrs":{"width":"24","height":"24","viewBox":"0 0 24 24","fill":"none","stroke":"currentColor","stroke-width":"2","stroke-linecap":"round","stroke-linejoin":"round","class":"icon icon-tabler icons-tabler-outline icon-tabler-circle-check"},"children":[{"tag":"path","attrs":{"stroke":"none","d":"M0 0h24v24H0z","fill":"none"},"children":[]},{"tag":"path","attrs":{"d":"M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"},"children":[]},{"tag":"path","attrs":{"d":"M9 12l2 2l4 -4"},"children":[]}]},"alert-triangle":{"tag":"svg","attrs":{"width":"24","height":"24","viewBox":"0 0 24 24","fill":"none","stroke":"currentColor","stroke-width":"2","stroke-linecap":"round","stroke-linejoin":"round","class":"icon icon-tabler icons-tabler-outline icon-tabler-alert-triangle"},"children":[{"tag":"path","attrs":{"stroke":"none","d":"M0 0h24v24H0z","fill":"none"},"children":[]},{"tag":"path","attrs":{"d":"M12 9v4"},"children":[]},{"tag":"path","attrs":{"d":"M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0"},"children":[]},{"tag":"path","attrs":{"d":"M12 16h.01"},"children":[]}]},"circle-x":{"tag":"svg","attrs":{"width":"24","height":"24","viewBox":"0 0 24 24","fill":"none","stroke":"currentColor","stroke-width":"2","stroke-linecap":"round","stroke-linejoin":"round","class":"icon icon-tabler icons-tabler-outline icon-tabler-circle-x"},"children":[{"tag":"path","attrs":{"stroke":"none","d":"M0 0h24v24H0z","fill":"none"},"children":[]},{"tag":"path","attrs":{"d":"M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0"},"children":[]},{"tag":"path","attrs":{"d":"M10 10l4 4m0 -4l-4 4"},"children":[]}]}};
  function drawIcon(data) {
    const element = document.createElementNS('http://www.w3.org/2000/svg', data.tag);
    for (const [name, value] of Object.entries(data.attrs)) element.setAttribute(name, value);
    for (const child of data.children) element.append(drawIcon(child));
    return element;
  }
  let stack = null, stackHost = null, hostObserver = null;
  let templateResolver = null;

  function resolveText(original, eventKey, variables) {
    if (!templateResolver) return original;
    try {
      const copied = {};
      for (const [key, value] of Object.entries(variables || {}).slice(0, 32)) {
        if (!/^[a-z][a-zA-Z0-9_]{0,39}$/.test(key) || !['string', 'number', 'boolean'].includes(typeof value)) continue;
        const text = String(value);
        if (text.length <= 2000) copied[key] = text;
      }
      const snapshot = Object.freeze({...original,
        eventKey: typeof eventKey === 'string' && /^[a-z0-9-]{1,80}$/.test(eventKey) ? eventKey : '',
        variables: Object.freeze(copied)});
      const result = templateResolver(snapshot);
      if (!result || typeof result !== 'object' || Object.keys(result).some(key => !['title', 'message'].includes(key)) ||
          typeof result.title !== 'string' || typeof result.message !== 'string') return original;
      const title = result.title.trim(), message = result.message.trim();
      if (!title || !message || title.length > 160 || message.length > 2000) return original;
      return {tone: original.tone, title, message};
    } catch { return original; }
  }

  function notificationHost() {
    const dialogs = Array.from(document.querySelectorAll?.('dialog[open]') || []);
    return dialogs.at(-1) || document.body;
  }
  function placeStack() {
    if (!stack) return;
    const host = notificationHost();
    if (stack.parentNode === host) return;
    const focus = document.activeElement && stack.contains?.(document.activeElement) ? document.activeElement : null;
    if (host.moveBefore && stack.isConnected) host.moveBefore(stack, null);
    else host.append(stack);
    stackHost = host;
    if (focus?.isConnected && document.activeElement !== focus) focus.focus({preventScroll: true});
  }
  function watchDialogHosts() {
    if (hostObserver || typeof MutationObserver !== 'function') return;
    hostObserver = new MutationObserver(() => {
      if (stack?.isConnected || stackHost?.tagName === 'DIALOG') placeStack();
    });
    hostObserver.observe(document.body, {subtree: true, childList: true, attributes: true, attributeFilter: ['open']});
    // Capturing runs before a dialog owner's close handler can remove its subtree.
    document.addEventListener('close', placeStack, true);
  }

  function container() {
    if (stack?.isConnected || stack && stackHost?.tagName === 'DIALOG') { placeStack(); return stack; }
    stack = document.createElement('div');
    stack.className = 'toast-stack toast-stack--system';
    stack.setAttribute('aria-label', 'Всплывающие уведомления');
    placeStack();
    watchDialogHosts();
    return stack;
  }

  function present(entry) {
    const toast = document.createElement('div');
    toast.className = `toast toast--system is-${entry.tone}`;
    toast.setAttribute('role', entry.tone === 'danger' ? 'alert' : 'status');

    const icon = document.createElement('span');
    icon.className = 'toast-icon';
    icon.setAttribute('aria-hidden', 'true');
    const graphic = drawIcon(iconData[labels[entry.tone].icon]);
    graphic.setAttribute('aria-hidden', 'true');
    graphic.setAttribute('focusable', 'false');
    icon.append(graphic);

    const content = document.createElement('div');
    content.className = 'toast-content';
    const title = document.createElement('div');
    title.className = 'toast-title';
    title.textContent = entry.title;
    const description = document.createElement('div');
    description.className = 'toast-desc';
    description.textContent = entry.message;
    content.append(title, description);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast-close';
    close.setAttribute('aria-label', 'Закрыть уведомление');
    close.textContent = '×';
    toast.append(icon, content, close);
    container().prepend(toast);

    const opener = document.activeElement;
    const clock = () => window.performance?.now() ?? Date.now();
    const paused = new Set();
    let closed = false, removing = false, timer = null, exitTimer = null;
    let remaining = 10000, deadline = 0;
    const hasFocus = () => !!document.activeElement && toast.contains(document.activeElement);
    function startTimer() {
      if (closed || removing || paused.size) return;
      deadline = clock() + remaining;
      timer = window.setTimeout(() => {
        timer = null;
        remaining = 0;
        // Focus can move before a delegated focusin listener runs.
        if (hasFocus()) { paused.add('focus'); return; }
        dismiss(false);
      }, remaining);
    }
    function pause(reason) {
      if (closed || paused.has(reason)) return;
      if (timer !== null) {
        remaining = Math.max(0, deadline - clock());
        window.clearTimeout(timer); timer = null;
      }
      // An interaction during the exit transition must not lose its target.
      if (removing) {
        window.clearTimeout(exitTimer); exitTimer = null;
        removing = false; toast.classList.remove('is-removing');
      }
      paused.add(reason);
    }
    function resume(reason) {
      if (!paused.delete(reason)) return;
      if (!paused.size && !closed) startTimer();
    }
    function dismiss(manual) {
      if (closed || removing) return;
      if (!manual && hasFocus()) { pause('focus'); return; }
      const restoreFocus = manual && hasFocus();
      const siblings = Array.from(stack.children);
      const index = siblings.indexOf(toast);
      const neighbor = siblings[index + 1] || siblings[index - 1];
      window.clearTimeout(timer); timer = null;
      if (manual || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        closed = true; toast.remove();
        if (restoreFocus) {
          const target = neighbor?.querySelector('.toast-close');
          if (target?.isConnected) target.focus();
          else if (opener?.isConnected && opener !== document.body) opener.focus();
        }
        return;
      }
      removing = true;
      toast.classList.add('is-removing');
      exitTimer = window.setTimeout(() => {
        if (hasFocus()) { pause('focus'); return; }
        closed = true; toast.remove();
      }, 180);
    }
    toast.addEventListener('pointerenter', () => pause('hover'));
    toast.addEventListener('pointerleave', () => resume('hover'));
    toast.addEventListener('focusin', () => pause('focus'));
    toast.addEventListener('focusout', event => {
      if (!event.relatedTarget || !toast.contains(event.relatedTarget)) resume('focus');
    });
    close.addEventListener('click', () => dismiss(true));
    startTimer();
  }

  window.PlanexNotifications = Object.freeze({
    setTemplateResolver(callback) {
      if (callback !== null && typeof callback !== 'function') throw new TypeError('A notification text resolver must be a function or null');
      templateResolver = callback;
    },
    show({tone = 'success', title, message, eventKey, variables} = {}) {
      if (!Object.hasOwn(labels, tone)) throw new TypeError('Unknown notification tone');
      const body = String(message ?? '').trim();
      if (!body) return;
      const original = {tone, title: String(title ?? labels[tone].title), message: body};
      present(resolveText(original, eventKey, variables));
    }
  });
})();

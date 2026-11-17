(() => {
  'use strict';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  if (!csrf) return;
  const initialCursor = document.querySelector('meta[name="workspace-event-cursor"]')?.content;
  let socket = null, stopped = false, cursor = initialCursor != null && /^\d+$/.test(initialCursor) ? Number(initialCursor) : null, retry = 0, timer = null, fallbackTimer = null, polling = false;
  const listeners = new Set();
  let editing = null, authenticated = false;
  function sendEditing() {
    if(authenticated && socket?.readyState === WebSocket.OPEN) socket.send(JSON.stringify({type:'editing',cell:editing}));
  }
  const status = value => {
    document.documentElement.dataset.liveState = value;
    for (const el of document.querySelectorAll('[data-live-status]')) el.textContent = value === 'online' ? 'Онлайн' : value === 'polling' ? 'Обновление по запросам' : 'Подключение…';
    window.dispatchEvent(new CustomEvent('workspace:connection', {detail: {state: value}}));
  };
  function receive(item) {
    if (item.type === 'cursor') { cursor = Math.max(cursor || 0, Number(item.cursor)); return; }
    if (item.type !== 'event' || !Number.isSafeInteger(Number(item.id))) return;
    if (cursor !== null && Number(item.id) <= cursor) return;
    cursor = Number(item.id);
    const detail = {id: cursor, event: item.event, data: item.data};
    for (const fn of listeners) fn(detail);
    window.dispatchEvent(new CustomEvent('workspace:event', {detail}));
  }
  async function fallback() {
    if (polling || stopped || cursor === null || socket?.readyState === WebSocket.OPEN) return;
    polling = true;
    try {
      const response = await fetch(window.DltkPaths.path('/api/workspace/events?after=' + cursor), {credentials:'same-origin',cache:'no-store'});
      if (response.status === 401) { stop(); return; }
      if (!response.ok) return;
      const result = await response.json();
      for (const item of result.events || []) receive({...item, type:'event'});
      if (Number.isSafeInteger(Number(result.cursor))) cursor = Math.max(cursor, Number(result.cursor));
      status('polling');
    } catch (_) {} finally { polling = false; }
  }
  function schedule() {
    if (stopped) return;
    clearTimeout(timer);
    timer = setTimeout(connect, Math.min(10000, 500 * 2 ** Math.min(retry++, 5)));
    if (!fallbackTimer) fallbackTimer = setInterval(fallback, 2500);
  }
  async function connect() {
    if (stopped || socket?.readyState === WebSocket.OPEN || socket?.readyState === WebSocket.CONNECTING) return;
    try {
      const response = await fetch(window.DltkPaths.path('/api/workspace/ws-ticket'), {method:'POST',headers:{'X-CSRF-Token':csrf,'Content-Type':'application/json'},credentials:'same-origin',body:'{}'});
      if (response.status === 401) { stop(); return; }
      if (!response.ok) throw Error('ticket');
      const ticket = await response.json();
      if (cursor === null) cursor = Number(ticket.cursor || 0);
      socket = new WebSocket(ticket.url);
      socket.onopen = () => socket.send(JSON.stringify({type:'auth',ticket:ticket.ticket,cursor}));
      socket.onmessage = event => {
        try {
          const item = JSON.parse(event.data);
          if (item.type === 'ready') { authenticated=true;retry = 0; clearInterval(fallbackTimer);fallbackTimer=null;status('online');sendEditing(); }
          else if(item.type==='presence')window.dispatchEvent(new CustomEvent('workspace:presence',{detail:{editors:item.editors||[]}}));
          else if(item.type==='presence_error')window.PlanexNotifications?.show({tone:'warning',message:item.message||'Не удалось показать редактирование ячейки.'});
          else receive(item);
        } catch (_) {}
      };
      socket.onclose = () => {authenticated=false;socket=null;status('connecting');schedule();};
      socket.onerror = () => socket?.close();
    } catch (_) {status('connecting');schedule();}
  }
  function stop() {stopped=true;clearTimeout(timer);clearInterval(fallbackTimer);if(socket){if(authenticated&&socket.readyState===WebSocket.OPEN)socket.send(JSON.stringify({type:'editing',cell:null}));socket.onclose=null;socket.close();socket=null;}authenticated=false;window.dispatchEvent(new CustomEvent('workspace:presence',{detail:{editors:[]}}));}
  window.WorkspaceLive = {subscribe(fn){listeners.add(fn);return()=>listeners.delete(fn);},setEditing(cell){editing=cell;sendEditing();},stop,get cursor(){return cursor;}};
  setInterval(()=>{if(editing)sendEditing();},5000);
  document.addEventListener('visibilitychange',()=>{if(!document.hidden&&!stopped){fallback();connect();sendEditing();}});
  window.addEventListener('online',()=>{if(!stopped)connect();});
  window.addEventListener('pagehide',stop);
  window.addEventListener('pageshow',event=>{if(event.persisted){stopped=false;status('connecting');connect();}});
  status('connecting');connect();
})();

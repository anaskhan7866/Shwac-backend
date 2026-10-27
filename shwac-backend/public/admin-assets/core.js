/* SHWAC admin panel: core (API client, router, layout, UI helpers).
 * Plain browser JavaScript, no build step. Pages register themselves in SH.pages.
 */
(function () {
  'use strict';
  const CFG = window.SHWAC || { api: '/api/v1' };
  const LS = {
    get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch (e) { /* private mode */ } },
  };

  const SH = window.SH = {
    cfg: CFG, pages: [], state: { token: LS.get('shwac_admin_token'), user: null, branch: LS.get('shwac_admin_branch') || '', counts: {} },
    view: null, modal: null,
  };

  // ---------------------------------------------------------------- helpers
  const esc = SH.esc = (v) => (v === null || v === undefined ? '' : String(v)).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  SH.money = (v) => '₹' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  SH.money0 = (v) => '₹' + Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
  const TZ = 'Asia/Kolkata';
  SH.d = (v) => v ? new Date(v).toLocaleDateString('en-IN', { timeZone: TZ, day: '2-digit', month: 'short', year: 'numeric' }) : '—';
  SH.dt = (v) => v ? new Date(v).toLocaleString('en-IN', { timeZone: TZ, day: '2-digit', month: 'short', hour: 'numeric', minute: '2-digit' }) : '—';
  SH.t = (v) => v ? new Date(v).toLocaleTimeString('en-IN', { timeZone: TZ, hour: 'numeric', minute: '2-digit' }) : '—';
  SH.today = () => new Date().toLocaleDateString('en-CA', { timeZone: TZ }); // YYYY-MM-DD
  SH.isoDay = (v) => v ? new Date(v).toLocaleDateString('en-CA', { timeZone: TZ }) : '';
  SH.mins = (m) => { const a = Math.abs(m); const h = Math.floor(a / 60); const r = a % 60; return (h ? h + 'h ' : '') + r + 'm'; };
  const WORDS = { upi: 'UPI', gst: 'GST', sla: 'SLA', ro: 'RO', otp: 'OTP', sms: 'SMS', bank_transfer: 'Bank transfer' };
  SH.label = (s) => { const k = (s || '').toString(); if (WORDS[k.toLowerCase()]) return WORDS[k.toLowerCase()]; return k.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase()); };
  const PILL = {
    open: ['', 'Open'], pending: ['', 'Pending'], reopened: ['warn', 'Reopened'], assigned: ['teal', 'Assigned'], accepted: ['teal', 'Accepted'],
    en_route: ['blue', 'On the way'], in_progress: ['warn', 'In progress'], completed: ['ok', 'Completed'], resolved: ['ok', 'Resolved'], closed: ['', 'Closed'],
    cancelled: ['bad', 'Cancelled'], confirmed: ['teal', 'Confirmed'], scheduled: ['teal', 'Scheduled'], draft: ['', 'Draft'], issued: ['teal', 'Issued'],
    partially_paid: ['warn', 'Part paid'], paid: ['ok', 'Paid'], overdue: ['bad', 'Overdue'], success: ['ok', 'Received'], failed: ['bad', 'Failed'],
    pending_verification: ['warn', 'To verify'], rejected: ['bad', 'Rejected'], approved: ['ok', 'Approved'], ready: ['teal', 'To send'], sent: ['ok', 'Sent'],
    critical: ['bad', 'Critical'], high: ['warn', 'High'], normal: ['', 'Normal'], low: ['', 'Low'], on_duty: ['ok', 'On duty'], checked_out: ['', 'Checked out'],
    not_checked_in: ['bad', 'Not checked in'], active: ['ok', 'Active'], inactive: ['bad', 'Inactive'], skipped: ['', 'Skipped'],
  };
  SH.pill = (s, text) => { const p = PILL[s] || ['', SH.label(s)]; return `<span class="pill ${p[0]}">${esc(text || p[1])}</span>`; };
  SH.can = (perm) => { const p = (SH.state.user && SH.state.user.permissions) || []; return p.includes('*') || p.includes(perm); };
  SH.allBranches = () => SH.can('branches.all');
  SH.qs = (o) => { const u = new URLSearchParams(); Object.keys(o || {}).forEach((k) => { const v = o[k]; if (v !== undefined && v !== null && v !== '') u.set(k, v); }); const s = u.toString(); return s ? '?' + s : ''; };
  SH.withBranch = (o) => Object.assign({}, o, SH.allBranches() && SH.state.branch ? { branch_id: SH.state.branch } : {});

  // Form → object. Names like "address.line1" become nested; checkboxes become booleans; data-num inputs become numbers.
  SH.formData = (form) => {
    const out = {};
    form.querySelectorAll('[name]').forEach((el) => {
      if (el.disabled) return;
      let v;
      if (el.type === 'checkbox') { if (el.dataset.multi) { if (!el.checked) return; v = el.value; } else v = el.checked; }
      else if (el.type === 'radio') { if (!el.checked) return; v = el.value; }
      else v = el.value;
      if (typeof v === 'string') { v = v.trim(); if (el.dataset.num !== undefined && v !== '') v = Number(v); }
      const path = el.name.split('.'); let o = out;
      path.slice(0, -1).forEach((k) => { o[k] = o[k] || {}; o = o[k]; });
      const last = path[path.length - 1];
      if (el.dataset.multi) { o[last] = (o[last] || []).concat([v]); } else o[last] = v;
    });
    return out;
  };
  SH.clean = (o) => { Object.keys(o).forEach((k) => { if (o[k] === '') o[k] = null; }); return o; };

  // ---------------------------------------------------------------- API
  SH.api = async (method, path, body, opts) => {
    const headers = { Accept: 'application/json' };
    if (SH.state.token) headers.Authorization = 'Bearer ' + SH.state.token;
    if (body !== undefined && !(body instanceof FormData)) headers['Content-Type'] = 'application/json';
    let res;
    try {
      res = await fetch(CFG.api + path, { method, headers, body: body === undefined ? undefined : (body instanceof FormData ? body : JSON.stringify(body)) });
    } catch (e) { throw new Error('Cannot reach the server. Check your internet connection.'); }
    if (opts && opts.raw) { if (!res.ok) throw new Error('Request failed (' + res.status + ')'); return res; }
    const text = await res.text();
    let data = null; try { data = text ? JSON.parse(text) : null; } catch (e) { data = text; }
    if (res.status === 401) { SH.logout(true); throw new Error('Your session ended. Please log in again.'); }
    if (!res.ok) {
      let msg = (data && data.message) || ('Request failed (' + res.status + ')');
      if (data && data.errors) { const first = Object.values(data.errors)[0]; if (first && first[0]) msg = first[0]; }
      if (res.status === 403 && !(data && data.message)) msg = 'You do not have permission for this.';
      const err = new Error(msg); err.status = res.status; err.data = data; throw err;
    }
    return data;
  };
  SH.get = (p, q) => SH.api('GET', p + SH.qs(q));
  SH.post = (p, b) => SH.api('POST', p, b || {});
  SH.patch = (p, b) => SH.api('PATCH', p, b || {});
  SH.put = (p, b) => SH.api('PUT', p, b || {});

  // ---------------------------------------------------------------- toast / modal
  SH.toast = (msg, bad) => {
    const el = document.createElement('div'); el.className = 'toast' + (bad ? ' bad' : ''); el.textContent = msg;
    document.getElementById('toast-root').appendChild(el); setTimeout(() => el.remove(), bad ? 7000 : 4500);
  };
  SH.openModal = (html, actions, opts) => {
    SH.modal = { actions: actions || {}, onClose: opts && opts.onClose };
    document.getElementById('modal-root').innerHTML = `<div class="modal-back" data-modal-back><div class="modal ${opts && opts.wide ? 'wide' : ''}" role="dialog" aria-modal="true">${html}</div></div>`;
    const f = document.querySelector('#modal-root input:not([type=hidden]), #modal-root select, #modal-root textarea'); if (f) f.focus();
    return document.querySelector('#modal-root .modal');
  };
  SH.closeModal = () => { const m = SH.modal; SH.modal = null; document.getElementById('modal-root').innerHTML = ''; if (m && m.onClose) m.onClose(); };
  SH.confirm = (title, text, okLabel, withReason) => new Promise((resolve) => {
    SH.openModal(`<h3>${esc(title)}</h3><p class="muted" style="margin:0">${esc(text || '')}</p>
      ${withReason ? `<form data-submit="ok"><label class="f">${esc(withReason)}<input class="in" name="reason" required maxlength="255"></label></form>` : ''}
      <div class="actions"><button class="btn" data-act="no">Cancel</button><button class="btn primary" data-act="ok">${esc(okLabel || 'OK')}</button></div>`, {
      no: () => { SH.closeModal(); resolve(null); },
      ok: () => {
        if (withReason) { const r = document.querySelector('#modal-root [name=reason]'); if (!r.value.trim()) { r.focus(); return; } SH.closeModal(); resolve(r.value.trim()); }
        else { SH.closeModal(); resolve(true); }
      },
    });
  });

  // WhatsApp share (free click-to-send). Opening the link marks it sent.
  SH.share = (s, title) => {
    if (!s || !s.url) return;
    SH.openModal(`<h3>${esc(title || 'Send on WhatsApp?')}</h3>
      <div class="muted small">${esc(s.label || '')} · to +${esc(s.to || '')}</div>
      <div class="bubble">${esc(s.message)}</div>
      <div class="muted small">WhatsApp opens with this text. Press Send there. It is marked as sent here.</div>
      <div class="actions"><button class="btn" data-act="later">Later (stays in the outbox)</button>
      <a class="btn wa" href="${esc(s.url)}" target="_blank" rel="noopener" data-act="sent" data-id="${s.id}">Open WhatsApp</a></div>`, {
      later: () => SH.closeModal(),
      sent: async (el) => { SH.closeModal(); try { await SH.post('/admin/whatsapp/' + el.dataset.id + '/sent'); SH.refreshCounts(); } catch (e) { SH.toast(e.message, true); } },
    });
  };
  SH.shareFrom = (res, title) => { const s = res && (res.whatsapp || (res.message && res.url ? res : null)); if (s && s.url && !s.sent) SH.share(s, title); };

  // Customer picker: search by name / phone; sets hidden input; calls view/modal action named in data-onpick.
  SH.customerPicker = (name, label, preset, onpick) => `<div class="f" style="display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:var(--muted)">${esc(label || 'Customer')}
    <div class="picker" data-onpick="${esc(onpick || '')}">
      <input type="hidden" name="${esc(name)}" value="${preset ? esc(preset.id) : ''}">
      <div class="picked" ${preset ? '' : 'hidden'}><span data-picked-label>${preset ? esc(preset.name + ' · ' + preset.phone) : ''}</span><button type="button" class="btn sm ghost" data-act="pickerClear">Change</button></div>
      <input class="in" ${preset ? 'hidden' : ''} placeholder="Type name or phone" autocomplete="off" data-input="pickerSearch">
      <div class="opts" hidden></div>
    </div></div>`;

  // ---------------------------------------------------------------- global actions
  const G = SH.globalActions = {
    pickerSearch: async (el) => {
      const box = el.closest('.picker'); const opts = box.querySelector('.opts'); const q = el.value.trim();
      if (q.length < 2) { opts.hidden = true; return; }
      try {
        const r = await SH.get('/admin/customers', SH.withBranch({ q, per_page: 8 }));
        opts.innerHTML = r.data.length ? r.data.map((c) => `<button type="button" data-act="pickerChoose" data-id="${c.id}" data-label="${esc(c.name + ' · ' + c.phone)}"><b>${esc(c.name)}</b> <span class="muted">${esc(c.phone)} · ${esc(c.code || '')}</span></button>`).join('') : '<div class="empty">No customer found</div>';
        opts.hidden = false;
      } catch (e) { SH.toast(e.message, true); }
    },
    pickerChoose: (el) => {
      const box = el.closest('.picker');
      box.querySelector('input[type=hidden]').value = el.dataset.id;
      box.querySelector('[data-picked-label]').textContent = el.dataset.label;
      box.querySelector('.picked').hidden = false; box.querySelector('input.in').hidden = true; box.querySelector('.opts').hidden = true;
      const fn = box.dataset.onpick; if (fn) SH.runAction(fn, box, null);
    },
    pickerClear: (el) => {
      const box = el.closest('.picker');
      box.querySelector('input[type=hidden]').value = ''; box.querySelector('.picked').hidden = true;
      const i = box.querySelector('input.in'); i.hidden = false; i.value = ''; i.focus();
    },
    closeModal: () => SH.closeModal(),
    menu: () => document.querySelector('.side').classList.toggle('open'),
    logout: () => SH.logout(),
    branch: (el) => { SH.state.branch = el.value; LS.set('shwac_admin_branch', el.value || null); SH.route(); },
    wa: async (el) => { try { await SH.post('/admin/whatsapp/' + el.dataset.id + '/sent'); SH.refreshCounts(); if (SH.view && SH.view.reload) SH.view.reload(); } catch (e) { SH.toast(e.message, true); } },
    printInvoice: async (el) => {
      const w = window.open('', '_blank');
      try {
        const res = await SH.api('GET', '/admin/invoices/' + el.dataset.id + '/print', undefined, { raw: true });
        const html = await res.text(); w.document.open(); w.document.write(html); w.document.close();
      } catch (e) { w.close(); SH.toast(e.message, true); }
    },
    copy: async (el) => { try { await navigator.clipboard.writeText(el.dataset.text); SH.toast('Copied.'); } catch (e) { SH.toast('Copy failed: ' + el.dataset.text, true); } },
  };

  SH.runAction = (name, el, ev) => {
    const tables = [];
    if (SH.modal && el && el.closest && el.closest('#modal-root')) tables.push(SH.modal.actions);
    if (SH.view && SH.view.actions) tables.push(SH.view.actions);
    if (SH.modal && !(el && el.closest && el.closest('#modal-root'))) tables.push(SH.modal.actions);
    tables.push(G);
    for (const t of tables) {
      if (t && typeof t[name] === 'function') {
        return Promise.resolve().then(() => t[name](el, ev)).catch((e) => SH.toast(e.message || String(e), true));
      }
    }
    return Promise.resolve(false);
  };

  // Busy state for submit buttons
  SH.busy = async (btn, fn) => { if (btn) { btn.disabled = true; } try { return await fn(); } finally { if (btn) btn.disabled = false; } };

  document.addEventListener('click', (ev) => {
    const back = ev.target.closest('[data-modal-back]');
    if (back && ev.target === back) { SH.closeModal(); return; }
    const el = ev.target.closest('[data-act]');
    if (el) {
      if (el.tagName !== 'A' || !el.getAttribute('target')) ev.preventDefault();
      SH.runAction(el.dataset.act, el, ev);
      return;
    }
    const row = ev.target.closest('tr[data-href]');
    if (row && !ev.target.closest('a,button,input,select')) location.hash = row.dataset.href;
    if (!ev.target.closest('.picker')) document.querySelectorAll('.picker .opts').forEach((o) => { o.hidden = true; });
  });
  document.addEventListener('submit', (ev) => {
    const f = ev.target.closest('form[data-submit]'); if (!f) return;
    ev.preventDefault();
    const btn = f.querySelector('[type=submit]') || (f.closest('.modal') && f.closest('.modal').querySelector('[data-submit-btn]'));
    SH.busy(btn, () => SH.runAction(f.dataset.submit, f, ev));
  });
  // Debounce per element, so typing quickly across several fields never drops a value.
  document.addEventListener('input', (ev) => {
    const el = ev.target.closest('[data-input]'); if (!el) return;
    if (el.dataset.now !== undefined) { SH.runAction(el.dataset.input, el, ev); return; }
    clearTimeout(el._shT); el._shT = setTimeout(() => SH.runAction(el.dataset.input, el, ev), 280);
  });
  document.addEventListener('change', (ev) => { const el = ev.target.closest('[data-change]'); if (el) SH.runAction(el.dataset.change, el, ev); });
  document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape' && SH.modal) SH.closeModal(); });

  // ---------------------------------------------------------------- auth
  SH.logout = async (silent) => {
    if (!silent && SH.state.token) { try { await SH.post('/auth/logout'); } catch (e) { /* ignore */ } }
    SH.state.token = null; SH.state.user = null; LS.set('shwac_admin_token', null);
    if (SH.view && SH.view.destroy) SH.view.destroy(); SH.view = null;
    renderLogin();
  };
  function renderLogin(msg) {
    document.getElementById('app').innerHTML = `<div class="login"><form data-submit="login" autocomplete="on">
      <div class="brand" style="padding:0">${logo()}<div><b style="color:var(--ink)">SHWAC</b><span>Admin panel</span></div></div>
      <h1 style="margin:0;font-size:22px">Log in</h1>
      ${msg ? `<div class="note warn">${esc(msg)}</div>` : ''}
      <label class="f">Email or phone<input class="in" name="login" autocomplete="username" required></label>
      <label class="f">Password<input class="in" type="password" name="password" autocomplete="current-password" required></label>
      <div class="err" data-err></div>
      <button class="btn primary" type="submit" style="min-height:46px">Log in</button>
      <div class="muted small">For office staff. Technicians use the Android app; customers use the customer app.</div>
    </form></div>`;
    SH.view = { actions: { login: async (f) => {
      const d = SH.formData(f);
      try {
        const r = await SH.api('POST', '/auth/login', { login: d.login, password: d.password, device_name: 'admin-web' });
        if (r.user.user_type !== 'staff') { SH.state.token = r.token; await SH.logout(); renderLogin('This login is for office staff. Technicians use the technician app.'); return; }
        SH.state.token = r.token; SH.state.user = r.user; LS.set('shwac_admin_token', r.token);
        if (!location.hash || location.hash === '#/') location.hash = '#/dashboard';
        SH.route();
      } catch (e) { f.querySelector('[data-err]').textContent = e.message; }
    } } };
  }

  // ---------------------------------------------------------------- layout & navigation
  function logo() { return '<svg width="36" height="36" viewBox="0 0 36 36" aria-hidden="true"><rect width="36" height="36" rx="10" fill="#1C4249"></rect><path d="M18 8c4 5.2 7 9 7 12.6A7 7 0 0 1 11 20.6C11 17 14 13.2 18 8z" fill="none" stroke="#7FD3DC" stroke-width="2" stroke-linejoin="round"></path><path d="M15 21.5a3 3 0 0 0 3 3" fill="none" stroke="#7FD3DC" stroke-width="2" stroke-linecap="round"></path></svg>'; }

  SH.nav = [
    ['Operations', [
      ['#/dashboard', 'Dashboard', 'dashboard.view'],
      ['#/todo', 'Today’s to-do', 'dashboard.view', (c) => c.todo],
      ['#/complaints', 'Complaints', 'complaints.view', (c) => c.breached, 'red'],
      ['#/bookings', 'Bookings', 'bookings.view'],
      ['#/jobs', 'Service jobs', 'jobs.view', (c) => c.unassigned, 'amber'],
      ['#/customers', 'Customers', 'customers.view'],
      ['#/map', 'Live technician map', 'field.view'],
    ]],
    ['Money', [
      ['#/invoices', 'Invoices', 'invoices.view'],
      ['#/verify', 'UPI payments to verify', 'payments.manage', (c) => c.verify, 'amber'],
      ['#/payments', 'Payments', 'payments.view'],
    ]],
    ['WhatsApp', [
      ['#/outbox', 'WhatsApp outbox', 'dashboard.view', (c) => c.outbox, 'wa'],
      ['#/templates', 'Message texts', 'settings.manage'],
    ]],
    ['Stock & staff', [
      ['#/inventory', 'Inventory', 'inventory.view', (c) => c.lowStock, 'amber'],
      ['#/expenses', 'Expense approvals', 'expenses.manage', (c) => c.expenses],
      ['#/attendance', 'Attendance', 'field.view'],
    ]],
    ['Setup', [
      ['#/catalog', 'Services & prices', 'services.manage'],
      ['#/users', 'Staff & roles', 'users.manage'],
      ['#/branches', 'Branches', 'branches.manage'],
      ['#/settings', 'Settings', 'settings.manage'],
      ['#/costs', 'Running costs', null],
    ]],
  ];

  SH.refreshCounts = async () => {
    if (!SH.can('dashboard.view')) return;
    try {
      const d = await SH.get('/admin/dashboard', SH.withBranch({}));
      SH.state.dash = d;
      SH.state.counts = {
        todo: (d.todo.whatsapp_to_send || 0) + (d.todo.payments_to_verify || 0), outbox: d.todo.whatsapp_to_send, verify: d.todo.payments_to_verify,
        breached: d.complaints.sla_breached, unassigned: d.jobs.unassigned, lowStock: d.inventory.low_stock_alerts, expenses: d.field.expenses_pending,
      };
      drawNav();
    } catch (e) { /* badges are optional */ }
  };

  function drawNav() {
    const box = document.getElementById('nav'); if (!box) return;
    const cur = '#' + (location.hash.slice(1).split('?')[0].split('/').slice(0, 2).join('/'));
    const c = SH.state.counts || {};
    box.innerHTML = SH.nav.map(([group, items]) => {
      const vis = items.filter((i) => !i[2] || SH.can(i[2])); if (!vis.length) return '';
      return `<nav class="navgroup" aria-label="${esc(group)}"><h6>${esc(group)}</h6>${vis.map((i) => {
        const n = i[3] ? i[3](c) : 0;
        return `<a href="${i[0]}" class="${cur === i[0] ? 'on' : ''}"><span>${esc(i[1])}</span>${n ? `<span class="badge ${i[4] || ''}">${n}</span>` : ''}</a>`;
      }).join('')}</nav>`;
    }).join('');
  }

  async function drawShell() {
    const u = SH.state.user;
    let branchSel = '';
    if (SH.allBranches()) {
      if (!SH.state.branches) { try { SH.state.branches = await SH.get('/admin/branches'); } catch (e) { SH.state.branches = []; } }
      branchSel = `<label class="f" style="color:#A9BDBF;padding:0 8px">Branch<select class="in" data-change="branch" style="background:#1C4249;color:#fff;border-color:#23464D">
        <option value="">All branches</option>${SH.state.branches.map((b) => `<option value="${b.id}" ${String(b.id) === String(SH.state.branch) ? 'selected' : ''}>${esc(b.name)}</option>`).join('')}</select></label>`;
    }
    const initials = (u.name || '?').split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();
    document.getElementById('app').innerHTML = `<div class="shell">
      <aside class="side">
        <div class="brand">${logo()}<div><b>SHWAC</b><span>Service Platform</span></div><button class="menu-btn" data-act="menu" aria-label="Menu">Menu</button></div>
        ${branchSel}
        <div id="nav" style="display:flex;flex-direction:column;gap:12px"></div>
        <div class="me"><div class="avatar">${esc(initials)}</div><div class="who"><b>${esc(u.name)}</b><span>${esc((u.roles || []).map(SH.label).join(', '))}${u.branch ? ' · ' + esc(u.branch.code) : ''}</span></div><button class="linkbtn" data-act="logout">Log out</button></div>
      </aside>
      <main class="main"><div class="page" id="page"></div></main></div>`;
    drawNav();
  }

  // ---------------------------------------------------------------- router
  // SH.pages: [{ path: '/complaints/:id', render: async (params, query) => ({ html, actions, init, destroy, reload }) }]
  SH.page = (path, render) => SH.pages.push({ path, render, re: new RegExp('^' + path.replace(/:\w+/g, '([^/]+)') + '$') });
  SH.parseHash = () => {
    const h = location.hash.slice(1) || '/dashboard'; const [p, q] = h.split('?');
    return { path: p, query: Object.fromEntries(new URLSearchParams(q || '')) };
  };
  SH.go = (path, query) => { location.hash = '#' + path + SH.qs(query); };
  // Navigate, then run fn once the new page has rendered (e.g. open the WhatsApp share dialog).
  SH.goThen = (path, fn) => { const h = '#' + path; if (location.hash === h) { SH.route().then(fn); return; } SH.afterRoute = fn; location.hash = h; };
  SH.setQuery = (patch) => { const { path, query } = SH.parseHash(); SH.go(path, Object.assign({}, query, patch)); };

  let routing = 0;
  SH.route = async () => {
    if (!SH.state.token) { renderLogin(); return; }
    const my = ++routing;
    if (!SH.state.user) {
      try { SH.state.user = await SH.api('GET', '/auth/me'); } catch (e) { if (!SH.state.token) return; renderLogin(e.message); return; }
      if (SH.state.user.user_type !== 'staff') { await SH.logout(); renderLogin('This login is for office staff.'); return; }
    }
    if (!document.getElementById('page')) await drawShell();
    const { path, query } = SH.parseHash();
    let match = null; let params = [];
    for (const p of SH.pages) { const m = p.re.exec(path); if (m) { match = p; params = m.slice(1); break; } }
    if (SH.view && SH.view.destroy) SH.view.destroy();
    if (SH.modal) SH.closeModal();
    drawNav();
    const side = document.querySelector('.side'); if (side) side.classList.remove('open');
    const root = document.getElementById('page');
    if (!match) { root.innerHTML = '<div class="card empty">Page not found. <a href="#/dashboard">Go to the dashboard</a></div>'; SH.view = null; return; }
    root.style.opacity = '.55';
    try {
      const v = await match.render(params, query);
      if (my !== routing) return;
      SH.view = v || {};
      root.innerHTML = SH.view.html || '';
      root.style.opacity = '';
      if (SH.view.init) await SH.view.init(root);
      window.scrollTo(0, 0);
      const after = SH.afterRoute; SH.afterRoute = null; if (after) after();
    } catch (e) {
      if (my !== routing) return;
      root.style.opacity = '';
      root.innerHTML = `<div class="card"><h2>Could not load this page</h2><div class="muted">${esc(e.message)}</div><div><button class="btn" onclick="SH.route()">Try again</button></div></div>`;
      SH.view = null;
    }
    SH.refreshCounts();
  };
  // Re-render the current page in place (after an action).
  SH.reload = () => SH.route();

  // Shared page fragments
  SH.head = (title, sub, tools, crumb) => `<div class="head"><div>${crumb ? `<div class="crumb">${crumb}</div>` : ''}<h1>${esc(title)}</h1>${sub ? `<div class="sub">${sub}</div>` : ''}</div><div class="tools">${tools || ''}</div></div>`;
  SH.pager = (r) => {
    if (!r || !r.last_page || r.last_page <= 1) return r && r.total !== undefined ? `<div class="pager"><span>${r.total} total</span><span></span></div>` : '';
    return `<div class="pager"><span>${r.from || 0}–${r.to || 0} of ${r.total}</span><span class="row">
      <button class="btn sm" ${r.current_page <= 1 ? 'disabled' : ''} data-act="page" data-page="${r.current_page - 1}">← Previous</button>
      <button class="btn sm" ${r.current_page >= r.last_page ? 'disabled' : ''} data-act="page" data-page="${r.current_page + 1}">Next →</button></span></div>`;
  };
  G.page = (el) => SH.setQuery({ page: el.dataset.page });
  SH.chips = (key, current, options) => `<div class="chips">${options.map(([v, l]) => `<a class="chip ${String(current || '') === String(v) ? 'on' : ''}" href="#${SH.parseHash().path}${SH.qs(Object.assign({}, SH.parseHash().query, { [key]: v, page: '' }))}">${esc(l)}</a>`).join('')}</div>`;
  SH.searchBox = (value, placeholder) => `<form data-submit="search" class="row"><input class="in search" name="q" value="${esc(value || '')}" placeholder="${esc(placeholder || 'Search')}"><button class="btn" type="submit">Search</button></form>`;
  G.search = (f) => SH.setQuery({ q: SH.formData(f).q, page: '' });
  SH.opt = (v, l, cur) => `<option value="${esc(v)}" ${String(v) === String(cur === undefined || cur === null ? '' : cur) ? 'selected' : ''}>${esc(l)}</option>`;
  SH.states = [['01', 'Jammu & Kashmir'], ['02', 'Himachal Pradesh'], ['03', 'Punjab'], ['04', 'Chandigarh'], ['05', 'Uttarakhand'], ['06', 'Haryana'], ['07', 'Delhi'], ['08', 'Rajasthan'], ['09', 'Uttar Pradesh'], ['10', 'Bihar'], ['11', 'Sikkim'], ['12', 'Arunachal Pradesh'], ['13', 'Nagaland'], ['14', 'Manipur'], ['15', 'Mizoram'], ['16', 'Tripura'], ['17', 'Meghalaya'], ['18', 'Assam'], ['19', 'West Bengal'], ['20', 'Jharkhand'], ['21', 'Odisha'], ['22', 'Chhattisgarh'], ['23', 'Madhya Pradesh'], ['24', 'Gujarat'], ['26', 'Dadra & Nagar Haveli and Daman & Diu'], ['27', 'Maharashtra'], ['29', 'Karnataka'], ['30', 'Goa'], ['31', 'Lakshadweep'], ['32', 'Kerala'], ['33', 'Tamil Nadu'], ['34', 'Puducherry'], ['35', 'Andaman & Nicobar'], ['36', 'Telangana'], ['37', 'Andhra Pradesh'], ['38', 'Ladakh']];
  SH.stateSelect = (name, cur) => `<select class="in" name="${name}" required>${SH.states.map(([c, n]) => SH.opt(c, n + ' (' + c + ')', cur || '09')).join('')}</select>`;
  SH.stateName = (code) => (SH.states.find((s) => s[0] === code) || [code, ''])[1];
  SH.addressFields = (prefix, a) => {
    a = a || {}; const p = prefix ? prefix + '.' : '';
    return `<label class="f">Label<input class="in" name="${p}label" value="${esc(a.label || 'Home')}" maxlength="30"></label>
      <label class="f full">House / street<input class="in" name="${p}line1" value="${esc(a.line1 || '')}" required maxlength="255"></label>
      <label class="f">Area / line 2<input class="in" name="${p}line2" value="${esc(a.line2 || '')}"></label>
      <label class="f">Landmark<input class="in" name="${p}landmark" value="${esc(a.landmark || '')}"></label>
      <label class="f">City<input class="in" name="${p}city" value="${esc(a.city || '')}" required></label>
      <label class="f">State${SH.stateSelect(p + 'state_code', a.state_code)}</label>
      <label class="f">PIN code<input class="in" name="${p}pincode" value="${esc(a.pincode || '')}" required pattern="\\d{6}" inputmode="numeric"></label>`;
  };
  SH.fixState = (o) => { if (o && o.state_code) o.state = SH.stateName(o.state_code); return o; };
  SH.addrText = (a) => a ? [a.line1, a.line2, a.landmark, a.city, a.pincode].filter(Boolean).join(', ') : '—';
  SH.mapLink = (a) => a && a.lat ? `https://www.google.com/maps/search/?api=1&query=${a.lat},${a.lng}` : (a ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(SH.addrText(a)) : '#');

  window.addEventListener('hashchange', () => SH.route());
})();

/* SHWAC admin: message texts, services & prices, staff & roles, branches, settings, running costs. */
(function () {
  'use strict';
  const { esc, money, d, dt, pill, page, head, pager, chips, searchBox, opt } = SH;
  const LABELS = { booking_confirmed: 'Booking confirmation', technician_assigned: 'Technician details', technician_on_the_way: 'On the way', complaint_registered: 'Complaint registered', invoice_issued: 'Invoice & payment link', payment_received: 'Payment receipt', payment_reminder: 'Payment reminder', otp_login: 'Login code', sla_breach: 'SLA breach (staff)' };
  const SAMPLE = { customer_name: 'Sunita Sharma', company_name: 'Shree Hargangeshwar Water Care', invoice_number: 'SHW/2627/00041', amount: '₹884.00', due_date: '31 Oct 2026', link: 'https://yourdomain/i/k3v9q2m8…', technician_name: 'Ravi Kumar', technician_phone: '9000000005', scheduled_date: '28 Oct 2026', time_slot: '11:00-13:00', job_number: 'JOB-000238', booking_number: 'BK-000127', service_name: 'RO General Service', complaint_number: 'CMP-000044', subject: 'Leakage', expected_by: '28 Oct, 01:30 PM', receipt_number: 'RCT/HQ/2627/00032', otp: '482913' };
  const fill = (body, data) => (body || '').replace(/\{(\w+)\}/g, (m, k) => (data[k] !== undefined ? data[k] : m));

  // =============================================================== MESSAGE TEXTS
  page('/templates', async (p, q) => {
    const all = (await SH.get('/admin/notification-templates')).filter((t) => t.channel === 'whatsapp');
    const cur = all.find((t) => t.event === q.event) || all[0];
    return {
      html: `${head('Message texts', 'Edit what customers receive on WhatsApp. {placeholders} are filled in automatically. Use *bold* for WhatsApp bold.')}
        ${all.length ? `<div class="grid two" style="grid-template-columns:minmax(0,1fr) minmax(0,2fr)">
          <section class="card" style="padding:8px;gap:2px">${all.map((t) => `<a class="chip ${t === cur ? 'on' : ''}" style="border-radius:10px;justify-content:space-between" href="#/templates?event=${esc(t.event)}">${esc(LABELS[t.event] || SH.label(t.event))}${t.is_active ? '' : ' <span class="pill">Off</span>'}</a>`).join('')}</section>
          <form class="card" data-submit="save"><h2>${esc(LABELS[cur.event] || SH.label(cur.event))}</h2>
            <label class="f">Message text<textarea class="in" name="body" rows="7" data-input="preview" maxlength="1024">${esc(cur.body || '')}</textarea></label>
            <div class="row small"><b class="muted">Insert:</b>${(cur.variables || []).concat(['company_name']).filter((v, i, a) => a.indexOf(v) === i).map((v) => `<button type="button" class="chip mono" data-act="ins" data-v="${esc(v)}">{${esc(v)}}</button>`).join('')}</div>
            <label class="check"><input type="checkbox" name="is_active" ${cur.is_active ? 'checked' : ''}> Prepare this message automatically</label>
            <div class="small muted">Preview with sample data</div><div style="background:#E7F3EE;border-radius:14px;padding:12px"><div class="bubble" style="background:#fff;max-width:440px" data-prev>${esc(fill(cur.body, SAMPLE))}</div></div>
            <div><button class="btn primary" type="submit">Save text</button></div></form></div>` : '<div class="card empty">No templates found. Run the database seeder.</div>'}`,
      actions: {
        preview: (el) => { document.querySelector('[data-prev]').textContent = fill(el.value, SAMPLE); },
        ins: (el) => { const ta = document.querySelector('[name=body]'); const pos = ta.selectionStart || ta.value.length; ta.value = ta.value.slice(0, pos) + '{' + el.dataset.v + '}' + ta.value.slice(pos); ta.focus(); document.querySelector('[data-prev]').textContent = fill(ta.value, SAMPLE); },
        save: async (f) => { const b = SH.formData(f); await SH.post('/admin/notification-templates', { event: cur.event, channel: 'whatsapp', body: b.body, preview: b.body, is_active: b.is_active }); SH.toast('Saved. New messages use this text from now on.'); },
      },
    };
  });

  // =============================================================== CATALOG
  const FIELD_TYPES = ['select', 'multiselect', 'text', 'textarea', 'number', 'date', 'boolean', 'phone'];
  page('/catalog', async () => {
    const [cats, svcs] = await Promise.all([SH.get('/admin/catalog/categories'), SH.get('/admin/catalog/services')]);
    return {
      html: `${head('Services & prices', 'What customers can book, the price before GST, and the questions asked on the booking form.', '<button class="btn" data-act="newCat">+ Category</button><button class="btn primary" data-act="newSvc">+ Service</button>')}
        ${cats.map((c) => `<section class="card"><div class="cardhead"><h2>${esc(c.name)} ${c.is_active === false ? '<span class="pill">Hidden</span>' : ''}</h2><button class="btn sm" data-act="editCat" data-id="${c.id}">Edit category</button></div>
          <div class="tablebox" style="border-radius:12px"><table class="t"><thead><tr><th>Code</th><th>Service</th><th class="right">Price</th><th>GST</th><th>SAC</th><th>Time</th><th>Form questions</th><th>Status</th><th></th></tr></thead><tbody>
          ${svcs.filter((s) => s.service_category_id === c.id).map((s) => `<tr><td class="mono">${esc(s.code)}</td><td><b>${esc(s.name)}</b></td><td class="right">${money(s.base_price)}</td><td>${Number(s.tax_rate)}%</td><td class="mono">${esc(s.sac_code)}</td><td>${s.duration_minutes} min</td><td class="small">${(s.form_schema || []).map((f) => esc(f.label)).join(', ') || '—'}</td><td>${pill(s.is_active ? 'active' : 'inactive')}</td><td><button class="btn sm" data-act="editSvc" data-id="${s.id}">Edit</button></td></tr>`).join('') || '<tr><td colspan="9" class="empty">No services.</td></tr>'}
          </tbody></table></div></section>`).join('')}`,
      actions: {
        newCat: () => catModal({}), editCat: (el) => catModal(cats.find((c) => String(c.id) === el.dataset.id)),
        newSvc: () => svcModal({ form_schema: [] }), editSvc: (el) => svcModal(svcs.find((c) => String(c.id) === el.dataset.id)),
      },
    };
    function catModal(c) {
      SH.openModal(`<h3>${c.id ? 'Edit category' : 'New category'}</h3><form data-submit="save" class="form"><label class="f">Name<input class="in" name="name" value="${esc(c.name || '')}" required></label><label class="f">Order<input class="in" type="number" name="sort_order" data-num value="${esc(c.sort_order || 0)}"></label>
        ${c.id ? `<label class="check full"><input type="checkbox" name="is_active" ${c.is_active !== false ? 'checked' : ''}> Shown to customers</label>` : ''}<div class="actions full"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">Save</button></div></form>`, {
        save: async (f) => { const b = SH.formData(f); if (c.id) await SH.patch('/admin/catalog/categories/' + c.id, b); else await SH.post('/admin/catalog/categories', b); SH.closeModal(); SH.toast('Saved.'); SH.reload(); },
      });
    }
    function svcModal(s) {
      let fields = JSON.parse(JSON.stringify(s.form_schema || []));
      const drawFields = () => {
        const box = document.querySelector('[data-schema]'); if (!box) return;
        box.innerHTML = fields.map((f, i) => `<div class="card" style="padding:10px;gap:8px;background:#F8FBFA"><div class="row" style="flex-wrap:wrap">
          <input class="in" style="flex:2;min-width:160px" placeholder="Question" value="${esc(f.label || '')}" data-input="fset" data-i="${i}" data-k="label">
          <select class="in" style="width:140px" data-change="fset" data-i="${i}" data-k="type">${FIELD_TYPES.map((t) => opt(t, SH.label(t), f.type)).join('')}</select>
          <label class="check"><input type="checkbox" ${f.required ? 'checked' : ''} data-change="fset" data-i="${i}" data-k="required"> Required</label>
          <button type="button" class="btn sm ghost" data-act="fdel" data-i="${i}">Remove</button></div>
          ${['select', 'multiselect'].includes(f.type) ? `<input class="in" placeholder="Choices, separated by commas" value="${esc((f.options || []).join(', '))}" data-input="fset" data-i="${i}" data-k="options">` : ''}</div>`).join('') || '<div class="muted small">No questions. Customers just pick a date and time.</div>';
      };
      SH.openModal(`<h3>${s.id ? 'Edit service' : 'New service'}</h3><form data-submit="save" style="display:flex;flex-direction:column;gap:12px"><div class="form">
        <label class="f">Category<select class="in" name="service_category_id">${cats.map((c) => opt(c.id, c.name, s.service_category_id)).join('')}</select></label>
        <label class="f">Name<input class="in" name="name" value="${esc(s.name || '')}" required></label><label class="f">Code<input class="in" name="code" value="${esc(s.code || '')}" required pattern="[A-Za-z0-9_\\-]+"></label>
        <label class="f">Price before GST<input class="in" type="number" step="0.01" min="0" name="base_price" data-num value="${esc(s.base_price || '')}" required></label>
        <label class="f">GST %<select class="in" name="tax_rate">${[0, 5, 12, 18, 28].map((r) => opt(r, r + '%', s.tax_rate !== undefined ? Number(s.tax_rate) : 18)).join('')}</select></label>
        <label class="f">SAC code<input class="in" name="sac_code" value="${esc(s.sac_code || '998719')}" pattern="\\d{4,8}"></label><label class="f">Time needed (min)<input class="in" type="number" min="5" name="duration_minutes" data-num value="${esc(s.duration_minutes || 60)}"></label>
        <label class="f full">Description<textarea class="in" name="description">${esc(s.description || '')}</textarea></label>
        <label class="check full"><input type="checkbox" name="is_active" ${s.id ? (s.is_active ? 'checked' : '') : 'checked'}> Customers can book it</label></div>
        <div class="row between"><b>Booking form questions</b><button type="button" class="btn sm" data-act="fadd">+ Question</button></div><div data-schema style="display:flex;flex-direction:column;gap:8px"></div>
        <div class="actions"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">Save service</button></div></form>`, {
        fadd: () => { fields.push({ key: '', label: '', type: 'select', required: false, options: [] }); drawFields(); },
        fdel: (el) => { fields.splice(Number(el.dataset.i), 1); drawFields(); },
        fset: (el) => { const f = fields[Number(el.dataset.i)]; const k = el.dataset.k; if (k === 'required') f.required = el.checked; else if (k === 'options') f.options = el.value.split(',').map((x) => x.trim()).filter(Boolean); else { f[k] = el.value; if (k === 'type') drawFields(); } },
        save: async (f) => {
          const b = SH.formData(f); b.tax_rate = Number(b.tax_rate); b.service_category_id = Number(b.service_category_id);
          b.form_schema = fields.filter((x) => x.label).map((x) => { const o = { key: x.key || x.label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 40), label: x.label, type: x.type, required: !!x.required }; if (['select', 'multiselect'].includes(x.type)) o.options = x.options || []; return o; });
          if (s.id) await SH.patch('/admin/catalog/services/' + s.id, b); else await SH.post('/admin/catalog/services', b); SH.closeModal(); SH.toast('Service saved.'); SH.reload();
        },
      }, { wide: true });
      drawFields();
    }
  });

  // =============================================================== USERS & ROLES
  page('/users', async (p, q) => {
    const tab = q.tab || 'users';
    const [r, roles] = await Promise.all([SH.get('/admin/users', { page: q.page, q: q.q, user_type: q.type }), SH.get('/admin/roles')]);
    if (!SH.state.branches && SH.allBranches()) SH.state.branches = await SH.get('/admin/branches');
    const branches = SH.state.branches || (SH.state.user.branch ? [SH.state.user.branch] : []);
    const assignable = roles.roles.filter((x) => !['customer', 'technician'].includes(x.slug));
    const isSA = (SH.state.user.roles || []).includes('super_admin') || SH.can('settings.manage');
    const usersHtml = `<div class="row between">${chips('type', q.type || '', [['', 'Everyone'], ['staff', 'Office staff'], ['technician', 'Technicians']])}${searchBox(q.q, 'Name, phone or email')}</div>
      <div class="tablebox"><table class="t"><thead><tr><th>Name</th><th>Login</th><th>Type</th><th>Roles</th><th>Branch</th><th>Last login</th><th>Status</th><th></th></tr></thead><tbody>
      ${r.data.map((u) => `<tr><td><b>${esc(u.name)}</b></td><td>${esc(u.email || '')}<div class="sub">${esc(u.phone || '')}</div></td><td>${esc(SH.label(u.user_type))}</td><td>${(u.roles || []).map((x) => esc(x.name)).join(', ')}</td><td>${esc(u.branch ? u.branch.name : 'All')}</td><td>${dt(u.last_login_at)}</td><td>${pill(u.is_active ? 'active' : 'inactive')}</td><td><button class="btn sm" data-act="edit" data-id="${u.id}">Edit</button></td></tr>`).join('') || '<tr><td colspan="8" class="empty">No users.</td></tr>'}
      </tbody></table>${pager(r)}</div>`;
    const groups = {}; roles.permissions.forEach((x) => { (groups[x.group] = groups[x.group] || []).push(x); });
    const editableRoles = roles.roles.filter((x) => !['super_admin', 'customer'].includes(x.slug));
    const rolesHtml = `<div class="note">Super Admin always has every permission. Changes apply on the user’s next request.</div>
      <form data-submit="saveRoles"><div class="tablebox"><table class="t matrix"><thead><tr><th>Permission</th>${editableRoles.map((x) => `<th>${esc(x.name)}</th>`).join('')}</tr></thead><tbody>
      ${Object.entries(groups).map(([g, list]) => `<tr><td colspan="${editableRoles.length + 1}" style="background:#F8FBFA"><b>${esc(g)}</b></td></tr>${list.map((perm) => `<tr><td>${esc(perm.name)}<div class="sub mono">${esc(perm.slug)}</div></td>${editableRoles.map((x) => `<td><input type="checkbox" name="r${x.id}" value="${esc(perm.slug)}" data-multi="1" ${x.permissions.some((pp) => pp.slug === perm.slug) ? 'checked' : ''} ${isSA ? '' : 'disabled'} aria-label="${esc(x.name + ': ' + perm.name)}"></td>`).join('')}</tr>`).join('')}`).join('')}
      </tbody></table></div>${isSA ? '<div class="right" style="margin-top:12px"><button class="btn primary" type="submit">Save permissions</button></div>' : ''}</form>`;
    return {
      html: `${head('Staff & roles', 'Office logins (admin panel) and technician logins (Android app).', '<button class="btn primary" data-act="new">+ New login</button>')}
        ${chips('tab', tab, [['users', 'People'], ['roles', 'Role permissions']])}${tab === 'roles' ? rolesHtml : usersHtml}`,
      actions: {
        new: () => userModal({ user_type: 'staff', is_active: true, roles: [] }),
        edit: (el) => userModal(r.data.find((u) => String(u.id) === el.dataset.id)),
        saveRoles: async (f) => {
          for (const x of editableRoles) {
            const perms = Array.from(f.querySelectorAll(`[name="r${x.id}"]:checked`)).map((c) => c.value);
            const before = x.permissions.map((pp) => pp.slug).sort().join(',');
            if (perms.slice().sort().join(',') !== before) await SH.put('/admin/roles/' + x.id, { permissions: perms });
          }
          SH.toast('Permissions saved.'); SH.reload();
        },
      },
    };
    function userModal(u) {
      const has = (slug) => (u.roles || []).some((x) => (x.slug || x) === slug);
      SH.openModal(`<h3>${u.id ? 'Edit ' + esc(u.name) : 'New login'}</h3><form data-submit="save" class="form">
        <label class="f">Name<input class="in" name="name" value="${esc(u.name || '')}" required></label>
        ${u.id ? '' : `<label class="f">Type<select class="in" name="user_type">${opt('staff', 'Office staff (admin panel)', u.user_type)}${opt('technician', 'Technician (Android app)', u.user_type)}</select></label>`}
        <label class="f">Email<input class="in" type="email" name="email" value="${esc(u.email || '')}"></label><label class="f">Mobile<input class="in" name="phone" value="${esc(u.phone || '')}" pattern="[6-9]\\d{9}" inputmode="numeric"></label>
        <label class="f">${u.id ? 'New password (leave blank to keep)' : 'Password'}<input class="in" type="password" name="password" minlength="8" ${u.id ? '' : 'required'} autocomplete="new-password" placeholder="8+ characters, letters and numbers"></label>
        ${SH.allBranches() ? `<label class="f">Branch<select class="in" name="branch_id"><option value="">All branches</option>${branches.map((b) => opt(b.id, b.name, u.branch_id)).join('')}</select></label>` : ''}
        <div class="full"><div class="small muted" style="font-weight:700;margin-bottom:6px">Roles (office staff)</div><div class="chips">${assignable.map((x) => `<label class="chip check"><input type="checkbox" name="roles" value="${esc(x.slug)}" data-multi="1" ${has(x.slug) ? 'checked' : ''}> ${esc(x.name)}</label>`).join('')}</div><div class="small muted">Technicians always get the Technician role.</div></div>
        ${u.id ? `<label class="check full"><input type="checkbox" name="is_active" ${u.is_active ? 'checked' : ''}> Can log in (untick to block and sign out everywhere)</label>` : ''}
        <div class="actions full"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">Save</button></div></form>`, {
        save: async (f) => {
          const b = SH.formData(f); b.roles = b.roles || []; if (!b.password) delete b.password;['email', 'phone'].forEach((k) => { if (!b[k]) b[k] = null; });
          if (b.branch_id !== undefined) b.branch_id = b.branch_id ? Number(b.branch_id) : null;
          if (u.id) await SH.patch('/admin/users/' + u.id, b); else await SH.post('/admin/users', b);
          SH.closeModal(); SH.toast('Saved.'); SH.reload();
        },
      });
    }
  });

  // =============================================================== BRANCHES
  page('/branches', async () => {
    const list = await SH.get('/admin/branches');
    return {
      html: `${head('Branches', 'Each branch has its own GSTIN, state (decides CGST+SGST or IGST) and invoice numbering.', '<button class="btn primary" data-act="new">+ New branch</button>')}
        <div class="tablebox"><table class="t"><thead><tr><th>Code</th><th>Name</th><th>State</th><th>GSTIN</th><th>Invoice prefix</th><th>Phone</th><th>Status</th><th></th></tr></thead><tbody>
        ${list.map((b) => `<tr><td class="mono">${esc(b.code)}</td><td><b>${esc(b.name)}</b><div class="sub">${esc([b.address, b.city, b.pincode].filter(Boolean).join(', '))}</div></td><td>${esc(b.state)} (${esc(b.state_code)})</td><td class="mono">${esc(b.gstin || '—')}</td><td class="mono">${esc(b.invoice_prefix || '')}</td><td>${esc(b.phone || '')}</td><td>${pill(b.is_active === false ? 'inactive' : 'active')}</td><td><button class="btn sm" data-act="edit" data-id="${b.id}">Edit</button></td></tr>`).join('')}
        </tbody></table></div>`,
      actions: { new: () => modal({}), edit: (el) => modal(list.find((b) => String(b.id) === el.dataset.id)) },
    };
    function modal(b) {
      SH.openModal(`<h3>${b.id ? 'Edit branch' : 'New branch'}</h3><form data-submit="save" class="form">
        <label class="f">Name<input class="in" name="name" value="${esc(b.name || '')}" required></label><label class="f">Code<input class="in" name="code" value="${esc(b.code || '')}" required maxlength="10" pattern="[A-Za-z0-9]+"></label>
        <label class="f">State${SH.stateSelect('state_code', b.state_code)}</label><label class="f">GSTIN<input class="in" name="gstin" value="${esc(b.gstin || '')}" maxlength="15" style="text-transform:uppercase"></label>
        <label class="f">Invoice prefix<input class="in" name="invoice_prefix" value="${esc(b.invoice_prefix || 'SHW')}" maxlength="6" pattern="[A-Za-z0-9]+"></label><label class="f">Phone<input class="in" name="phone" value="${esc(b.phone || '')}"></label>
        <label class="f">Email<input class="in" type="email" name="email" value="${esc(b.email || '')}"></label><label class="f full">Address<input class="in" name="address" value="${esc(b.address || '')}"></label>
        <label class="f">City<input class="in" name="city" value="${esc(b.city || '')}"></label><label class="f">PIN code<input class="in" name="pincode" value="${esc(b.pincode || '')}" pattern="\\d{6}"></label>
        ${b.id ? `<label class="check full"><input type="checkbox" name="is_active" ${b.is_active !== false ? 'checked' : ''}> Active</label>` : ''}
        <div class="actions full"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">Save</button></div></form>`, {
        save: async (f) => { const x = SH.fixState(SH.clean(SH.formData(f))); if (x.gstin) x.gstin = x.gstin.toUpperCase(); if (b.id) await SH.patch('/admin/branches/' + b.id, x); else await SH.post('/admin/branches', x); SH.state.branches = null; SH.closeModal(); SH.toast('Branch saved.'); SH.reload(); },
      });
    }
  });

  // =============================================================== SETTINGS
  page('/settings', async (p, q) => {
    const tab = q.tab || 'company';
    const s = await SH.get('/admin/settings');
    const st = s.settings; const c = st.company || {}; const br = st.branding || {}; const tx = st.tax || {}; const cp = st.complaints || {}; const bk = st.booking || {};
    let body = '';
    if (tab === 'company') body = `<form class="card" data-submit="save" data-key="company"><div class="form">
      ${[['name', 'Business name'], ['legal_name', 'Legal name'], ['gstin', 'GSTIN'], ['pan', 'PAN'], ['phone', 'Phone'], ['email', 'Email'], ['website', 'Website']].map(([k, l]) => `<label class="f">${l}<input class="in" name="${k}" value="${esc(c[k] || '')}"></label>`).join('')}
      <label class="f full">Address<textarea class="in" name="address">${esc(c.address || '')}</textarea></label></div><div><button class="btn primary" type="submit">Save</button></div></form>`;
    if (tab === 'branding') body = `<form class="card" data-submit="save" data-key="branding"><div class="form">
      <label class="f">Logo URL<input class="in" name="logo_url" value="${esc(br.logo_url || '')}" placeholder="https://…/logo.png"></label><label class="f">Brand colour<input class="in" type="color" name="primary_color" value="${esc(br.primary_color || '#0b6e7a')}"></label>
      <label class="f full">Terms printed on invoices<textarea class="in" name="invoice_terms" rows="4">${esc(br.invoice_terms || '')}</textarea></label><label class="f full">Invoice footer<input class="in" name="invoice_footer" value="${esc(br.invoice_footer || '')}"></label></div><div><button class="btn primary" type="submit">Save</button></div></form>`;
    if (tab === 'tax') body = `<form class="card" data-submit="save" data-key="tax"><div class="form">
      <label class="f">Default GST %<select class="in" name="default_rate">${[0, 5, 12, 18, 28].map((r) => opt(r, r + '%', tx.default_rate)).join('')}</select></label><label class="f">Default SAC for services<input class="in" name="default_sac" value="${esc(tx.default_sac || '998719')}"></label>
      <label class="check full"><input type="checkbox" name="prices_include_tax" ${tx.prices_include_tax ? 'checked' : ''}> Catalog prices include GST</label></div><div><button class="btn primary" type="submit">Save</button></div></form>`;
    if (tab === 'ops') body = `<form class="card" data-submit="save" data-key="ops"><h2>Complaints</h2><div class="form">
      <label class="check full"><input type="checkbox" name="complaints.auto_assign" ${cp.auto_assign ? 'checked' : ''}> Auto-assign new complaints to the technician with the fewest open jobs</label>
      <label class="f full">Complaint categories (comma separated)<input class="in" name="complaints.categories" value="${esc((cp.categories || []).join(', '))}"></label></div>
      <h2>Bookings</h2><div class="form"><label class="f full">Time slots (comma separated)<input class="in" name="booking.time_slots" value="${esc((bk.time_slots || []).join(', '))}"></label>
      <label class="f">Earliest booking (days from today)<input class="in" type="number" min="0" name="booking.lead_days" data-num value="${esc(bk.lead_days || 0)}"></label><label class="f">Max bookings per slot (blank = no limit)<input class="in" type="number" min="1" name="booking.max_per_slot" data-num value="${esc(bk.max_per_slot || '')}"></label></div>
      <div><button class="btn primary" type="submit">Save</button></div></form>`;
    if (tab === 'sla') body = `<form class="card" data-submit="sla"><div class="muted">How fast the office must respond and fix a complaint, by priority. Breaches show in red and staff get a WhatsApp alert.</div>
      <div class="tablebox" style="border-radius:12px"><table class="t" style="min-width:520px"><thead><tr><th>Priority</th><th>Respond within (minutes)</th><th>Resolve within (minutes)</th></tr></thead><tbody>
      ${s.sla_policies.map((x, i) => `<tr><td>${pill(x.priority)}<input type="hidden" name="p${i}" value="${esc(x.priority)}"></td><td><input class="in" type="number" min="5" name="r${i}" value="${x.response_minutes}"></td><td><input class="in" type="number" min="15" name="s${i}" value="${x.resolution_minutes}"></td></tr>`).join('')}
      </tbody></table></div><div><button class="btn primary" type="submit">Save SLA</button></div></form>`;
    if (tab === 'log') {
      const r = await SH.get('/admin/notification-logs', { page: q.page });
      body = `<div class="tablebox"><table class="t"><thead><tr><th>When</th><th>Channel</th><th>Message</th><th>To</th><th>Status</th></tr></thead><tbody>
        ${r.data.map((l) => `<tr><td>${dt(l.created_at)}</td><td>${esc(SH.label(l.channel))}</td><td>${esc(LABELS[l.event] || SH.label(l.event))}<div class="sub">${esc((l.message || '').split('\n')[0].slice(0, 90))}</div></td><td class="mono">${esc(l.to)}</td><td>${pill(l.status)}${l.error ? `<div class="sub">${esc(l.error)}</div>` : ''}</td></tr>`).join('') || '<tr><td colspan="5" class="empty">No messages yet.</td></tr>'}
        </tbody></table>${pager(r)}</div>`;
    }
    return {
      html: `${head('Settings', 'Business details, invoices, complaint rules and the message log.')}
        ${chips('tab', tab, [['company', 'Business'], ['branding', 'Invoice look'], ['tax', 'GST'], ['ops', 'Complaints & bookings'], ['sla', 'SLA'], ['log', 'Message log']])}${body}`,
      actions: {
        save: async (f) => {
          const b = SH.formData(f); const key = f.dataset.key; let payload;
          if (key === 'ops') {
            const list = (v) => (v || '').split(',').map((x) => x.trim()).filter(Boolean);
            payload = { complaints: { auto_assign: b.complaints.auto_assign, categories: list(b.complaints.categories) }, booking: { time_slots: list(b.booking.time_slots), lead_days: b.booking.lead_days || 0, max_per_slot: b.booking.max_per_slot || null } };
          } else { if (key === 'tax') b.default_rate = Number(b.default_rate); payload = { [key]: b }; }
          await SH.patch('/admin/settings', payload); SH.toast('Settings saved.');
        },
        sla: async (f) => {
          const b = SH.formData(f); const policies = s.sla_policies.map((x, i) => ({ priority: b['p' + i], response_minutes: Number(b['r' + i]), resolution_minutes: Number(b['s' + i]) }));
          await SH.put('/admin/settings/sla', { policies }); SH.toast('SLA saved. New complaints use it.');
        },
      },
    };
  });

  // =============================================================== RUNNING COSTS
  page('/costs', async () => {
    const gw = SH.cfg.paymentGateway; const wa = SH.cfg.whatsappMode;
    const rows = [
      ['Server & domain', 'Your Linux server (PHP, MySQL, cron) + free Let’s Encrypt SSL', 'You pay', 'Hosting + domain only', false],
      ['Login codes', 'SMS through your DLT-registered SMS provider (customer app login)', 'You pay', '≈ ₹0.15–0.25 per code', false],
      ['WhatsApp', wa === 'cloud' ? 'Meta Cloud API: sent automatically' : 'Click-to-send links from your own WhatsApp (outbox)', wa === 'cloud' ? 'Paid' : 'Free', 'Automatic sending (Meta Cloud API) is a later option, paid per message', wa !== 'cloud'],
      ['Payments', gw === 'razorpay' ? 'Razorpay gateway + UPI' : 'UPI app / QR straight to your UPI ID, cash, reference check', gw === 'razorpay' ? 'Fee per payment' : 'Free', 'Cards via Razorpay (≈2% per payment) is a later option', gw !== 'razorpay'],
      ['Location & maps', 'Phone GPS, Google Maps links, OpenStreetMap map', 'Free', 'No API keys needed', true],
      ['Job alerts', 'Technician app checks for new jobs every minute', 'Free', 'Firebase push later (also free)', true],
      ['App install', 'APK download page at yourdomain/apps', 'Free', 'Play Store later ($25 one-time)', true],
    ];
    return {
      html: `${head('Running costs', 'Only your domain, server and SMS cost money. Paid automation stays switched off until you turn it on in the server .env file.')}
        <div class="tablebox"><table class="t"><tbody>${rows.map((r) => `<tr><td><b>${esc(r[0])}</b></td><td>${esc(r[1])}</td><td><span class="pill ${r[4] ? 'ok' : 'warn'}">${esc(r[2])}</span><div class="sub">${esc(r[3])}</div></td></tr>`).join('')}</tbody></table></div>
        <div class="card"><h2>Current switches</h2><dl class="kv"><dt>WHATSAPP_MODE</dt><dd class="mono">${esc(wa)}</dd><dt>PAYMENT_GATEWAY</dt><dd class="mono">${esc(gw)}</dd></dl></div>`,
    };
  });
})();

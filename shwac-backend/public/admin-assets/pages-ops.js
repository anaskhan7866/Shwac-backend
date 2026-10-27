/* SHWAC admin: operations pages (dashboard, to-do, complaints, bookings, jobs, customers, live map, outbox). */
(function () {
  'use strict';
  const { esc, money, d, dt, pill, page, head, pager, chips, searchBox, opt } = SH;

  const CATEGORIES = ['no_water', 'leakage', 'taste_odour', 'low_flow', 'noise', 'electrical', 'filter_change', 'general'];
  const SLOTS = ['09:00-11:00', '11:00-13:00', '14:00-16:00', '16:00-18:00'];
  SH.techs = async () => SH.get('/admin/technicians', SH.withBranch({}));
  SH.slaPill = (c) => {
    if (['resolved', 'closed'].includes(c.status)) return pill(c.status);
    const m = c.sla_minutes_left;
    if (c.sla_breached || (m !== null && m !== undefined && m < 0)) return `<span class="pill bad">Breached${m !== null && m !== undefined ? ' ' + SH.mins(m) + ' ago' : ''}</span>`;
    if (m === null || m === undefined) return '<span class="pill">—</span>';
    return `<span class="pill ${m < 120 ? 'warn' : 'ok'}">${SH.mins(m)} left</span>`;
  };
  const techOptions = (techs, cur, branchId) => `<option value="">Choose technician</option>${techs.filter((t) => !branchId || String(t.branch_id) === String(branchId)).map((t) => opt(t.id, `${t.name} (${t.active_jobs} active job${t.active_jobs === 1 ? '' : 's'})`, cur)).join('')}`;

  // =============================================================== DASHBOARD
  page('/dashboard', async () => {
    const [dash, sla, live] = await Promise.all([
      SH.get('/admin/dashboard', SH.withBranch({})),
      SH.can('complaints.view') ? SH.get('/admin/complaints', SH.withBranch({ open: 1, per_page: 6 })) : null,
      SH.can('jobs.view') ? SH.get('/admin/jobs', SH.withBranch({ live: 1, per_page: 6 })) : null,
    ]);
    const f = dash.finance;
    const byMethod = f ? Object.entries(f.collections_by_method || {}) : [];
    const maxM = Math.max(1, ...byMethod.map(([, v]) => Number(v)));
    const k = (href, l, v, s, cls) => `<a class="kpi" href="${href}"><span class="l">${l}</span><span class="v" ${cls ? `style="color:${cls}"` : ''}>${v}</span><span class="s">${s}</span></a>`;
    return {
      html: `${head('Dashboard', 'As of ' + dt(dash.as_of), SH.can('bookings.manage') ? '<a class="btn primary" href="#/bookings/new">+ New booking</a>' : '')}
        <div class="grid">
          ${k('#/bookings', 'Bookings today', dash.bookings.today, `${dash.bookings.scheduled_today} scheduled for today · ${dash.bookings.pending} pending`)}
          ${k('#/jobs?live=1', 'Live jobs', dash.jobs.live, `${dash.jobs.unassigned} waiting for a technician · ${dash.jobs.completed_today} done today`)}
          ${k('#/complaints', 'Open complaints', dash.complaints.open, `<b style="color:var(--bad)">${dash.complaints.sla_breached} past SLA</b> · ${dash.complaints.due_in_2h} due within 2h`)}
          ${f ? k('#/payments', 'Collected today', money(f.collected_today), 'Receivables ' + money(f.receivables)) : k('#/attendance', 'Technicians on duty', dash.field.on_duty + ' of ' + dash.field.technicians, 'checked in today')}
        </div>
        <div class="grid">
          ${k('#/outbox', 'WhatsApp messages to send', dash.todo.whatsapp_to_send, 'Open the outbox →', 'var(--wa)')}
          ${k('#/verify', 'UPI payments to verify', dash.todo.payments_to_verify, 'Check against your bank app →', 'var(--warn)')}
          ${k('#/inventory?tab=alerts', 'Low-stock alerts', dash.inventory.low_stock_alerts, 'Store and vans')}
          ${k('#/expenses', 'Expenses waiting', dash.field.expenses_pending, dash.field.on_duty + ' of ' + dash.field.technicians + ' technicians on duty')}
        </div>
        <div class="grid wide">
          ${sla ? `<section class="card"><div class="cardhead"><h2>SLA watch</h2><a href="#/complaints">Complaint hub →</a></div><div class="list">
            ${sla.data.length ? sla.data.map((c) => `<a class="row between" style="text-decoration:none;color:inherit" href="#/complaints/${c.id}"><span class="grow"><span class="mono muted">${esc(c.number)}</span> <b>${esc(c.customer && c.customer.name)}</b><div class="small muted">${esc(c.subject)} · ${esc(c.assignee ? c.assignee.name : 'Unassigned')}</div></span>${SH.slaPill(c)}</a>`).join('') : '<div class="empty">No open complaints.</div>'}</div></section>` : ''}
          ${live ? `<section class="card"><div class="cardhead"><h2>Live jobs</h2><a href="#/jobs">Job board →</a></div><div class="list">
            ${live.data.length ? live.data.map((j) => `<a class="row between" style="text-decoration:none;color:inherit" href="#/jobs/${j.id}"><span class="grow"><span class="mono muted">${esc(j.number)}</span> <b>${esc(j.customer && j.customer.name)}</b><div class="small muted">${esc(j.service ? j.service.name : SH.label(j.type))} · ${esc(j.technician ? j.technician.name : 'Unassigned')}</div></span>${pill(j.status)}</a>`).join('') : '<div class="empty">No jobs in progress.</div>'}</div></section>` : ''}
        </div>
        ${f ? `<div class="grid wide">
          <section class="card"><h2>Collections this month, by method</h2>
            ${byMethod.length ? byMethod.map(([m, v]) => `<div style="display:grid;grid-template-columns:120px 1fr 110px;gap:12px;align-items:center"><span>${esc(SH.label(m))}</span><div class="bar"><i style="width:${Math.round(Number(v) / maxM * 100)}%"></i></div><b class="right">${money(v)}</b></div>`).join('') : '<div class="muted">No payments yet this month.</div>'}
          </section>
          <section class="card"><h2>Finance this month</h2><div class="grid">
            <div class="kpi"><span class="l">Invoiced</span><span class="v" style="font-size:20px">${money(f.invoiced_month)}</span></div>
            <div class="kpi"><span class="l">Collected</span><span class="v" style="font-size:20px">${money(f.collected_month)}</span></div>
            <div class="kpi"><span class="l">Expenses approved</span><span class="v" style="font-size:20px">${money(f.expenses_month)}</span></div>
            <div class="kpi" style="background:var(--bad-soft)"><span class="l" style="color:var(--bad)">Overdue</span><span class="v" style="font-size:20px;color:#8A1C12">${money(f.overdue)}</span></div>
          </div></section></div>` : ''}`,
    };
  });

  // =============================================================== TO-DO
  page('/todo', async () => {
    const [dash, overdue] = await Promise.all([
      SH.get('/admin/dashboard', SH.withBranch({})),
      SH.can('invoices.view') ? SH.get('/admin/invoices', SH.withBranch({ outstanding: 1, per_page: 100 })) : { data: [] },
    ]);
    const today = SH.today();
    const late = overdue.data.filter((i) => i.due_date && SH.isoDay(i.due_date) < today);
    return {
      html: `${head('Today’s to-do', 'What needs a tap from the office. Nothing here costs money per message or per payment.')}
        <div class="grid">
          <a class="kpi" href="#/outbox"><span class="l">WhatsApp messages to send</span><span class="v" style="color:var(--wa)">${dash.todo.whatsapp_to_send}</span><span class="s" style="color:var(--accent);font-weight:700">Open outbox →</span></a>
          <a class="kpi" href="#/verify"><span class="l">UPI payments to verify</span><span class="v" style="color:var(--warn)">${dash.todo.payments_to_verify}</span><span class="s" style="color:var(--accent);font-weight:700">Check against bank app →</span></a>
          <a class="kpi" href="#/jobs?unassigned=1"><span class="l">Jobs without a technician</span><span class="v">${dash.jobs.unassigned}</span><span class="s">Assign them →</span></a>
          <a class="kpi" href="#/complaints?filter=breached"><span class="l">Complaints past SLA</span><span class="v" style="color:var(--bad)">${dash.complaints.sla_breached}</span><span class="s">Open complaint hub →</span></a>
        </div>
        <section class="card"><h2>Overdue invoices</h2><div class="list">
          ${late.length ? late.map((i) => `<div class="row between"><span class="grow"><b>${esc(i.customer && i.customer.name)}</b> · <a class="mono" href="#/invoices/${i.id}">${esc(i.number)}</a><div class="small" style="color:var(--bad)">${money(i.balance_due)} due since ${d(i.due_date)}</div></span>
            ${SH.can('payments.manage') ? `<button class="btn sm" style="color:var(--wa);border-color:var(--wa)" data-act="remind" data-id="${i.id}">Prepare WhatsApp reminder</button>` : ''}</div>`).join('') : '<div class="empty">No overdue invoices.</div>'}
        </div></section>`,
      actions: {
        remind: async (el) => { const r = await SH.post('/admin/invoices/' + el.dataset.id + '/reminder'); if (r.skipped) SH.toast('Not prepared: ' + (r.reason || 'customer has no WhatsApp number'), true); else SH.share(r, 'Send the payment reminder?'); },
      },
    };
  });

  // =============================================================== COMPLAINTS
  const complaintForm = (customer) => `<form data-submit="create" class="form">
      <div class="full">${SH.customerPicker('customer_id', 'Customer', customer)}</div>
      <label class="f full">What is the problem?<input class="in" name="subject" required maxlength="160" placeholder="e.g. No water from RO"></label>
      <label class="f">Category<select class="in" name="category">${CATEGORIES.map((c) => opt(c, SH.label(c), 'general')).join('')}</select></label>
      <label class="f">Priority<select class="in" name="priority">${['normal', 'high', 'critical', 'low'].map((p) => opt(p, SH.label(p))).join('')}</select></label>
      <label class="f">Received via<select class="in" name="channel">${['phone', 'whatsapp', 'walk_in', 'email', 'admin'].map((p) => opt(p, SH.label(p))).join('')}</select></label>
      <label class="f full">Details<textarea class="in" name="description" maxlength="5000"></textarea></label>
      <label class="check full"><input type="checkbox" name="auto_assign" checked> Auto-assign the technician with the fewest open jobs</label>
      <div class="actions full"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">Register complaint</button></div></form>`;
  SH.newComplaint = (customer) => SH.openModal('<h3>New complaint</h3>' + complaintForm(customer), {
    create: async (f) => {
      const b = SH.clean(SH.formData(f)); if (!b.customer_id) { SH.toast('Choose the customer first.', true); return; }
      const c = await SH.post('/admin/complaints', b); SH.closeModal(); SH.toast(c.number + ' registered.');
      SH.goThen('/complaints/' + c.id, () => SH.shareFrom(c, 'Tell the customer the complaint is registered?'));
    },
  });

  page('/complaints', async (p, q) => {
    const filter = q.filter || 'open';
    const params = SH.withBranch({ page: q.page, q: q.q, per_page: 25 });
    if (filter === 'open') params.open = 1;
    if (filter === 'breached') { params.open = 1; params.breached = 1; }
    if (filter === 'due') params.due_soon = 1;
    if (filter === 'unassigned') params.status = 'open';
    if (filter === 'resolved') params.status = 'resolved';
    const r = await SH.get('/admin/complaints', params);
    return {
      html: `${head('Complaints', 'Most urgent first. SLA timers come from the priority’s policy in Settings.', `${searchBox(q.q, 'Complaint no., subject or phone')}${SH.can('complaints.manage') ? '<button class="btn primary" data-act="new">+ New complaint</button>' : ''}`)}
        ${chips('filter', filter, [['open', 'All open'], ['breached', 'Past SLA'], ['due', 'Due within 2h'], ['unassigned', 'Not assigned'], ['resolved', 'Resolved'], ['all', 'Everything']])}
        <div class="tablebox"><table class="t"><thead><tr><th>Complaint</th><th>Customer</th><th>Problem</th><th>Priority</th><th>Technician</th><th>SLA</th><th>Status</th></tr></thead><tbody>
        ${r.data.map((c) => `<tr class="click" data-href="#/complaints/${c.id}"><td class="mono">${esc(c.number)}<div class="sub">${dt(c.created_at)}</div></td>
          <td><b>${esc(c.customer && c.customer.name)}</b><div class="sub">${esc(c.customer && c.customer.phone)}</div></td>
          <td>${esc(c.subject)}<div class="sub">${esc(SH.label(c.category))}</div></td><td>${pill(c.priority)}</td>
          <td>${c.assignee ? esc(c.assignee.name) : '<span class="muted">Unassigned</span>'}</td><td>${SH.slaPill(c)}</td><td>${pill(c.status)}</td></tr>`).join('') || '<tr><td colspan="7" class="empty">Nothing in this view.</td></tr>'}
        </tbody></table>${pager(r)}</div>`,
      actions: { new: () => SH.newComplaint() },
    };
  });
  page('/complaints/new', async (p, q) => { setTimeout(() => SH.newComplaint(), 0); return { html: head('New complaint') }; });

  page('/complaints/:id', async ([id]) => {
    const [c, techs] = await Promise.all([SH.get('/admin/complaints/' + id), SH.can('complaints.manage') ? SH.techs() : []]);
    const manage = SH.can('complaints.manage');
    const nextStatuses = { open: ['in_progress', 'resolved', 'closed'], assigned: ['in_progress', 'resolved', 'closed'], in_progress: ['resolved', 'closed'], reopened: ['in_progress', 'resolved', 'closed'], resolved: ['closed', 'reopened'], closed: ['reopened'] }[c.status] || [];
    return {
      html: `${head(c.subject, `${pill(c.status)} ${pill(c.priority)} ${SH.slaPill(c)}`, manage ? `<button class="btn" data-act="wa">WhatsApp customer</button>` : '', `<a href="#/complaints">Complaints</a> / <span class="mono">${esc(c.number)}</span>`)}
        <div class="grid two">
          <div style="display:flex;flex-direction:column;gap:14px">
            <section class="card"><h2>Details</h2><dl class="kv">
              <dt>Customer</dt><dd><a href="#/customers/${c.customer.id}">${esc(c.customer.name)}</a> · <a href="tel:+91${esc(c.customer.phone)}">${esc(c.customer.phone)}</a></dd>
              <dt>Address</dt><dd>${esc(SH.addrText(c.address))} ${c.address ? `· <a href="${SH.mapLink(c.address)}" target="_blank" rel="noopener">Map</a>` : ''}</dd>
              <dt>Appliance</dt><dd>${c.customer_product ? esc(c.customer_product.product_name + ' ' + (c.customer_product.brand || '')) : '—'}</dd>
              <dt>Category</dt><dd>${esc(SH.label(c.category))}</dd><dt>Received via</dt><dd>${esc(SH.label(c.channel))} · ${dt(c.created_at)}</dd>
              <dt>Respond by</dt><dd>${dt(c.sla_response_due_at)} ${c.first_response_at ? '(responded ' + dt(c.first_response_at) + ')' : ''}</dd>
              <dt>Resolve by</dt><dd>${dt(c.sla_resolution_due_at)}</dd>
              ${c.description ? `<dt>Details</dt><dd style="white-space:pre-line">${esc(c.description)}</dd>` : ''}
              ${c.resolution_notes ? `<dt>Resolution</dt><dd>${esc(c.resolution_notes)}</dd>` : ''}
            </dl></section>
            <section class="card"><h2>Timeline</h2><div class="list">
              ${(c.updates || []).map((u) => `<div><div class="row between"><b>${esc(u.user ? u.user.name : 'System')}</b><span class="small muted">${dt(u.created_at)} ${u.is_internal ? '<span class="pill">Internal</span>' : ''}</span></div><div style="white-space:pre-line">${esc(u.body)}</div></div>`).join('') || '<div class="muted">No updates yet.</div>'}
            </div>
            ${manage ? `<form data-submit="comment" class="form"><label class="f full">Add a note<textarea class="in" name="body" required maxlength="5000"></textarea></label>
              <label class="check"><input type="checkbox" name="is_internal"> Internal (customer does not see it)</label><div class="right"><button class="btn primary" type="submit">Add note</button></div></form>` : ''}
            </section>
          </div>
          <div style="display:flex;flex-direction:column;gap:14px">
            ${manage ? `<section class="card"><h2>Technician</h2><div>${c.assignee ? `<b>${esc(c.assignee.name)}</b> · ${esc(c.assignee.phone || '')}` : '<span class="muted">Not assigned yet</span>'}</div>
              <form data-submit="assign" class="form"><label class="f full">${c.assignee ? 'Re-assign to' : 'Assign to'}<select class="in" name="technician_id" required>${techOptions(techs, null, c.branch_id)}</select></label>
              <label class="f full">Note (optional)<input class="in" name="note" maxlength="255"></label><button class="btn primary full" type="submit">Assign</button></form></section>
            <section class="card"><h2>Status</h2>${nextStatuses.length ? `<form data-submit="status" class="form"><label class="f full">Change to<select class="in" name="status">${nextStatuses.map((s) => opt(s, SH.label(s))).join('')}</select></label>
              <label class="f full">Note<textarea class="in" name="note" maxlength="2000" placeholder="What was done (shown on resolution)"></textarea></label><button class="btn full" type="submit">Update status</button></form>` : '<div class="muted">No further changes.</div>'}</section>` : ''}
            <section class="card"><h2>Service jobs</h2><div class="list">${(c.jobs || []).map((j) => `<a class="row between" href="#/jobs/${j.id}" style="text-decoration:none;color:inherit"><span><span class="mono">${esc(j.number)}</span><div class="small muted">${esc(j.technician ? j.technician.name : 'Unassigned')}</div></span>${pill(j.status)}</a>`).join('') || '<div class="muted">No job yet. Assigning a technician creates one.</div>'}</div></section>
          </div>
        </div>`,
      actions: {
        assign: async (f) => { const r = await SH.post(`/admin/complaints/${id}/assign`, SH.clean(SH.formData(f))); SH.toast('Technician assigned.'); await SH.reload(); SH.shareFrom(r); },
        status: async (f) => { const r = await SH.post(`/admin/complaints/${id}/status`, SH.clean(SH.formData(f))); SH.toast('Status updated.'); await SH.reload(); SH.shareFrom(r); },
        comment: async (f) => { await SH.post(`/admin/complaints/${id}/comments`, SH.formData(f)); SH.toast('Note added.'); SH.reload(); },
        wa: () => SH.compose(c.customer),
      },
    };
  });

  // Free-form WhatsApp to a customer
  SH.compose = (customer, text) => SH.openModal(`<h3>WhatsApp ${esc(customer.name)}</h3><form data-submit="send" class="form">
      <label class="f full">Message<textarea class="in" name="message" required maxlength="2000">${esc(text || 'Namaste ' + customer.name + ', ')}</textarea></label>
      <div class="actions full"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn wa" type="submit">Prepare message</button></div></form>`, {
    send: async (f) => { const r = await SH.post('/admin/whatsapp/compose', { customer_id: customer.id, message: SH.formData(f).message }); SH.closeModal(); SH.share(r); },
  });

  // =============================================================== BOOKINGS
  page('/bookings', async (p, q) => {
    const r = await SH.get('/admin/bookings', SH.withBranch({ page: q.page, q: q.q, status: q.status, from: q.from, to: q.to }));
    return {
      html: `${head('Bookings', 'Service visits booked by customers (app / website) or by the office.', `${searchBox(q.q, 'Booking no., name or phone')}${SH.can('bookings.manage') ? '<a class="btn primary" href="#/bookings/new">+ New booking</a>' : ''}`)}
        ${chips('status', q.status || '', [['', 'All'], ['pending', 'Pending'], ['confirmed', 'Confirmed'], ['scheduled', 'Scheduled'], ['completed', 'Completed'], ['cancelled', 'Cancelled']])}
        <div class="tablebox"><table class="t"><thead><tr><th>Booking</th><th>Customer</th><th>Service</th><th>Visit</th><th>Source</th><th>Job</th><th>Status</th></tr></thead><tbody>
        ${r.data.map((b) => `<tr class="click" data-href="#/bookings/${b.id}"><td class="mono">${esc(b.number)}</td><td><b>${esc(b.customer && b.customer.name)}</b><div class="sub">${esc(b.customer && b.customer.phone)}</div></td>
          <td>${esc(b.service && b.service.name)}<div class="sub">${money(b.quoted_amount)}</div></td><td>${d(b.scheduled_date)}<div class="sub">${esc(b.time_slot || '')}</div></td><td>${esc(SH.label(b.source))}</td>
          <td>${(b.jobs || []).map((j) => `<span class="mono">${esc(j.number)}</span> ${pill(j.status)}`).join('<br>') || '—'}</td><td>${pill(b.status)}</td></tr>`).join('') || '<tr><td colspan="7" class="empty">No bookings.</td></tr>'}
        </tbody></table>${pager(r)}</div>`,
    };
  });

  const fieldHtml = (f) => {
    const n = 'form_data.' + f.key; const req = f.required ? 'required' : ''; const star = f.required ? ' *' : '';
    const lab = esc(f.label) + star + (f.help ? ` <span class="muted" style="font-weight:500">${esc(f.help)}</span>` : '');
    switch (f.type) {
      case 'select': return `<label class="f">${lab}<select class="in" name="${n}" ${req}><option value="">Choose</option>${(f.options || []).map((o) => opt(o, o)).join('')}</select></label>`;
      case 'multiselect': return `<div class="f full" style="display:flex;flex-direction:column;gap:6px;font-size:12px;font-weight:700;color:var(--muted)">${lab}<div class="chips">${(f.options || []).map((o) => `<label class="check chip"><input type="checkbox" name="${n}" value="${esc(o)}" data-multi="1"> ${esc(o)}</label>`).join('')}</div></div>`;
      case 'textarea': return `<label class="f full">${lab}<textarea class="in" name="${n}" ${req}></textarea></label>`;
      case 'number': return `<label class="f">${lab}<input class="in" type="number" name="${n}" data-num ${f.min !== null && f.min !== undefined ? 'min="' + f.min + '"' : ''} ${f.max !== null && f.max !== undefined ? 'max="' + f.max + '"' : ''} ${req}></label>`;
      case 'date': return `<label class="f">${lab}<input class="in" type="date" name="${n}" ${req}></label>`;
      case 'boolean': return `<label class="check">${'<input type="checkbox" name="' + n + '">'} ${esc(f.label)}</label>`;
      case 'phone': return `<label class="f">${lab}<input class="in" type="tel" name="${n}" pattern="[6-9]\\d{9}" ${req}></label>`;
      default: return `<label class="f">${lab}<input class="in" name="${n}" ${req}></label>`;
    }
  };

  page('/bookings/new', async (p, q) => {
    const svcs = (await SH.get('/admin/catalog/services')).filter((s) => s.is_active !== false);
    let pre = null; if (q.customer) { try { pre = await SH.get('/admin/customers/' + q.customer); } catch (e) { pre = null; } }
    const v = {
      html: `${head('New booking', 'Book a visit for a customer who called, messaged or walked in.', '', '<a href="#/bookings">Bookings</a> / New')}
        <form data-submit="save" class="card"><div class="form">
          <div class="full">${SH.customerPicker('customer_id', 'Customer', pre, 'customerPicked')}</div>
          <div class="full muted small">New customer? <a href="#/customers/new">Add the customer first</a>.</div>
          <label class="f">Visit address<select class="in" name="customer_address_id" data-addr><option value="">Default address</option></select></label>
          <label class="f">Appliance<select class="in" name="customer_product_id" data-prod><option value="">—</option></select></label>
          <label class="f full">Service<select class="in" name="service_id" required data-change="svc"><option value="">Choose service</option>${svcs.map((s) => opt(s.id, `${s.name} · ${money(s.base_price)} + GST`)).join('')}</select></label>
          <div class="full form" data-fields></div>
          <label class="f">Date<input class="in" type="date" name="scheduled_date" min="${SH.today()}" value="${SH.today()}" required></label>
          <label class="f">Time slot<select class="in" name="time_slot">${SLOTS.map((s) => opt(s, s)).join('')}</select></label>
          <label class="f">Booked via<select class="in" name="source">${['phone', 'whatsapp', 'walk_in', 'admin'].map((s) => opt(s, SH.label(s))).join('')}</select></label>
          <label class="f full">Notes<textarea class="in" name="notes" maxlength="2000"></textarea></label>
        </div><div class="row" style="justify-content:flex-end"><a class="btn" href="#/bookings">Cancel</a><button class="btn primary" type="submit">Confirm booking</button></div></form>`,
      init: async () => { if (pre) await v.actions.customerPicked(); },
      actions: {
        customerPicked: async () => {
          const id = document.querySelector('[name=customer_id]').value; if (!id) return;
          const c = await SH.get('/admin/customers/' + id);
          document.querySelector('[data-addr]').innerHTML = c.addresses.map((a) => opt(a.id, `${a.label || 'Address'}: ${SH.addrText(a)}`, (c.addresses.find((x) => x.is_default) || {}).id)).join('');
          document.querySelector('[data-prod]').innerHTML = '<option value="">—</option>' + (c.products || []).map((x) => opt(x.id, `${x.product_name} ${x.brand || ''} ${x.serial_no ? '· ' + x.serial_no : ''}`)).join('');
        },
        svc: (el) => { const s = svcs.find((x) => String(x.id) === el.value); document.querySelector('[data-fields]').innerHTML = s ? (s.form_schema || []).map(fieldHtml).join('') : ''; },
        save: async (f) => {
          const b = SH.formData(f); if (!b.customer_id) { SH.toast('Choose the customer first.', true); return; }
          ['customer_address_id', 'customer_product_id', 'notes'].forEach((k) => { if (b[k] === '') delete b[k]; });
          const r = await SH.post('/admin/bookings', b); SH.toast(r.number + ' booked.'); SH.goThen('/bookings/' + r.id, () => SH.shareFrom(r, 'Send the booking confirmation?'));
        },
      },
    };
    return v;
  });

  page('/bookings/:id', async ([id]) => {
    const b = await SH.get('/admin/bookings/' + id);
    const open = !['completed', 'cancelled'].includes(b.status); const manage = SH.can('bookings.manage');
    return {
      html: `${head(b.service ? b.service.name : 'Booking', pill(b.status), manage && open ? '<button class="btn" data-act="resched">Reschedule</button><button class="btn danger" data-act="cancel">Cancel booking</button>' : '', `<a href="#/bookings">Bookings</a> / <span class="mono">${esc(b.number)}</span>`)}
        <div class="grid two"><section class="card"><h2>Booking</h2><dl class="kv">
          <dt>Customer</dt><dd><a href="#/customers/${b.customer.id}">${esc(b.customer.name)}</a> · ${esc(b.customer.phone)}</dd>
          <dt>Address</dt><dd>${esc(SH.addrText(b.address))}</dd><dt>Visit</dt><dd>${d(b.scheduled_date)} · ${esc(b.time_slot || 'Any time')}</dd>
          <dt>Estimate</dt><dd>${money(b.quoted_amount)} + GST</dd><dt>Booked via</dt><dd>${esc(SH.label(b.source))} · ${dt(b.created_at)}</dd>
          ${Object.entries(b.form_data || {}).map(([k, v]) => `<dt>${esc(SH.label(k))}</dt><dd>${esc(Array.isArray(v) ? v.join(', ') : (v === true ? 'Yes' : v === false ? 'No' : v))}</dd>`).join('')}
          ${b.notes ? `<dt>Notes</dt><dd>${esc(b.notes)}</dd>` : ''}${b.cancel_reason ? `<dt>Cancelled</dt><dd>${esc(b.cancel_reason)}</dd>` : ''}
        </dl></section>
        <section class="card"><h2>Service jobs</h2><div class="list">${(b.jobs || []).map((j) => `<a class="row between" href="#/jobs/${j.id}" style="text-decoration:none;color:inherit"><span><span class="mono">${esc(j.number)}</span><div class="small muted">${esc(j.technician ? j.technician.name : 'Unassigned')}</div></span>${pill(j.status)}</a>`).join('') || '<div class="muted">No job.</div>'}</div></section></div>`,
      actions: {
        cancel: async () => { const r = await SH.confirm('Cancel this booking?', 'Open jobs for it are cancelled too.', 'Cancel booking', 'Reason'); if (!r) return; await SH.post(`/admin/bookings/${id}/cancel`, { reason: r }); SH.toast('Booking cancelled.'); SH.reload(); },
        resched: () => SH.openModal(`<h3>Reschedule</h3><form data-submit="save" class="form"><label class="f">Date<input class="in" type="date" name="scheduled_date" min="${SH.today()}" value="${SH.isoDay(b.scheduled_date)}" required></label>
          <label class="f">Time slot<select class="in" name="time_slot">${SLOTS.map((s) => opt(s, s, b.time_slot)).join('')}</select></label>
          <div class="actions full"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">Save</button></div></form>`, {
          save: async (f) => { await SH.patch('/admin/bookings/' + id, SH.formData(f)); SH.closeModal(); SH.toast('Rescheduled.'); SH.reload(); },
        }),
      },
    };
  });

  // =============================================================== JOBS
  page('/jobs', async (p, q) => {
    const status = q.status || (q.unassigned ? 'unassigned' : (q.live ? 'live' : ''));
    const params = SH.withBranch({ page: q.page, per_page: 30, date: q.date });
    if (status === 'live') params.live = 1; else if (status === 'unassigned') params.unassigned = 1; else if (status) params.status = status;
    const [r, techs] = await Promise.all([SH.get('/admin/jobs', params), SH.can('jobs.manage') ? SH.techs() : []]);
    return {
      html: `${head('Service jobs', 'Every visit a technician makes: from bookings, complaints and installations.', `<form data-submit="date" class="row"><input class="in" type="date" name="date" value="${esc(q.date || '')}"><button class="btn" type="submit">Filter day</button>${q.date ? '<button class="btn ghost" data-act="clearDate">Clear</button>' : ''}</form>`)}
        ${techs.length ? `<div class="chips">${techs.map((t) => `<span class="chip">${esc(t.name)} · ${t.active_jobs} active</span>`).join('')}</div>` : ''}
        ${chips('status', status, [['', 'All'], ['unassigned', 'Waiting for technician'], ['live', 'Live'], ['assigned', 'Assigned'], ['en_route', 'On the way'], ['in_progress', 'In progress'], ['completed', 'Completed'], ['cancelled', 'Cancelled']])}
        <div class="tablebox"><table class="t"><thead><tr><th>Job</th><th>Customer</th><th>Work</th><th>When</th><th>Technician</th><th>Status</th></tr></thead><tbody>
        ${r.data.map((j) => `<tr class="click" data-href="#/jobs/${j.id}"><td class="mono">${esc(j.number)}</td><td><b>${esc(j.customer && j.customer.name)}</b><div class="sub">${esc(j.address ? j.address.line1 + ', ' + j.address.city : '')}</div></td>
          <td>${esc(j.service ? j.service.name : SH.label(j.type))}</td><td>${d(j.scheduled_date)}<div class="sub">${esc(j.time_slot || '')}</div></td>
          <td>${j.technician ? esc(j.technician.name) : (SH.can('jobs.manage') && j.status === 'pending' ? `<select class="in" style="min-width:180px" data-change="quickAssign" data-id="${j.id}">${techOptions(techs, null, j.branch_id)}</select>` : '<span class="muted">—</span>')}</td><td>${pill(j.status)}</td></tr>`).join('') || '<tr><td colspan="6" class="empty">No jobs in this view.</td></tr>'}
        </tbody></table>${pager(r)}</div>`,
      actions: {
        date: (f) => SH.setQuery({ date: SH.formData(f).date, page: '' }),
        clearDate: () => SH.setQuery({ date: '' }),
        quickAssign: async (el) => { if (!el.value) return; const r = await SH.post(`/admin/jobs/${el.dataset.id}/assign`, { technician_id: el.value }); SH.toast(r.number + ' assigned.'); await SH.reload(); SH.shareFrom(r, 'Send the technician details?'); },
      },
    };
  });

  page('/jobs/:id', async ([id]) => {
    const [j, techs] = await Promise.all([SH.get('/admin/jobs/' + id), SH.can('jobs.manage') ? SH.techs() : []]);
    const manage = SH.can('jobs.manage');
    const nexts = { pending: ['cancelled'], assigned: ['accepted', 'en_route', 'in_progress', 'cancelled'], accepted: ['en_route', 'in_progress', 'cancelled'], en_route: ['in_progress', 'cancelled'], in_progress: ['completed', 'cancelled'] }[j.status] || [];
    return {
      html: `${head(j.service ? j.service.name : SH.label(j.type) + ' visit', pill(j.status), j.invoice ? `<a class="btn" href="#/invoices/${j.invoice.id}">Invoice ${esc(j.invoice.number || 'draft')}</a>` : (SH.can('invoices.manage') && j.status !== 'cancelled' ? `<a class="btn" href="#/invoices/new?customer=${j.customer_id}&job=${j.id}">Make invoice</a>` : ''), `<a href="#/jobs">Service jobs</a> / <span class="mono">${esc(j.number)}</span>`)}
        <div class="grid two"><div style="display:flex;flex-direction:column;gap:14px">
          <section class="card"><h2>Visit</h2><dl class="kv">
            <dt>Customer</dt><dd><a href="#/customers/${j.customer.id}">${esc(j.customer.name)}</a> · <a href="tel:+91${esc(j.customer.phone)}">${esc(j.customer.phone)}</a></dd>
            <dt>Address</dt><dd>${esc(SH.addrText(j.address))} · <a href="${SH.mapLink(j.address)}" target="_blank" rel="noopener">Open in Google Maps</a></dd>
            <dt>When</dt><dd>${d(j.scheduled_date)} ${esc(j.time_slot || '')}</dd>
            ${j.booking ? `<dt>Booking</dt><dd><a href="#/bookings/${j.booking.id}">${esc(j.booking.number)}</a></dd>` : ''}
            ${j.complaint ? `<dt>Complaint</dt><dd><a href="#/complaints/${j.complaint.id}">${esc(j.complaint.number)}</a> · ${esc(j.complaint.subject)}</dd>` : ''}
            <dt>Started</dt><dd>${dt(j.started_at)}</dd><dt>Completed</dt><dd>${dt(j.completed_at)}</dd>
            ${j.work_summary ? `<dt>Work done</dt><dd>${esc(j.work_summary)}</dd>` : ''}
          </dl></section>
          <section class="card"><h2>Status history</h2><div class="list">${(j.status_logs || []).map((l) => `<div class="row between"><span>${pill(l.to_status)} <span class="muted">${esc(l.note || '')}</span>${l.lat ? ` · <a href="https://www.google.com/maps/search/?api=1&query=${l.lat},${l.lng}" target="_blank" rel="noopener">GPS</a>` : ''}</span><span class="small muted">${esc(l.user ? l.user.name : 'System')} · ${dt(l.created_at)}</span></div>`).join('')}</div></section>
        </div><div style="display:flex;flex-direction:column;gap:14px">
          <section class="card"><h2>Technician</h2><div>${j.technician ? `<b>${esc(j.technician.name)}</b> · <a href="tel:+91${esc(j.technician.phone)}">${esc(j.technician.phone)}</a>` : '<span class="muted">Not assigned</span>'}</div>
            ${manage && !['completed', 'cancelled'].includes(j.status) ? `<form data-submit="assign" class="form"><label class="f full">${j.technician ? 'Re-assign' : 'Assign'}<select class="in" name="technician_id" required>${techOptions(techs, null, j.branch_id)}</select></label><button class="btn primary full" type="submit">Assign</button></form>` : ''}</section>
          ${manage && nexts.length ? `<section class="card"><h2>Update status</h2><form data-submit="status" class="form"><label class="f full">Change to<select class="in" name="status">${nexts.map((s) => opt(s, SH.label(s))).join('')}</select></label><label class="f full">Note<input class="in" name="note" maxlength="1000"></label><button class="btn full" type="submit">Update</button></form><div class="small muted">Technicians normally move jobs from the app; use this to correct a job.</div></section>` : ''}
        </div></div>`,
      actions: {
        assign: async (f) => { const r = await SH.post(`/admin/jobs/${id}/assign`, SH.formData(f)); SH.toast('Assigned.'); await SH.reload(); SH.shareFrom(r, 'Send the technician details?'); },
        status: async (f) => { const r = await SH.post(`/admin/jobs/${id}/status`, SH.clean(SH.formData(f))); SH.toast('Status updated.'); await SH.reload(); SH.shareFrom(r); },
      },
    };
  });

  // =============================================================== CUSTOMERS
  page('/customers', async (p, q) => {
    const r = await SH.get('/admin/customers', SH.withBranch({ page: q.page, q: q.q }));
    return {
      html: `${head('Customers', '', `${searchBox(q.q, 'Name, phone or code')}${SH.can('customers.manage') ? '<a class="btn primary" href="#/customers/new">+ New customer</a>' : ''}`)}
        <div class="tablebox"><table class="t"><thead><tr><th>Code</th><th>Name</th><th>Phone</th><th>Address</th><th>Open complaints</th></tr></thead><tbody>
        ${r.data.map((c) => `<tr class="click" data-href="#/customers/${c.id}"><td class="mono">${esc(c.code)}</td><td><b>${esc(c.name)}</b>${c.gstin ? `<div class="sub">GSTIN ${esc(c.gstin)}</div>` : ''}</td><td>${esc(c.phone)}</td><td>${esc(SH.addrText(c.default_address))}</td><td>${c.open_complaints ? `<span class="pill warn">${c.open_complaints}</span>` : '—'}</td></tr>`).join('') || '<tr><td colspan="5" class="empty">No customers found.</td></tr>'}
        </tbody></table>${pager(r)}</div>`,
    };
  });

  const customerFields = (c) => `<label class="f">Name<input class="in" name="name" value="${esc(c.name || '')}" required maxlength="120"></label>
    <label class="f">Mobile<input class="in" name="phone" value="${esc(c.phone || '')}" required pattern="[6-9]\\d{9}" inputmode="numeric" placeholder="10 digits"></label>
    <label class="f">WhatsApp (if different)<input class="in" name="whatsapp" value="${esc(c.whatsapp && c.whatsapp !== c.phone ? c.whatsapp : '')}" pattern="[6-9]\\d{9}" inputmode="numeric"></label>
    <label class="f">Email<input class="in" type="email" name="email" value="${esc(c.email || '')}"></label>
    <label class="f">GSTIN (business customers)<input class="in" name="gstin" value="${esc(c.gstin || '')}" maxlength="15" style="text-transform:uppercase"></label>
    ${SH.allBranches() ? `<label class="f">Branch<select class="in" name="branch_id" required><option value="">Choose branch</option>${(SH.state.branches || []).map((b) => opt(b.id, b.name, c.branch_id || SH.state.branch || (SH.state.user.branch && SH.state.user.branch.id))).join('')}</select></label>` : ''}
    <label class="f full">Notes<textarea class="in" name="notes">${esc(c.notes || '')}</textarea></label>`;

  page('/customers/new', async () => ({
    html: `${head('New customer', '', '', '<a href="#/customers">Customers</a> / New')}
      <form data-submit="save" class="card"><div class="form">${customerFields({})}<div class="full"><h2 style="margin:6px 0 0;font-size:15px">Address</h2></div>${SH.addressFields('address', {})}</div>
      <div class="row" style="justify-content:flex-end"><a class="btn" href="#/customers">Cancel</a><button class="btn primary" type="submit">Save customer</button></div></form>`,
    actions: {
      save: async (f) => {
        const b = SH.clean(SH.formData(f)); SH.fixState(b.address); if (b.gstin) b.gstin = b.gstin.toUpperCase(); if (b.branch_id) b.branch_id = Number(b.branch_id);
        const c = await SH.post('/admin/customers', b); SH.toast('Customer ' + c.code + ' saved.'); SH.go('/customers/' + c.id);
      },
    },
  }));

  page('/customers/:id', async ([id]) => {
    const c = await SH.get('/admin/customers/' + id);
    const m = SH.can('customers.manage');
    return {
      html: `${head(c.name, `<span class="mono">${esc(c.code)}</span> · ${esc(c.phone)} ${c.branch ? '· ' + esc(c.branch.name) : ''}`, `
          <button class="btn" data-act="wa" style="color:var(--wa);border-color:var(--wa)">WhatsApp</button>
          ${SH.can('bookings.manage') ? `<a class="btn" href="#/bookings/new?customer=${c.id}">New booking</a>` : ''}
          ${SH.can('complaints.manage') ? '<button class="btn" data-act="complaint">New complaint</button>' : ''}
          ${SH.can('invoices.manage') ? `<a class="btn" href="#/invoices/new?customer=${c.id}">New invoice</a>` : ''}
          ${m ? '<button class="btn primary" data-act="edit">Edit</button>' : ''}`, '<a href="#/customers">Customers</a>')}
        <div class="grid wide">
          <section class="card"><div class="cardhead"><h2>Addresses</h2>${m ? '<button class="btn sm" data-act="addAddr">+ Add</button>' : ''}</div><div class="list">
            ${c.addresses.map((a) => `<div class="row between"><span class="grow"><b>${esc(a.label || 'Address')}</b> ${a.is_default ? '<span class="pill teal">Default</span>' : ''}<div>${esc(SH.addrText(a))}, ${esc(a.state)}</div><div class="small muted">${a.lat ? 'Location saved' : 'Location is saved on the first visit'} · <a href="${SH.mapLink(a)}" target="_blank" rel="noopener">Map</a></div></span>${m ? `<button class="btn sm" data-act="editAddr" data-id="${a.id}">Edit</button>` : ''}</div>`).join('')}
          </div></section>
          <section class="card"><div class="cardhead"><h2>Appliances</h2>${m ? '<button class="btn sm" data-act="addProd">+ Add</button>' : ''}</div><div class="list">
            ${(c.products || []).map((x) => `<div class="row between"><span class="grow"><b>${esc(x.product_name)}</b> ${esc(x.brand || '')} ${esc(x.model || '')}<div class="small muted">${x.serial_no ? 'S/N ' + esc(x.serial_no) + ' · ' : ''}${x.warranty_until ? 'Warranty till ' + d(x.warranty_until) : 'No warranty date'}</div></span>${m ? `<button class="btn sm" data-act="editProd" data-id="${x.id}">Edit</button>` : ''}</div>`).join('') || '<div class="muted">No appliances recorded.</div>'}
          </div></section>
        </div>
        <div class="grid wide">
          <section class="card"><h2>Recent bookings</h2><div class="list">${(c.bookings || []).map((b) => `<a class="row between" href="#/bookings/${b.id}" style="text-decoration:none;color:inherit"><span><span class="mono">${esc(b.number)}</span> ${esc(b.service ? b.service.name : '')}<div class="small muted">${d(b.scheduled_date)}</div></span>${pill(b.status)}</a>`).join('') || '<div class="muted">None.</div>'}</div></section>
          <section class="card"><h2>Recent complaints</h2><div class="list">${(c.complaints || []).map((x) => `<a class="row between" href="#/complaints/${x.id}" style="text-decoration:none;color:inherit"><span><span class="mono">${esc(x.number)}</span> ${esc(x.subject)}<div class="small muted">${dt(x.created_at)}</div></span>${pill(x.status)}</a>`).join('') || '<div class="muted">None.</div>'}</div></section>
          <section class="card"><h2>Recent invoices</h2><div class="list">${(c.invoices || []).map((x) => `<a class="row between" href="#/invoices/${x.id}" style="text-decoration:none;color:inherit"><span><span class="mono">${esc(x.number || 'Draft')}</span><div class="small muted">${money(x.total)} · balance ${money(x.balance_due)}</div></span>${pill(x.status)}</a>`).join('') || '<div class="muted">None.</div>'}</div></section>
        </div>
        ${c.notes ? `<section class="card"><h2>Notes</h2><div style="white-space:pre-line">${esc(c.notes)}</div></section>` : ''}`,
      actions: {
        wa: () => SH.compose(c),
        complaint: () => SH.newComplaint(c),
        edit: () => SH.openModal(`<h3>Edit customer</h3><form data-submit="save" class="form">${customerFields(c)}<label class="check full"><input type="checkbox" name="whatsapp_opt_in" ${c.whatsapp_opt_in ? 'checked' : ''}> Customer agrees to WhatsApp messages</label>
            <div class="actions full"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">Save</button></div></form>`, {
          save: async (f) => { const b = SH.clean(SH.formData(f)); if (b.gstin) b.gstin = b.gstin.toUpperCase(); if (!b.whatsapp) b.whatsapp = b.phone; await SH.patch('/admin/customers/' + id, b); SH.closeModal(); SH.toast('Saved.'); SH.reload(); },
        }),
        addAddr: () => addrModal(null),
        editAddr: (el) => addrModal(c.addresses.find((a) => String(a.id) === el.dataset.id)),
        addProd: () => prodModal(null),
        editProd: (el) => prodModal(c.products.find((a) => String(a.id) === el.dataset.id)),
      },
    };
    function addrModal(a) {
      SH.openModal(`<h3>${a ? 'Edit address' : 'Add address'}</h3><form data-submit="save" class="form">${SH.addressFields('', a || {})}<label class="check full"><input type="checkbox" name="is_default" ${a && a.is_default ? 'checked' : ''}> Default address</label>
        <div class="actions full"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">Save</button></div></form>`, {
        save: async (f) => { const b = SH.fixState(SH.clean(SH.formData(f))); if (a) await SH.patch(`/admin/customers/${id}/addresses/${a.id}`, b); else await SH.post(`/admin/customers/${id}/addresses`, b); SH.closeModal(); SH.toast('Address saved.'); SH.reload(); },
      });
    }
    function prodModal(x) {
      x = x || {};
      SH.openModal(`<h3>${x.id ? 'Edit appliance' : 'Add appliance'}</h3><form data-submit="save" class="form">
        <label class="f">Appliance<input class="in" name="product_name" value="${esc(x.product_name || '')}" required placeholder="e.g. RO purifier"></label>
        <label class="f">Brand<input class="in" name="brand" value="${esc(x.brand || '')}"></label><label class="f">Model<input class="in" name="model" value="${esc(x.model || '')}"></label>
        <label class="f">Serial no.<input class="in" name="serial_no" value="${esc(x.serial_no || '')}"></label>
        <label class="f">Purchase date<input class="in" type="date" name="purchase_date" value="${esc(SH.isoDay(x.purchase_date))}"></label>
        <label class="f">Installed on<input class="in" type="date" name="installed_on" value="${esc(SH.isoDay(x.installed_on))}"></label>
        <label class="f">Warranty until<input class="in" type="date" name="warranty_until" value="${esc(SH.isoDay(x.warranty_until))}"></label>
        <div class="actions full"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">Save</button></div></form>`, {
        save: async (f) => { const b = SH.clean(SH.formData(f)); if (x.id) await SH.patch(`/admin/customers/${id}/products/${x.id}`, b); else await SH.post(`/admin/customers/${id}/products`, b); SH.closeModal(); SH.toast('Appliance saved.'); SH.reload(); },
      });
    }
  });

  // =============================================================== WHATSAPP OUTBOX
  page('/outbox', async (p, q) => {
    const status = q.status || 'ready';
    const r = await SH.get('/admin/whatsapp/outbox', { status, page: q.page, per_page: 30 });
    return {
      html: `${head('WhatsApp outbox', 'Messages the system prepared. Tap WhatsApp: your WhatsApp (app or WhatsApp Web) opens with the text filled in. Press Send there.')}
        ${chips('status', status, [['ready', 'To send'], ['sent', 'Sent'], ['all', 'All']])}
        ${r.data.map((m) => `<div class="card" style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:start">
          <div style="display:flex;flex-direction:column;gap:6px;min-width:0"><div class="row"><b>${esc(m.label)}</b><span class="muted">to +${esc(m.to)}</span><span class="mono muted">${esc(m.subject || '')}</span></div>
            <div class="bubble" style="max-width:620px">${esc(m.message)}</div>
            <div class="small muted">Prepared ${dt(m.created_at)}${m.created_by ? ' · ' + esc(m.created_by) : ''}${m.sent ? ' · sent ' + dt(m.sent_at) + (m.sent_by ? ' by ' + esc(m.sent_by) : '') : ''}</div></div>
          <div style="display:flex;flex-direction:column;gap:8px;min-width:150px">${m.sent ? '<span class="pill ok" style="text-align:center">Sent</span>' : `<a class="btn wa" href="${esc(m.url)}" target="_blank" rel="noopener" data-act="wa" data-id="${m.id}">WhatsApp</a><button class="btn sm" data-act="wa" data-id="${m.id}">Mark as sent</button>`}</div></div>`).join('') || '<div class="card empty" style="color:var(--ok);font-weight:700">All caught up. Nothing waiting to be sent.</div>'}
        ${r.last_page > 1 ? `<div class="tablebox">${pager(r)}</div>` : ''}`,
      reload: () => SH.reload(),
    };
  });

  // =============================================================== LIVE MAP
  page('/map', async () => {
    let map = null; let layer = null; let timer = null; let selected = null; let rows = [];
    const v = {
      html: `${head('Live technician map', 'Positions update while a technician is checked in with the app open. Location is never collected before check-in or after check-out.', '<button class="btn" data-act="refresh">Refresh</button>')}
        <div class="grid two"><section class="card" style="padding:10px"><div id="map"></div><div class="small muted">Map © OpenStreetMap contributors. Free, no API key.</div></section>
        <section style="display:flex;flex-direction:column;gap:10px" data-list></section></div>`,
      init: async () => {
        if (!window.L) { document.getElementById('map').innerHTML = '<div class="empty">The map library did not load. Check the internet connection.</div>'; }
        else {
          map = window.L.map('map').setView([28.98, 77.70], 12);
          window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
          layer = window.L.layerGroup().addTo(map);
        }
        await load(true);
        timer = setInterval(() => load(false), 60000);
      },
      destroy: () => { clearInterval(timer); if (map) map.remove(); },
      actions: { refresh: () => load(false), pick: (el) => { selected = Number(el.dataset.id); draw(); trail(); } },
    };
    async function load(fit) { rows = await SH.get('/admin/field/live', SH.withBranch({})); draw(fit); if (selected) trail(); }
    function draw(fit) {
      const list = document.querySelector('[data-list]'); if (!list) return;
      list.innerHTML = rows.map((t) => `<button class="card" data-act="pick" data-id="${t.id}" style="text-align:left;border:2px solid ${selected === t.id ? 'var(--accent)' : 'var(--line)'}">
        <span class="row between"><b style="font-size:15px">${esc(t.name)}</b>${pill(t.duty)}</span>
        <span class="muted">${t.current_job ? `${SH.label(t.current_job.status)} · ${esc(t.current_job.number)} ${esc(t.current_job.customer || '')}` : (t.active_jobs ? t.active_jobs + ' active job(s)' : 'No active job')}</span>
        <span class="small muted">${t.last_seen_at ? 'Seen ' + dt(t.last_seen_at) + (t.stale ? ' (old)' : '') : 'No location yet'}${t.checked_in_at ? ' · In ' + SH.t(t.checked_in_at) : ''}${t.checked_out_at ? ' · Out ' + SH.t(t.checked_out_at) : ''}</span></button>`).join('') || '<div class="card empty">No active technicians.</div>';
      if (!layer) return;
      layer.clearLayers(); const pts = [];
      rows.forEach((t) => {
        if (t.lat && t.duty === 'on_duty') {
          const initials = t.name.split(' ').map((w) => w[0]).join('');
          const icon = window.L.divIcon({ className: '', html: `<span class="tech-pin ${selected === t.id ? 'sel' : (t.current_job ? '' : 'free')}">${esc(initials)}</span>`, iconSize: null });
          window.L.marker([t.lat, t.lng], { icon }).addTo(layer).bindPopup(`<b>${esc(t.name)}</b><br>${t.current_job ? esc(t.current_job.number + ' ' + (t.current_job.customer || '')) : 'Free'}<br>Seen ${dt(t.last_seen_at)}`);
          pts.push([t.lat, t.lng]);
        }
        const a = t.current_job && t.current_job.address;
        if (a && a.lat) { window.L.circleMarker([a.lat, a.lng], { radius: 7, color: '#9A4B00', weight: 3, fillColor: '#fff', fillOpacity: 1 }).addTo(layer).bindPopup(esc((t.current_job.customer || '') + ' · ' + a.line1)); pts.push([a.lat, a.lng]); }
      });
      if (fit && pts.length) map.fitBounds(pts, { padding: [40, 40], maxZoom: 14 });
    }
    async function trail() {
      if (!layer || !selected) return;
      const r = await SH.get('/admin/field/technicians/' + selected + '/trail', {});
      const pts = (r.points || []).map((p) => [p.lat, p.lng]);
      if (pts.length > 1) { window.L.polyline(pts, { color: '#0B6E7A', weight: 4, dashArray: '6 6' }).addTo(layer); map.fitBounds(pts, { padding: [40, 40], maxZoom: 15 }); }
      else SH.toast('No location trail for today yet.');
    }
    return v;
  });
})();

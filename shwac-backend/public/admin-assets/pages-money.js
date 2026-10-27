/* SHWAC admin: invoices, payments, UPI verification. */
(function () {
  'use strict';
  const { esc, money, d, dt, pill, page, head, pager, chips, searchBox, opt } = SH;
  const RATES = [0, 5, 12, 18, 28];
  const invStatus = (i) => (['issued', 'partially_paid'].includes(i.status) && i.due_date && SH.isoDay(i.due_date) < SH.today() ? 'overdue' : i.status);

  // =============================================================== INVOICE LIST
  page('/invoices', async (p, q) => {
    const st = q.status || '';
    const params = SH.withBranch({ page: q.page, q: q.q, from: q.from, to: q.to });
    if (st === 'outstanding') params.outstanding = 1; else if (st) params.status = st;
    const r = await SH.get('/admin/invoices', params);
    const sum = (k) => r.data.reduce((a, i) => a + (i.status === 'cancelled' || i.status === 'draft' ? 0 : Number(i[k] || 0)), 0);
    return {
      html: `${head('Invoices', 'GST invoices. Same state as the branch → CGST + SGST; another state → IGST. Numbers run per branch per financial year.', `${searchBox(q.q, 'Invoice no. or phone')}${SH.can('invoices.manage') ? '<a class="btn primary" href="#/invoices/new">+ New invoice</a>' : ''}`)}
        ${chips('status', st, [['', 'All'], ['outstanding', 'Unpaid'], ['draft', 'Drafts'], ['issued', 'Issued'], ['partially_paid', 'Part paid'], ['paid', 'Paid'], ['cancelled', 'Cancelled']])}
        <form data-submit="range" class="row"><label class="f">From<input class="in" type="date" name="from" value="${esc(q.from || '')}"></label><label class="f">To<input class="in" type="date" name="to" value="${esc(q.to || '')}"></label><button class="btn" type="submit" style="align-self:flex-end">Apply dates</button>
          <span class="muted" style="align-self:flex-end;margin-left:auto">This page: taxable ${money(sum('taxable_value'))} · CGST ${money(sum('cgst_total'))} · SGST ${money(sum('sgst_total'))} · IGST ${money(sum('igst_total'))} · balance ${money(sum('balance_due'))}</span></form>
        <div class="tablebox"><table class="t"><thead><tr><th>Invoice</th><th>Customer</th><th>Date</th><th>Tax</th><th class="right">Total</th><th class="right">Balance</th><th>Status</th></tr></thead><tbody>
        ${r.data.map((i) => `<tr class="click" data-href="#/invoices/${i.id}"><td class="mono">${esc(i.number || 'Draft #' + i.id)}</td><td><b>${esc(i.customer && i.customer.name)}</b><div class="sub">Place of supply ${esc(SH.stateName(i.place_of_supply))} (${esc(i.place_of_supply)})</div></td>
          <td>${d(i.invoice_date || i.created_at)}<div class="sub">${i.due_date ? 'Due ' + d(i.due_date) : ''}</div></td><td>${i.supply_type === 'inter' ? 'IGST' : 'CGST + SGST'}</td>
          <td class="right"><b>${money(i.total)}</b></td><td class="right">${money(i.balance_due)}</td><td>${pill(invStatus(i))}</td></tr>`).join('') || '<tr><td colspan="7" class="empty">No invoices.</td></tr>'}
        </tbody></table>${pager(r)}</div>`,
      actions: { range: (f) => { const b = SH.formData(f); SH.setQuery({ from: b.from, to: b.to, page: '' }); } },
    };
  });

  // =============================================================== INVOICE EDITOR (new / edit draft)
  async function editor(invoice, q) {
    const [svcs, prods, whs] = await Promise.all([
      SH.get('/admin/catalog/services'),
      SH.can('inventory.view') ? SH.get('/admin/products', { active: 1, per_page: 100 }).then((r) => r.data) : Promise.resolve([]),
      SH.can('inventory.view') ? SH.get('/admin/warehouses') : Promise.resolve([]),
    ]);
    let pre = null;
    const custId = invoice ? invoice.customer_id : q.customer;
    if (custId) { try { pre = await SH.get('/admin/customers/' + custId); } catch (e) { pre = null; } }
    let lines = invoice ? invoice.items.map((it) => ({ kind: it.item_type === 'service' ? 'service' : (it.item_type === 'part' && it.item_id ? 'part' : 'other'), id: it.item_id, description: it.description, hsn: it.hsn_sac, qty: Number(it.quantity), price: Number(it.unit_price), discount: Number(it.discount), rate: Number(it.tax_rate) }))
      : [{ kind: 'service', id: svcs[0] ? svcs[0].id : null, qty: 1, price: svcs[0] ? Number(svcs[0].base_price) : 0, discount: 0, rate: svcs[0] ? Number(svcs[0].tax_rate) : 18 }];
    const v = {
      html: `${head(invoice ? 'Edit draft invoice' : 'New invoice', 'Prices are before GST. The exact CGST/SGST or IGST split is worked out by the server from the customer’s state.', '', '<a href="#/invoices">Invoices</a>')}
        <form data-submit="save" class="card"><div class="form">
          <div class="full">${invoice ? `<div class="picked">${esc(pre ? pre.name + ' · ' + pre.phone : '')}</div><input type="hidden" name="customer_id" value="${invoice.customer_id}">` : SH.customerPicker('customer_id', 'Customer', pre, 'picked')}</div>
          ${invoice ? '' : `<label class="f">Billing address<select class="in" name="customer_address_id" data-addr><option value="">Default address</option></select></label>`}
          ${invoice ? '' : `<label class="f">Take parts from<select class="in" name="stock_warehouse_id"><option value="">Branch main store (automatic)</option>${whs.map((w) => opt(w.id, w.name)).join('')}</select></label>`}
          ${q && q.job ? `<input type="hidden" name="service_job_id" value="${esc(q.job)}"><div class="note full">For service job #${esc(q.job)}</div>` : ''}
        </div>
        <div class="tablebox" style="border-radius:12px"><table class="t" style="min-width:900px"><thead><tr><th style="width:120px">Type</th><th>Item</th><th style="width:80px">Qty</th><th style="width:120px">Price</th><th style="width:100px">Discount</th><th style="width:90px">GST %</th><th class="right" style="width:120px">Amount</th><th></th></tr></thead><tbody data-lines></tbody></table></div>
        <div class="row between"><button type="button" class="btn" data-act="addLine">+ Add line</button><div class="right" data-totals></div></div>
        <label class="f">Notes on invoice<textarea class="in" name="notes" maxlength="2000">${esc(invoice ? invoice.notes || '' : '')}</textarea></label>
        <div class="row" style="justify-content:flex-end"><a class="btn" href="${invoice ? '#/invoices/' + invoice.id : '#/invoices'}">Cancel</a>
          <button class="btn" type="submit" data-mode="draft">Save draft</button>${invoice ? '' : '<button class="btn primary" type="button" data-act="saveIssue">Save &amp; issue</button>'}</div></form>`,
      init: async () => { draw(); if (pre && !invoice) await v.actions.picked(); },
      actions: {
        picked: async () => {
          const id = document.querySelector('[name=customer_id]').value; if (!id) return;
          const c = await SH.get('/admin/customers/' + id); const sel = document.querySelector('[data-addr]'); if (!sel) return;
          sel.innerHTML = c.addresses.map((a) => opt(a.id, `${a.label || 'Address'}: ${SH.addrText(a)} (${a.state})`, (c.addresses.find((x) => x.is_default) || {}).id)).join('');
        },
        addLine: () => { lines.push({ kind: 'part', id: prods[0] ? prods[0].id : null, qty: 1, price: prods[0] ? Number(prods[0].sale_price) : 0, discount: 0, rate: prods[0] ? Number(prods[0].tax_rate) : 18 }); if (!prods.length) Object.assign(lines[lines.length - 1], { kind: 'other', description: '', rate: 18 }); draw(); },
        delLine: (el) => { lines.splice(Number(el.dataset.i), 1); draw(); },
        lineKind: (el) => { const l = lines[Number(el.dataset.i)]; l.kind = el.value; const src = l.kind === 'service' ? svcs[0] : (l.kind === 'part' ? prods[0] : null); l.id = src ? src.id : null; l.price = src ? Number(l.kind === 'service' ? src.base_price : src.sale_price) : 0; l.rate = src ? Number(src.tax_rate) : 18; l.description = ''; draw(); },
        lineItem: (el) => { const l = lines[Number(el.dataset.i)]; const list = l.kind === 'service' ? svcs : prods; const src = list.find((x) => String(x.id) === el.value); l.id = src.id; l.price = Number(l.kind === 'service' ? src.base_price : src.sale_price); l.rate = Number(src.tax_rate); draw(); },
        lineVal: (el) => { const l = lines[Number(el.dataset.i)]; const k = el.dataset.k; l[k] = ['description', 'hsn'].includes(k) ? el.value : Number(el.value || 0); totals(); },
        save: (f, ev) => submit(f, false),
        saveIssue: (el) => submit(el.closest('form'), true),
      },
    };
    function draw() {
      const tb = document.querySelector('[data-lines]'); if (!tb) return;
      tb.innerHTML = lines.map((l, i) => `<tr>
        <td><select class="in" data-change="lineKind" data-i="${i}">${opt('service', 'Service', l.kind)}${prods.length ? opt('part', 'Spare part', l.kind) : ''}${opt('other', 'Other', l.kind)}</select></td>
        <td>${l.kind === 'other' ? `<div class="row" style="flex-wrap:nowrap"><input class="in" placeholder="Description" value="${esc(l.description || '')}" data-input="lineVal" data-now data-k="description" data-i="${i}"><input class="in" style="width:110px" placeholder="HSN/SAC" value="${esc(l.hsn || '')}" data-input="lineVal" data-now data-k="hsn" data-i="${i}"></div>`
          : `<select class="in" data-change="lineItem" data-i="${i}">${(l.kind === 'service' ? svcs : prods).map((x) => opt(x.id, l.kind === 'service' ? x.name : `${x.name} (${x.sku})`, l.id)).join('')}</select>`}</td>
        <td><input class="in" type="number" min="0.01" step="0.01" value="${l.qty}" data-input="lineVal" data-now data-k="qty" data-i="${i}"></td>
        <td><input class="in" type="number" min="0" step="0.01" value="${l.price}" data-input="lineVal" data-now data-k="price" data-i="${i}"></td>
        <td><input class="in" type="number" min="0" step="0.01" value="${l.discount || 0}" data-input="lineVal" data-now data-k="discount" data-i="${i}"></td>
        <td>${l.kind === 'other' ? `<select class="in" data-change="lineVal" data-k="rate" data-i="${i}">${RATES.map((r) => opt(r, r + '%', l.rate)).join('')}</select>` : esc(l.rate) + '%'}</td>
        <td class="right" data-amt="${i}"></td><td>${lines.length > 1 ? `<button type="button" class="btn sm ghost" data-act="delLine" data-i="${i}" aria-label="Remove line">✕</button>` : ''}</td></tr>`).join('');
      totals();
    }
    function totals() {
      let taxable = 0; let gst = 0;
      lines.forEach((l, i) => { const t = Math.max(0, (l.qty || 0) * (l.price || 0) - (l.discount || 0)); const g = t * (l.rate || 0) / 100; taxable += t; gst += g; const c = document.querySelector(`[data-amt="${i}"]`); if (c) c.textContent = money(t); });
      const total = Math.round(taxable + gst);
      const box = document.querySelector('[data-totals]'); if (box) box.innerHTML = `<div class="muted">Taxable ${money(taxable)} · GST ≈ ${money(gst)}</div><div style="font-size:22px;font-weight:800">≈ ${money(total)}</div>`;
    }
    async function submit(f, issue) {
      const b = SH.clean(SH.formData(f));
      if (!b.customer_id) { SH.toast('Choose the customer first.', true); return; }
      const items = lines.map((l) => {
        const base = { quantity: l.qty, unit_price: l.price, discount: l.discount || 0 };
        if (l.kind === 'service') return Object.assign(base, { service_id: l.id });
        if (l.kind === 'part') return Object.assign(base, { product_id: l.id });
        return Object.assign(base, { item_type: 'other', description: l.description, hsn_sac: l.hsn || null, tax_rate: l.rate });
      });
      if (invoice) {
        const r = await SH.put('/admin/invoices/' + invoice.id, { items, notes: b.notes });
        SH.toast('Draft saved.'); SH.go('/invoices/' + r.id); return;
      }
      ['customer_address_id', 'stock_warehouse_id', 'service_job_id'].forEach((k) => { if (!b[k]) delete b[k]; else b[k] = Number(b[k]); });
      const r = await SH.post('/admin/invoices', Object.assign(b, { items, issue }));
      SH.toast(issue ? 'Invoice ' + r.number + ' issued.' : 'Draft saved.');
      SH.goThen('/invoices/' + r.id, () => { if (issue) SH.shareFrom(r, 'Send the invoice to the customer?'); });
    }
    return v;
  }
  page('/invoices/new', async (p, q) => editor(null, q));
  page('/invoices/:id/edit', async ([id]) => editor(await SH.get('/admin/invoices/' + id), {}));

  // =============================================================== INVOICE DETAIL
  page('/invoices/:id', async ([id]) => {
    const i = await SH.get('/admin/invoices/' + id);
    const m = SH.can('invoices.manage'); const pm = SH.can('payments.manage');
    const payable = ['issued', 'partially_paid'].includes(i.status) && Number(i.balance_due) > 0;
    const intra = i.supply_type !== 'inter';
    const a = i.billing_address || {};
    return {
      html: `${head(i.number || 'Draft invoice', `${pill(invStatus(i))} · ${esc(i.customer.name)} · ${money(i.total)}`, `
          ${i.status === 'draft' && m ? `<a class="btn" href="#/invoices/${i.id}/edit">Edit</a><button class="btn primary" data-act="issue">Issue invoice</button>` : ''}
          ${i.status !== 'draft' ? `<button class="btn" data-act="printInvoice" data-id="${i.id}">Print / PDF</button>` : ''}
          ${i.public_url && i.status !== 'draft' ? `<a class="btn" href="${esc(i.public_url)}" target="_blank" rel="noopener">Customer page</a><button class="btn" data-act="copy" data-text="${esc(i.public_url)}">Copy link</button>` : ''}
          ${payable && pm ? '<button class="btn" data-act="remind" style="color:var(--wa);border-color:var(--wa)">WhatsApp reminder</button><button class="btn primary" data-act="pay">Record payment</button>' : ''}
          ${['draft', 'issued'].includes(i.status) && m && Number(i.amount_paid) === 0 ? '<button class="btn danger" data-act="cancel">Cancel</button>' : ''}`, '<a href="#/invoices">Invoices</a>')}
        <div class="grid two"><div style="display:flex;flex-direction:column;gap:14px">
          <section class="card"><div class="grid">
            <div><div class="small muted">Billed to</div><b>${esc(a.name || i.customer.name)}</b><div>${esc([a.line1, a.line2, a.city, a.pincode].filter(Boolean).join(', '))}</div><div class="muted">${esc(a.state || '')} (${esc(a.state_code || '')}) · ${esc(a.phone || '')}</div>${i.customer_gstin ? `<div>GSTIN ${esc(i.customer_gstin)}</div>` : ''}</div>
            <div><div class="small muted">From</div><b>${esc(i.branch.name)}</b>${i.branch.gstin ? `<div>GSTIN ${esc(i.branch.gstin)}</div>` : ''}<div class="muted">${esc(i.branch.state)} (${esc(i.branch.state_code)})</div></div>
            <div><div class="small muted">Dates</div><div>Invoice ${d(i.invoice_date)}</div><div>Due ${d(i.due_date)}</div>${i.job ? `<div>Job <a href="#/jobs/${i.job.id}">${esc(i.job.number)}</a></div>` : ''}</div>
          </div></section>
          <div class="tablebox"><table class="t"><thead><tr><th>Item</th><th>HSN/SAC</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Taxable</th><th class="right">${intra ? 'CGST + SGST' : 'IGST'}</th><th class="right">Total</th></tr></thead><tbody>
            ${i.items.map((it) => `<tr><td><b>${esc(it.description)}</b><div class="sub">${esc(SH.label(it.item_type))}</div></td><td class="mono">${esc(it.hsn_sac || '')}</td><td class="right">${Number(it.quantity)} ${esc(it.unit)}</td><td class="right">${money(it.unit_price)}${Number(it.discount) ? `<div class="sub">− ${money(it.discount)}</div>` : ''}</td><td class="right">${money(it.taxable_value)}</td>
              <td class="right">${intra ? `${money(Number(it.cgst_amount) + Number(it.sgst_amount))}<div class="sub">${Number(it.cgst_rate)}% + ${Number(it.sgst_rate)}%</div>` : `${money(it.igst_amount)}<div class="sub">${Number(it.igst_rate)}%</div>`}</td><td class="right">${money(it.total)}</td></tr>`).join('')}
          </tbody></table></div>
          ${i.notes ? `<section class="card"><h2>Notes</h2><div style="white-space:pre-line">${esc(i.notes)}</div></section>` : ''}
        </div><div style="display:flex;flex-direction:column;gap:14px">
          <section class="card"><h2>Totals</h2><dl class="kv" style="grid-template-columns:1fr auto">
            <dt>Taxable value</dt><dd class="right">${money(i.taxable_value)}</dd>
            ${intra ? `<dt>CGST</dt><dd class="right">${money(i.cgst_total)}</dd><dt>SGST</dt><dd class="right">${money(i.sgst_total)}</dd>` : `<dt>IGST</dt><dd class="right">${money(i.igst_total)}</dd>`}
            <dt>Round off</dt><dd class="right">${money(i.round_off)}</dd><dt><b style="color:var(--ink)">Total</b></dt><dd class="right" style="font-size:20px">${money(i.total)}</dd>
            <dt>Paid</dt><dd class="right">${money(i.amount_paid)}</dd><dt><b style="color:var(--ink)">Balance due</b></dt><dd class="right" style="font-size:18px;color:${Number(i.balance_due) > 0 ? 'var(--bad)' : 'var(--ok)'}">${money(i.balance_due)}</dd></dl></section>
          <section class="card"><h2>Payments</h2><div class="list">${(i.payments || []).map((p) => `<div class="row between"><span>${esc(SH.label(p.method))} ${p.reference ? `<span class="mono">${esc(p.reference)}</span>` : ''}<div class="small muted">${p.number ? esc(p.number) + ' · ' : ''}${dt(p.paid_at || p.created_at)}${p.review_note ? ' · ' + esc(p.review_note) : ''}</div></span><span class="right"><b>${money(p.amount)}</b><br>${pill(p.status)}</span></div>`).join('') || '<div class="muted">No payments yet.</div>'}</div>
            ${(i.payments || []).some((p) => p.status === 'pending_verification') && pm ? '<a class="btn" href="#/verify">Verify the UPI payment →</a>' : ''}</section>
          <section class="card"><h2>WhatsApp messages</h2><div class="list">${(i.messages || []).map((s) => `<div><div class="row between"><b>${esc(s.label)}</b>${s.sent ? '<span class="pill ok">Sent</span>' : `<a class="btn sm wa" href="${esc(s.url)}" target="_blank" rel="noopener" data-act="wa" data-id="${s.id}">WhatsApp</a>`}</div><div class="small muted" style="white-space:pre-line">${esc(s.message.split('\n')[0])}</div></div>`).join('') || '<div class="muted">None yet. Issuing the invoice prepares one.</div>'}</div></section>
        </div></div>`,
      reload: () => SH.reload(),
      actions: {
        issue: async () => { const r = await SH.post(`/admin/invoices/${id}/issue`, { send_payment_link: true }); SH.toast('Invoice ' + r.number + ' issued.'); await SH.reload(); SH.shareFrom(r, 'Send the invoice to the customer?'); },
        cancel: async () => { const reason = await SH.confirm('Cancel this invoice?', 'Stock used on it goes back to the store. The number stays used (shown as cancelled).', 'Cancel invoice', 'Reason'); if (!reason) return; await SH.post(`/admin/invoices/${id}/cancel`, { reason }); SH.toast('Invoice cancelled.'); SH.reload(); },
        remind: async () => { const r = await SH.post(`/admin/invoices/${id}/reminder`); if (r.skipped) SH.toast('Not prepared: ' + (r.reason || 'no WhatsApp number'), true); else SH.share(r, 'Send the payment reminder?'); },
        pay: () => SH.openModal(`<h3>Record payment · ${esc(i.number)}</h3><form data-submit="save" class="form">
            <label class="f">Amount<input class="in" type="number" step="0.01" min="0.01" max="${Number(i.balance_due)}" name="amount" value="${Number(i.balance_due)}" required></label>
            <label class="f">Method<select class="in" name="method" data-change="method">${[['cash', 'Cash'], ['upi', 'UPI (checked in bank app)'], ['bank_transfer', 'Bank transfer'], ['cheque', 'Cheque'], ['card', 'Card (own machine)']].map(([k, l]) => opt(k, l)).join('')}</select></label>
            <label class="f full" data-ref hidden>Reference (UTR / cheque no.)<input class="in" name="reference" maxlength="60"></label>
            <div class="actions full"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">Save payment</button></div></form>`, {
          method: (el) => { const r = document.querySelector('[data-ref]'); r.hidden = !['upi', 'bank_transfer', 'cheque'].includes(el.value); r.querySelector('input').required = !r.hidden; },
          save: async (f) => { const b = SH.clean(SH.formData(f)); b.invoice_id = Number(id); b.amount = Number(b.amount); const r = await SH.post('/admin/payments', b); SH.closeModal(); SH.toast('Payment recorded.'); await SH.reload(); SH.shareFrom(r, 'Send the receipt?'); },
        }),
      },
    };
  });

  // =============================================================== PAYMENTS
  page('/payments', async (p, q) => {
    const r = await SH.get('/admin/payments', SH.withBranch({ page: q.page, status: q.status, method: q.method, from: q.from, to: q.to }));
    return {
      html: `${head('Payments', 'Everything received: cash, UPI, bank transfer and cheques.')}
        <div class="row">${chips('status', q.status || '', [['', 'All'], ['success', 'Received'], ['pending_verification', 'To verify'], ['rejected', 'Rejected']])}${chips('method', q.method || '', [['', 'Any method'], ['cash', 'Cash'], ['upi', 'UPI'], ['bank_transfer', 'Bank'], ['cheque', 'Cheque']])}</div>
        <div class="tablebox"><table class="t"><thead><tr><th>Receipt</th><th>Customer</th><th>Invoice</th><th>Method</th><th class="right">Amount</th><th>When</th><th>By</th><th>Status</th></tr></thead><tbody>
        ${r.data.map((x) => `<tr><td class="mono">${esc(x.number || '—')}</td><td><b>${esc(x.customer && x.customer.name)}</b><div class="sub">${esc(x.customer && x.customer.phone)}</div></td><td>${x.invoice ? `<a class="mono" href="#/invoices/${x.invoice.id}">${esc(x.invoice.number)}</a>` : '—'}</td>
          <td>${esc(SH.label(x.method))}${x.reference ? `<div class="sub mono">${esc(x.reference)}</div>` : ''}</td><td class="right"><b>${money(x.amount)}</b></td><td>${dt(x.paid_at || x.created_at)}</td><td>${esc(x.receiver ? x.receiver.name : SH.label(x.source || ''))}</td><td>${pill(x.status)}</td></tr>`).join('') || '<tr><td colspan="8" class="empty">No payments.</td></tr>'}
        </tbody></table>${pager(r)}</div>`,
    };
  });

  // =============================================================== UPI VERIFY
  page('/verify', async (p, q) => {
    const r = await SH.get('/admin/payments', SH.withBranch({ status: 'pending_verification', per_page: 50, page: q.page }));
    return {
      html: `${head('UPI payments to verify', 'Customers paid straight to your UPI ID and reported the reference number.')}
        <div class="note">Open your bank or UPI business app, find a credit with the same amount and reference (UTR), then press Received. The receipt message is prepared automatically.</div>
        <div class="tablebox"><table class="t"><thead><tr><th>Customer</th><th>Invoice</th><th class="right">Amount</th><th>UPI reference</th><th>Reported</th><th>Decision</th></tr></thead><tbody>
        ${r.data.map((x) => `<tr><td><b>${esc(x.customer && x.customer.name)}</b><div class="sub">${esc(x.customer && x.customer.phone)}</div></td><td>${x.invoice ? `<a class="mono" href="#/invoices/${x.invoice.id}">${esc(x.invoice.number)}</a>` : '—'}</td>
          <td class="right"><b>${money(x.amount)}</b></td><td class="mono" style="font-size:15px">${esc(x.reference)}</td><td>${dt(x.created_at)}<div class="sub">${esc(SH.label(x.source || ''))}</div></td>
          <td class="nowrap"><button class="btn sm okb" data-act="ok" data-id="${x.id}">Received</button> <button class="btn sm danger" data-act="no" data-id="${x.id}">Not found</button></td></tr>`).join('') || '<tr><td colspan="6" class="empty" style="color:var(--ok);font-weight:700">Nothing to verify.</td></tr>'}
        </tbody></table></div>`,
      actions: {
        ok: async (el) => { const r = await SH.post(`/admin/payments/${el.dataset.id}/verify`, { approve: true }); SH.toast('Payment confirmed. Invoice updated.'); await SH.reload(); SH.shareFrom(r, 'Send the receipt?'); },
        no: async (el) => { const note = await SH.confirm('Payment not found?', 'The customer sees that the reference could not be confirmed.', 'Mark not found', 'Reason (shown to the customer)'); if (!note) return; await SH.post(`/admin/payments/${el.dataset.id}/verify`, { approve: false, note }); SH.toast('Marked as not found.'); SH.reload(); },
      },
    };
  });
})();

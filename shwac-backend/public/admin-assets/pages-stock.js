/* SHWAC admin: inventory, expenses, attendance. */
(function () {
  'use strict';
  const { esc, money, d, dt, pill, page, head, pager, chips, searchBox, opt } = SH;
  const num = (v) => Number(v || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 });

  // =============================================================== INVENTORY
  page('/inventory', async (p, q) => {
    const tab = q.tab || 'stock';
    const manage = SH.can('inventory.manage');
    const whs = await SH.get('/admin/warehouses');
    const wh = q.wh || (whs.find((w) => w.type === 'main') || whs[0] || {}).id || '';
    let body = '';
    if (tab === 'stock') {
      const r = await SH.get('/admin/stock', { warehouse_id: wh, per_page: 100, page: q.page });
      const w = whs.find((x) => String(x.id) === String(wh));
      body = `<div class="chips">${whs.map((x) => `<a class="chip ${String(x.id) === String(wh) ? 'on' : ''}" href="#/inventory?tab=stock&wh=${x.id}">${esc(x.name)}</a>`).join('')}</div>
        <div class="tablebox"><table class="t"><thead><tr><th>Part</th><th>Barcode</th><th class="right">Qty</th><th class="right">Minimum</th><th>Status</th>${manage ? '<th></th>' : ''}</tr></thead><tbody>
        ${r.data.map((s) => { const min = Number(w && w.type === 'van' ? s.product.van_min_level : s.product.reorder_level); const qn = Number(s.quantity); const st = qn <= 0 ? ['bad', 'Out'] : (min > 0 && qn <= min ? ['warn', 'Low'] : ['ok', 'OK']);
          return `<tr><td><b>${esc(s.product.name)}</b><div class="sub">${esc(s.product.sku)} · ${money(s.product.sale_price)}</div></td><td class="mono">${esc(s.product.barcode || '')}</td><td class="right" style="font-size:16px;font-weight:800">${num(qn)} <span class="small muted">${esc(s.product.unit)}</span></td><td class="right muted">${min || '—'}</td><td><span class="pill ${st[0]}">${st[1]}</span></td>
            ${manage ? `<td class="right"><button class="btn sm" data-act="adjust" data-p="${s.product.id}" data-name="${esc(s.product.name)}" data-q="${qn}">Count</button></td>` : ''}</tr>`; }).join('') || `<tr><td colspan="6" class="empty">No stock in ${esc(w ? w.name : 'this store')} yet.</td></tr>`}
        </tbody></table>${pager(r)}</div>`;
    } else if (tab === 'alerts') {
      const r = await SH.get('/admin/stock/alerts');
      body = `<div class="tablebox"><table class="t"><thead><tr><th>Part</th><th>Where</th><th class="right">Qty when raised</th><th>Raised</th></tr></thead><tbody>
        ${r.map((a) => `<tr><td><b>${esc(a.product.name)}</b><div class="sub">${esc(a.product.sku)}</div></td><td>${esc(a.warehouse.name)}</td><td class="right">${num(a.quantity)} / min ${num(a.threshold)}</td><td>${dt(a.created_at)}</td></tr>`).join('') || '<tr><td colspan="4" class="empty" style="color:var(--ok);font-weight:700">All stock is above its minimum.</td></tr>'}</tbody></table></div>`;
    } else if (tab === 'moves') {
      const r = await SH.get('/admin/stock/movements', { page: q.page, warehouse_id: q.wh });
      body = `<div class="tablebox"><table class="t"><thead><tr><th>When</th><th>Part</th><th>Movement</th><th class="right">Qty</th><th>By</th><th>Note</th></tr></thead><tbody>
        ${r.data.map((m) => `<tr><td>${dt(m.created_at)}</td><td><b>${esc(m.product && m.product.name)}</b></td><td>${esc(SH.label(m.reason))}<div class="sub">${esc(m.from_warehouse ? m.from_warehouse.name : '')}${m.from_warehouse && m.to_warehouse ? ' → ' : ''}${esc(m.to_warehouse ? m.to_warehouse.name : '')}</div></td><td class="right">${num(m.quantity)}</td><td>${esc(m.user ? m.user.name : '')}</td><td class="muted">${esc(m.note || '')}</td></tr>`).join('') || '<tr><td colspan="6" class="empty">No movements.</td></tr>'}</tbody></table>${pager(r)}</div>`;
    } else if (tab === 'products') {
      const r = await SH.get('/admin/products', { page: q.page, q: q.q, per_page: 50 });
      body = `<div class="row between">${searchBox(q.q, 'Name, SKU or barcode')}${manage ? '<button class="btn primary" data-act="newProduct">+ New part</button>' : ''}</div>
        <div class="tablebox"><table class="t"><thead><tr><th>SKU</th><th>Name</th><th>HSN</th><th class="right">Price</th><th>GST</th><th class="right">In stock</th><th>Reorder / van min</th><th>Status</th>${manage ? '<th></th>' : ''}</tr></thead><tbody>
        ${r.data.map((x) => `<tr><td class="mono">${esc(x.sku)}</td><td><b>${esc(x.name)}</b><div class="sub">${esc([x.brand, x.category, x.barcode].filter(Boolean).join(' · '))}</div></td><td class="mono">${esc(x.hsn || '')}</td><td class="right">${money(x.sale_price)}</td><td>${Number(x.tax_rate)}%</td><td class="right">${num(x.total_stock)}</td><td>${num(x.reorder_level)} / ${num(x.van_min_level)}</td><td>${pill(x.is_active ? 'active' : 'inactive')}</td>
          ${manage ? `<td><button class="btn sm" data-act="editProduct" data-id="${x.id}">Edit</button></td>` : ''}</tr>`).join('') || '<tr><td colspan="9" class="empty">No parts yet.</td></tr>'}</tbody></table>${pager(r)}</div>`;
      SH._products = r.data;
    }
    const techs = manage ? await SH.techs() : [];
    const vans = whs.filter((w) => w.type === 'van');
    const noVan = techs.filter((t) => !vans.some((v) => v.user_id === t.id));
    return {
      html: `${head('Inventory', 'Spare parts in the store and in each technician’s van. Parts billed on an invoice are taken out of stock automatically.', manage ? `<button class="btn" data-act="receive">Receive stock</button><button class="btn primary" data-act="transfer">Issue to van / return</button>` : '')}
        ${chips('tab', tab, [['stock', 'Stock'], ['alerts', 'Low-stock alerts'], ['moves', 'Movements'], ['products', 'Parts list']])}
        ${body}
        ${manage && (noVan.length || !whs.some((w) => w.type === 'main')) ? `<section class="card"><h2>Set up stores</h2><div class="row">
          ${!whs.some((w) => w.type === 'main') ? '<button class="btn" data-act="newWh">+ Main store</button>' : ''}
          ${noVan.map((t) => `<button class="btn" data-act="van" data-id="${t.id}">+ Van for ${esc(t.name)}</button>`).join('')}</div></section>` : ''}`,
      actions: {
        van: async (el) => { await SH.post(`/admin/technicians/${el.dataset.id}/van`); SH.toast('Van store created.'); SH.reload(); },
        newWh: () => SH.openModal(`<h3>New store</h3><form data-submit="save" class="form"><label class="f">Name<input class="in" name="name" required value="Main Store"></label><label class="f">Code<input class="in" name="code" required placeholder="HQ-MAIN"></label>
            <label class="f">Branch<select class="in" name="branch_id">${(SH.state.branches || (SH.state.user.branch ? [SH.state.user.branch] : [])).map((b) => opt(b.id, b.name)).join('')}</select></label>
            <div class="actions full"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">Create</button></div></form>`, {
          save: async (f) => { const b = SH.formData(f); b.branch_id = Number(b.branch_id); await SH.post('/admin/warehouses', b); SH.closeModal(); SH.toast('Store created.'); SH.reload(); },
        }),
        receive: () => stockModal('receive', whs),
        transfer: () => stockModal('transfer', whs),
        adjust: (el) => SH.openModal(`<h3>Stock count · ${esc(el.dataset.name)}</h3><form data-submit="save" class="form"><div class="full muted">System shows ${num(el.dataset.q)}. Enter what you physically counted.</div>
            <label class="f">Counted quantity<input class="in" type="number" min="0" step="0.01" name="counted_quantity" data-num value="${el.dataset.q}" required></label><label class="f">Reason<input class="in" name="note" required placeholder="e.g. Monthly count, damaged"></label>
            <div class="actions full"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">Save count</button></div></form>`, {
          save: async (f) => { const b = SH.formData(f); await SH.post('/admin/stock/adjust', Object.assign(b, { warehouse_id: Number(wh), product_id: Number(el.dataset.p) })); SH.closeModal(); SH.toast('Stock updated.'); SH.reload(); },
        }),
        newProduct: () => productModal({}),
        editProduct: (el) => productModal((SH._products || []).find((x) => String(x.id) === el.dataset.id)),
      },
    };
  });

  async function stockModal(kind, whs) {
    const prods = (await SH.get('/admin/products', { active: 1, per_page: 100 })).data;
    if (!prods.length) { SH.toast('Add parts in the Parts list first.', true); return; }
    const main = whs.find((w) => w.type === 'main') || whs[0];
    const rows = (n) => Array.from({ length: n }).map((_, i) => `<div class="row" style="flex-wrap:nowrap"><select class="in grow" name="p${i}"><option value="">—</option>${prods.map((p) => opt(p.id, `${p.name} (${p.sku})`)).join('')}</select>
      <input class="in" style="width:100px" type="number" min="0" step="0.01" name="q${i}" placeholder="Qty">${kind === 'receive' ? `<input class="in" style="width:120px" type="number" min="0" step="0.01" name="c${i}" placeholder="Cost each">` : ''}</div>`).join('');
    SH.openModal(`<h3>${kind === 'receive' ? 'Receive stock (purchase)' : 'Move stock: issue to a van or return to store'}</h3><form data-submit="save" style="display:flex;flex-direction:column;gap:12px">
      <div class="form">${kind === 'receive' ? `<label class="f">Into<select class="in" name="warehouse_id">${whs.map((w) => opt(w.id, w.name, main && main.id)).join('')}</select></label><label class="f">Supplier bill no.<input class="in" name="note" maxlength="255"></label>`
        : `<label class="f">From<select class="in" name="from_warehouse_id">${whs.map((w) => opt(w.id, w.name, main && main.id)).join('')}</select></label><label class="f">To<select class="in" name="to_warehouse_id">${whs.filter((w) => w.type === 'van').concat(whs.filter((w) => w.type !== 'van')).map((w) => opt(w.id, w.name)).join('')}</select></label><label class="f full">Note<input class="in" name="note" maxlength="255"></label>`}</div>
      ${rows(5)}
      <div class="actions"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">${kind === 'receive' ? 'Add to stock' : 'Move stock'}</button></div></form>`, {
      save: async (f) => {
        const b = SH.formData(f); const items = [];
        for (let i = 0; i < 5; i++) if (b['p' + i] && Number(b['q' + i]) > 0) items.push(Object.assign({ product_id: Number(b['p' + i]), quantity: Number(b['q' + i]) }, kind === 'receive' && b['c' + i] ? { unit_cost: Number(b['c' + i]) } : {}));
        if (!items.length) { SH.toast('Choose at least one part and quantity.', true); return; }
        if (kind === 'receive') await SH.post('/admin/stock/receive', { warehouse_id: Number(b.warehouse_id), note: b.note || null, items });
        else { if (b.from_warehouse_id === b.to_warehouse_id) { SH.toast('From and To must be different.', true); return; } await SH.post('/admin/stock/transfer', { from_warehouse_id: Number(b.from_warehouse_id), to_warehouse_id: Number(b.to_warehouse_id), note: b.note || null, items }); }
        SH.closeModal(); SH.toast(kind === 'receive' ? 'Stock received.' : 'Stock moved. It shows in the technician app’s van stock.'); SH.reload();
      },
    }, { wide: true });
  }

  function productModal(x) {
    x = x || {};
    SH.openModal(`<h3>${x.id ? 'Edit part' : 'New spare part'}</h3><form data-submit="save" class="form">
      <label class="f">SKU / code<input class="in" name="sku" value="${esc(x.sku || '')}" required pattern="[A-Za-z0-9_\\-]+"></label>
      <label class="f full">Name<input class="in" name="name" value="${esc(x.name || '')}" required></label>
      <label class="f">Category<input class="in" name="category" value="${esc(x.category || '')}" placeholder="RO parts"></label><label class="f">Brand<input class="in" name="brand" value="${esc(x.brand || '')}"></label>
      <label class="f">Barcode<input class="in" name="barcode" value="${esc(x.barcode || '')}"></label><label class="f">HSN<input class="in" name="hsn" value="${esc(x.hsn || '')}" pattern="\\d{4,8}"></label>
      <label class="f">Unit<input class="in" name="unit" value="${esc(x.unit || 'NOS')}"></label><label class="f">GST %<select class="in" name="tax_rate">${[0, 5, 12, 18, 28].map((r) => opt(r, r + '%', x.tax_rate !== undefined ? Number(x.tax_rate) : 18)).join('')}</select></label>
      <label class="f">Sale price (before GST)<input class="in" type="number" step="0.01" min="0" name="sale_price" value="${esc(x.sale_price || '')}" required></label>
      <label class="f">Purchase price<input class="in" type="number" step="0.01" min="0" name="purchase_price" value="${esc(x.purchase_price || '')}"></label><label class="f">MRP<input class="in" type="number" step="0.01" min="0" name="mrp" value="${esc(x.mrp || '')}"></label>
      <label class="f">Store reorder level<input class="in" type="number" step="0.01" min="0" name="reorder_level" value="${esc(x.reorder_level || 0)}"></label><label class="f">Van minimum<input class="in" type="number" step="0.01" min="0" name="van_min_level" value="${esc(x.van_min_level || 0)}"></label>
      <label class="f">Warranty (months)<input class="in" type="number" min="0" max="120" name="warranty_months" value="${esc(x.warranty_months || 0)}"></label>
      <label class="check full"><input type="checkbox" name="is_active" ${x.id ? (x.is_active ? 'checked' : '') : 'checked'}> Active (can be billed)</label>
      <div class="actions full"><button type="button" class="btn" data-act="closeModal">Cancel</button><button class="btn primary" type="submit">Save</button></div></form>`, {
      save: async (f) => {
        const b = SH.clean(SH.formData(f)); ['tax_rate', 'warranty_months'].forEach((k) => { if (b[k] !== null) b[k] = Number(b[k]); });
        if (x.id) await SH.patch('/admin/products/' + x.id, b); else await SH.post('/admin/products', b); SH.closeModal(); SH.toast('Part saved.'); SH.reload();
      },
    }, { wide: true });
  }

  // =============================================================== EXPENSES
  page('/expenses', async (p, q) => {
    const status = q.status === undefined ? 'pending' : q.status;
    const r = await SH.get('/admin/expenses', { status, page: q.page, from: q.from, to: q.to });
    const t = r.totals || {}; const tot = (k) => (t[k] ? money(t[k].total) + ' · ' + t[k].n : '₹0.00 · 0');
    return {
      html: `${head('Expense approvals', 'Fuel, travel and small purchases submitted by technicians from the app.')}
        <div class="grid"><div class="kpi"><span class="l">Waiting</span><span class="v" style="font-size:22px">${tot('pending')}</span></div><div class="kpi"><span class="l">Approved</span><span class="v" style="font-size:22px">${tot('approved')}</span></div><div class="kpi"><span class="l">Rejected</span><span class="v" style="font-size:22px">${tot('rejected')}</span></div></div>
        ${chips('status', status, [['pending', 'Waiting'], ['approved', 'Approved'], ['rejected', 'Rejected'], ['', 'All']])}
        <div class="tablebox"><table class="t"><thead><tr><th>Technician</th><th>Type</th><th>Note</th><th class="right">Amount</th><th>Bill</th><th>Decision</th></tr></thead><tbody>
        ${r.data.map((e) => `<tr><td><b>${esc(e.user && e.user.name)}</b><div class="sub">${d(e.date)}${e.job ? ' · ' + esc(e.job.number) : ''}</div></td><td>${esc(SH.label(e.category))}</td><td class="muted">${esc(e.description || '')}</td><td class="right"><b>${money(e.amount)}</b></td>
          <td>${e.receipt_url ? `<a href="${esc(e.receipt_url)}" target="_blank" rel="noopener">View bill</a>` : '<span class="muted">None</span>'}</td>
          <td class="nowrap">${e.status === 'pending' ? `<button class="btn sm okb" data-act="ok" data-id="${e.id}">Approve</button> <button class="btn sm danger" data-act="no" data-id="${e.id}">Reject</button>` : `${pill(e.status)}<div class="sub">${esc(e.reviewer ? e.reviewer.name : '')}${e.review_note ? ' · ' + esc(e.review_note) : ''}</div>`}</td></tr>`).join('') || '<tr><td colspan="6" class="empty">Nothing here.</td></tr>'}
        </tbody></table>${pager(r)}</div><div class="small muted">Rejections need a reason; it shows in the technician’s app. Nobody can approve their own claim.</div>`,
      actions: {
        ok: async (el) => { await SH.post(`/admin/expenses/${el.dataset.id}/review`, { status: 'approved' }); SH.toast('Approved.'); SH.reload(); },
        no: async (el) => { const note = await SH.confirm('Reject this expense?', '', 'Reject', 'Reason (the technician sees it)'); if (!note) return; await SH.post(`/admin/expenses/${el.dataset.id}/review`, { status: 'rejected', note }); SH.toast('Rejected.'); SH.reload(); },
      },
    };
  });

  // =============================================================== ATTENDANCE
  page('/attendance', async (p, q) => {
    const day = q.date || SH.today();
    const r = await SH.get('/admin/field/attendance', SH.withBranch({ date: day }));
    const gps = (lat, lng) => lat ? `<a href="https://www.google.com/maps/search/?api=1&query=${lat},${lng}" target="_blank" rel="noopener">Map</a>` : '—';
    return {
      html: `${head('Attendance', `${r.present} present · ${r.absent} absent on ${d(r.date)}`, `<form data-submit="day" class="row"><input class="in" type="date" name="date" value="${esc(day)}" max="${SH.today()}"><button class="btn" type="submit">Show</button></form>`)}
        <div class="tablebox"><table class="t"><thead><tr><th>Technician</th><th>Check-in</th><th>Where</th><th>Check-out</th><th>Where</th><th>Hours</th><th>Status</th></tr></thead><tbody>
        ${r.rows.map(({ user, attendance: a }) => { const mins = a ? (a.worked_minutes || (a.check_out_at ? null : Math.round((Date.now() - new Date(a.check_in_at)) / 60000))) : null;
          return `<tr><td><b>${esc(user.name)}</b><div class="sub">${esc(user.phone)}</div></td><td>${a ? SH.t(a.check_in_at) : '—'}</td><td>${a ? gps(a.check_in_lat, a.check_in_lng) : '—'}</td><td>${a && a.check_out_at ? SH.t(a.check_out_at) : '—'}</td><td>${a ? gps(a.check_out_lat, a.check_out_lng) : '—'}</td>
            <td>${mins ? SH.mins(mins) + (a.check_out_at ? '' : ' so far') : '—'}</td><td>${!a ? pill('not_checked_in', 'Absent') : (a.check_out_at ? pill('checked_out') : pill('on_duty'))}</td></tr>`; }).join('') || '<tr><td colspan="7" class="empty">No technicians.</td></tr>'}
        </tbody></table></div>`,
      actions: { day: (f) => SH.setQuery({ date: SH.formData(f).date }) },
    };
  });
})();

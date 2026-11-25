const vouchers = {
  available: [
    { id: 1, cat: 'home', top: '30% OFF', sub: 'Shipping', code: 'DISCSHIP30', title: '30% Off Shipping Voucher', region: 'Zambales', info: 'Valid until November 29' },
    { id: 2, cat: 'electronics', top: 'FREE', sub: 'Shipping', code: 'FREESHIPN29', title: 'Free Shipping Voucher', region: 'Zambales', info: 'Valid until November 29' },
    { id: 6, cat: 'jewelry', top: '15% OFF', sub: 'DISCOUNT', code: 'JEWEL15', title: '15% Off Accessories Voucher', region: 'Bataan', info: 'Valid until December 5' },
    { id: 7, cat: 'wellness', top: '₱100 OFF', sub: 'DISCOUNT', code: 'WELLNESS100', title: '₱100 Off Wellness & Self-Care', region: 'Pampanga', info: 'Valid until December 10' },
    { id: 8, cat: 'home', top: '20% OFF', sub: 'DISCOUNT', code: 'HOMECOZY20', title: '20% Off Home Essentials', region: 'Zambales', info: 'Valid until December 12' },
    { id: 9, cat: 'electronics', top: '₱150 OFF', sub: 'DISCOUNT', code: 'TECHSAVE150', title: '₱150 Off Tech & Gadgets', region: 'Tarlac', info: 'Valid until December 15' },
    { id: 10, cat: 'wellness', top: 'FREE', sub: 'Shipping', code: 'CAREFREE', title: 'Free Shipping on Wellness Picks', region: 'Bataan', info: 'Valid until December 18' },
    { id: 11, cat: 'jewelry', top: '₱50 OFF', sub: 'DISCOUNT', code: 'SPARKLE50', title: '₱50 Off Handcrafted Jewelry', region: 'Bulacan', info: 'Valid until December 20' },
    { id: 12, cat: 'home', top: '25% OFF', sub: 'Shipping', code: 'MITHISHIP25', title: '25% Off Regional Shipping', region: 'Pampanga', info: 'Valid until December 25' },
    { id: 13, cat: 'electronics', top: '10% OFF', sub: 'DISCOUNT', code: 'GADGET10', title: '10% Off All Tech Finds', region: 'Zambales', info: 'Valid until December 30' }
  ],
  claimed: [
    { id: 3, cat: 'home', top: '30% OFF', sub: 'Shipping', code: 'DISCSHIP30', title: '30% Off Shipping Voucher', region: 'Zambales', info: 'Valid until November 29' }
  ],
  spent: [
    { id: 4, cat: 'jewelry', top: '12% OFF', sub: 'DISCOUNT', status: 'EXPIRED', title: '12% Off Discount Voucher', region: 'Zambales', info: 'Valid until October 8' },
    { id: 5, cat: 'wellness', top: '12% OFF', sub: 'DISCOUNT', status: 'REDEEMED', title: '12% Off Discount Voucher', region: 'Zambales', info: 'Voucher redeemed on October 25' }
  ]
};

let activeTab = 'available';
let searchQuery = '';
let activeCat = '';

function renderVouchers() {
  const tabs = $('voucherTabs');
  const grid = $('voucherGrid');

  tabs.querySelector('[data-tab="available"]').textContent = `Available (${vouchers.available.length})`;
  tabs.querySelector('[data-tab="claimed"]').textContent = `Claimed (${vouchers.claimed.length})`;

  const list = vouchers[activeTab].filter(v => {
    const matchText = `${v.title} ${v.code || ''} ${v.region}`.toLowerCase().includes(searchQuery);
    const matchCat = !activeCat || v.cat === activeCat;
    return matchText && matchCat;
  });

  if (!list.length) {
    grid.innerHTML = `<div class="v-empty">No vouchers match your current filter.</div>`;
    return;
  }

  grid.innerHTML = list.map(v => `
    <article class="v-card">
      <div class="v-stamp">
        <div class="v-stamp-top">${v.top}</div>
        <div class="v-stamp-sub">${v.sub}</div>
      </div>
      <div class="v-body">
        <div>
          <div class="v-top">
            ${activeTab !== 'spent'
              ? `<span class="v-pill-pop">Popular</span><span class="v-pill-code">${v.code}</span>`
              : `<span class="v-pill-${v.status.toLowerCase()}">${v.status}</span>`}
          </div>
          <h2 class="v-title">${v.title}</h2>
          <p class="v-meta">Region: ${v.region}<br>${v.info}</p>
        </div>
        ${activeTab === 'available' ? `<button type="button" class="v-btn" data-action="claim" data-id="${v.id}">Claim</button>` : ''}
        ${activeTab === 'claimed' ? `<button type="button" class="v-btn" data-action="copy" data-code="${v.code}">Copy Code</button>` : ''}
      </div>
    </article>
  `).join('');
}

window.onSearch = text => {
  searchQuery = text.trim().toLowerCase();
  renderVouchers();
};

window.onCategory = catKey => {
  activeCat = catKey;
  renderVouchers();
};

document.addEventListener('DOMContentLoaded', () => {

  const tabs = $('voucherTabs');
  const grid = $('voucherGrid');

  tabs.addEventListener('click', e => {
    const btn = e.target.closest('.v-tab');
    if (!btn) return;
    activeTab = btn.dataset.tab;
    tabs.querySelectorAll('.v-tab').forEach(t => {
      const isActive = t === btn;
      t.classList.toggle('active', isActive);
      t.setAttribute('aria-selected', isActive);
    });
    renderVouchers();
  });

  grid.addEventListener('click', e => {
    const btn = e.target.closest('.v-btn');
    if (!btn) return;

    if (btn.dataset.action === 'claim') {
      const idx = vouchers.available.findIndex(v => v.id === Number(btn.dataset.id));
      if (idx > -1) {
        const [item] = vouchers.available.splice(idx, 1);
        vouchers.claimed.push(item);
        renderVouchers();
        toast(`Claimed ${item.code}!`);
      }
    } else if (btn.dataset.action === 'copy') {
      navigator.clipboard?.writeText(btn.dataset.code);
      toast(`Copied ${btn.dataset.code}`);
    }
  });

  renderVouchers();
});
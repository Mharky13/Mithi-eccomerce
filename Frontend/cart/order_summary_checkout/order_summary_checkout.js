/* ======================= PART 3: CART PAGE ======================= */
/* ==========================================================================
   Mithi - Cart & Checkout (logged-in user)
   Cart page only. The header, main nav, categories dropdown, toast and image
   fallbacks live in categories.js + common.js.

   Everything (categories, header, cart) now lives in this one file

   CONTENTS
   1. Helpers            (peso, escapeHtml)
   2. Cart: screens & data
   3. Cart: state & money
   4. Cart: HTML templates
   5. Cart: render
   6. Cart: actions
   7. Start-up

   Open a screen directly with a hash, e.g. order_summary_checkout.html#stock
   Screens: summary (default) · stock · voucher · empty
   Uses $ , toast and reduce from common.js.
   ========================================================================== */


/* ==========================================================================
   1. Helpers
   ========================================================================== */
const peso = n => '₱' + Number(n).toFixed(2);
/* Order Summary shows whole pesos without decimals (₱30), otherwise two decimals (₱65.99) */
const pesoShort = n => Number.isInteger(Number(n)) ? '₱' + Number(n) : peso(n);

function escapeHtml(v) {
  return String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}


/* ==========================================================================
   2. Cart: screens & data
   ========================================================================== */
const SCREENS = {
  summary:       { label: 'Order Summary',  voucher: 0,  mode: 'summary' },
  stock:         { label: 'Cart - Stock changed', voucher: 0,  mode: 'stock' },
  voucher:       { label: 'Cart - Stock changed', voucher: 20, mode: 'stock' },
  empty:         { label: 'Cart - Empty',  empty: 'entire' },
  'empty-mithi': { label: 'CART',          empty: 'mithi'  },
  'empty-wow':   { label: 'Cart - Empty',  empty: 'wow'    }
};

/* Standard summary screen items */
const REGIONS = [
  {
    name: 'Zambales',
    items: [
      { id: 'fig1', name: 'Action Figure (Limited edition)', price: 5, qty: 1, img: 'pictures/action-figure.png', discount: 0,  unavailable: false, inStock: true  },
      { id: 'fig2', name: 'Action Figure (Limited edition)', price: 5, qty: 1, img: 'pictures/action-figure.png', discount: 0,  unavailable: true,  inStock: false },
      { id: 'fig3', name: 'Action Figure (Limited edition)', price: 5, qty: 1, img: 'pictures/action-figure.png', discount: 20, unavailable: false, inStock: true  }
    ]
  }
];

/* Stock screen: first item is unavailable */
const STOCK_REGIONS = [
  {
    name: 'local',
    label: 'Your local finds',
    items: [
      { id: 's1', name: 'A long product name that serves a purpose for the dev...', price: 65.99, qty: 1, img: 'pictures/Accessories.png', inStock: false, unavailable: true  },
      { id: 's2', name: 'A long product name that serves a purpose for the dev...', price: 80.00, qty: 1, img: 'pictures/Home Stuff.png',  inStock: true,  unavailable: false }
    ]
  }
];

const cloneRegions = list => list.map(r => ({ ...r, items: r.items.map(it => ({ ...it })) }));


/* ==========================================================================
   3. Cart: state & money
   ========================================================================== */
let model;

const screenName = () => {
  const name = (location.hash || '#summary').slice(1);
  return SCREENS[name] ? name : 'summary';
};

function loadScreen() {
  const preset = SCREENS[screenName()];
  const mode = preset.mode || 'summary'; // 'summary' | 'stock'

  let regions = [];
  if (!preset.empty) {
    regions = cloneRegions(mode === 'stock' ? STOCK_REGIONS : REGIONS);
  }

  model = {
    label:        preset.label,
    mode,
    voucher:      preset.voucher  || 0,
    empty:        preset.empty    || null,
    voucherInput: '',
    regions
  };
  document.title = 'Mithi - ' + model.label;
  render();
}

const allItems = () => model.regions.flatMap(r => r.items);

function money() {
  const items = allItems();
  const subtotal = items.filter(it => !it.unavailable).reduce((s, it) => s + it.price * it.qty, 0);
  /* ₱20 fixed shipping to the saved delivery address */
  const shipping = 20;
  const voucher = items.length ? model.voucher : 0;
  return {
    subtotal,
    shipping,
    voucher,
    total: Math.max(0, subtotal - voucher + shipping)
  };
}

const countItems = () => allItems().filter(it => !it.unavailable).reduce((s, it) => s + it.qty, 0);

function syncBadge() {
  const badge = $('cartCount');
  if (!badge) return;
  const count = model.empty ? 0 : allItems().reduce((s, it) => s + it.qty, 0);
  badge.textContent = String(count);
  badge.hidden = count === 0;
}


/* ==========================================================================
   4. Cart: HTML templates
   ========================================================================== */

/* ----- Banners ----- */
function bannerHtml() {
  if (model.mode === 'stock') {
    return `<div class="notice notice--alert" role="alert">One item is unavailable and has been excluded from your total.</div>`;
  }
  return '';
}

/* ----- Item rows ----- */

/* Standard summary item */
function itemRowHtml(item) {
  const sub = item.unavailable ? 0 : item.price * item.qty;
  return `<div class="item" data-id="${item.id}">
    <div class="item-img-wrap">
      ${item.discount ? `<span class="discount-badge">${item.discount}% OFF</span>` : ''}
      <img class="thumb" src="${escapeHtml(item.img)}" alt="${escapeHtml(item.name)}">
    </div>
    <div class="item-info">
      <div class="item-name">${escapeHtml(item.name)}</div>
      <div class="item-price">${peso(item.price)}</div>
      ${item.unavailable ? `<div class="unavail"><span class="unavail-ico" aria-hidden="true"></span>Currently unavailable from seller</div>` : ''}
    </div>
    <div class="item-col label-col">
      <span class="col-lbl">Quantity</span>
      <div class="qty">
        <button type="button" data-act="dec" data-id="${item.id}" aria-label="Decrease">−</button>
        <span>${item.qty}</span>
        <button type="button" data-act="inc" data-id="${item.id}" aria-label="Increase">+</button>
      </div>
    </div>
    <div class="item-col label-col">
      <span class="col-lbl">SubTotal</span>
      <span class="item-sub${item.unavailable ? ' muted' : ''}"> ${peso(sub)}</span>
    </div>
    <button type="button" class="remove-btn" data-act="remove" data-id="${item.id}" aria-label="Remove">
      <span class="remove-dot" aria-hidden="true"></span>Remove
    </button>
  </div>`;
}

/* Stock-screen item (compact row) */
function stockItemRowHtml(item) {
  const off = item.unavailable ? 'disabled' : '';
  return `<div class="item item--guest${item.unavailable ? ' item--unavail' : ''}" data-id="${item.id}">
    <img class="thumb" src="${escapeHtml(item.img)}" alt="${escapeHtml(item.name)}">
    <div class="item-info">
      <div class="item-name-row">
        <span class="item-name">${escapeHtml(item.name)}</span>
        <span class="item-price${item.unavailable ? ' item-price--dim' : ''}">${peso(item.price)}</span>
      </div>
      ${item.unavailable
        ? `<div class="unavail-label">Unavailable</div>
           <div class="excluded-label">Excluded from total</div>`
        : `<span class="in-stock-badge">● In Stock</span>`}
      <div class="guest-item-actions">
        <div class="qty qty--rect${item.unavailable ? ' qty--dim' : ''}">
          <button type="button" data-act="dec" data-id="${item.id}" aria-label="Decrease" ${off}>−</button>
          <span>${item.qty}</span>
          <button type="button" data-act="inc" data-id="${item.id}" aria-label="Increase" ${off}>+</button>
        </div>
        <button type="button" class="remove-text" data-act="remove" data-id="${item.id}">Remove</button>
        <button type="button" class="visit-store-inline" data-act="visit" data-region="${escapeHtml(item.id)}">Visit store →</button>
      </div>
    </div>
  </div>`;
}

/* ----- Region groups ----- */
function regionHtml(region) {
  if (model.mode !== 'summary') {
    return `<div class="region-group region-group--guest">
      <div class="region-head region-head--guest">
        <span class="region-label-guest">${escapeHtml(region.label || region.name)}</span>
        <button type="button" class="choose-delivery" data-act="choose-delivery">Change delivery region</button>
      </div>
      <div class="items-list">
        ${region.items.map(stockItemRowHtml).join('')}
      </div>
    </div>`;
  }
  return `<div class="region-group">
    <div class="region-head">
      <span class="region-label">Region: ${escapeHtml(region.name)}</span>
      <button type="button" class="visit-store" data-act="visit" data-region="${escapeHtml(region.name)}">Visit store →</button>
    </div>
    <div class="items-list">
      ${region.items.map(itemRowHtml).join('')}
    </div>
  </div>`;
}

/* ----- Cards below the items ----- */
function belowItemsHtml() {
  if (model.mode === 'stock') {
    return `<div class="explore-card">
      <h3>Still looking for that perfect find?</h3>
      <p>You can keep shopping or continue with the available item.</p>
      <button type="button" class="keep-exploring-btn" data-act="shop">Keep exploring</button>
    </div>`;
  }
  return '';
}

/* ----- Order Summary sidebar ----- */
function summaryHtml() {
  const bill = money();
  const itemCount = countItems();
  const checkoutLabel = model.mode === 'stock' ? 'Checkout available item' : 'Checkout';

  return `<aside class="summary summary--orange">
    <div class="summary-title">
      <h2>Order Summary</h2>
      <p>A little closer to your next favorite.</p>
    </div>
    <div class="summary-body">
        <form class="voucher-form" id="voucherForm">
          <input id="voucherInput" type="text" placeholder="Enter voucher code" value="${escapeHtml(model.voucherInput)}" autocomplete="off">
          <button type="submit" class="voucher-enter">Enter</button>
        </form>
        <div class="voucher-select-row">
          <span>Voucher</span>
          <button type="button" class="voucher-select" data-act="voucher-select">Select</button>
        </div>
        <div class="sum-row"><span>Subtotal (${itemCount} item${itemCount !== 1 ? 's' : ''})</span><span class="orange-val">${pesoShort(bill.subtotal)}</span></div>
        <div class="sum-row sum-row--shipping"><span>Shipping</span><span class="orange-val">${pesoShort(bill.shipping)}</span></div>
        ${bill.voucher ? `<div class="sum-row voucher-row"><span>Voucher</span><span>−${pesoShort(bill.voucher)}</span></div>` : ''}
        <div class="total-row"><span>Total</span><strong>${pesoShort(bill.total)}</strong></div>
        <button type="button" class="checkout-btn" data-act="checkout">${checkoutLabel}</button>
        <p class="summary-note">Shipping and any voucher savings are confirmed at checkout.</p>
        <div class="tara-row">
          <img src="pictures/mithi-circlelogo 1.png" alt="" class="tara-ico">
          <span>Tara na, Sis!</span>
        </div>
    </div>
  </aside>`;
}

/* ----- Empty screen ----- */
function emptyHtml() {
  return `<div class="cart-outer">
    <div class="cart-head">
      <h1>Cart mo, Sis</h1>
      <p class="cart-subtitle">A little guidance for your next step.</p>
    </div>
    <div class="empty-card">
      <div class="empty-ico"><img src="pictures/Cart.svg" alt=""></div>
      <h2>Your <span class="mithi-highlight">Mithi</span> cart is empty.</h2>
      <p>Explore high-quality products, delicacies, and handmade crafts straight from local sellers across Zambales and Region III.</p>
      <button type="button" class="shop-btn" data-act="shop">Shop Today's Deal</button>
    </div>
    <p class="empty-footer">Need a hand? Mithi Customer Support is here to help.</p>
  </div>`;
}


/* ==========================================================================
   5. Cart: render
   ========================================================================== */
function render() {
  const app = $('cartApp');
  if (!app) return;
  syncBadge();

  if (model.empty || !allItems().length) {
    if (!model.empty) model.empty = 'entire';
    app.innerHTML = emptyHtml();
    return;
  }

  const headHtml = model.mode === 'summary'
    ? `<div class="cart-head">
        <h1>Cart mo, Sis</h1>
        <nav class="breadcrumb" aria-label="Breadcrumb">
          <a href="#">Home</a><span>/</span><span>Shopping Cart</span>
        </nav>
      </div>`
    : `<div class="cart-head">
        <h1>Cart mo, Sis</h1>
        <p class="cart-subtitle">A quick update before your next step.</p>
      </div>`;

  app.innerHTML = `<div class="cart-outer">
    ${headHtml}
    ${bannerHtml()}
    <div class="cart-layout">
      <div class="cart-col">
        <section class="cart-card">
          ${model.regions.map(regionHtml).join('')}
        </section>
        ${belowItemsHtml()}
      </div>
      ${summaryHtml()}
    </div>
  </div>`;
}


/* ==========================================================================
   6. Cart: actions
   ========================================================================== */
function placeOrder() {
  const items = allItems().filter(it => !it.unavailable);

  if (items.length === 0) {
    toast('No available items to checkout');
    return;
  }

  sessionStorage.setItem('mithiCheckout', JSON.stringify({
    items: items,
    bill: money()
  }));

  window.location.href = '../proceed_payment/proceed_payment.html';
}

function initCart() {
  const app = $('cartApp');
  if (!app) return;

  loadScreen();
  window.addEventListener('hashchange', loadScreen);

  /* Header cart button goes back to the main summary screen */
  const cartBtn = document.querySelector('.cart-button');
  if (cartBtn) cartBtn.onclick = () => {
    if (location.hash === '#summary' || !location.hash) loadScreen();
    else location.hash = 'summary';
  };

  /* Click actions (data-act) */
  app.addEventListener('click', e => {
    const btn = e.target.closest('[data-act]');
    if (!btn) return;
    const act = btn.dataset.act;
    const id = btn.dataset.id;
    let item = null;
    for (const r of model.regions) item = r.items.find(it => it.id === id) || item;

    if (act === 'inc' && item && !item.unavailable) { item.qty += 1; render(); return; }
    if (act === 'dec' && item && !item.unavailable) { item.qty = Math.max(1, item.qty - 1); render(); return; }
    if (act === 'remove' && item) {
      for (const r of model.regions) r.items = r.items.filter(it => it.id !== id);
      model.regions = model.regions.filter(r => r.items.length);
      if (!allItems().length) model.empty = 'entire';
      render();
      return;
    }
    if (act === 'checkout')        { placeOrder(); return; }
    if (act === 'shop')            { toast('Happy shopping on Mithi'); return; }
    if (act === 'visit')           { toast('Visiting store'); return; }
    if (act === 'voucher-select')  { toast('Select a voucher from your wallet'); return; }
    if (act === 'choose-delivery') { toast('Change your delivery region'); return; }
  });

  /* Keep typed values in the model so they survive re-renders */
  app.addEventListener('input', e => {
    if (e.target.id === 'voucherInput') model.voucherInput = e.target.value;
  });

  /* Forms */
  app.addEventListener('submit', e => {
    if (e.target.id === 'voucherForm') {
      e.preventDefault();
      const code = model.voucherInput.trim().toUpperCase();
      if (code === 'MITHI20') { model.voucher = 20; toast('Voucher applied: ₱20 off'); }
      else if (code) toast('Invalid voucher code');
      render();
    }
  });
}


/* ==========================================================================
   7. Start-up
   ========================================================================== */
document.addEventListener('DOMContentLoaded', initCart);

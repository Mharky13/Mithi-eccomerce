/* Shared header & categories behaviour for every page: toast, image fallbacks, categories dropdown
   (filter, edit, drag-to-sort), search bar, cart / wishlist / user buttons and the main nav.
   Pages may define onSearch(text) and onCategory(catKey). */
const $ = id => document.getElementById(id);
const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
const CAT_STORE = 'mithiCategories';
const frontendRootUrl = new URL('../', document.currentScript.src);
let cart = 0;
const frontendUrl = path => new URL(path, frontendRootUrl).href;

/* Orange pin shown until the location icon exists */
const PIN = `data:image/svg+xml;charset=UTF-8,${encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><circle cx="24" cy="24" r="22" fill="#df8d16"/><path d="M24 11c-5 0-9 4-9 9 0 6.500 9 17 9 17s9-10.500 9-17c0-5-4-9-9-9z" fill="#fff"/><circle cx="24" cy="20" r="3.500" fill="#df8d16"/></svg>')}`;

const svg = (txt, a, b) => `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="600" height="420" viewBox="0 0 600 420"><defs><linearGradient id="g" x1="0" x2="1"><stop offset="0%" stop-color="${b}"/><stop offset="100%" stop-color="${a}"/></linearGradient></defs><rect width="600" height="420" fill="url(#g)"/><text x="300" y="215" text-anchor="middle" font-size="90" fill="white" font-family="Arial,sans-serif">${txt[0].toUpperCase()}</text><text x="300" y="320" text-anchor="middle" font-size="28" fill="white" font-family="Arial,sans-serif" font-weight="700">${txt.slice(0, 26)}</text></svg>`)}`;

/* Any missing image falls back to a generated placeholder */
document.addEventListener('error', e => {
  const img = e.target; if (img.tagName !== 'IMG' || img.dataset.fb || img.classList.contains('cat-icon')) return;
  img.dataset.fb = 1;
  img.src = img.classList.contains('loc') ? PIN : svg(img.alt || 'Mithi', '#f1b24a', '#f8ecdc');
}, true);

let toastEl;
function toast(m) {
  if (!toastEl) { toastEl = Object.assign(document.createElement('div'), { className: 'toast' }); toastEl.setAttribute('role', 'status') }
  toastEl.textContent = m; document.body.appendChild(toastEl);
  requestAnimationFrame(() => toastEl.classList.add('show'));
  clearTimeout(toastEl.t); toastEl.t = setTimeout(() => toastEl.classList.remove('show'), 1800);
}

function addToCart(msg) {
  cart++; const c = $('cartCount'); c.textContent = cart; c.hidden = false;
  c.classList.remove('pop'); void c.offsetWidth; c.classList.add('pop'); toast(msg);
}

/* FLIP: remember positions, change the DOM, then slide every element from its old spot to its new one */
function flipEls(els, change) {
  const before = new Map(els.map(el => [el, el.getBoundingClientRect()]));
  change();
  if (reduce) return;
  els.forEach(el => {
    const a = before.get(el), b = el.getBoundingClientRect(), dx = a.left - b.left, dy = a.top - b.top;
    if (dx || dy) el.animate([{ transform: `translate(${dx}px,${dy}px)` }, { transform: 'none' }], { duration: 300, easing: 'cubic-bezier(.22,.61,.36,1)' });
  });
}

function initCategories(onSelect) {
  const btn = $('categoryBtn'), menu = $('categoryMenu'), row = $('catActive'), bin = $('catBin'),
    editBtn = $('catEdit'), doneBtn = $('catDone');
  let selected = '', dragged = false;
  const pills = () => [...row.children, ...bin.children];
  const editing = () => menu.classList.contains('editing');

  /* Remember order + removed categories between visits */
  function save() {
    try { localStorage.setItem(CAT_STORE, JSON.stringify({ order: [...row.children].map(p => p.dataset.cat), hidden: [...bin.children].map(p => p.dataset.cat) })) } catch (e) { }
  }
  function restore() {
    try {
      const s = JSON.parse(localStorage.getItem(CAT_STORE)); if (!s) return;
      const map = new Map(pills().map(p => [p.dataset.cat, p]));
      (s.order || []).forEach(c => { if (map.has(c)) { row.appendChild(map.get(c)); map.delete(c) } });
      (s.hidden || []).forEach(c => { if (map.has(c)) { bin.appendChild(map.get(c)); map.delete(c) } });
      map.forEach(p => row.appendChild(p));
    } catch (e) { }
  }

  function setEditing(on) {
    if (editing() === on) return;
    menu.classList.toggle('editing', on);
    if (!menu.classList.contains('open')) return;
    (on ? doneBtn : editBtn).focus({ preventScroll: true });
  }
  function setMenu(open) {
    if (open) menu.style.setProperty('--caret', btn.offsetWidth / 2 + 'px');
    menu.classList.toggle('open', open);
    btn.classList.toggle('open', open);
    btn.setAttribute('aria-expanded', open);
    if (!open) setEditing(false);
  }

  /* Remove (red minus) / add back (plus) */
  function toggleCat(p) {
    const to = p.parentElement === row ? bin : row;
    flipEls(pills(), () => to.appendChild(p));
    if (to === bin && selected === p.dataset.cat) { selected = ''; p.setAttribute('aria-pressed', 'false'); onSelect('') }
    p.focus({ preventScroll: true });
    save();
  }

  btn.addEventListener('click', e => { e.stopPropagation(); setMenu(!menu.classList.contains('open')) });
  document.addEventListener('click', e => { if (!e.target.closest('.cat-wrap')) setMenu(false) });
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (editing()) setEditing(false); else { setMenu(false); btn.focus() }
  });
  editBtn.addEventListener('click', () => setEditing(true));
  doneBtn.addEventListener('click', () => { setEditing(false); save(); toast('Categories saved') });

  menu.addEventListener('click', e => {
    const pill = e.target.closest('.cat-pill'); if (!pill || dragged) return;
    if (editing()) { if (e.target.closest('.cat-badge') || e.detail === 0) toggleCat(pill); return }
    selected = selected === pill.dataset.cat ? '' : pill.dataset.cat;
    pills().forEach(p => p.setAttribute('aria-pressed', p.dataset.cat === selected));
    onSelect(selected);
    toast(selected ? `Showing ${pill.title}` : 'Showing all products');
    setMenu(false);
  });

  /* Sort: drag a pill along the top row (arrow keys also work) */
  let el = null, sx = 0, sy = 0, tx = 0, ty = 0, ox = 0, oy = 0, moved = false;
  const nat = () => { const r = el.getBoundingClientRect(); return [r.left + r.width / 2 - tx, r.top + r.height / 2 - ty] };
  const follow = e => { tx = e.clientX - sx + ox; ty = e.clientY - sy + oy; el.style.transform = `translate(${tx}px,${ty}px)` };
  row.addEventListener('pointerdown', e => {
    const p = e.target.closest('.cat-pill');
    if (!p || !editing() || e.target.closest('.cat-badge')) return;
    el = p; sx = e.clientX; sy = e.clientY; tx = ty = ox = oy = 0; moved = false;
    p.setPointerCapture(e.pointerId);
  });
  row.addEventListener('pointermove', e => {
    if (!el) return;
    if (!moved) { if (Math.hypot(e.clientX - sx, e.clientY - sy) < 5) return; moved = true; dragged = true; el.classList.add('dragging') }
    follow(e);
    const over = [...row.children].find(c => {
      if (c === el || c.getAnimations().some(a => !(a instanceof CSSTransition))) return false; /* skip pills that are still sliding into place */
      const r = c.getBoundingClientRect();
      return e.clientX > r.left && e.clientX < r.right && e.clientY > r.top && e.clientY < r.bottom;
    });
    if (!over) return;
    const kids = [...row.children], after = kids.indexOf(over) > kids.indexOf(el), n0 = nat();
    flipEls(kids.filter(c => c !== el), () => row.insertBefore(el, after ? over.nextSibling : over));
    const n1 = nat(); ox += n0[0] - n1[0]; oy += n0[1] - n1[1];
    follow(e);
  });
  const drop = () => {
    if (!el) return;
    if (moved) { el.classList.remove('dragging'); el.style.transform = ''; save() }
    el = null; setTimeout(() => { dragged = false }, 0);
  };
  row.addEventListener('pointerup', drop);
  row.addEventListener('pointercancel', drop);
  row.addEventListener('keydown', e => {
    const p = e.target.closest('.cat-pill');
    if (!p || !editing() || !/^Arrow(Left|Right)$/.test(e.key)) return;
    const left = e.key === 'ArrowLeft', sib = left ? p.previousElementSibling : p.nextElementSibling;
    if (!sib) return; e.preventDefault();
    flipEls([p, sib], () => row.insertBefore(p, left ? sib : sib.nextSibling));
    p.focus({ preventScroll: true }); save();
  });

  restore();
}

function initNavBar() {
  const search = $('searchInput');
  $('searchForm').onsubmit = e => { e.preventDefault(); if (window.onSearch) window.onSearch(search.value) };
  search.oninput = e => { if (window.onSearch) window.onSearch(e.target.value) };
  initCategories(c => { if (window.onCategory) window.onCategory(c) });

// Redirect to seller login page
$('sellerBtn').onclick = () => {
  window.location.href = frontendUrl('seller/seller.html#login');
};  document.querySelector('.cart-button').onclick = () => {
    window.location.href = frontendUrl('cart/order_summary_checkout/order_summary_checkout.html');
  };
  document.querySelector('.user-btn').onclick = () => {
    window.location.href = frontendUrl('customer_page/customer.html');
  };
  const wish = document.querySelector('.wish-btn');
  wish.onclick = () => { wish.classList.toggle('on'); toast('Wishlist updated') };

  /* Click ripple on buttons */
  document.addEventListener('pointerdown', e => {
    const b = e.target.closest('.nav-item,.nav-seller b,.buy,.cat-edit'); if (!b || reduce) return;
    const r = document.createElement('span'); r.className = 'rip'; b.appendChild(r); setTimeout(() => r.remove(), 600);
  });

  /* Main nav: highlight the tapped tab and keep it in view on small screens */
  document.querySelectorAll('.nav-item').forEach(btn => btn.onclick = () => {
    document.querySelectorAll('.nav-item').forEach(n => n.classList.toggle('active', n === btn));
    if (btn.dataset.toast) toast(btn.dataset.toast);
    const nav = btn.closest('.main-nav');
    if (nav) nav.scrollTo({ left: btn.offsetLeft - (nav.clientWidth - btn.offsetWidth) / 2, behavior: reduce ? 'auto' : 'smooth' });
  });

  document.getElementById('vouchersBtn').onclick = () => {
  window.location.href = frontendUrl('vouchers/voucher.html');
  };

  document.getElementById('regionalFindsBtn').onclick = () => {
  window.location.href = frontendUrl('regional_finds/regional_finds.html');
  };
}

window.initNavBar = initNavBar;
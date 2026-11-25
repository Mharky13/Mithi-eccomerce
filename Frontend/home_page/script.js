const cats = [
  { title: "Top Products", product: "Diaper", color: "#df8d16", g: ["#e18d17", "#d98312"] },
  { title: "New Arrivals", product: "Baby Care", color: "#d97985", g: ["#ee8fa6", "#d9607e"] },
  { title: "Regional Finds", product: "Local Favorites", color: "#398b6b", g: ["#4aa583", "#2f8a68"] },
  { title: "Flash Sale", product: "Daily Essentials", color: "#586dc5", g: ["#6c82d8", "#4a60b8"] }
];
const flash = [
  ["Household product", 220, 67, 33], ["Cooking pot", 345, 54, 2], ["Canned products", 450, 67, 15],
  ["Packaged goods", 600, 54, 45], ["Beverages", 821, 54, 230], ["New product", 199, 40, 25], ["Sale product", 399, 50, 60]
].map(([name, price, off, sold], i) => ({ name, price, off, sold, img: `images/product${i + 1}.jpg` }));
const CAT_LABELS = { home: 'Home', electronics: 'Electronics', jewelry: 'Jewelry', wellness: 'Wellness', food: 'Food' };
/* [name, price, old price, stock, category] — discount % is computed from old price */
const discover = [
  ["Camera", 19999, 40000, "out", "electronics"], ["Kitchen Favorites", 2226, 4400, "low", "home"], ["Artificial Flowers", 460, 900, "in", "home"],
  ["Household Supplies", 80, 0, "in", "home"], ["Furniture", 460, 0, "low", "home"], ["Perfume", 925, 0, "out", "wellness"],
  ["Graphics Card", 21233, 35000, "low", "electronics"], ["Decorative Items", 66, 0, "in", "home"], ["Snacks", 900, 0, "in", "food"],
  ["Fresh Produce", 900, 0, "low", "food"], ["Gold Necklace", 3500, 4800, "in", "jewelry"], ["Silver Ring", 1200, 0, "low", "jewelry"],
  ["Aroma Diffuser", 780, 1100, "in", "wellness"], ["Yoga Mat", 640, 0, "in", "wellness"], ["Wireless Earbuds", 1499, 2499, "low", "electronics"]
  ,
  ["Smart Watch", 2999, 4500, "in", "electronics"], ["Bluetooth Speaker", 1299, 0, "in", "electronics"], ["Mechanical Keyboard", 2450, 3200, "low", "electronics"],
  ["Table Lamp", 540, 0, "in", "home"], ["Throw Pillow Set", 780, 1000, "in", "home"], ["Scented Candle", 350, 0, "low", "wellness"],
  ["Essential Oils", 620, 850, "in", "wellness"], ["Pearl Earrings", 1850, 2400, "low", "jewelry"], ["Silver Bracelet", 1450, 0, "in", "jewelry"],
  ["Gold Hoop Earrings", 2750, 3500, "out", "jewelry"], ["Dried Mango", 180, 0, "in", "food"], ["Coffee Beans", 520, 650, "in", "food"],
  ["Instant Noodles Pack", 240, 0, "low", "food"], ["Cutting Board", 330, 0, "in", "home"], ["Massage Roller", 450, 600, "out", "wellness"]
].map(([n, p, old, stock, cat], i) => ({
  name: `A Long Product Name That Serves A Purpose For The Description, ${n}`, short: n, price: p, old, off: old ? Math.round((1 - p / old) * 100) : 0, stock, cat,
  rating: [5, 5, 4.5, 3, 5, 4, 4.5][i % 7], reviews: [345, 12, 27, 21, 432, 218, 45, 96, 150, 310][i % 10], sold: [199, 39, 78, 192, 3200, 1000, 110, 540, 860, 1500][(i * 7) % 10]
}));
const kfmt = n => n >= 1000 ? +(n / 1000).toFixed(1) + "K" : n;
const peso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2 });
const money = n => '₱' + n.toLocaleString('en-PH');
let topI = 0, flashI = 0, timer, activeCat = '';
/* Top products: the grid never moves; contents swap while the section gradient fades to the category colour */
function syncNav() { document.querySelectorAll('.nav-item[data-cat]').forEach(n => n.classList.toggle('active', +n.dataset.cat === topI)) }
function fillTop() {
  const item = cats[topI];
  $('topTitle').textContent = item.title;
  document.querySelectorAll('.top-card').forEach((c, i) => {
    const img = c.querySelector('img');
    c.dataset.name = item.product;
    img.removeAttribute('data-fb');
    img.src = svg(`${item.product} ${i + 1}`, item.color, '#f9f1ea');
    img.alt = item.product;
    c.querySelector('.card-name').textContent = item.product;
  });
}
function renderTop() {
  $('topCards').innerHTML = [...Array(6)].map(() => '<button type="button" class="top-card"><span class="thumb"><img alt=""><span class="sales">Monthly Sales 20K+</span></span><span class="card-name"></span></button>').join('');
  fillTop();
}
function setTop(i) {
  const s = $('topProductsSection');
  topI = (i % cats.length + cats.length) % cats.length;
  s.style.setProperty('--g1', cats[topI].g[0]);
  s.style.setProperty('--g2', cats[topI].g[1]);
  syncNav();
  if (reduce) { fillTop(); return }
  s.classList.add('swap');
  setTimeout(() => { fillTop(); requestAnimationFrame(() => s.classList.remove('swap')) }, 180);
}
window.moveTop = d => setTop(topI + d);

/* Flash sales carousel (visible count comes from the CSS --visible breakpoints) */
function visible() { return Math.round(parseFloat(getComputedStyle($('flashProducts')).getPropertyValue('--visible')) || 5) }
function updateFlash() {
  const track = $('flashProducts'), first = track.firstElementChild; if (!first) return;
  const gap = parseFloat(getComputedStyle(track).columnGap) || 0;
  track.style.transform = `translateX(-${flashI * (first.getBoundingClientRect().width + gap)}px)`;
}
function renderFlash() {
  $('flashProducts').innerHTML = flash.map(p => `<article class="flash-product" tabindex="0" data-name="${p.name}"><img src="${p.img}" alt="${p.name}"><div class="price"><strong>${money(p.price)}</strong><span class="discount">${p.off}% OFF</span></div><div class="sold"><span style="width:${Math.min(p.sold, 100)}%"></span><b>${p.sold} Sold</b></div></article>`).join('');
  updateFlash();
}
function resetFlash() { clearInterval(timer); if (!reduce) timer = setInterval(() => window.moveFlash(1, true), 3000) }
window.moveFlash = (d, auto) => {
  const max = Math.max(0, flash.length - visible()); flashI += d;
  if (flashI > max) flashI = 0; if (flashI < 0) flashI = max;
  updateFlash(); if (!auto) resetFlash();
};

/* Daily discover (search term + optional category filter) */
function renderDiscover(q = '') {
  const grid = $('discoverProducts'), term = q.trim().toLowerCase();
  const hit = discover.filter(p => (!activeCat || p.cat === activeCat) && `${p.short} ${CAT_LABELS[p.cat]}`.toLowerCase().includes(term));
  const label = { in: 'In Stock', low: 'Low Stock', out: 'Out of Stock' };
  grid.innerHTML = hit.length ? hit.map((p, i) => `<article class="discover-card" style="--i:${i}" tabindex="0" role="button" data-name="${p.short}" data-stock="${p.stock}">${p.off ? `<span class="off"><b>${p.off}</b><i class="pct">%</i><span class="off-txt">OFF</span></span>` : ''}<img src="${svg(p.short, '#f3d94e', '#f8f1d6')}" alt="${p.short}"><div class="discover-info"><div class="discover-name">${p.name}</div><span class="stock ${p.stock}">${label[p.stock]}</span><div class="discover-price">${peso(p.price)}${p.old ? `<s>${peso(p.old)}</s>` : ''}</div><div class="rating" aria-label="Rated ${p.rating} out of 5 from ${p.reviews} reviews, ${kfmt(p.sold)} sold"><span class="star" aria-hidden="true">★</span><span>${p.rating}</span><span>(${p.reviews})</span><i aria-hidden="true"></i><span>${kfmt(p.sold)} sold</span></div></div></article>`).join('') : '<div class="discover-empty">No matching products found. Try a different keyword.</div>';
}

function bind() {
  
  document.querySelectorAll('.see-all').forEach(a => a.onclick = e => e.preventDefault());

  /* Event delegation: survives re-renders */
  $('topCards').addEventListener('click', e => { const c = e.target.closest('.top-card'); if (c) toast(`${c.dataset.name} selected`) });
  $('flashProducts').addEventListener('click', e => { const c = e.target.closest('.flash-product'); if (c) toast(`${c.dataset.name} selected`) });
  $('flashProducts').addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.matches('.flash-product')) e.target.click() });
  $('discoverProducts').addEventListener('click', e => {
    const c = e.target.closest('.discover-card'); if (!c) return;
    if (c.dataset.stock === 'out') return toast(`${c.dataset.name} is out of stock`);
    toast(`${c.dataset.name} selected`);
  });
  $('discoverProducts').addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.matches('.discover-card')) e.target.click() });

  /* Flash carousel: pause on hover/focus, swipe on touch, recalc on any size change */
  const fs = $('flashSection'), car = document.querySelector('.carousel'); let sx = null;
  fs.addEventListener('mouseenter', () => clearInterval(timer)); fs.addEventListener('mouseleave', resetFlash);
  fs.addEventListener('focusin', () => clearInterval(timer)); fs.addEventListener('focusout', resetFlash);
  car.addEventListener('touchstart', e => { sx = e.touches[0].clientX }, { passive: true });
  car.addEventListener('touchend', e => {
    if (sx === null) return; const dx = e.changedTouches[0].clientX - sx; sx = null;
    if (Math.abs(dx) > 40) window.moveFlash(dx < 0 ? 1 : -1);
  });
  let rz; const onResize = () => { clearTimeout(rz); rz = setTimeout(() => { flashI = Math.min(flashI, Math.max(0, flash.length - visible())); updateFlash() }, 100) };
  if ('ResizeObserver' in window) new ResizeObserver(onResize).observe(car); else addEventListener('resize', onResize);
}

document.addEventListener('DOMContentLoaded', () => {
  renderTop();
  renderFlash();
  renderDiscover();
  bind();
  syncNav();
  resetFlash();
});

window.onCategory = function (category) {
  activeCat = category;
  renderDiscover($('searchInput').value);
};

window.onSearch = function (text) {
  renderDiscover(text);
};

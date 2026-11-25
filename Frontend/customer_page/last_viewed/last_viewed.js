/* Last Viewed page. Needs customer.js ($, toast, svg) and viewed_store.js.
   Preview the empty state with  last_viewed.html?state=empty
   DEMO is shown only while nothing has been viewed yet: delete it once the homepage saves real views. */
const DEMO = [
  ['Perfume', 925, 0, 'out', 2, 218, 1000], ['Graphics Card', 21233, 35000, 'low', 5, 45, 110],
  ['Embroidery Hoop', 65.99, 0, 'in', 4, 22, 78], ['Rice 25kg', 900, 0, 'in', 3, 21, 192]
].map(([short, price, old, stock, rating, reviews, sold], i) => ({
  id: 'demo-' + i, short, name: `A Long Product Name That Serves A Purpose For The Description, ${short}`,
  price, old, off: old ? Math.round((1 - price / old) * 100) : 0, stock, rating, reviews, sold
}));
const DEMO_LIST = [...DEMO, ...DEMO.map(p => ({ ...p, id: p.id + 'b' }))];

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const peso = n => '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2 });
const kfmt = n => n >= 1000 ? +(n / 1000).toFixed(1) + 'K' : n;
const STOCK = { in: 'In Stock', low: 'Low Stock', out: 'Out of Stock' };

/* The one place that decides where the list comes from (swap for fetch('/api/last-viewed') later) */
function getLastViewed() {
  if (new URLSearchParams(location.search).get('state') === 'empty') return [];
  const saved = window.LastViewed ? LastViewed.get() : [];
  return saved.length ? saved : DEMO_LIST;
}

function card(p, i) {
  const name = p.short || p.name;
  return `<article class="lv-card" style="--i:${i}" tabindex="0" role="button" data-name="${esc(name)}" data-stock="${esc(p.stock)}">
    ${p.off ? `<span class="lv-off"><b>${esc(p.off)}</b><i class="pct">%</i><span>OFF</span></span>` : ''}
    <img src="${p.image ? esc(p.image) : svg(name, '#f3d94e', '#f8f1d6')}" alt="${esc(name)}">
    <div class="lv-info">
      <div class="lv-name">${esc(p.name)}</div>
      <span class="lv-stock ${esc(p.stock)}">${STOCK[p.stock] || ''}</span>
      <div class="lv-price${p.old ? ' has-old' : ''}">${peso(p.price)}${p.old ? `<s>${peso(p.old)}</s>` : ''}</div>
      <div class="lv-rating" aria-label="Rated ${esc(p.rating)} out of 5 from ${esc(p.reviews)} reviews, ${kfmt(p.sold)} sold"><span class="star" aria-hidden="true">★</span><span>${esc(p.rating)}</span><span>(${esc(p.reviews)})</span><i aria-hidden="true"></i><span>${kfmt(p.sold)} sold</span></div>
    </div></article>`;
}

function render() {
  const list = getLastViewed(), grid = $('lvGrid');
  grid.innerHTML = list.length ? list.map(card).join('')
    : '<div class="lv-empty"><strong>Nothing viewed yet</strong>Products you open will show up here. <a href="../../home_page/index.html">Start browsing</a></div>';
}

document.addEventListener('DOMContentLoaded', () => {
  render();
  const grid = $('lvGrid');
  grid.addEventListener('click', e => {
    const c = e.target.closest('.lv-card'); if (!c) return;
    toast(c.dataset.stock === 'out' ? `${c.dataset.name} is out of stock` : `${c.dataset.name} selected`);
  });
  grid.addEventListener('keydown', e => { if (e.key === 'Enter' && e.target.matches('.lv-card')) e.target.click() });

  /* Right panel: same behaviour as the other account pages */
  document.querySelectorAll('.side-tabs button').forEach(t => t.onclick = () => document.querySelectorAll('.side-tabs button').forEach(x => { const on = x === t; x.classList.toggle('on', on); x.setAttribute('aria-selected', on) }));
  document.querySelector('.side-nav').onclick = e => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.dataset.t === 'Home') { location.href = '../customer.html'; return }
    if (b.dataset.t === 'Delivery Status') { location.href = '../delivery_status/delivery.html'; return }
    if (b.dataset.t === 'Notification') { location.href = '../notification/notification.html'; return }
    if (b.classList.contains('s-item')) document.querySelectorAll('.s-item').forEach(x => x.classList.toggle('on', x === b));
    toast(b.dataset.t);
  };
});

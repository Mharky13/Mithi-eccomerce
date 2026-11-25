// Regional Finds page. Shared navbar handles search and category events.
(() => {
  const regions = {
    Luzon: ['NCR - National Capital Region (Metro Manila)', 'CAR — Cordillera Administrative Region', 'Region I — Ilocos Region', 'Region II — Cagayan Valley', 'Region III — Central Luzon', 'Region IV-A — CALABARZON', 'MIMAROPA Region', 'Region V — Bicol Region'],
    Visayas: ['Region VI — Western Visayas', 'NIR — Negros Island Region', 'Region VII — Central Visayas', 'Region VIII — Eastern Visayas'],
    Mindanao: ['Region IX — Zamboanga Peninsula', 'Region X — Northern Mindanao', 'Region XI — Davao Region', 'Region XII — SOCCSKSARGEN', 'Region XIII — Caraga', 'BARMM — Bangsamoro Autonomous Region in Muslim Mindanao']
  };
  // Sample products and sample region assignments, not live inventory.
  const products = [
    ['Perfume', 'perfume', 925, 0, 'out', 'wellness', 'Luzon', 0, 2, 218, '1K'],
    ['Graphics Card', 'graphics-card', 21233, 35000, 'low', 'electronics', 'Luzon', 4, 5, 45, '110'],
    ['Handmade Embroidery', 'embroidery', 65.99, 0, 'in', 'home', 'Visayas', 0, 4.5, 22, '78'],
    ['Rice', 'rice', 900, 1200, 'in', 'food', 'Luzon', 4, 3, 21, '192'],
    ['Fresh Produce', 'produce', 900, 1800, 'low', 'food', 'Mindanao', 2, 5, 432, '2K'],
    ['Camera', 'camera', 19599, 30499, 'out', 'electronics', 'Luzon', 5, 5, 345, '199'],
    ['Canned Food', 'canned-food', 2226.15, 2498, 'low', 'food', 'Visayas', 2, 5, 12, '39'],
    ['Collectible Figure', 'figure', 460, 490, 'in', 'home', 'Mindanao', 1, 4.5, 22, '78'],
    ['Handmade Craft', 'craft', 80, 0, 'in', 'home', 'Visayas', 1, 3, 21, '192'],
    ['Wooden Table', 'table', 460, 0, 'low', 'home', 'Mindanao', 0, 5, 432, '2K']
  ].map(([name,img,price,old,stock,cat,island,region,rating,reviews,sold], id) => ({id,name,img,price,old,stock,cat,island,region:regions[island][region],rating,reviews,sold}));
  let region = '', query = '', category = '', limit = 10;
  let favorites = new Set();
  try { favorites = new Set(JSON.parse(localStorage.getItem('mithiRegionalFavorites') || '[]')); } catch (_) {}
  const el = id => document.getElementById(id);
  const money = value => '₱' + value.toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2});
  const stockLabels = {in:'In Stock',low:'Low Stock',out:'Out of Stock'};
  function render() {
    const sort = el('rfSort').value;
    const list = products.filter(p => (!region || p.region === region) && (!category || p.cat === category) && `${p.name} ${p.region} ${p.island}`.toLowerCase().includes(query));
    if (sort === 'low') list.sort((a,b) => a.price-b.price);
    if (sort === 'high') list.sort((a,b) => b.price-a.price);
    el('rfHeading').textContent = region || 'Discover across the Philippines';
    el('rfReset').hidden = !region;
    el('rfProducts').innerHTML = list.length ? list.slice(0,limit).map(p => `
      <article class="rf-card">
        <div class="rf-photo">
          <img src="pictures/${p.img}.jpg" alt="${p.name}">
          ${p.old && (1-p.price/p.old)>=.1 ? `<span class="rf-discount">${((1-p.price/p.old)*100).toFixed(1).replace(/\.0$/,'')}% OFF</span>` : ''}
          <button type="button" class="rf-wish" data-id="${p.id}" aria-label="Wishlist: ${p.name}" aria-pressed="${favorites.has(p.id)}">${favorites.has(p.id)?'♥':'♡'}</button>
        </div>
        <div class="rf-info">
          <h3 class="rf-name">${p.name} — A local find from ${p.island}</h3>
          <span class="rf-stock ${p.stock}">${stockLabels[p.stock]}</span>
          <div class="rf-price">${money(p.price)}</div>
          ${p.old ? `<s class="rf-old">${money(p.old)}</s>` : ''}
          <p class="rf-rating">★ ${p.rating} (${p.reviews}) · ${p.sold} sold</p>
        </div>
      </article>`).join('') : '<div class="rf-empty">No products match your current filters.</div>';
    el('rfMore').textContent = list.length > limit ? 'Explore more finds' : 'Explore more finds';
    el('rfMore').hidden = !list.length;
    document.querySelectorAll('.rf-region button').forEach(b => b.setAttribute('aria-pressed', String(b.dataset.region === region)));
  }
  document.addEventListener('DOMContentLoaded', () => {
    el('rfRegions').innerHTML = Object.entries(regions).map(([island,items]) => `<details class="rf-region"><summary>${island}</summary>${items.map(r => `<button type="button" data-region="${r}" aria-pressed="false">${r}</button>`).join('')}</details>`).join('');
    el('rfRegions').addEventListener('click', e => { const b=e.target.closest('[data-region]'); if(b){ region=region===b.dataset.region?'':b.dataset.region; limit=10; render(); } });
    el('rfReset').onclick = () => { region=''; render(); };
    el('rfSort').onchange = render;
    el('rfMore').onclick = () => { limit+=10; render(); if(typeof toast==='function') toast('All matching sample finds are displayed.'); };
    el('rfProducts').addEventListener('click', e => { const b=e.target.closest('.rf-wish'); if(!b)return; const id=Number(b.dataset.id); if(favorites.has(id))favorites.delete(id);else favorites.add(id); try{localStorage.setItem('mithiRegionalFavorites',JSON.stringify([...favorites]));}catch(_){} render(); });
    render();
  });
  window.onSearch = text => { query=text.trim().toLowerCase(); render(); };
  window.onCategory = key => { category=key; render(); };
})();

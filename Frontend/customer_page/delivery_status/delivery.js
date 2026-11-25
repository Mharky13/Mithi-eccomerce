/* Delivery Status page. Needs customer.js (toast, $) loaded first.
   Preview states with the URL:  delivery.html?items=1|2&stage=0..4
   stage = the step that is current: 0 Order Place, 1 Confirmed, 2 Preparing, 3 Shipped/In Transit, 4 Delivered */
const q = new URLSearchParams(location.search);
const ORDER = {
  id: 'ORD-2026-00125', date: 'November 14, 2026', tracking: 'TRK-987654321', payment: 'Paid',
  courier: 'J&T Express',
  rider: { name: 'Juswa Melgar', role: 'Assigned Delivery Rider', phone: '09123456789', company: 'J&T Express', vehicle: 'Motorcycle · ABC 1234', photo: '../pictures/juswa.png' },
  customer: { name: 'Jordan Monton', address: '123, Everywhere St. Anywhere BLK 4. 5TH Floor.', phone: '09123456789', email: 'mithitest12345678910@gmail.com' },
  from: '123 Street Anywhere Building NO. 4 St. Everywhere',
  to: '123 Street Everywhere Building NO. 4 St. Anywhere',
  total: '22 Days, 6 hours', departure: '21 Oct 2026', arrive: '26 Nov 2026',
  items: [
    { name: 'Product Name', store: 'Store Name', qty: 4, price: 850 },
    { name: 'Product NameLong Text', store: 'Store Name', qty: 1, price: 350 }
  ].slice(0, Number(q.get('items')) >= 2 ? 2 : 1),
  stage: Math.min(4, Math.max(0, q.has('stage') ? Number(q.get('stage')) : 3))
};
const STEPS = [
  { t: 'Order Place', time: '21 Oct 2026 14:00 PM', d: 'Shipment information receive by seller', loc: 'Everywhere Building' },
  { t: 'Order Confirmed', time: '21 Oct 2026 14:30 PM', d: 'Your order have just confirmed' },
  { t: 'Preparing to Ship', time: '21 Oct 2026 14:40 PM', d: 'Seller is preparing to ship your Order' },
  { t: 'Shipped/In Transit', d: 'Your package is currently in transit and on its way to your delivery address' },
  { t: 'Delivered', d: 'Your package has arrived at its destination and has been successfully received.' }
];
const WAIT = 'Waiting for courier update';
const peso = n => '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2 });
/* Progress icons live in pictures/: order sent, packed, on the way, delivered */
const ICONS = ['delivery', 'package', 'delivery_truck', 'location_check'].map(n => `<img src="../pictures/${n}.png" alt="">`);
const PIN_SVG = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/></svg>';
const CHEV = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.500" stroke-linecap="round" stroke-linejoin="round"><path d="M7 3l7 7-7 7"/></svg>';

function render() {
  const o = ORDER, s = o.stage, multi = o.items.length > 1;
  const items = o.items.map(i => `<div class="it"><img src="../pictures/product.png" alt="${i.name}"><div class="it-t"><h3>${i.name}</h3>${multi ? '' : `<p class="store">${i.store}</p>`}<p class="qty">Quantity : ${i.qty}</p><p class="price">${peso(i.price)}</p></div></div>`).join('');
  const icons = ICONS.map((ic, k) => `<span class="ico-step${s >= k + 1 ? ' on' : ''}">${ic}</span>${k < 3 ? `<span class="link" style="--fill:${s >= k + 2 ? 1 : 0}"></span>` : ''}`).join('');
  const tl = STEPS.map((st, i) => {
    const body = i < s ? '<span class="badge">Complete</span>' : i === s ? `<p>${st.d}</p>` : `<p>${WAIT}</p>`;
    return `<li class="${i <= s ? 'dot line' : ''}"><h3>${st.t}${st.time && i <= s ? `<span>· ${st.time}</span>` : ''}</h3>${i < s ? '<div>' + body + '</div>' : body}${st.loc && i <= s ? `<p class="loc">${PIN_SVG}${st.loc}</p>` : ''}</li>`;
  }).join('');
  $('dsCard').innerHTML = `
    <section class="order">
      <div class="od"><h2>Order Details</h2>
        <div class="od-row">Order ID<b>${o.id}</b></div>
        <div class="od-row">Order Date<b>${o.date}</b></div>
        <div class="od-row">Tracking Number<b>${o.tracking}</b></div>
        <div class="od-row">Payment Status<span class="pay">${o.payment}</span></div>
      </div>
      <div class="items ${multi ? 'multi' : 'single'}" id="items">${items}${multi ? `<button type="button" class="next" id="nextItem" aria-label="Next product">${CHEV}</button>` : ''}</div>
    </section>
    <section class="route-panel">
      <div class="route"><span class="courier">${o.courier}</span><div class="stop">${o.from}</div><div class="stop">${o.to}</div></div>
      <div class="progress" aria-label="Delivery progress">${icons}</div>
    </section>
    <section class="times"><div><small>Total Time</small><b>${o.total}</b></div><div><small>Departure Time</small><b>${o.departure}</b></div><div><small>Expected Arrive</small><b>${o.arrive}</b></div></section>
    <section class="ship"><div class="ship-in"><h2>Shipment Status</h2><ul class="tl">${tl}</ul></div></section>
    <section class="rider"><div class="rider-in">
      <div class="rider-head"><img src="${o.rider.photo}" alt="${o.rider.name}"><div><h2>Delivery Personnel</h2><p class="r-name">${o.rider.name}</p><p class="r-role">${o.rider.role}</p></div></div>
      <ul class="rider-list">
        <li><img src="../pictures/phone_hollow.png" alt=""><div><h3>Contact Number</h3><p>${o.rider.phone}</p></div></li>
        <li><img src="../pictures/building.png" alt=""><div><h3>Delivery Company</h3><p>${o.rider.company}</p></div></li>
        <li><img src="../pictures/truckk.png" alt=""><div><h3>Vehicle information</h3><p>${o.rider.vehicle}</p></div></li>
      </ul>
    </div></section>
    <section class="cust"><div class="cust-in"><h2>Customer Information</h2><p>${o.customer.name}</p><p>${o.customer.address}</p><p>${o.customer.phone}</p><p>${o.customer.email}</p></div></section>`;

  const track = $('items'), next = $('nextItem');
  if (next) {
    const atEnd = () => track.scrollLeft + track.clientWidth >= track.scrollWidth - 4;
    const sync = () => next.classList.toggle('back', atEnd());
    track.addEventListener('scroll', sync, { passive: true });
    next.onclick = () => track.scrollTo({ left: atEnd() ? 0 : track.scrollLeft + track.querySelector('.it').offsetWidth + 20, behavior: reduce ? 'auto' : 'smooth' });
  }
}

document.addEventListener('DOMContentLoaded', () => {
  render();
  document.querySelectorAll('.side-tabs button').forEach(t => t.onclick = () => document.querySelectorAll('.side-tabs button').forEach(x => { const on = x === t; x.classList.toggle('on', on); x.setAttribute('aria-selected', on) }));
  document.querySelector('.side-nav').onclick = e => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.dataset.t === 'Home') { location.href = '../customer.html'; return }
    if (b.dataset.t === 'Last viewed') { location.href = '../last_viewed/last_viewed.html'; return }
    if (b.dataset.t === 'Notification') { location.href = '../notification/notification.html'; return }
    if (b.classList.contains('s-item')) document.querySelectorAll('.s-item').forEach(x => x.classList.toggle('on', x === b));
    toast(b.dataset.t);
  };
});

/* Notification page. Needs customer.js ($, toast) loaded first.
   Preview the empty state with  notification.html?state=empty
   NOTES is sample data: swap getNotes() for fetch('/api/notifications') later. */
const MESSAGE = 'We are pleased to inform you that a new customer has registered! Please follow up promptly to welcome them, confirm their details and make sure their first order goes smoothly.';
const NOTES = [
  ['Just now', 1], ['3 minutes ago', 1], ['7 minutes ago', 1], ['15 minutes ago', 0], ['20 minutes ago', 0], ['12 hours ago', 0],
  ['22 hours ago', 0], ['24 hours ago', 0], ['1 day ago', 0], ['2 weeks ago', 0], ['Oct 26, 2026', 0], ['Oct 12, 2026', 0]
].map(([time, starred], i) => ({ id: i, time, starred: !!starred, text: MESSAGE }));

const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const STAR_ON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.2l2.6 5.4 5.9.8-4.3 4.1 1 5.9L12 16.6 6.8 19.4l1-5.900L3.500 9.400l5.900-.8z"/></svg>';

function getNotes() {
  return new URLSearchParams(location.search).get('state') === 'empty' ? [] : NOTES;
}

function row(n) {
  return `<li class="nt-row${n.starred ? ' starred' : ''}" data-id="${n.id}">
    <button type="button" class="nt-star" aria-label="Star notification" aria-pressed="${n.starred}"><img src="../pictures/star.png" alt="">${STAR_ON}</button>
    <button type="button" class="nt-main" aria-expanded="false" aria-label="Open notification"><span class="nt-text">${esc(n.text)}</span><span class="nt-time">${esc(n.time)}</span></button>
    <button type="button" class="nt-del" aria-label="Delete notification"><img src="../pictures/delete.png" alt=""></button>
  </li>`;
}

const EMPTY = '<li class="nt-empty"><strong>No notifications</strong>You are all caught up.</li>';

function render() {
  const list = getNotes();
  $('ntList').innerHTML = list.length ? list.map(row).join('') : EMPTY;
}

function removeRow(li) {
  const done = () => { li.remove(); if (!$('ntList').children.length) $('ntList').innerHTML = EMPTY };
  if (reduce) return done();
  li.style.height = li.offsetHeight + 'px'; void li.offsetHeight;
  li.classList.add('out'); setTimeout(done, 320);
}

document.addEventListener('DOMContentLoaded', () => {
  render();
  const list = $('ntList');

  list.addEventListener('click', e => {
    const li = e.target.closest('.nt-row'); if (!li) return;
    if (e.target.closest('.nt-star')) {
      const on = li.classList.toggle('starred');
      li.querySelector('.nt-star').setAttribute('aria-pressed', on);
    } else if (e.target.closest('.nt-del')) {
      removeRow(li); toast('Notification deleted');
    } else if (e.target.closest('.nt-main')) {
      /* Click a message to read all of it; click again (or open another) to fold it back */
      const open = !li.classList.contains('open');
      list.querySelectorAll('.nt-row.open').forEach(r => { r.classList.remove('open'); r.querySelector('.nt-main').setAttribute('aria-expanded', 'false') });
      if (open) { li.classList.add('open'); li.querySelector('.nt-main').setAttribute('aria-expanded', 'true') }
    }
  });

  /* Right panel: same behaviour as the other account pages */
  document.querySelectorAll('.side-tabs button').forEach(t => t.onclick = () => document.querySelectorAll('.side-tabs button').forEach(x => { const on = x === t; x.classList.toggle('on', on); x.setAttribute('aria-selected', on) }));
  document.querySelector('.side-nav').onclick = e => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.dataset.t === 'Home') { location.href = '../customer.html'; return }
    if (b.dataset.t === 'Delivery Status') { location.href = '../delivery_status/delivery.html'; return }
    if (b.dataset.t === 'Last viewed') { location.href = '../last_viewed/last_viewed.html'; return }
    if (b.dataset.t === 'Notification') return;
    if (b.classList.contains('s-item')) document.querySelectorAll('.s-item').forEach(x => x.classList.toggle('on', x === b));
    toast(b.dataset.t);
  };
});

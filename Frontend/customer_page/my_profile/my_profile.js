/* My Profile page. Needs customer.js ($, toast) loaded first.
   SAVED is sample data: swap it for fetch('/api/profile') and make save() a PUT later. */
const SAVED = {
  username: '@thomas.norstein', first: 'Lolong', last: 'Chi', email: 'mithiexample123456@gmail.com',
  phone: '09123456789', dob: '2004-01-12', address: 'Philippines, Central Luzon, Zambales, Olongapo City 2200',
  emailOk: false, phoneOk: true, photo: null
};
const FIELDS = ['username', 'first', 'last', 'email', 'phone', 'dob', 'address'];
const CHECK = '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="9" fill="currentColor"/><path d="M5.800 10.300l2.800 2.800 5.600-5.800" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
const RULES = {
  username: v => /^@?[\w.]{3,30}$/.test(v) || 'Use 3 to 30 letters, numbers, dots or underscores.',
  first: v => !!v || 'Enter your first name.',
  last: v => !!v || 'Enter your last name.',
  email: v => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) || 'Enter a valid email address.',
  phone: v => (/^\+?[\d\s-]{7,16}$/.test(v) && v.replace(/\D/g, '').length >= 7) || 'Enter a valid phone number.',
  dob: v => !!v || 'Choose your date of birth.',
  address: v => v.length >= 5 || 'Enter your address.'
};
let photoUrl = null;

const el = k => $('f-' + k);
const val = k => el(k).value.trim();
const fmtDob = v => v ? new Date(v + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';

function fill() {
  FIELDS.forEach(k => el(k).value = SAVED[k]);
  $('dobText').textContent = fmtDob(SAVED.dob);
  el('dob').max = new Date().toISOString().slice(0, 10);
}

function head() {
  const name = `${SAVED.first} ${SAVED.last}`;
  $('pfName').textContent = name; $('pfHandle').textContent = SAVED.username;
  const su = document.querySelector('.side-user');
  if (su) { su.querySelector('h2').textContent = name; su.querySelector('.su-handle').textContent = SAVED.username }
}

/* Verified badge shows for the saved value only; hidden while the person is editing it */
function badges() {
  ['email', 'phone'].forEach(k => {
    const b = $('st-' + k), ok = SAVED[k + 'Ok'];
    b.hidden = val(k) !== SAVED[k];
    b.className = 'pf-status ' + (ok ? 'ok' : 'no');
    b.innerHTML = ok ? CHECK + 'Verified' : 'Not Yet Verified';
  });
}

function refresh() {
  const dirty = FIELDS.some(k => val(k) !== SAVED[k]) || photoUrl !== SAVED.photo;
  $('pfSave').classList.toggle('dirty', dirty);
  badges();
}

function setErr(k, msg) {
  const box = el(k).closest('.pf-input'), out = $('e-' + k);
  box.classList.toggle('err', !!msg); el(k).setAttribute('aria-invalid', !!msg); out.textContent = msg || '';
}

function save() {
  let first = null;
  FIELDS.forEach(k => {
    const r = RULES[k](val(k)), bad = r !== true;
    setErr(k, bad ? r : ''); if (bad && !first) first = k;
  });
  if (first) { el(first).focus(); toast('Fix the highlighted fields'); return }
  if (!$('pfSave').classList.contains('dirty')) { toast('No changes to save'); return }

  let u = val('username'); if (u[0] !== '@') u = '@' + u; el('username').value = u;
  const next = { ...SAVED };
  FIELDS.forEach(k => next[k] = val(k));
  if (next.email !== SAVED.email) next.emailOk = false;
  if (next.phone !== SAVED.phone) next.phoneOk = false;
  if (photoUrl) {
    next.photo = photoUrl;
    document.querySelectorAll('.su-pic img, .avatar img').forEach(i => i.src = photoUrl);
  }
  Object.assign(SAVED, next);
  head(); refresh(); toast('Changes saved');
}

document.addEventListener('DOMContentLoaded', () => {
  fill(); head(); refresh();

  FIELDS.forEach(k => el(k).addEventListener('input', () => {
    if (k === 'dob') $('dobText').textContent = fmtDob(el(k).value);
    setErr(k, ''); refresh();
  }));

  /* Open the calendar anywhere on the date field */
  el('dob').addEventListener('click', e => { try { e.target.showPicker() } catch (_) { } });

  $('pfForm').addEventListener('submit', e => { e.preventDefault(); save() });

  /* Profile photo: preview now, applied to the header and sidebar on save */
  $('pfPic').onclick = () => $('pfPhoto').click();
  $('pfPhoto').onchange = e => {
    const f = e.target.files[0]; if (!f) return;
    if (!f.type.startsWith('image/')) { toast('Choose an image file'); return }
    if (f.size > 5 * 1024 * 1024) { toast('Photo must be under 5 MB'); return }
    if (photoUrl && photoUrl !== SAVED.photo) URL.revokeObjectURL(photoUrl);
    photoUrl = URL.createObjectURL(f); $('pfImg').src = photoUrl; refresh(); e.target.value = '';
  };

  /* Right panel: same behaviour as the other account pages (Settings / Account Management dropdowns and My Profile live in customer.js) */
  document.querySelectorAll('.side-tabs button').forEach(t => t.onclick = () => document.querySelectorAll('.side-tabs button').forEach(x => { const on = x === t; x.classList.toggle('on', on); x.setAttribute('aria-selected', on) }));
  document.querySelector('.side-nav').onclick = e => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.dataset.t === 'Home') { location.href = '../customer.html'; return }
    if (b.dataset.t === 'Delivery Status') { location.href = '../delivery_status/delivery.html'; return }
    if (b.dataset.t === 'Last viewed') { location.href = '../last_viewed/last_viewed.html'; return }
    if (b.dataset.t === 'Notification') { location.href = '../notification/notification.html'; return }
    if (b.classList.contains('s-item')) document.querySelectorAll('.s-item').forEach(x => x.classList.toggle('on', x === b));
    toast(b.dataset.t);
  };
});

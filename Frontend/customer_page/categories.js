/* Categories dropdown: filter, edit (remove / re-add) and drag-to-sort.
   Shared by every page. Needs the globals $, toast and reduce (defined in script.js / common.js).
   initCategories(onSelect) calls onSelect(categoryKey or '') whenever the active filter changes. */
const CAT_STORE = 'mithiCategories';

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

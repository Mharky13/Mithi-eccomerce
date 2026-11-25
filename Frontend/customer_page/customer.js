document.addEventListener('DOMContentLoaded', () => {
  if (!$('wishList')) return;
  const wishList = $('wishList');
  wishList.innerHTML = Array.from({ length: 4 }, () => `
        <article class="w-card">
          <img src="pictures/watch.png" alt="Apple Watch">
          <div class="w-body"><h3>Apple Watch</h3><small>June 7, 2026</small>
            <div class="w-btns"><button type="button" class="w-buy buy"><img class="w-arrow" src="pictures/arrow.png" alt="">Buy now</button><button type="button" class="w-view">View product</button><button type="button" class="w-more" aria-label="More options">&bull;&bull;&bull;</button></div>
          </div>
        </article>`).join('');
  wishList.onclick = e => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.classList.contains('w-buy')) return;
    else if (b.classList.contains('w-view')) toast('Opening product…');
    else toast('More options');
  };
  const nameKey = 'mithiCustomerDisplayName';
  const name = $('customerName');
  const editName = $('editName');
  const nameEditor = $('nameEditor');
  const nameInput = $('nameInput');
  const nameError = $('nameError');
  try {
    const savedName = localStorage.getItem(nameKey);
    if (savedName && savedName.trim()) name.textContent = savedName.trim();
  } catch (error) {
    console.error('Unable to load the saved customer name:', error);
    toast('Unable to load your saved name');
  }

  function closeNameEditor() {
    nameEditor.hidden = true;
    editName.setAttribute('aria-expanded', 'false');
    nameInput.value = name.textContent;
    nameError.textContent = '';
  }

  editName.onclick = () => {
    nameInput.value = name.textContent;
    nameError.textContent = '';
    nameEditor.hidden = false;
    editName.setAttribute('aria-expanded', 'true');
    nameInput.focus();
    nameInput.select();
  };
  $('cancelName').onclick = closeNameEditor;
  nameEditor.onsubmit = e => {
    e.preventDefault();
    const value = nameInput.value.trim().replace(/\s+/g, ' ');
    if (!value) {
      nameError.textContent = 'Enter a display name.';
      nameInput.focus();
      return;
    }
    try {
      localStorage.setItem(nameKey, value);
    } catch (error) {
      console.error('Unable to save the customer name:', error);
      nameError.textContent = 'Your name could not be saved in this browser.';
      return;
    }
    name.textContent = value;
    closeNameEditor();
    editName.focus();
    toast('Name updated');
  };
  $('pfpBtn').onclick = () => toast('Change profile photo');
  document.querySelectorAll('.side-tabs button').forEach(t => t.onclick = () => {
    document.querySelectorAll('.side-tabs button').forEach(x => { const on = x === t; x.classList.toggle('on', on); x.setAttribute('aria-selected', on) });
  });
  document.querySelector('.side-nav').onclick = e => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.classList.contains('s-item')) document.querySelectorAll('.s-item').forEach(x => x.classList.toggle('on', x === b));
    if (b.dataset.t === 'Delivery Status') { location.href = 'delivery_status/delivery.html'; return }
    if (b.dataset.t === 'Last viewed') { location.href = 'last_viewed/last_viewed.html'; return }
    if (b.dataset.t === 'Notification') { location.href = 'notification/notification.html'; return }
    toast(b.dataset.t);
  };

});

// Sidebar dropdown functionality
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.s-group > .s-item').forEach(button => {
    button.addEventListener('click', e => {
      e.stopPropagation();

      const group = button.closest('.s-group');
      group.classList.toggle('open');
    });
  });
});

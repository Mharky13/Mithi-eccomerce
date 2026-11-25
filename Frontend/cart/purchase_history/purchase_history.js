document.addEventListener('DOMContentLoaded', () => {
  let activeTab = 'order';
  let searchQuery = '';
  let activeCategory = '';

  const listEl = $('ohOrderList');
  const dateBadgeEl = $('ohDateBadge');
  const emptyEl = $('ohEmpty');

  function filterOrders() {
    const q = searchQuery.trim().toLowerCase();
    const cards = [...listEl.querySelectorAll('.oh-card')];
    let firstVisibleDate = '';
    let visibleCount = 0;

    cards.forEach(card => {
      const title = card.querySelector('.oh-prod-title').textContent.toLowerCase();
      const orderNum = card.querySelector('.oh-order-num span').textContent.toLowerCase();
      const status = card.querySelector('.oh-status').textContent.toLowerCase();

      const matchTab = card.dataset.tab === activeTab;
      const matchCat = !activeCategory || card.dataset.category === activeCategory;
      const matchQuery = !q || title.includes(q) || orderNum.includes(q) || status.includes(q);
      const show = matchTab && matchCat && matchQuery;

      card.hidden = !show;
      if (show) {
        visibleCount++;
        if (!firstVisibleDate) firstVisibleDate = card.dataset.shortDate;
      }
    });

    if (firstVisibleDate) {
      dateBadgeEl.textContent = firstVisibleDate;
    } else {
      dateBadgeEl.textContent = activeTab === 'order' ? 'Oct 26, 2027' : 'Oct 18, 2027';
    }

    emptyEl.hidden = visibleCount > 0;
  }

  document.querySelectorAll('.oh-tab').forEach(tab => {
    tab.addEventListener('click', () => {
      document.querySelectorAll('.oh-tab').forEach(t => t.classList.remove('active'));
      tab.classList.add('active');
      activeTab = tab.dataset.tab;
      filterOrders();
    });
  });

  listEl.addEventListener('click', e => {
    const btn = e.target.closest('button[data-action]');
    if (!btn) return;
    const action = btn.dataset.action;

    if (action === 'buy-again') {
      addToCart('Added item to cart');
    } else if (action === 'view-product') {
      toast('Opening product details');
    } else if (action === 'invoice') {
      toast('Opening order invoice');
    } else if (action === 'cancel') {
      const card = btn.closest('.oh-card');
      if (card) {
        card.dataset.tab = 'cancelled';
        const statusEl = card.querySelector('.oh-status');
        statusEl.textContent = 'Cancelled';
        statusEl.classList.add('cancelled');
        card.querySelector('.oh-prod-note').textContent = 'This order was cancelled. You have not been charged.';
        btn.remove();
        filterOrders();
        toast('Order moved to Cancelled Order');
      }
    }
  });

  window.onSearch = text => {
    searchQuery = text;
    filterOrders();
  };

  window.onCategory = catKey => {
    activeCategory = catKey;
    filterOrders();
  };

  filterOrders();
});
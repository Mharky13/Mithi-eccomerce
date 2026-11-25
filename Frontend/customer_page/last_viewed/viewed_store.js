/* Shared "last viewed" list. No dependencies, so any page (homepage, product page) can load it:
     <script src="../customer_page/last_viewed/viewed_store.js"></script>
   Save a product when it is opened:  LastViewed.add({ id, name, short, price, old, off, stock, rating, reviews, sold, image })
   With a backend, replace the body of get() / add() with fetch() calls; the page code does not change. */
(function () {
  const KEY = 'mithi.lastViewed', MAX = 24;
  const read = () => { try { const a = JSON.parse(localStorage.getItem(KEY)); return Array.isArray(a) ? a : [] } catch { return [] } };
  window.LastViewed = {
    get: read,
    add(p) {
      if (!p || p.id == null) return;
      try {
        const list = read().filter(x => x.id !== p.id);   /* viewing again moves it to the front */
        list.unshift({ ...p, viewedAt: Date.now() });
        localStorage.setItem(KEY, JSON.stringify(list.slice(0, MAX)));
      } catch { /* storage blocked: skip silently */ }
    },
    clear() { try { localStorage.removeItem(KEY) } catch { } }
  };
})();

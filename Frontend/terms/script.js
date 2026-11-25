(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  const scroller = $("#content");
  const hero = $("#hero");
  const heroText = $("#heroText");
  const tocLists = $("#tocList");
  const toggleBtn = $("#tocToggle");
  const curLabel = $("#tocCurrent");
  const tabs = $$(".policy-tab");
  const reduce = matchMedia("(prefers-reduced-motion: reduce)");
  const mobile = matchMedia("(max-width: 860px)");

  const META = {
    terms:    { title: "Terms of Service", sub: "Understanding how and where your data is being used." },
    privacy:  { title: "Privacy Policy",   sub: "Last updated October 2026" },
    shipping: { title: "Shipping Policy",  sub: "Last updated October 2026" },
    return:   { title: "Return Policy",    sub: "Last updated October 2026" },
  };
  const ORDER = Object.keys(META);

  /* ---------- Build TOC, labels and pagination from the markup ---------- */
  const items = [];            // every section, in document order
  const lists = {};            // policy id -> <ul>
  const behavior = () => (reduce.matches ? "auto" : "smooth");

  $$(".policy").forEach((article, i) => {
    const id = article.id;
    const label = document.createElement("p");
    label.className = "policy-label";
    label.textContent = `Policy ${i + 1} of ${ORDER.length}: ${META[id].title}`;
    article.prepend(label);

    const ul = document.createElement("ul");
    ul.className = "toc-list";
    ul.setAttribute("aria-label", `${META[id].title} sections`);
    lists[id] = ul;
    tocLists.appendChild(ul);

    $$("section", article).forEach((el) => {
      const a = document.createElement("a");
      a.href = `#${el.id}`;
      a.innerHTML = '<span class="toc-icon"></span><span></span>';
      a.firstChild.style.setProperty("--icon", `url("pictures/${el.dataset.icon}.svg")`);
      a.lastChild.textContent = el.dataset.title;
      const li = document.createElement("li");
      li.appendChild(a);
      ul.appendChild(li);
      items.push({ el, link: a, policy: id });
    });

   
    //const prev = ORDER[i - 1], next = ORDER[i + 1];
    //const nav = document.createElement("nav");
    //nav.className = "policy-pagination";
    //nav.setAttribute("aria-label", "Policy pagination");
    //nav.innerHTML =
    //  (prev ? `<a href="#${prev}" class="pagination-link prev"><small>Previous Policy</small><span>&larr; ${META[prev].title}</span></a>` : "") +
    //  (next ? `<a href="#${next}" class="pagination-link next"><small>Next Policy</small><span>${META[next].title} &rarr;</span></a>` : "");
    //article.appendChild(nav);
  });

  /* ---------- State ---------- */
  let currentPolicy = null;
  let currentItem = null;
  let locked = false;
  let lockTimer;

  function setPolicy(id) {
    if (id === currentPolicy) return;
    const first = currentPolicy === null;
    currentPolicy = id;
    hero.dataset.theme = id;                       // CSS transition animates the gradient
    document.title = `${META[id].title} | Mithi`;
    tabs.forEach((t) => (t.dataset.policy === id ? t.setAttribute("aria-current", "true") : t.removeAttribute("aria-current")));
    ORDER.forEach((k) => lists[k].classList.toggle("current", k === id));

    const swap = () => { $("#heroTitle").textContent = META[id].title; $("#heroSub").textContent = META[id].sub; };
    if (first || reduce.matches) return swap();
    heroText.classList.add("out");
    setTimeout(() => { swap(); heroText.classList.remove("out"); }, 200);
  }

  function setActive(item) {
    if (item === currentItem) return;
    currentItem = item;
    setPolicy(item.policy);
    items.forEach((it) => {
      const on = it === item;
      it.link.classList.toggle("active", on);
      on ? it.link.setAttribute("aria-current", "true") : it.link.removeAttribute("aria-current");
    });
    curLabel.textContent = item.el.dataset.title;
    keepLinkVisible(item.link);
  }

  function keepLinkVisible(link) {
    if (mobile.matches) return;
    const toc = $("#toc");
    const top = link.offsetTop - link.parentElement.parentElement.offsetTop;
    const t = link.getBoundingClientRect().top - toc.getBoundingClientRect().top;
    if (t < 0) toc.scrollTop += t - 8;
    else if (t + link.offsetHeight > toc.clientHeight) toc.scrollTop += t + link.offsetHeight - toc.clientHeight + 8;
  }

  /* ---------- Scroll spy ---------- */
  function spy() {
    const line = scroller.getBoundingClientRect().top + scroller.clientHeight * 0.2;
    let cur = items[0];
    for (const it of items) {
      if (it.el.getBoundingClientRect().top <= line) cur = it; else break;
    }
    if (scroller.scrollTop + scroller.clientHeight >= scroller.scrollHeight - 4) cur = items[items.length - 1];
    setActive(cur);
  }

  let ticking = false;
  scroller.addEventListener("scroll", () => {
    if (locked) {                                   // unlock once the programmatic scroll settles
      clearTimeout(lockTimer);
      lockTimer = setTimeout(() => { locked = false; spy(); }, 150);
      return;
    }
    if (!ticking) { ticking = true; requestAnimationFrame(() => { ticking = false; spy(); }); }
  }, { passive: true });

  /* ---------- Jump (section or whole policy) ---------- */
  function jumpTo(targetId, instant) {
    let target = document.getElementById(targetId);
    if (!target) return;
    
    // If target is a policy article, jump directly to its first section instead
    if (target.classList.contains("policy")) {
      const firstSec = target.querySelector("section");
      if (firstSec) target = firstSec;
    }

    const item = items.find((it) => it.el === target) || items.find((it) => target.contains(it.el));
    locked = true;
    clearTimeout(lockTimer);
    lockTimer = setTimeout(() => { locked = false; spy(); }, 500);   // fallback if no scroll happens
    setActive(item);                                // hero starts animating right away
    target.scrollIntoView({ behavior: instant ? "instant" : behavior(), block: "start" });
    history.replaceState(null, "", `#${target.id}`);
    if (mobile.matches) closeMenu();
  }

  document.addEventListener("click", (e) => {
    const a = e.target.closest('a[href^="#"]');
    if (!a || a.getAttribute("href") === "#") return;
    const id = a.getAttribute("href").slice(1);
    if (!document.getElementById(id) || !scroller.contains(document.getElementById(id))) return;
    e.preventDefault();
    jumpTo(id);
  });

  /* ---------- Mobile menu ---------- */
  function openMenu()  { tocLists.classList.add("open");    toggleBtn.setAttribute("aria-expanded", "true"); }
  function closeMenu() { tocLists.classList.remove("open"); toggleBtn.setAttribute("aria-expanded", "false"); }
  toggleBtn.addEventListener("click", () => (tocLists.classList.contains("open") ? closeMenu() : openMenu()));
  document.addEventListener("click", (e) => { if (mobile.matches && !$("#toc").contains(e.target)) closeMenu(); });
  document.addEventListener("keydown", (e) => { if (e.key === "Escape") closeMenu(); });
  mobile.addEventListener("change", closeMenu);

  /* ---------- Initial state (deep links like #privacy-rights) ---------- */
  const start = location.hash.slice(1);
  if (start && document.getElementById(start)) jumpTo(start, true);
  else spy();
})();


/* ===== 01. ICONS ===== */
/* ==========================================================================
   Inline SVG icons (Lucide paths). Usage: icon('search') -> '<svg ...>'
   ========================================================================== */
const ICON_PATHS = {
  'arrow-up': '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>',
  'arrow-up-right': '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
  'arrow-left': '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
  'chevron-down': '<path d="m6 9 6 6 6-6"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  'layout-grid': '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
  store: '<path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/><path d="M22 7v3a2 2 0 0 1-2 2a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7"/>',
  box: '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
  'receipt-text': '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M14 8H8"/><path d="M16 12H8"/><path d="M13 16H8"/>',
  wallet: '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
  plus: '<path d="M5 12h14"/><path d="M12 5v14"/>',
  minus: '<path d="M5 12h14"/>',
  x: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
  'image-plus': '<path d="M16 5h6"/><path d="M19 2v6"/><path d="M21 11.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7.5"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/><circle cx="9" cy="9" r="2"/>',
  star: '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
  circle: '<circle cx="12" cy="12" r="10"/>',
  'circle-check': '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
  'triangle-alert': '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
};

function icon(name, extraClass) {
  return '<svg class="icon' + (extraClass ? ' ' + extraClass : '') + '" viewBox="0 0 24 24" aria-hidden="true">' + ICON_PATHS[name] + '</svg>';
}

// Replace <span data-icon="name"></span> placeholders with inline SVGs
function hydrateIcons(root) {
  (root || document).querySelectorAll('[data-icon]').forEach((el) => {
    el.outerHTML = icon(el.dataset.icon, el.dataset.iconClass);
  });
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}


/* ===== 02. DATA ===== */
/* ==========================================================================
   Data: Philippine locations + sample seller data (globals)
   ========================================================================== */
const REGIONS = [
  {
    name: 'National Capital Region (NCR)',
    provinces: [
      {
        name: 'Metro Manila',
        cities: [
          { name: 'Quezon City', postal: '1100', barangays: ['Bagong Pag-asa', 'Batasan Hills', 'Commonwealth', 'Diliman', 'Loyola Heights'] },
          { name: 'City of Manila', postal: '1000', barangays: ['Binondo', 'Ermita', 'Malate', 'Sampaloc', 'Tondo'] },
          { name: 'Makati City', postal: '1200', barangays: ['Bel-Air', 'Poblacion', 'San Antonio', 'San Lorenzo'] },
          { name: 'Pasig City', postal: '1600', barangays: ['Kapitolyo', 'Oranbo', 'San Antonio', 'Ugong'] },
        ],
      },
    ],
  },
  {
    name: 'Region IV-A (CALABARZON)',
    provinces: [
      {
        name: 'Cavite',
        cities: [
          { name: 'Bacoor City', postal: '4102', barangays: ['Molino I', 'Molino II', 'Niog', 'Talaba'] },
          { name: 'Imus City', postal: '4103', barangays: ['Anabu I', 'Bucandala', 'Malagasang', 'Poblacion'] },
        ],
      },
      {
        name: 'Laguna',
        cities: [
          { name: 'Santa Rosa City', postal: '4026', barangays: ['Balibago', 'Dila', 'Market Area', 'Tagapo'] },
          { name: 'Calamba City', postal: '4027', barangays: ['Canlubang', 'Halang', 'Parian', 'Real'] },
        ],
      },
    ],
  },
  {
    name: 'Region III (Central Luzon)',
    provinces: [
      {
        name: 'Pampanga',
        cities: [
          { name: 'City of San Fernando', postal: '2000', barangays: ['Dolores', 'San Agustin', 'Sindalan', 'Telabastagan'] },
          { name: 'Angeles City', postal: '2009', barangays: ['Balibago', 'Malabanias', 'Pampang', 'Pulung Maragul'] },
        ],
      },
    ],
  },
  {
    name: 'Region VII (Central Visayas)',
    provinces: [
      {
        name: 'Cebu',
        cities: [
          { name: 'Cebu City', postal: '6000', barangays: ['Banilad', 'Guadalupe', 'Lahug', 'Mabolo'] },
          { name: 'Mandaue City', postal: '6014', barangays: ['Banilad', 'Centro', 'Subangdaku', 'Tipolo'] },
        ],
      },
    ],
  },
  {
    name: 'Region XI (Davao Region)',
    provinces: [
      {
        name: 'Davao del Sur',
        cities: [{ name: 'Davao City', postal: '8000', barangays: ['Buhangin', 'Poblacion', 'Talomo', 'Toril'] }],
      },
    ],
  },
];

function getProvinces(region) {
  const r = REGIONS.find((x) => x.name === region);
  return r ? r.provinces : [];
}

function getCities(region, province) {
  const p = getProvinces(region).find((x) => x.name === province);
  return p ? p.cities : [];
}

function getCity(region, province, city) {
  return getCities(region, province).find((c) => c.name === city);
}

// Order status: 'Pending' | 'Complete' | 'Cancelled'
const ORDERS = [
  { id: 'MZ-1234', date: 'Oct 29, 2026', buyer: 'Maria F.', items: 1, total: 50, status: 'Pending' },
  { id: 'MZ-5678', date: 'Oct 29, 2026', buyer: 'Louis B.', items: 1, total: 50, status: 'Complete' },
];

// Product status: 'Active' | 'Draft'
const PRODUCTS = [
  { sku: 'ABC001', name: 'Stick-o', description: 'Chocolate', category: 'Food', price: 50, stock: 1, reorderLevel: 10, reorderQty: 50, status: 'Active' },
  { sku: 'ABC002', name: 'Honey', description: 'Pure raw', category: 'Food', price: 50, stock: 0, reorderLevel: 4, reorderQty: 10, status: 'Draft' },
];

// Products created on the Add product page (stored in this browser until a backend exists)
const SAVED_PRODUCTS_KEY = 'mithi.seller.products';
function loadSavedProducts() {
  try {
    const saved = JSON.parse(localStorage.getItem(SAVED_PRODUCTS_KEY) || '[]');
    return Array.isArray(saved) ? saved : [];
  } catch (e) {
    return [];
  }
}
const initialProducts = PRODUCTS.slice();
function refreshProducts() { PRODUCTS.splice(0, PRODUCTS.length, ...initialProducts, ...loadSavedProducts()); }

function peso(n) {
  return '₱' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}


/* ===== 03. VALIDATORS ===== */
/* ==========================================================================
   Validators & formatters (globals)
   ========================================================================== */
const isEmail = (v) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.trim());

const phDigits = (v) => {
  const digits = v.replace(/\D/g, '');
  if (digits.startsWith('63')) return digits.slice(2);
  if (digits.startsWith('0')) return digits.slice(1);
  return digits;
};

const isPhMobile = (v) => /^9\d{9}$/.test(phDigits(v));
const isEmailOrPhone = (v) => isEmail(v) || isPhMobile(v);
const isFullName = (v) => /^[A-Za-zÀ-ÿñÑ.'\- ]{2,}$/.test(v.trim()) && v.trim().includes(' ');
const isOtp = (v) => /^\d{6}$/.test(v);
const isZip = (v) => /^\d{4}$/.test(v);
const isTin = (v) => /^\d{3}-\d{3}-\d{3}-\d{3,5}$/.test(v);

function formatTin(v) {
  const d = v.replace(/\D/g, '').slice(0, 14);
  return [d.slice(0, 3), d.slice(3, 6), d.slice(6, 9), d.slice(9)].filter(Boolean).join('-');
}

function formatPhMobile(v) {
  const d = v.replace(/\D/g, '').slice(0, 10);
  return [d.slice(0, 3), d.slice(3, 6), d.slice(6)].filter(Boolean).join(' ');
}


/* ===== 04. FORMS ===== */
/* ==========================================================================
   Form helpers shared by the signup, onboarding and store pages
   ========================================================================== */
const Forms = (function () {
  const CONTROL_SELECTOR = 'input:not([type=radio]):not([type=checkbox]):not([type=file]), select, textarea';

  // Show/hide error messages for every [data-field] inside root.
  // errors: { fieldName: 'message' }
  function applyErrors(root, errors) {
    root.querySelectorAll('[data-field]').forEach(function (field) {
      const key = field.dataset.field;
      const msg = errors[key];
      const errEl = field.querySelector('.field-error');
      field.classList.toggle('is-invalid', Boolean(msg));
      if (errEl) {
        errEl.hidden = !msg;
        errEl.textContent = msg || '';
      }
      field.querySelectorAll(CONTROL_SELECTOR + ', input[type=file]').forEach(function (ctrl) {
        if (msg && errEl) {
          ctrl.setAttribute('aria-invalid', 'true');
          ctrl.setAttribute('aria-describedby', errEl.id);
        } else {
          ctrl.removeAttribute('aria-invalid');
          ctrl.removeAttribute('aria-describedby');
        }
      });
    });
  }

  // Rebuild the <option> list of a <select>, keeping the current value if still valid.
  function fillSelect(select, options, placeholder) {
    const prev = select.value;
    select.innerHTML = '';
    const first = document.createElement('option');
    first.value = '';
    first.textContent = placeholder;
    select.appendChild(first);
    options.forEach(function (o) {
      const opt = document.createElement('option');
      opt.value = o;
      opt.textContent = o;
      select.appendChild(opt);
    });
    select.value = options.indexOf(prev) >= 0 ? prev : '';
  }

  // Format a PH mobile number as the user types.
  function bindPhone(input) {
    input.addEventListener('input', function () {
      input.value = formatPhMobile(input.value);
    });
  }

  // Digits-only input with a max length (zip, OTP codes).
  function bindDigits(input, maxLength) {
    input.addEventListener('input', function () {
      input.value = input.value.replace(/\D/g, '').slice(0, maxLength);
    });
  }

  function selectedRadio(form, name) {
    const checked = form.querySelector('input[name="' + name + '"]:checked');
    return checked ? checked.value : '';
  }

  // Show a banner (element with .form-banner) and bring it into view.
  function showBanner(banner, title, bodyHtml) {
    banner.querySelector('.form-banner-title-text').textContent = title;
    banner.querySelector('.form-banner-body').innerHTML = bodyHtml;
    banner.hidden = false;
  }

  function focusBanner(banner) {
    requestAnimationFrame(function () {
      banner.scrollIntoView({ behavior: 'smooth', block: 'center' });
      banner.focus({ preventScroll: true });
    });
  }

  // ----- File upload boxes -----
  const ACCEPTED = ['image/jpeg', 'image/png', 'application/pdf'];
  const MAX_BYTES = 20 * 1024 * 1024;

  // Wires an upload box. Returns { getFile, getLocalError }.
  function bindUpload(id, onChange) {
    const input = document.getElementById(id);
    const box = input.closest('.upload');
    const title = box.querySelector('.upload-title');
    const sub = box.querySelector('.upload-sub');
    let file = null;
    let localError = '';

    function accept(next) {
      if (!next) return;
      if (ACCEPTED.indexOf(next.type) < 0) {
        localError = 'Use a JPG, JPEG, PNG or PDF file.';
      } else if (next.size > MAX_BYTES) {
        localError = 'File is larger than 20 MB.';
      } else {
        localError = '';
        file = next;
        title.textContent = next.name;
        sub.textContent = 'Click to replace';
        box.classList.add('has-file');
      }
      if (onChange) onChange();
    }

    input.addEventListener('change', function () { accept(input.files && input.files[0]); });
    box.addEventListener('dragover', function (e) { e.preventDefault(); box.classList.add('is-dragging'); });
    box.addEventListener('dragleave', function () { box.classList.remove('is-dragging'); });
    box.addEventListener('drop', function (e) {
      e.preventDefault();
      box.classList.remove('is-dragging');
      accept(e.dataTransfer.files && e.dataTransfer.files[0]);
    });

    return {
      getFile: function () { return file; },
      getLocalError: function () { return localError; },
    };
  }

  return {
    applyErrors: applyErrors,
    fillSelect: fillSelect,
    bindPhone: bindPhone,
    bindDigits: bindDigits,
    selectedRadio: selectedRadio,
    showBanner: showBanner,
    focusBanner: focusBanner,
    bindUpload: bindUpload,
  };
})();


/* ===== 05. AUTH ===== */
/* ==========================================================================
   Auth helpers: error display for the large "auth" inputs
   ========================================================================== */
const Auth = (function () {
  // Show errors for each .auth-field[data-field]. When the input is empty the
  // error text replaces the placeholder (and the <p> is screen-reader only).
  function applyErrors(form, errors) {
    form.querySelectorAll('.auth-field[data-field]').forEach(function (field) {
      const msg = errors[field.dataset.field];
      const input = field.querySelector('.auth-input');
      const errEl = field.querySelector('.auth-error');
      const asPlaceholder = Boolean(msg) && !input.value;

      field.classList.toggle('is-invalid', Boolean(msg));
      input.placeholder = asPlaceholder ? msg : input.dataset.placeholder || '';
      if (msg) {
        input.setAttribute('aria-invalid', 'true');
        input.setAttribute('aria-describedby', errEl.id);
      } else {
        input.removeAttribute('aria-invalid');
        input.removeAttribute('aria-describedby');
      }
      errEl.hidden = !msg;
      errEl.textContent = msg || '';
      errEl.classList.toggle('sr-only-error', asPlaceholder);
    });
  }

  return { applyErrors: applyErrors };
})();


/* ===== 06. LAYOUT ===== */
/* ==========================================================================
   Shared layout: injects header, sidebar and footer into placeholders so
   they live in one place instead of being repeated in every HTML page.
   ========================================================================== */
const Layout = (function () {
  function logo(label) {
    return (
      '<span class="logo">' +
      '<span class="logo-brand">' +
      '<img class="logo-mark" src="images/mithi-circle-logo.png" alt="" aria-hidden="true" width="64" height="64">' +
      '<span class="logo-name">Mithi</span>' +
      '</span>' +
      '<span class="logo-divider" aria-hidden="true"></span>' +
      '<span class="logo-label">' + escapeHtml(label || 'Seller Center') + '</span>' +
      '</span>'
    );
  }

  function mark(sizeClass) {
    return '<img class="logo-mark ' + (sizeClass || '') + '" src="images/mithi-circle-logo.png" alt="" aria-hidden="true" width="64" height="64">';
  }

  // <div id="app-header" data-center="..." data-home="#login" data-fixed></div>
  function mountHeader() {
    const el = document.getElementById('app-header');
    if (!el) return;
    const center = el.dataset.center || '';
    const home = el.dataset.home || 'seller_login.html';
    const fixed = el.hasAttribute('data-fixed');
    el.outerHTML =
      '<header class="app-header' + (fixed ? ' is-fixed' : '') + '">' +
      '<div class="app-header-inner">' +
      '<a class="app-header-home" href="' + home + '" aria-label="Mithi Seller Center home">' + logo() + '</a>' +
      '<p class="app-header-center">' + escapeHtml(center) + '</p>' +
      '<a class="app-header-help" href="mailto:sellers@mithi.ph">Need help?' + icon('arrow-up-right') + '</a>' +
      '</div></header>';
  }

  const NAV = [
    { key: 'dashboard', href: 'seller_dashboard.html', label: 'Dashboard', icon: 'layout-grid' },
    { key: 'store', href: 'seller_store_settings.html', label: 'Store Setting', icon: 'store' },
    { key: 'products', href: 'seller_inventory.html', label: 'Inventory', icon: 'box' },
    { key: 'orders', href: 'seller_orders.html', label: 'Orders', icon: 'receipt-text' },
    { key: 'notifications', href: 'seller_notifications.html', label: 'Notifications', icon: 'circle' },
    { key: 'reports', href: 'seller_reports.html', label: 'Reports', icon: 'layout-grid' },
  ];

  // <div id="seller-sidebar" data-active="dashboard"></div>
  function mountSidebar() {
    const el = document.getElementById('seller-sidebar');
    if (!el) return;
    const active = el.dataset.active;
    const items = NAV.map(function (n) {
      const isActive = n.key === active;
      return (
        '<li><a class="nav-link' + (isActive ? ' is-active' : '') + '" href="' + n.href + '"' +
        (isActive ? ' aria-current="page"' : '') + '>' + icon(n.icon) + n.label + '</a></li>'
      );
    }).join('');
    el.outerHTML =
      '<aside class="sidebar">' +
      '<div class="sidebar-brand"><p class="sidebar-title">Your seller<br> corner</p>' +
      '<p class="sidebar-sub">SELLER CENTER</p></div>' +
      '<nav class="sidebar-nav" aria-label="Seller Center"><ul>' + items + '</ul></nav>' +
      '<div class="sidebar-help"><p class="help-title">A little help?</p>' +
      '<p class="help-text">Support is here when you need a hand.</p>' +
      '<a class="help-btn" href="mailto:sellers@mithi.ph">Help center</a></div>' +
      '</aside>';
  }

  function mountFooter() {
    const el = document.getElementById('seller-footer');
    if (!el) return;
    el.outerHTML =
      '<footer class="seller-footer">' + mark('size-24') +
      '<p>Tara na, Sis! Small shop, big possibilities.</p></footer>';
  }

  function mountBannerMark() {
    document.querySelectorAll('[data-banner-mark]').forEach(function (el) {
      el.outerHTML = mark('size-44');
    });
  }

  function init() {
    mountHeader();
    mountSidebar();
    mountFooter();
    mountBannerMark();
    hydrateIcons();
  }

  return { init: init };
})();




/* ===== 07. LOGIN SCREEN LOGIC ===== */
function init_login() {
  (function () {
    const form = document.getElementById('login-form');
    const identifier = document.getElementById('identifier');
    const password = document.getElementById('password');
    const forgotBtn = document.getElementById('forgot-btn');
    const resetHint = document.getElementById('reset-hint');
    let submitted = false;

    // Check seller username and password
function validate() {
  const errors = {};

  if (identifier.value.trim() !== 'seller') {
    errors.identifier = 'Incorrect username.';
  }

  if (password.value !== 'seller123') {
    errors.password = 'Incorrect password.';
  }

  return errors;
}

    function render() {
      const errors = submitted ? validate() : {};
      Auth.applyErrors(form, errors);
      return errors;
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      submitted = true;
      if (Object.keys(render()).length === 0) {
        window.location.href = 'seller_dashboard.html';
      }
    });

    form.addEventListener('input', function () { if (submitted) render(); });

    forgotBtn.addEventListener('click', function () {
      resetHint.hidden = !resetHint.hidden;
    });
  })();

}

/* ===== 08. SIGNUP SCREEN LOGIC ===== */
function init_signup() {
  (function () {
    const form = document.getElementById('signup-form');
    const terms = document.getElementById('terms');
    const termsWrap = document.getElementById('terms-wrap');
    const termsError = document.getElementById('terms-error');
    let submitted = false;

    function values() {
      return {
        fullName: document.getElementById('fullName').value,
        mobile: document.getElementById('mobile').value,
        email: document.getElementById('email').value,
        password: document.getElementById('password').value,
        confirm: document.getElementById('confirm').value,
      };
    }

    function validate() {
      const v = values();
      const errors = {};
      if (!isFullName(v.fullName)) errors.fullName = 'Enter your full name.';
      if (!isPhMobile(v.mobile)) errors.mobile = 'Enter a valid mobile number.';
      if (!isEmail(v.email)) errors.email = 'Enter a valid email address.';
      if (v.password.length < 8) errors.password = 'Use at least 8 characters.';
      if (!v.confirm || v.confirm !== v.password) errors.confirm = 'Passwords do not match.';
      if (!terms.checked) errors.terms = 'Agree to the Terms & Privacy Policies to continue.';
      return errors;
    }

    function render() {
      const errors = submitted ? validate() : {};
      Auth.applyErrors(form, errors);

      termsWrap.classList.toggle('is-invalid', Boolean(errors.terms));
      termsError.hidden = !errors.terms;
      termsError.textContent = errors.terms || '';
      if (errors.terms) {
        terms.setAttribute('aria-invalid', 'true');
        terms.setAttribute('aria-describedby', 'terms-error');
      } else {
        terms.removeAttribute('aria-invalid');
        terms.removeAttribute('aria-describedby');
      }
      return errors;
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      submitted = true;
      if (Object.keys(render()).length === 0) {
        window.location.href = 'seller_application.html';
      }
    });

    form.addEventListener('input', function () { if (submitted) render(); });
    terms.addEventListener('change', function () { if (submitted) render(); });
  })();

}

/* ===== 09. SHOP SCREEN LOGIC ===== */
function init_shop() {
  (function () {
    const form = document.getElementById('shop-form');
    const banner = document.getElementById('form-banner');
    const footerNote = document.getElementById('footer-note');
    const f = {
      shopName: document.getElementById('shopName'),
      fullName: document.getElementById('fullName'),
      phone: document.getElementById('phone'),
      region: document.getElementById('region'),
      province: document.getElementById('province'),
      city: document.getElementById('city'),
      barangay: document.getElementById('barangay'),
      postal: document.getElementById('postal'),
      address: document.getElementById('address'),
    };
    let submitted = false;

    function values() {
      const v = {};
      Object.keys(f).forEach(function (k) { v[k] = f[k].value; });
      return v;
    }

    function validate(v) {
      const e = {};
      if (!v.shopName.trim()) e.shopName = 'Enter your shop name.';
      if (v.fullName.trim().length < 2) e.fullName = 'Enter the pickup contact’s full name.';
      if (!isPhMobile(v.phone)) e.phone = 'Enter a valid Philippine mobile number.';
      if (!v.region) e.region = 'Select your region first.';
      if (!v.province) e.province = 'Select your province.';
      if (!v.city) e.city = 'Select your city or municipality.';
      if (!v.barangay) e.barangay = 'Select your barangay.';
      if (!/^\d{4}$/.test(v.postal)) e.postal = 'Enter a 4-digit postal code.';
      if (v.address.trim().length < 5) e.address = 'Enter the complete pickup address.';
      return e;
    }

    // ----- dependent selects: region -> province -> city -> barangay / postal -----
    function syncLocation() {
      const v = values();
      const city = getCity(v.region, v.province, v.city);
      Forms.fillSelect(f.region, REGIONS.map(function (r) { return r.name; }), 'Select region');
      Forms.fillSelect(f.province, getProvinces(v.region).map(function (p) { return p.name; }), 'Select province');
      Forms.fillSelect(f.city, getCities(v.region, v.province).map(function (c) { return c.name; }), 'Select city / municipality');
      Forms.fillSelect(f.barangay, city ? city.barangays : [], 'Select barangay');
      Forms.fillSelect(f.postal, city ? [city.postal] : [], 'Select postal code');
      f.province.disabled = !f.region.value;
      f.city.disabled = !f.province.value;
      f.barangay.disabled = !f.city.value;
      f.postal.disabled = !f.city.value;
    }

    f.region.addEventListener('change', function () {
      f.province.value = ''; f.city.value = ''; f.barangay.value = ''; f.postal.value = '';
      syncLocation();
    });
    f.province.addEventListener('change', function () {
      f.city.value = ''; f.barangay.value = ''; f.postal.value = '';
      syncLocation();
    });
    f.city.addEventListener('change', function () {
      f.barangay.value = '';
      const city = getCity(f.region.value, f.province.value, f.city.value);
      syncLocation();
      f.postal.value = city ? city.postal : '';
    });

    Forms.bindPhone(f.phone);

    function render() {
      const errors = submitted ? validate(values()) : {};
      const count = Object.keys(errors).length;
      Forms.applyErrors(form, errors);

      if (count > 0) {
        Forms.showBanner(
          banner,
          'We couldn’t continue yet',
          'Complete the ' + count + ' highlighted field' + (count === 1 ? '' : 's') + ', then continue to Business information.'
        );
        footerNote.textContent = 'Correct the highlighted fields to continue.';
      } else {
        banner.hidden = true;
        footerNote.textContent = 'You can review your details before submitting.';
      }
      return errors;
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      submitted = true;
      if (Object.keys(render()).length === 0) {
        window.location.href = 'seller_business_information.html';
        return;
      }
      Forms.focusBanner(banner);
    });

    form.addEventListener('input', function () { if (submitted) render(); });
    form.addEventListener('change', function () { if (submitted) render(); });

    syncLocation();
  })();

}

/* ===== 10. BUSINESS SCREEN LOGIC ===== */
function init_business() {
  (function () {
    const DOCUMENT_TYPES = ['DTI Certificate of Business Name Registration', 'Mayor’s / Business Permit', 'Barangay Business Clearance'];
    const ID_TYPES = ['Philippine National ID', 'Passport', 'Driver’s License', 'UMID', 'PRC ID', 'Postal ID'];
    const SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV'];

    // Error groups, used for the "Review N items" breakdown in the banner
    const GROUPS = [
      { label: 'Entity details', keys: ['lastName', 'firstName', 'businessName', 'region', 'province', 'city', 'barangay', 'address', 'zip'] },
      { label: 'Documents', keys: ['docType', 'idType', 'primaryDoc', 'idFront'] },
      { label: 'Contact verification', keys: ['email', 'phone', 'emailOtp', 'phoneOtp'] },
      { label: 'Tax details', keys: ['tin', 'birCert'] },
      { label: 'Agreement', keys: ['agree'] },
    ];

    const form = document.getElementById('business-form');
    const banner = document.getElementById('form-banner');
    const footerNote = document.getElementById('footer-note');
    const details = document.getElementById('business-details');
    const agree = document.getElementById('agree');
    const $ = (id) => document.getElementById(id);

    const textIds = [
      'lastName', 'firstName', 'middleName', 'suffix', 'businessName', 'region', 'province', 'city', 'barangay',
      'address', 'zip', 'docType', 'idType', 'email', 'phone', 'emailOtp', 'phoneOtp', 'tin',
    ];
    let submitted = false;
    const otpSent = { email: false, phone: false };

    // ----- static select options -----
    Forms.fillSelect($('suffix'), SUFFIXES, 'Select suffix');
    Forms.fillSelect($('docType'), DOCUMENT_TYPES, 'Select document type');
    Forms.fillSelect($('idType'), ID_TYPES, 'Select government ID type');

    // ----- uploads -----
    const uploads = {};
    ['primaryDoc', 'idFront', 'birCert', 'birSeal'].forEach(function (id) {
      uploads[id] = Forms.bindUpload(id, function () { if (submitted) render(); });
    });

    // ----- masks -----
    Forms.bindPhone($('phone'));
    Forms.bindDigits($('zip'), 4);
    Forms.bindDigits($('emailOtp'), 6);
    Forms.bindDigits($('phoneOtp'), 6);
    $('tin').addEventListener('input', function (e) { e.target.value = formatTin(e.target.value); });

    // ----- dependent location selects -----
    function syncLocation() {
      const region = $('region').value;
      const province = $('province').value;
      const city = getCity(region, province, $('city').value);
      Forms.fillSelect($('region'), REGIONS.map(function (r) { return r.name; }), 'Select region');
      Forms.fillSelect($('province'), getProvinces(region).map(function (p) { return p.name; }), 'Select province');
      Forms.fillSelect($('city'), getCities(region, province).map(function (c) { return c.name; }), 'Select municipality');
      Forms.fillSelect($('barangay'), city ? city.barangays : [], 'Select barangay');
      $('province').disabled = !$('region').value;
      $('city').disabled = !$('province').value;
      $('barangay').disabled = !$('city').value;
    }
    $('region').addEventListener('change', function () {
      $('province').value = ''; $('city').value = ''; $('barangay').value = '';
      syncLocation();
    });
    $('province').addEventListener('change', function () {
      $('city').value = ''; $('barangay').value = '';
      syncLocation();
    });
    $('city').addEventListener('change', function () {
      $('barangay').value = '';
      const city = getCity($('region').value, $('province').value, $('city').value);
      syncLocation();
      if (!$('zip').value && city) $('zip').value = city.postal; // only prefill when empty
    });

    // ----- OTP buttons -----
    function updateOtpButtons() {
      $('emailOtpBtn').disabled = !isEmail($('email').value);
      $('phoneOtpBtn').disabled = !isPhMobile($('phone').value);
    }
    function bindOtp(kind) {
      $(kind + 'OtpBtn').addEventListener('click', function () {
        otpSent[kind] = true;
        this.textContent = 'Resend';
        $(kind + '-note').hidden = false;
      });
    }
    bindOtp('email');
    bindOtp('phone');

    // ----- submission timing -----
    function isLater() { return Forms.selectedRadio(form, 'timing') === 'later'; }

    function values() {
      const v = {};
      textIds.forEach(function (id) { v[id] = $(id).value; });
      return v;
    }

    function validate() {
      const t = values();
      const e = {};
      const later = isLater();
      if (!agree.checked) e.agree = 'Confirm the details and accept the terms to submit.';
      if (later) return e;

      if (!t.lastName.trim()) e.lastName = 'Enter your last name.';
      if (!t.firstName.trim()) e.firstName = 'Enter your first name.';
      if (!t.businessName.trim()) e.businessName = 'Enter the name on your registration document.';
      if (!t.region) e.region = 'Select your region.';
      if (!t.province) e.province = 'Select your province.';
      if (!t.city) e.city = 'Select a municipality / city.';
      if (!t.barangay) e.barangay = 'Select your barangay.';
      if (t.address.trim().length < 5) e.address = 'Enter your registered business address.';
      if (!isZip(t.zip)) e.zip = 'Enter a 4-digit ZIP code.';
      if (!t.docType) e.docType = 'Select your registration document type.';
      if (!t.idType) e.idType = 'Select the ID type you will upload.';
      if (!uploads.primaryDoc.getFile()) e.primaryDoc = 'Upload your business registration document.';
      if (!uploads.idFront.getFile()) e.idFront = 'Upload the front of your government photo ID.';
      if (!isEmail(t.email)) e.email = 'Enter a valid business email address.';
      if (!isPhMobile(t.phone)) e.phone = 'Enter a valid Philippine mobile number.';
      if (!isOtp(t.emailOtp)) e.emailOtp = 'Enter the code sent to your email.';
      if (!isOtp(t.phoneOtp)) e.phoneOtp = 'Enter the code sent to your phone.';
      if (!isTin(t.tin)) e.tin = 'Enter your 9-digit TIN and branch code.';
      if (!uploads.birCert.getFile()) e.birCert = 'Upload your BIR Certificate of Registration.';
      return e;
    }

    function render() {
      details.hidden = isLater();
      updateOtpButtons();

      const errors = submitted ? validate() : {};
      const count = Object.keys(errors).length; // banner counts submit-time errors only

      // An upload's own type/size error shows under its field and takes priority over "required"
      const shown = Object.assign({}, errors);
      Object.keys(uploads).forEach(function (id) {
        const local = uploads[id].getLocalError();
        if (local) shown[id] = local;
      });
      Forms.applyErrors(form, shown);

      if (errors.agree) {
        agree.setAttribute('aria-invalid', 'true');
        agree.setAttribute('aria-describedby', 'agree-error');
      } else {
        agree.removeAttribute('aria-invalid');
        agree.removeAttribute('aria-describedby');
      }

      if (count > 0) {
        const breakdown = GROUPS
          .map(function (g) { return { label: g.label, count: g.keys.filter(function (k) { return errors[k]; }).length }; })
          .filter(function (g) { return g.count > 0; })
          .map(function (g) { return g.label + ' (' + g.count + ')'; })
          .join(' · ');
        Forms.showBanner(
          banner,
          'Review ' + count + ' item' + (count === 1 ? '' : 's') + ' before submitting',
          '<p>Complete the highlighted fields, uploads and agreement below.</p><p class="banner-breakdown">' + escapeHtml(breakdown) + '</p>'
        );
        footerNote.textContent = 'Resolve the highlighted items before submitting.';
      } else {
        banner.hidden = true;
        footerNote.textContent = 'Review your information before you submit.';
      }
      return errors;
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      submitted = true;
      if (Object.keys(render()).length === 0) {
        window.location.href = 'seller_dashboard.html';
        return;
      }
      Forms.focusBanner(banner);
    });

    form.addEventListener('input', function () { if (submitted) render(); else updateOtpButtons(); });
    form.addEventListener('change', render);

    syncLocation();
    render();
  })();

}

/* ===== 11. SELLER SCREEN LOGIC ===== */
function init_seller() {
  /* ==========================================================================
     Seller pages: dashboard, orders, products, store settings.
     Each page has a <body data-page="..."> and only its own section runs.
     ========================================================================== */
  (function () {
    const page = document.body.dataset.page;

    function pill(status) {
      return '<span class="pill ' + escapeHtml(status.toLowerCase()) + '">' + escapeHtml(status) + '</span>';
    }

    // Wire up a group of filter buttons: calls onChange(value) when one is chosen.
    function bindTabs(group, onChange) {
      group.addEventListener('click', function (e) {
        const btn = e.target.closest('button[data-value]');
        if (!btn) return;
        group.querySelectorAll('button').forEach(function (b) {
          b.setAttribute('aria-pressed', String(b === btn));
        });
        onChange(btn.dataset.value);
      });
    }

    /* ---------------- Dashboard ---------------- */
    function initDashboard() {
      const totalSale = ORDERS.filter(function (o) { return o.status !== 'Cancelled'; })
        .reduce(function (sum, o) { return sum + o.total; }, 0);
      const lowStock = PRODUCTS.filter(function (p) { return p.stock <= p.reorderLevel; });
      const stats = [
        { label: 'Total sale', value: peso(totalSale), icon: 'wallet' },
        { label: 'Total orders', value: ORDERS.length, icon: 'receipt-text' },
        { label: 'Pending Orders', value: ORDERS.filter(function (o) { return o.status === 'Pending'; }).length, icon: 'box' },
        { label: 'Low/ Out of stock', value: lowStock.length, icon: 'triangle-alert' },
      ];

      document.getElementById('stats').innerHTML = stats.map(function (s) {
        return (
          '<section class="panel p-4"><div class="stat-head">' +
          '<span class="stat-icon">' + icon(s.icon) + '</span>' +
          '<p class="stat-label">' + s.label + '</p></div>' +
          '<p class="stat-value">' + s.value + '</p></section>'
        );
      }).join('');

      document.getElementById('recent-orders').innerHTML = ORDERS.map(function (o) {
        return (
          '<tr><td>' + escapeHtml(o.id) + '</td><td>' + escapeHtml(o.buyer) + '</td><td>' +
          peso(o.total) + '</td><td>' + pill(o.status) + '</td></tr>'
        );
      }).join('');

      document.getElementById('stock-list').innerHTML = lowStock.map(function (p) {
        return (
          '<li>' + escapeHtml(p.name) + '<span class="stock-badge">' +
          (p.stock === 0 ? 'Out of stock' : p.stock + ' left') + '</span></li>'
        );
      }).join('');
    }

    /* ---------------- Orders ---------------- */
    function initOrders() {
      const valid = ORDERS.filter(function (o) { return o.status !== 'Cancelled'; });
      document.getElementById('valid-label').textContent = 'Total amount of orders (' + valid.length + ' valid)';
      document.getElementById('valid-total').textContent = peso(valid.reduce(function (s, o) { return s + o.total; }, 0));

      const body = document.getElementById('orders-body');
      const empty = document.getElementById('orders-empty');
      const count = document.getElementById('orders-count');
      const search = document.getElementById('orders-search');
      let filter = 'All';

      function render() {
        const q = search.value.trim().toLowerCase();
        const rows = ORDERS.filter(function (o) {
          return (filter === 'All' || o.status === filter) && (!q || (o.id + ' ' + o.buyer).toLowerCase().indexOf(q) >= 0);
        });
        body.innerHTML = rows.map(function (o) {
          return (
            '<tr><td>' + escapeHtml(o.id) + '</td><td>' + escapeHtml(o.date) + '</td><td>' + escapeHtml(o.buyer) +
            '</td><td>' + o.items + '</td><td>' + peso(o.total) + '</td><td>' + pill(o.status) + '</td></tr>'
          );
        }).join('');
        empty.hidden = rows.length > 0;
        count.textContent = rows.length + ' valid orders';
      }

      bindTabs(document.getElementById('orders-tabs'), function (v) { filter = v; render(); });
      search.addEventListener('input', render);
      render();
    }

    /* ---------------- Products ---------------- */
    function initProducts() {
      const body = document.getElementById('products-body');
      const empty = document.getElementById('products-empty');
      const count = document.getElementById('products-count');
      const search = document.getElementById('products-search');
      const tabs = document.getElementById('products-tabs');
      let filter = 'All';

      function countOf(status) { return PRODUCTS.filter(function (p) { return p.status === status; }).length; }
      tabs.querySelector('[data-value="All"]').textContent = 'All products (' + PRODUCTS.length + ')';
      tabs.querySelector('[data-value="Active"]').textContent = 'Active (' + countOf('Active') + ')';
      tabs.querySelector('[data-value="Draft"]').textContent = 'Draft (' + countOf('Draft') + ')';

      function render() {
        const q = search.value.trim().toLowerCase();
        const rows = PRODUCTS.filter(function (p) {
          return (filter === 'All' || p.status === filter) && (!q || (p.sku + ' ' + p.name).toLowerCase().indexOf(q) >= 0);
        });
        body.innerHTML = rows.map(function (p) {
          return (
            '<tr><td>' + escapeHtml(p.sku) + '</td><td>' + escapeHtml(p.name) + '</td><td>' + escapeHtml(p.description) +
            '</td><td>' + escapeHtml(p.category) + '</td><td>' + peso(p.price) + '</td><td>' + p.stock +
            '</td><td>' + p.reorderLevel + '</td><td>' + p.reorderQty + '</td><td>' + pill(p.status) +
            '</td><td><button type="button" class="edit-btn">Edit<span class="sr-only"> ' + escapeHtml(p.name) + '</span></button></td></tr>'
          );
        }).join('');
        empty.hidden = rows.length > 0;
        count.textContent = rows.length + ' products in your catalog';
      }

      bindTabs(tabs, function (v) { filter = v; render(); });
      search.addEventListener('input', render);
      render();
    }

    /* ---------------- Store settings ---------------- */
    function initStore() {
      const form = document.getElementById('store-form');
      const status = document.getElementById('save-status');
      const $ = function (id) { return document.getElementById(id); };
      let submitted = false;

      Forms.fillSelect($('region'), REGIONS.map(function (r) { return r.name; }), 'Choose your region');
      Forms.fillSelect($('status'), ['Open', 'Closed', 'On vacation'], 'Select status');
      $('status').value = 'Open';

      function validate() {
        const e = {};
        if (!/^[A-Za-z0-9 '&.-]{2,}$/.test($('name').value.trim())) e.name = 'Invalid characters';
        if (!$('region').value) e.region = 'Select a region';
        if (!isEmail($('email').value)) e.email = 'Email must contain @';
        if (!/^\+?\d{10,13}$/.test($('phone').value.replace(/\s/g, ''))) e.phone = 'Must contain only numbers';
        const fee = $('fee').value;
        if (fee && !/^\d+(\.\d{1,2})?$/.test(fee)) e.fee = 'Enter an amount, e.g. 50';
        return e;
      }

      function render() {
        const errors = submitted ? validate() : {};
        Forms.applyErrors(form, errors);
        return errors;
      }

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        submitted = true;
        const ok = Object.keys(render()).length === 0;
        status.textContent = ok ? 'Your shop details were saved.' : '';
      });

      form.addEventListener('input', function () {
        status.textContent = '';
        if (submitted) render();
      });
      form.addEventListener('change', function () {
        status.textContent = '';
        if (submitted) render();
      });
    }

    if (page === 'dashboard') initDashboard();
    if (page === 'orders') initOrders();
    if (page === 'products') initProducts();
    if (page === 'store') initStore();
  })();

}

/* ===== 12. PRODUCT SCREEN LOGIC ===== */
function init_product() {
  /* ==========================================================================
     Add product page: photos, options, validation, save/publish, notification.
     ========================================================================== */
  (function () {
    if (document.body.dataset.page !== 'product-new') return;

    const $ = function (id) { return document.getElementById(id); };
    const form = $('product-form');

    const MAX_PHOTOS = 4; // slot 0 = cover, slots 1-3 = photo variations
    const MAX_OPTIONS = 4;
    const MAX_BYTES = 5 * 1024 * 1024;
    const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];
    const CATEGORIES = ['Food', 'Fashion', 'Home & Living', 'Beauty & Care', 'Electronics', 'Crafts & Hobbies', 'Health', 'Toys & Kids', 'Other'];
    const PREFS = [
      { key: 'ratings', label: 'Allow ratings', on: true },
      { key: 'stock', label: 'Show stock left', on: false },
      { key: 'comments', label: 'With comments', on: true },
      { key: 'media', label: 'With media', on: true },
    ];

    const state = {
      photos: new Array(MAX_PHOTOS).fill(null), // { url }
      active: 0,
      options: [{ name: 'Single item', desc: 'Choose the individual item.', price: '', stock: 1 }],
      selected: 0,
      prefs: PREFS.map(function (p) { return p.on; }),
      photoError: '',
      submitted: false,
      submitMode: 'Active',
    };

    /* ---------- helpers ---------- */
    function fmtPrice(n) {
      return '₱' + (Number.isInteger(n) ? n.toLocaleString('en-PH') : n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    }
    function validPrice(v) { return /^\d+(\.\d{1,2})?$/.test(String(v).trim()) && parseFloat(v) > 0; }
    function prices() {
      return state.options.filter(function (o) { return validPrice(o.price); }).map(function (o) { return parseFloat(o.price); });
    }
    function priceText() {
      const p = prices();
      if (!p.length) return '₱0';
      const lo = Math.min.apply(null, p);
      const hi = Math.max.apply(null, p);
      return lo === hi ? fmtPrice(lo) : fmtPrice(lo) + ' – ' + fmtPrice(hi);
    }
    function coverUrl() {
      const first = state.photos.find(Boolean);
      return state.photos[0] ? state.photos[0].url : first ? first.url : '';
    }
    function imgOrIcon(url) {
      return url ? '<img src="' + url + '" alt="">' : icon('image-plus');
    }
    function productName() { return $('name').value.trim(); }

    /* ---------- Product name field grows with its text, like the heading it mirrors ---------- */
    const nameInput = $('name');
    const measureCanvas = document.createElement('canvas').getContext('2d');
    function fitName() {
      const cs = getComputedStyle(nameInput);
      measureCanvas.font = cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily;
      const text = nameInput.value || nameInput.placeholder;
      nameInput.style.width = Math.ceil(measureCanvas.measureText(text).width) + 32 + 'px';
    }

    /* ---------- Photos ---------- */
    const stage = $('stage');
    const thumbs = $('thumbs');
    const photoInput = $('photo-input');
    let pickSlot = 0;

    function renderPhotos() {
      const cur = state.photos[state.active];
      stage.classList.toggle('is-empty', !cur);
      if (cur) {
        const tools = [
          '<button type="button" data-act="replace">Replace</button>',
          state.active > 0 ? '<button type="button" data-act="cover">Make cover</button>' : '',
          '<button type="button" data-act="remove">Remove</button>',
        ].join('');
        stage.innerHTML = '<img src="' + cur.url + '" alt="' + (state.active === 0 ? 'Product cover' : 'Photo variation ' + state.active) + '">' +
          '<div class="pp-stage-tools">' + tools + '</div>';
      } else {
        stage.innerHTML =
          '<button type="button" class="pp-stage-empty" data-act="pick">' + icon('image-plus') +
          '<strong>' + (state.active === 0 ? 'Add product cover' : 'Add photo variation ' + state.active) + '</strong>' +
          '<span>Click to browse or drop an image here</span></button>';
      }

      thumbs.innerHTML = state.photos.map(function (p, i) {
        const label = (i === 0 ? 'Cover photo' : 'Photo variation ' + i) + (p ? '' : ' (empty, add a photo)');
        return (
          '<button type="button" class="pp-thumb' + (i === state.active ? ' is-active' : '') + (p ? '' : ' is-empty') + '"' +
          ' data-slot="' + i + '" aria-label="' + label + '"' + (i === state.active ? ' aria-current="true"' : '') + '>' +
          imgOrIcon(p && p.url) + (i === 0 ? '<span class="pp-thumb-badge">Cover</span>' : '') + '</button>'
        );
      }).join('');
      updatePreview();
    }

    function addFiles(files, startSlot) {
      const list = Array.prototype.slice.call(files || []);
      if (!list.length) return;
      let rejected = false;
      let first = -1;
      list.forEach(function (file) {
        if (ACCEPTED.indexOf(file.type) < 0 || file.size > MAX_BYTES) { rejected = true; return; }
        // The first good file goes where the seller clicked; extras fill the next empty slots.
        let slot = first < 0 ? startSlot : -1;
        if (first >= 0) {
          for (let i = 0; i < MAX_PHOTOS; i++) { if (!state.photos[i]) { slot = i; break; } }
        }
        if (slot < 0) return;
        if (state.photos[slot]) URL.revokeObjectURL(state.photos[slot].url);
        state.photos[slot] = { url: URL.createObjectURL(file) };
        if (first < 0) first = slot;
      });
      if (first >= 0) state.active = first;
      state.photoError = rejected ? 'Use a JPG, PNG or WEBP image up to 5 MB.' : '';
      renderPhotos();
      renderErrors();
      updateDerived();
    }

    stage.addEventListener('click', function (e) {
      const btn = e.target.closest('[data-act]');
      if (!btn) return;
      const act = btn.dataset.act;
      if (act === 'pick' || act === 'replace') { pickSlot = state.active; photoInput.value = ''; photoInput.click(); }
      if (act === 'remove') {
        URL.revokeObjectURL(state.photos[state.active].url);
        state.photos[state.active] = null;
        state.photoError = '';
        renderPhotos(); renderErrors(); updateDerived();
      }
      if (act === 'cover') {
        const a = state.active;
        const tmp = state.photos[0];
        state.photos[0] = state.photos[a];
        state.photos[a] = tmp;
        state.active = 0;
        renderPhotos(); renderErrors(); updateDerived();
      }
    });
    thumbs.addEventListener('click', function (e) {
      const btn = e.target.closest('[data-slot]');
      if (!btn) return;
      const i = Number(btn.dataset.slot);
      state.active = i;
      renderPhotos();
      if (!state.photos[i]) { pickSlot = i; photoInput.value = ''; photoInput.click(); }
    });
    photoInput.addEventListener('change', function () { addFiles(photoInput.files, pickSlot); });
    stage.addEventListener('dragover', function (e) { e.preventDefault(); stage.classList.add('is-dragging'); });
    stage.addEventListener('dragleave', function () { stage.classList.remove('is-dragging'); });
    stage.addEventListener('drop', function (e) {
      e.preventDefault();
      stage.classList.remove('is-dragging');
      addFiles(e.dataTransfer.files, state.active);
    });

    /* ---------- Options (variants) ---------- */
    const optionsEl = $('options');

    function renderOptions() {
      const canRemove = state.options.length > 1;
      optionsEl.innerHTML = state.options.map(function (o, i) {
        return (
          '<div class="pp-opt' + (i === state.selected ? ' is-selected' : '') + '" data-i="' + i + '">' +
          '<div class="pp-opt-head">' +
          '<div class="field" data-field="opt-' + i + '-name">' +
          '<label class="sr-only" for="opt-' + i + '-name">Option ' + (i + 1) + ' name</label>' +
          '<input class="pp-in pp-in-name" id="opt-' + i + '-name" data-key="name" maxlength="30" placeholder="e.g. Single item" value="' + escapeHtml(o.name) + '" autocomplete="off">' +
          '<p class="field-error" id="opt-' + i + '-name-error" hidden></p></div>' +
          '<span class="pp-opt-check" aria-hidden="true">' + icon('check') + '</span>' +
          (canRemove ? '<button type="button" class="pp-opt-remove" data-remove="' + i + '" aria-label="Remove option ' + (i + 1) + '">' + icon('x') + '</button>' : '') +
          '</div>' +
          '<div class="field"><label class="sr-only" for="opt-' + i + '-desc">Option ' + (i + 1) + ' description</label>' +
          '<input class="pp-in" id="opt-' + i + '-desc" data-key="desc" maxlength="60" placeholder="Short description" value="' + escapeHtml(o.desc) + '" autocomplete="off"></div>' +
          '<div class="field" data-field="opt-' + i + '-price">' +
          '<label class="sr-only" for="opt-' + i + '-price">Option ' + (i + 1) + ' price in pesos</label>' +
          '<div class="pp-price-wrap"><span class="pp-price-sign" aria-hidden="true">₱</span>' +
          '<input class="pp-in pp-in-price" id="opt-' + i + '-price" data-key="price" inputmode="decimal" maxlength="9" placeholder="0" value="' + escapeHtml(o.price) + '" autocomplete="off"></div>' +
          '<p class="field-error" id="opt-' + i + '-price-error" hidden></p></div>' +
          '<p class="pp-opt-stock" data-stock-for="' + i + '">Stock ' + o.stock + '</p>' +
          '</div>'
        );
      }).join('') + (state.options.length < MAX_OPTIONS
        ? '<button type="button" class="pp-opt-add" id="add-option">' + icon('plus') + 'Add option</button>' : '');
      renderErrors();
      updateDerived();
    }

    function selectOption(i) {
      if (i === state.selected) return;
      state.selected = i;
      optionsEl.querySelectorAll('.pp-opt').forEach(function (el) {
        el.classList.toggle('is-selected', Number(el.dataset.i) === i);
      });
      updateDerived();
    }

    optionsEl.addEventListener('input', function (e) {
      const input = e.target.closest('input[data-key]');
      if (!input) return;
      const i = Number(input.closest('.pp-opt').dataset.i);
      const key = input.dataset.key;
      if (key === 'price') input.value = input.value.replace(/[^\d.]/g, '').replace(/(\..*)\./g, '$1');
      state.options[i][key] = input.value;
      if (state.submitted) renderErrors();
      updateDerived();
    });
    optionsEl.addEventListener('focusin', function (e) {
      const card = e.target.closest('.pp-opt');
      if (card) selectOption(Number(card.dataset.i));
    });
    optionsEl.addEventListener('click', function (e) {
      const rm = e.target.closest('[data-remove]');
      if (rm) {
        const i = Number(rm.dataset.remove);
        state.options.splice(i, 1);
        state.selected = Math.min(state.selected > i ? state.selected - 1 : state.selected, state.options.length - 1);
        renderOptions();
        return;
      }
      if (e.target.closest('#add-option')) {
        state.options.push({ name: '', desc: '', price: '', stock: 1 });
        state.selected = state.options.length - 1;
        renderOptions();
        const input = $('opt-' + state.selected + '-name');
        if (input) input.focus();
        return;
      }
      const card = e.target.closest('.pp-opt');
      if (card) selectOption(Number(card.dataset.i));
    });

    /* ---------- Stock steppers (hero + variant panel edit the selected option) ---------- */
    function setStock(n) {
      const v = Math.max(0, Math.min(99999, n));
      state.options[state.selected].stock = v;
      updateDerived();
    }
    document.querySelectorAll('.pp-stepper').forEach(function (stepper) {
      stepper.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-step]');
        if (btn) setStock(state.options[state.selected].stock + Number(btn.dataset.step));
      });
      const input = stepper.querySelector('input');
      input.addEventListener('input', function () {
        input.value = input.value.replace(/\D/g, '').slice(0, 5);
        if (input.value !== '') setStock(parseInt(input.value, 10));
      });
      input.addEventListener('blur', function () { input.value = state.options[state.selected].stock; });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowUp') { e.preventDefault(); setStock(state.options[state.selected].stock + 1); }
        if (e.key === 'ArrowDown') { e.preventDefault(); setStock(state.options[state.selected].stock - 1); }
      });
    });

    /* ---------- Shopper settings + tags ---------- */
    function renderPrefs() {
      $('prefs').innerHTML = PREFS.map(function (p, i) {
        return '<button type="button" class="pp-chip" data-pref="' + i + '" aria-pressed="' + state.prefs[i] + '">' + p.label + '</button>';
      }).join('');
    }
    $('prefs').addEventListener('click', function (e) {
      const btn = e.target.closest('[data-pref]');
      if (!btn) return;
      const i = Number(btn.dataset.pref);
      state.prefs[i] = !state.prefs[i];
      btn.setAttribute('aria-pressed', String(state.prefs[i]));
    });
    function tags() {
      return ['tag-1', 'tag-2', 'tag-3'].map(function (id) { return $(id).value.trim(); }).filter(Boolean);
    }

    /* ---------- Live views ---------- */
    function checklist() {
      return [
        { label: 'Cover photo added', done: Boolean(state.photos[0]) },
        { label: 'Name and description', done: productName().length >= 2 && $('description').value.trim().length >= 20 },
        { label: 'Price on every option', done: state.options.every(function (o) { return validPrice(o.price); }) },
        { label: 'Category chosen', done: Boolean($('category').value) },
      ];
    }

    function updatePreview() {
      const url = coverUrl();
      $('preview-img').innerHTML = imgOrIcon(url);
    }

    function updateDerived() {
      const sel = state.options[state.selected];
      const name = productName();
      $('price-range').textContent = priceText();
      $('preview-price').textContent = priceText();
      $('preview-name').textContent = name || 'Product Name';
      $('variant-summary').textContent = state.options.length + (state.options.length === 1 ? ' option' : ' options') + ' · ' + (sel.name || 'Untitled');
      $('selected-line').textContent = 'Selected: ' + (sel.name || 'Untitled option') + ' · Stock ' + sel.stock;
      document.querySelectorAll('.pp-stock-input').forEach(function (inp) {
        if (document.activeElement !== inp) inp.value = sel.stock;
      });
      state.options.forEach(function (o, i) {
        const el = optionsEl.querySelector('[data-stock-for="' + i + '"]');
        if (el) el.textContent = 'Stock ' + o.stock;
      });
      $('tag-count').textContent = tags().length;
      $('description-count').textContent = $('description').value.length + '/400';
      $('checklist').innerHTML = checklist().map(function (c) {
        return '<li class="' + (c.done ? 'is-done' : '') + '">' + icon(c.done ? 'circle-check' : 'circle') + c.label + '</li>';
      }).join('');
      updatePreview();
    }

    /* ---------- Validation ---------- */
    const FIELD_LABELS = { name: 'Product name', cover: 'Product cover', description: 'Description', category: 'Category', reorderLevel: 'Reorder level', reorderQty: 'Reorder quantity' };

    function validate(mode) {
      const e = {};
      const name = productName();
      if (name.length < 2) e.name = 'Enter a product name (at least 2 characters).';
      if (mode === 'Draft') return e;

      if (!state.photos[0]) e.cover = 'Add a product cover photo.';
      if ($('description').value.trim().length < 20) e.description = 'Add a short description (at least 20 characters).';
      if (!$('category').value) e.category = 'Choose a category.';
      ['reorderLevel', 'reorderQty'].forEach(function (k) {
        const v = $(k).value.trim();
        if (v && !/^\d+$/.test(v)) e[k] = 'Use whole numbers only.';
      });
      const seen = {};
      state.options.forEach(function (o, i) {
        const n = o.name.trim();
        if (!n) e['opt-' + i + '-name'] = 'Name this option.';
        else if (seen[n.toLowerCase()]) e['opt-' + i + '-name'] = 'Option names must be different.';
        seen[n.toLowerCase()] = true;
        if (!validPrice(o.price)) e['opt-' + i + '-price'] = 'Enter a price, e.g. 128.';
      });
      return e;
    }

    let lastMode = 'Active';
    function renderErrors() {
      let errors = state.submitted ? validate(lastMode) : {};
      if (state.photoError) errors = Object.assign({}, errors, { cover: state.photoError });
      Forms.applyErrors(form, errors);
      if (state.submitted && !Object.keys(errors).length) $('form-banner').hidden = true;
      return errors;
    }

    function bannerList(errors) {
      const labels = Object.keys(errors).map(function (k) {
        const m = k.match(/^opt-(\d+)-(name|price)$/);
        if (m) return 'Option ' + (Number(m[1]) + 1) + ' ' + m[2];
        return FIELD_LABELS[k] || k;
      });
      return 'Please check: ' + labels.join(', ') + '.';
    }

    function firstErrorControl(errors) {
      const key = Object.keys(errors)[0];
      const field = form.querySelector('[data-field="' + key + '"]');
      if (!field) return null;
      return field.querySelector('input:not([type=file]), select, textarea') || field.querySelector('button');
    }

    /* ---------- Save / publish ---------- */
    function nextSku() {
      let max = 0;
      PRODUCTS.forEach(function (p) {
        const m = /(\d+)$/.exec(p.sku || '');
        if (m) max = Math.max(max, parseInt(m[1], 10));
      });
      return 'ABC' + String(max + 1).padStart(3, '0');
    }

    function buildRow(status) {
      const p = prices();
      const totalStock = state.options.reduce(function (s, o) { return s + (o.stock || 0); }, 0);
      const desc = $('description').value.trim();
      return {
        sku: $('sku').value.trim() || nextSku(),
        name: productName(),
        description: desc.length > 60 ? desc.slice(0, 57) + '…' : desc,
        category: $('category').value || 'Uncategorized',
        price: p.length ? Math.min.apply(null, p) : 0,
        stock: totalStock,
        reorderLevel: parseInt($('reorderLevel').value, 10) || 0,
        reorderQty: parseInt($('reorderQty').value, 10) || 0,
        status: status,
      };
    }

    function persist(row) {
      try {
        const saved = loadSavedProducts();
        saved.push(row);
        localStorage.setItem(SAVED_PRODUCTS_KEY, JSON.stringify(saved));
        return true;
      } catch (err) {
        return false;
      }
    }

    function submit(mode) {
      lastMode = mode;
      state.submitted = true;
      const errors = renderErrors();
      const banner = $('form-banner');
      if (Object.keys(errors).length) {
        Forms.showBanner(banner, mode === 'Draft' ? 'Add a name to save your draft' : 'A few details still need you, Sis', bannerList(errors));
        Forms.focusBanner(banner);
        return;
      }
      banner.hidden = true;
      const row = buildRow(mode);
      const saved = persist(row);
      openNotify(row, mode, saved);
    }

    $('save-draft').addEventListener('click', function () { submit('Draft'); });
    form.addEventListener('submit', function (e) { e.preventDefault(); submit('Active'); });

    /* ---------- Notification ---------- */
    const notify = $('notify');
    const dialog = notify.querySelector('.pp-notify');
    let opener = null;

    function openNotify(row, mode, saved) {
      opener = document.activeElement;
      const live = mode === 'Active';
      $('notify-title').textContent = live ? 'Product added, Sis!' : 'Saved as draft, Sis!';
      $('notify-text').textContent = live
        ? 'Your listing is live. Shoppers can find it in your shop.'
        : 'Your draft is saved. Finish it and publish whenever you’re ready.';
      $('notify-name').textContent = row.name;
      $('notify-note').textContent = (saved ? 'Your product is in your catalog as ' : 'Not stored in this browser. Status: ') + row.status + '.';
      $('notify-thumb').innerHTML = imgOrIcon(coverUrl());
      notify.hidden = false;
      document.body.style.overflow = 'hidden';
      $('notify-view').focus();
    }
    function closeNotify() {
      notify.hidden = true;
      document.body.style.overflow = '';
      if (opener && opener.focus) opener.focus();
    }
    $('notify-close').addEventListener('click', closeNotify);
    notify.addEventListener('click', function (e) { if (e.target === notify) closeNotify(); });
    $('notify-another').addEventListener('click', function () { window.location.reload(); });
    notify.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { closeNotify(); return; }
      if (e.key !== 'Tab') return;
      const f = Array.prototype.slice.call(dialog.querySelectorAll('button, a[href]'));
      const first = f[0];
      const last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    /* ---------- Wire-up ---------- */
    $('jump-variants').addEventListener('click', function () {
      const el = $('variants');
      el.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
      const first = $('opt-' + state.selected + '-name');
      if (first) first.focus({ preventScroll: true });
    });

    Forms.fillSelect($('category'), CATEGORIES, 'Choose a category');
    Forms.bindDigits($('reorderLevel'), 6);
    Forms.bindDigits($('reorderQty'), 6);

    form.addEventListener('input', function (e) {
      if (e.target === nameInput) fitName();
      if (state.submitted && !e.target.closest('#options')) renderErrors();
      if (!e.target.closest('#options')) updateDerived();
    });
    form.addEventListener('change', function () {
      if (state.submitted) renderErrors();
      updateDerived();
    });

    renderPhotos();
    renderOptions();
    renderPrefs();
    hydrateIcons();
    fitName();
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitName);
  })();

}

/* ===== 13. PAGE INITIALIZATION ===== */
// Each HTML page declares its own data-page attribute.
const sellerInitializers = { 'login': init_login, 'signup': init_signup, 'onboarding-shop': init_shop, 'onboarding-business': init_business, 'dashboard': init_seller, 'store': init_seller, 'products': init_seller, 'orders': init_seller, 'product-new': init_product };
function initSellerPage() {
  const page = document.body.dataset.page;
  refreshProducts();
  Layout.init();
  if (sellerInitializers[page]) sellerInitializers[page]();
}
initSellerPage();

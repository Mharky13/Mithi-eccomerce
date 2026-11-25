/* ======================= PART 3: PAYMENT PAGE ======================= */
function setView(viewName) {
  document.querySelectorAll('[data-view]').forEach(sec => {
    sec.hidden = sec.dataset.view !== viewName;
  });
  document.querySelectorAll('[data-view-tab]').forEach(tab => {
    tab.classList.toggle('is-active', tab.dataset.viewTab === viewName);
  });
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.addEventListener('click', e => {
  const tab = e.target.closest('[data-view-tab]');
  if (tab) {
    setView(tab.dataset.viewTab);
    return;
  }
  const go = e.target.closest('[data-go]');
  if (go) {
    e.preventDefault();
    setView(go.dataset.go);
  }
});

let selectedMethod = 'gcash';
let voucherApplied = false;

function updateBillingTotal() {
  const itemPrice = 30;
  const itemDiscount = 10;
  const gcashPromo = selectedMethod === 'gcash' ? 10 : 0;
  const voucherDisc = voucherApplied ? 10 : 0;
  const shipping = 50;

  const promoRow = $('promoRow');
  const voucherRow = $('voucherDiscountRow');
  const totalEl = $('billingTotalAmount');

  if (promoRow) promoRow.hidden = selectedMethod !== 'gcash';
  if (voucherRow) voucherRow.hidden = !voucherApplied;

  const total = Math.max(0, itemPrice - itemDiscount - gcashPromo - voucherDisc + shipping);
  if (totalEl) totalEl.textContent = `₱${total.toFixed(2)}`;
}

document.querySelectorAll('.payment-option').forEach(btn => {
  btn.addEventListener('click', () => {
    selectedMethod = btn.dataset.method;
    document.querySelectorAll('.payment-option').forEach(opt => {
      const active = opt === btn;
      opt.classList.toggle('is-selected', active);
      opt.setAttribute('aria-checked', String(active));
    });
    updateBillingTotal();
    toast(`Selected ${selectedMethod === 'gcash' ? 'GCash' : 'Cash on Delivery'}`);
  });
});

$('voucherForm1')?.addEventListener('submit', e => {
  e.preventDefault();
  const code = $('voucherInput1').value.trim();
  if (!code) {
    toast('Enter a voucher code first');
    return;
  }
  voucherApplied = true;
  updateBillingTotal();
  toast(`Voucher "${code.toUpperCase()}" applied (-₱10.00)`);
});

$('voucherForm2')?.addEventListener('submit', e => {
  e.preventDefault();
  const code = $('voucherInput2').value.trim();
  if (!code) {
    toast('Enter a voucher code first');
    return;
  }
  $('failedTotalAmount').textContent = '₱135.99';
  toast(`Voucher "${code.toUpperCase()}" applied`);
});

$('selectVoucherBtn')?.addEventListener('click', () => {
  $('voucherInput2').value = 'MITHI10';
  $('failedTotalAmount').textContent = '₱135.99';
  toast('Selected voucher MITHI10');
});

$('placeOrderBtn')?.addEventListener('click', () => {
  const failureChance = 0.20;

  if (Math.random() < failureChance) {
    setView('failed');
    toast('Payment failed. Please try again.');
  } else {
    setView('confirmed');
    toast('Salamat, Sis! Order placed.');
  }
});

$('simulateFailBtn')?.addEventListener('click', () => {
  setView('failed');
});

$('retryPaymentBtn')?.addEventListener('click', () => {
  setView('billing');
});

$('chooseAnotherBtn')?.addEventListener('click', () => {
  setView('billing');
  toast('Choose GCash or Cash on Delivery');
});

/* ================= DELIVERY INFORMATION ================= */

document.addEventListener("DOMContentLoaded", function () {

  const savedAddress = sessionStorage.getItem("mithiDeliveryAddress");

  if (!savedAddress) {
    document.getElementById("display-full-name").textContent =
      "No delivery address provided.";
    return;
  }

  const address = JSON.parse(savedAddress);

  // Full name
  document.getElementById("display-full-name").textContent =
    address.fullName;

  // Phone number
  document.getElementById("display-phone").textContent =
    address.phone;

  // Street address
  document.getElementById("display-street").textContent =
    address.street;

  // Barangay, city and province
  document.getElementById("display-location").textContent =
    [address.barangay, address.city, address.province]
      .filter(value => value && value !== "Not Applicable")
      .join(", ");

  // Region and postal code
  document.getElementById("display-postal-code").textContent =
    `${address.region}, ${address.postalCode}`;

  // Address label
  document.getElementById("display-address-label").textContent =
    address.label ? `Address Type: ${address.label}` : "";

});

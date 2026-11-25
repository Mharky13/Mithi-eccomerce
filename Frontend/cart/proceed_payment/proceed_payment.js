/* ======================= PART 3: PROCEED PAYMENT PAGE ======================= */
(function () {
  "use strict";

  var MIN_QTY = 1;
  var MAX_QTY = 99;
  var ERROR_DELAY_MS = 700;

  function formatPeso(value) {
    return "\u20B1" + value.toFixed(2);
  }

  function updateItem(item, delta) {
    var price = parseFloat(item.dataset.price);
    var valueEl = item.querySelector(".qty__value");
    var subtotalEl = item.querySelector(".cart-item__subtotal-value");
    var current = parseInt(valueEl.textContent, 10);
    var next = Math.min(MAX_QTY, Math.max(MIN_QTY, current + delta));

    valueEl.textContent = next;
    subtotalEl.textContent = formatPeso(price * next);

    valueEl.classList.remove("animate-quantity-pop");
    void valueEl.offsetWidth;
    valueEl.classList.add("animate-quantity-pop");
  }

  function initCart() {
    var list = document.querySelector(".cart__list");
    if (!list) return;

    list.addEventListener("click", function (event) {
      var button = event.target.closest("[data-qty-change]");
      if (!button) return;
      var item = button.closest(".cart-item");
      updateItem(item, parseInt(button.dataset.qtyChange, 10));
    });
  }

  function initAlert() {
    var wrap = document.getElementById("payment-alert");
    var proceed = document.getElementById("proceed-btn");
    var retry = document.getElementById("retry-btn");
    if (!wrap) return;

    function setOpen(open) {
      wrap.classList.toggle("is-open", open);
      wrap.setAttribute("aria-hidden", String(!open));
      if (open) {
        wrap.removeAttribute("inert");
        wrap.scrollIntoView({ behavior: "smooth", block: "center" });
      } else {
        wrap.setAttribute("inert", "");
      }
    }

    setOpen(false);

    if (proceed) {
  proceed.addEventListener("click", function () {

    const form = document.getElementById("address-form");

    // Validate required fields
    if (!form.reportValidity()) return;

    // Get selected dropdown text
    function getSelectedText(id) {
      const select = document.getElementById(id);
      return select.options[select.selectedIndex].text;
    }

    // Check location selections
    const locations = ["region", "province", "city", "barangay"];

    for (const id of locations) {
      if (!document.getElementById(id).value) {
        alert("Please complete your delivery address.");
        return;
      }
    }

    // Collect delivery information
    const address = {
      fullName: document.getElementById("full-name").value.trim(),
      phone: document.getElementById("phone-number").value,
      region: getSelectedText("region"),
      province: getSelectedText("province"),
      city: getSelectedText("city"),
      barangay: getSelectedText("barangay"),
      postalCode: document.getElementById("postal-code").value,
      street: document.getElementById("street-address").value.trim(),
      isDefault: document.getElementById("default-address").checked,
      label: document.querySelector(
        'input[name="address-label"]:checked'
      )?.value || ""
    };

    // Save delivery information
    sessionStorage.setItem(
      "mithiDeliveryAddress",
      JSON.stringify(address)
    );

    // Redirect to payment page
    window.location.href = "../payment_method/payment_method.html";

  });
}
    if (retry) retry.addEventListener("click", function () { setOpen(false); });

    // Open the error alert on load when the URL is proceed_payment.html#payment-error
    function checkHash() {
      if (window.location.hash === "#payment-error") {
        window.setTimeout(function () { setOpen(true); }, ERROR_DELAY_MS);
      }
    }
    checkHash();
    window.addEventListener("hashchange", checkHash);
  }

  document.addEventListener("DOMContentLoaded", function () {
    initCart();
    initAlert();
  });
})();

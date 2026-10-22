// Shop: Moonbase embedded storefront (https://moonbase.sh/docs/storefronts/embedded/).
// Fill in ACCOUNT and PRODUCT_ID to open the shop. As long as they are empty, the page shows
// "Coming soon" and loads nothing from Moonbase.
//
// Before going live, in the Moonbase account:
//   1. add https://scotandmillfield.studio to the whitelisted domains (otherwise CORS errors),
//   2. switch the storefront mode so customers are sent to this website instead of the hosted portal.
(function () {
  "use strict";

  var ACCOUNT = "";      // e.g. "https://scotandmillfield.moonbase.sh"
  var PRODUCT_ID = "scot-millfield-forge-furnace";   // Moonbase product id of Forge : Furnace

  var add = document.querySelector("[data-shop-add]");
  var view = document.querySelector("[data-shop-view]");
  var price = document.querySelector("[data-shop-price]");
  var note = document.querySelector("[data-shop-note]");
  var cart = document.querySelector("[data-shop-cart]");
  if (!ACCOUNT || !PRODUCT_ID || !add) return;

  // Moonbase fills these elements with live values
  if (price) {
    price.textContent = "";
    price.setAttribute("data-moonbase-render", "product." + PRODUCT_ID + ".price");
  }

  var script = document.createElement("script");
  script.src = "https://assets.moonbase.sh/storefront/moonbase.js";
  script.onload = function () {
    var Moonbase = window.Moonbase;
    Moonbase.setup(ACCOUNT, {
      theme: {
        colors: { primary: "#6E8C91", background: "#131617" },
        fonts: { heading: "inherit", body: "inherit" }
      },
      cart: { quantity: "single" }
    });
    add.disabled = false;
    view.disabled = false;
    if (cart) cart.hidden = false;
    if (note) note.textContent = "Secure checkout by Moonbase. Instant download after purchase.";
    add.addEventListener("click", function () { Moonbase.add_to_cart({ product_id: PRODUCT_ID, quantity: 1 }); });
    view.addEventListener("click", function () { Moonbase.view_cart(); });
    if (cart) cart.addEventListener("click", function (e) { e.preventDefault(); Moonbase.view_cart(); });
  };
  document.head.appendChild(script);
})();

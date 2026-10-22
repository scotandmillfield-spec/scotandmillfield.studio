// Shop: Moonbase embedded storefront (https://moonbase.sh/docs/storefronts/embedded/).
// Privacy: like the Spotify player on the home page, nothing is loaded from Moonbase until the
// visitor clicks a shop button (or comes back from Moonbase's hosted checkout with ?mb_… in the URL).
// Without ACCOUNT and PRODUCT_ID the page shows "Coming soon".
//
// In the Moonbase account:
//   1. add https://scotandmillfield.studio to the whitelisted domains (otherwise CORS errors),
//   2. switch the storefront mode so customers are sent to this website instead of the hosted portal.
(function () {
  "use strict";

  var ACCOUNT = "https://scotandmillfield.moonbase.sh";
  var PRODUCT_ID = "scot-millfield-forge-furnace";   // Moonbase product id of Forge : Furnace

  var add = document.querySelector("[data-shop-add]");
  var view = document.querySelector("[data-shop-view]");
  var price = document.querySelector("[data-shop-price]");
  var note = document.querySelector("[data-shop-note]");
  var cart = document.querySelector("[data-shop-cart]");
  if (!ACCOUNT || !PRODUCT_ID || !add) return;

  var loading = null;
  function moonbase() {
    if (loading) return loading;
    loading = new Promise(function (resolve, reject) {
      if (price) {
        // Moonbase writes the live price into this element
        price.setAttribute("data-moonbase-render", "product." + PRODUCT_ID + ".price");
      }
      var script = document.createElement("script");
      script.src = "https://assets.moonbase.sh/storefront/moonbase.js";
      script.onload = function () {
        window.Moonbase.setup(ACCOUNT, {
          theme: {
            colors: { primary: "#6E8C91", background: "#131617" },
            fonts: { heading: "inherit", body: "inherit" }
          },
          cart: { quantity: "single" }
        });
        resolve(window.Moonbase);
      };
      script.onerror = function () {
        loading = null;
        if (note) note.textContent = "The shop could not be reached. Please try again in a moment.";
        reject(new Error("Moonbase not reachable"));
      };
      document.head.appendChild(script);
    });
    return loading;
  }

  function on(el, action) {
    if (!el) return;
    el.disabled = false;
    el.hidden = false;
    el.addEventListener("click", function (e) {
      e.preventDefault();
      moonbase().then(action, function () {});
    });
  }

  on(add, function (Moonbase) { Moonbase.add_to_cart({ product_id: PRODUCT_ID, quantity: 1 }); });
  on(view, function (Moonbase) { Moonbase.view_cart(); });
  on(cart, function (Moonbase) { Moonbase.view_cart(); });

  if (price) price.textContent = "Price in cart";
  if (note) note.innerHTML = 'Secure checkout by Moonbase. Clicking a shop button connects to Moonbase – see our <a href="../datenschutz.html#en">privacy policy</a>.';

  // Back from the hosted checkout (mobile): Moonbase must finish the order on this page
  if (/[?&]mb_/.test(location.search)) moonbase();
})();

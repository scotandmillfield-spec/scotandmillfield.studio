// Shop: Moonbase embedded storefront (https://moonbase.sh/docs/storefronts/embedded/).
// Privacy: like the Spotify player on the home page, nothing is loaded from Moonbase until the
// visitor clicks a shop button (or comes back from Moonbase's hosted checkout with ?mb_… in the URL).
//
// Every product tile carries its Moonbase product id: <article data-shop-product="scot-millfield-forge-ember">
// with [data-shop-price] and [data-shop-add] inside. [data-shop-view], [data-shop-cart] and
// [data-shop-note] are shared by all products.
//
// In the Moonbase account:
//   1. add https://scotandmillfield.studio and https://www.scotandmillfield.studio to the whitelisted
//      domains (otherwise CORS errors),
//   2. switch the storefront mode so customers are sent to this website instead of the hosted portal.
(function () {
  "use strict";

  var ACCOUNT = "https://scotandmillfield.moonbase.sh";

  var products = Array.prototype.slice.call(document.querySelectorAll("[data-shop-product]"));
  var views = document.querySelectorAll("[data-shop-view]");
  var cart = document.querySelector("[data-shop-cart]");
  var note = document.querySelector("[data-shop-note]");
  if (!ACCOUNT || !products.length) return;

  var loading = null;
  function moonbase() {
    if (loading) return loading;
    loading = new Promise(function (resolve, reject) {
      // Moonbase writes the live price of each product into its price element
      products.forEach(function (product) {
        var price = product.querySelector("[data-shop-price]");
        if (price) price.setAttribute("data-moonbase-render", "product." + product.getAttribute("data-shop-product") + ".price");
      });
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

  products.forEach(function (product) {
    var id = product.getAttribute("data-shop-product");
    var price = product.querySelector("[data-shop-price]");
    on(product.querySelector("[data-shop-add]"), function (Moonbase) { Moonbase.add_to_cart({ product_id: id, quantity: 1 }); });
    if (price) price.textContent = "Price in cart";
  });
  Array.prototype.forEach.call(views, function (view) { on(view, function (Moonbase) { Moonbase.view_cart(); }); });
  on(cart, function (Moonbase) { Moonbase.view_cart(); });

  if (note) note.innerHTML = 'Secure checkout by Moonbase. Clicking a shop button connects to Moonbase – see our <a href="../datenschutz.html#en">privacy policy</a>.';

  // Back from the hosted checkout (mobile) or from an email link: Moonbase must finish on this page
  if (/[?&]mb_/.test(location.search)) moonbase();
})();

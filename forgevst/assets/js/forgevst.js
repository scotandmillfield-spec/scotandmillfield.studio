// Links the numbered markers on the screenshots with their legend entries:
// hovering either side highlights both; tapping a marker on a small screen jumps to its entry.
(function () {
  "use strict";

  function partners(key) {
    return document.querySelectorAll('[data-spot="' + key + '"]');
  }

  function setActive(key, active) {
    partners(key).forEach(function (el) { el.classList.toggle("is-active", active); });
  }

  document.querySelectorAll("[data-spot]").forEach(function (el) {
    var key = el.getAttribute("data-spot");
    el.addEventListener("mouseenter", function () { setActive(key, true); });
    el.addEventListener("mouseleave", function () { setActive(key, false); });
    el.addEventListener("focus", function () { setActive(key, true); });
    el.addEventListener("blur", function () { setActive(key, false); });
  });

  var narrow = window.matchMedia("(max-width: 760px)");
  document.querySelectorAll("button.spot").forEach(function (spot) {
    spot.addEventListener("click", function () {
      if (!narrow.matches) return;
      var key = spot.getAttribute("data-spot");
      var entry = document.querySelector('li[data-spot="' + key + '"]');
      if (!entry) return;
      document.querySelectorAll(".legend li.is-active").forEach(function (li) { li.classList.remove("is-active"); });
      entry.classList.add("is-active");
      entry.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  });
})();

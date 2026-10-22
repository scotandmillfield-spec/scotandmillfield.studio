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

// Documentation as tabs: every section after #docs becomes a tab. Without JavaScript all
// sections stay visible one below the other. Links to a section (or to anything inside it,
// e.g. #fig-curves) open the matching tab.
(function () {
  "use strict";

  var head = document.getElementById("docs");
  var bar = head && head.querySelector("[data-tabs]");
  if (!bar) return;

  var panels = [];
  for (var el = head.nextElementSibling; el; el = el.nextElementSibling) {
    if (el.matches("section.section[id]")) panels.push(el);
  }
  if (!panels.length) return;

  var tabs = panels.map(function (panel, i) {
    var eyebrow = panel.querySelector(".eyebrow");
    var label = eyebrow ? eyebrow.textContent.split("/").pop().trim().replace(/^Tab /, "") : panel.id;
    var tab = document.createElement("button");
    tab.type = "button";
    tab.className = "tabs__tab";
    tab.id = "tab-" + panel.id;
    tab.setAttribute("role", "tab");
    tab.setAttribute("aria-controls", panel.id);
    tab.innerHTML = '<span class="tabs__no">' + String(i + 1).padStart(2, "0") + "</span>";
    tab.appendChild(document.createTextNode(label));
    tab.addEventListener("click", function () { show(i, true); });
    tab.addEventListener("keydown", function (e) {
      var next = e.key === "ArrowRight" ? i + 1 : e.key === "ArrowLeft" ? i - 1 : -1;
      if (next < 0 || next >= panels.length) return;
      e.preventDefault();
      show(next, true);
      tabs[next].focus();
    });
    bar.appendChild(tab);

    panel.setAttribute("role", "tabpanel");
    panel.setAttribute("aria-labelledby", tab.id);
    panel.classList.add("is-tabpanel");

    // "Next" link at the end of each tab
    if (i < panels.length - 1) {
      var nextPanel = panels[i + 1];
      var nextEyebrow = nextPanel.querySelector(".eyebrow");
      var nextLabel = nextEyebrow ? nextEyebrow.textContent.split("/").pop().trim().replace(/^Tab /, "") : nextPanel.id;
      var foot = document.createElement("div");
      foot.className = "wrap tabs__next";
      foot.innerHTML = '<a href="#' + nextPanel.id + '">Next: ' + nextLabel + " →</a>";
      panel.appendChild(foot);
    }
    return tab;
  });

  function show(index, scroll) {
    panels.forEach(function (panel, i) {
      var on = i === index;
      panel.hidden = !on;
      tabs[i].setAttribute("aria-selected", on ? "true" : "false");
      tabs[i].tabIndex = on ? 0 : -1;
    });
    // keep the active tab visible in the scrollable bar (small screens)
    var t = tabs[index];
    if (t.offsetLeft < bar.scrollLeft || t.offsetLeft + t.offsetWidth > bar.scrollLeft + bar.clientWidth) {
      bar.scrollLeft = t.offsetLeft - 24;
    }
    if (scroll) {
      history.replaceState(null, "", "#" + panels[index].id);
      if (bar.getBoundingClientRect().top < 0) head.scrollIntoView({ block: "end" });
    }
  }

  function fromHash() {
    var id = decodeURIComponent(location.hash.slice(1));
    if (!id) return false;
    var target = document.getElementById(id);
    if (!target) return false;
    for (var i = 0; i < panels.length; i++) {
      if (panels[i] === target || panels[i].contains(target)) {
        show(i, false);
        requestAnimationFrame(function () { target.scrollIntoView(); });
        return true;
      }
    }
    return false;
  }

  bar.hidden = false;
  if (!fromHash()) show(0, false);
  window.addEventListener("hashchange", fromHash);
})();

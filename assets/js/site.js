/* Scot & Millfield – Seitenlogik
 * Liest assets/js/data.js (window.SITE_DATA) und baut Releases, Hörerzahl und
 * den Live-Bereich auf. Der Spotify-Player wird erst nach Klick auf "Play"
 * geladen (Zwei-Klick-Lösung, vorher keine Verbindung zu Spotify).
 */
(function () {
  "use strict";

  var data = window.SITE_DATA || {};
  var releases = Array.isArray(data.releases) ? data.releases : [];

  /* ---------- Hilfsfunktionen ---------- */
  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = text;
    return node;
  }

  function formatDate(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || "");
    return m ? m[3] + "." + m[2] + "." + m[1] : (iso || "");
  }

  // open.spotify.com/{track|album|artist|playlist}/ID  ->  Embed-URL
  function embedUrl(link) {
    var fallback = data.spotifyArtist || "";
    var m = /open\.spotify\.com\/(?:intl-[a-z]{2}\/)?(track|album|artist|playlist)\/([A-Za-z0-9]+)/.exec(link || fallback);
    if (!m) return null;
    return "https://open.spotify.com/embed/" + m[1] + "/" + m[2] + "?utm_source=generator&theme=0";
  }

  var PLAY_ICON = '<svg width="10" height="10" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3.5v17l15-8.5z"></path></svg>';
  var CLOSE_ICON = '<svg width="10" height="10" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5h14v14H5z"></path></svg>';

  /* ---------- Releases ---------- */
  var grid = document.getElementById("release-grid");
  var openCard = null;

  function closePlayer(card) {
    if (!card) return;
    var player = card.querySelector(".player");
    if (player) player.remove();
    var btn = card.querySelector(".btn-play");
    btn.setAttribute("aria-expanded", "false");
    btn.innerHTML = PLAY_ICON + "<span>Play</span>";
    if (openCard === card) openCard = null;
  }

  function openPlayer(card, release) {
    if (openCard && openCard !== card) closePlayer(openCard);
    var src = embedUrl(release.spotify);
    if (!src) return;
    var player = el("div", "player");
    var frame = document.createElement("iframe");
    frame.src = src;
    frame.title = "Spotify player: " + release.title;
    frame.loading = "lazy";
    frame.allow = "autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture";
    player.appendChild(frame);
    card.appendChild(player);
    var btn = card.querySelector(".btn-play");
    btn.setAttribute("aria-expanded", "true");
    btn.innerHTML = CLOSE_ICON + "<span>Close</span>";
    openCard = card;
  }

  function renderRelease(release, index) {
    var card = el("article", "release");

    var cover = el("div", "release__cover");
    var img = document.createElement("img");
    img.src = release.cover;
    img.alt = "Cover: " + release.title;
    img.loading = "lazy";
    img.width = 500;
    img.height = 500;
    cover.appendChild(img);

    var text = el("div", "release__text");
    text.appendChild(el("span", "release__meta",
      "R." + String(index + 1).padStart(2, "0") + " · " + formatDate(release.date)));
    var title = el("h3", "release__title", release.title);
    title.title = release.title;
    text.appendChild(title);
    var artists = el("p", "release__artists", release.artists);
    artists.title = release.artists;
    text.appendChild(artists);
    text.appendChild(el("p", "release__label", release.label));

    var actions = el("div", "release__actions");
    var btn = el("button", "btn-play");
    btn.type = "button";
    btn.setAttribute("aria-expanded", "false");
    btn.innerHTML = PLAY_ICON + "<span>Play</span>";
    btn.addEventListener("click", function () {
      if (openCard === card) closePlayer(card);
      else openPlayer(card, release);
    });
    actions.appendChild(btn);

    if (release.beatport) {
      var bp = el("a", "release__beatport", "Beatport ↗");
      bp.href = release.beatport;
      bp.target = "_blank";
      bp.rel = "noopener";
      actions.appendChild(bp);
    }

    card.appendChild(cover);
    card.appendChild(text);
    card.appendChild(actions);
    return card;
  }

  if (grid && releases.length) {
    grid.textContent = "";
    releases.forEach(function (r, i) { grid.appendChild(renderRelease(r, i)); });
  }

  /* ---------- Status-Leiste: neuestes Release ---------- */
  var statusText = document.querySelector("[data-latest]");
  if (statusText && releases[0]) {
    statusText.textContent = 'Out now — "' + releases[0].title + '" · ' + releases[0].artists + " · Listen on Spotify";
  }

  /* ---------- Monatliche Hörer ---------- */
  if (data.monthlyListeners) {
    document.querySelectorAll("[data-listeners]").forEach(function (node) {
      node.textContent = data.monthlyListeners;
    });
  }

  /* ---------- Live-Bereich ---------- */
  document.querySelectorAll("[data-live]").forEach(function (node) {
    node.hidden = !data.showLive;
  });

  /* ---------- Jahr im Footer ---------- */
  document.querySelectorAll("[data-year]").forEach(function (node) {
    node.textContent = String(new Date().getFullYear());
  });

  /* ---------- Formulare (noch ohne Versanddienst) ---------- */
  document.querySelectorAll("[data-notify]").forEach(function (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var msg = form.querySelector("[data-notify-msg]");
      if (msg) msg.textContent = "Thanks – sign-up will open soon.";
    });
  });
})();

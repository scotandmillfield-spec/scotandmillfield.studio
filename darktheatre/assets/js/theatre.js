// Virage – Dark Theatre: Vorhang, ein gemeinsamer Audio-Player für alle Akte, Akt-Navigation.
(function () {
  const doc = document.documentElement;
  const body = document.body;
  const reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  // ---------- Vorhang ----------
  const curtain = document.querySelector(".curtain");
  let seen = false;
  try { seen = sessionStorage.getItem("dt-curtain") === "open"; } catch (e) {}
  function reveal() { body.classList.add("is-revealed"); }
  if (!curtain || seen || location.hash) {
    if (curtain) curtain.classList.add("is-gone");
    reveal();
  } else {
    body.classList.add("is-closed");
    const open = () => {
      curtain.classList.add("is-open");
      body.classList.remove("is-closed");
      reveal();
      try { sessionStorage.setItem("dt-curtain", "open"); } catch (e) {}
      setTimeout(() => curtain.classList.add("is-gone"), reduce ? 700 : 1900);
    };
    curtain.querySelector("[data-open]").addEventListener("click", open);
    document.addEventListener("keydown", (e) => { if (e.key === "Escape" && !curtain.classList.contains("is-open")) open(); });
  }

  // ---------- Player ----------
  const audio = new Audio();
  audio.preload = "none";
  let current = null;
  const fmt = (s) => (isFinite(s) ? Math.floor(s / 60) + ":" + String(Math.floor(s % 60)).padStart(2, "0") : "–:––");
  const players = [...document.querySelectorAll(".player")];

  players.forEach((p) => {
    const src = p.dataset.src;
    const btn = p.querySelector(".player__btn");
    const bar = p.querySelector(".player__bar");
    if (!src) { btn.disabled = true; return; }
    p.querySelector(".player__note")?.remove();
    btn.addEventListener("click", () => {
      if (current === p && !audio.paused) { audio.pause(); return; }
      if (current !== p) {
        if (current) reset(current);
        current = p;
        audio.src = src;
      }
      audio.play();
    });
    bar.addEventListener("click", (e) => {
      if (current !== p || !audio.duration) return;
      const r = bar.getBoundingClientRect();
      audio.currentTime = ((e.clientX - r.left) / r.width) * audio.duration;
    });
  });

  function reset(p) {
    p.classList.remove("is-playing", "is-ended");
    p.querySelector(".player__fill").style.width = "0";
  }
  audio.addEventListener("play", () => current && current.classList.add("is-playing"));
  audio.addEventListener("pause", () => current && current.classList.remove("is-playing"));
  audio.addEventListener("timeupdate", () => {
    if (!current) return;
    current.querySelector(".player__fill").style.width = (audio.currentTime / audio.duration) * 100 + "%";
    current.querySelector(".player__time").textContent = fmt(audio.currentTime) + " / " + fmt(audio.duration);
  });
  audio.addEventListener("ended", () => current && current.classList.add("is-ended"));

  // ---------- Akt-Navigation, Fortschritt, Einblenden ----------
  const nav = document.querySelector(".acts-nav");
  const links = nav ? [...nav.querySelectorAll("a")] : [];
  const acts = [...document.querySelectorAll(".act")];
  const progress = document.querySelector(".progress");
  if ("IntersectionObserver" in window) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (!en.isIntersecting) return;
        links.forEach((a) => a.classList.toggle("is-active", a.hash === "#" + en.target.id));
      });
    }, { rootMargin: "-45% 0px -50% 0px" });
    acts.forEach((a) => io.observe(a));

    const ro = new IntersectionObserver((entries) => {
      entries.forEach((en) => { if (en.isIntersecting) { en.target.classList.add("is-in"); ro.unobserve(en.target); } });
    }, { rootMargin: "0px 0px -10% 0px" });
    document.querySelectorAll(".reveal").forEach((el) => ro.observe(el));
  } else {
    document.querySelectorAll(".reveal").forEach((el) => el.classList.add("is-in"));
  }

  const first = acts[0];
  function onScroll() {
    const max = doc.scrollHeight - innerHeight;
    if (progress) progress.style.width = (max > 0 ? (scrollY / max) * 100 : 0) + "%";
    if (nav && first) nav.classList.toggle("is-visible", first.getBoundingClientRect().top < innerHeight * .6);
  }
  addEventListener("scroll", onScroll, { passive: true });
  onScroll();
})();

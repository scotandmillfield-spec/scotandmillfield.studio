// Virage – Dark Theatre: Hero-Zoom, ein gemeinsamer Audio-Player für alle Akte, Akt-Navigation.
(function () {
  const doc = document.documentElement;
  const body = document.body;

  // ---------- Hero: langsames Heranfahren nach dem Laden ----------
  const heroImg = document.querySelector(".hero img");
  const reveal = () => requestAnimationFrame(() => requestAnimationFrame(() => body.classList.add("is-revealed")));
  if (!heroImg || heroImg.complete) reveal(); else heroImg.addEventListener("load", reveal, { once: true });

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

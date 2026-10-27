// Rendert die Kanal-Banner aus content/brand/banner.html (Playwright mit Chromium).
// Aufruf: node content/brand/render-banners.js
const path = require("path");
const { chromium } = require("playwright");

const page = "file://" + path.join(__dirname, "banner.html");
const jobs = [
  // YouTube: 2560 × 1440, Text nur im sicheren Bereich 1546 × 423 (auf allen Geräten sichtbar)
  { file: "youtube-banner-2560x1440.jpg", w: 2560, h: 1440, cols: 16, pos: "center 50%", text: true },
  // Spotify Header: 2660 × 1140, ohne Text und Logos; Spotify legt den Künstlernamen unten links darüber
  { file: "spotify-header-2660x1140.jpg", w: 2660, h: 1140, cols: 14, pos: "center 58%", text: false },
];

(async () => {
  const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
  for (const j of jobs) {
    const p = await browser.newPage({ viewport: { width: j.w, height: j.h } });
    await p.goto(page);
    await p.evaluate((j) => {
      const r = document.documentElement.style;
      r.setProperty("--w", j.w + "px"); r.setProperty("--h", j.h + "px");
      r.setProperty("--pos", j.pos); r.setProperty("--cols", j.cols);
      const g = document.getElementById("grid"); g.innerHTML = "";
      for (let i = 0; i < j.cols; i++) g.appendChild(document.createElement("span"));
      if (!j.text) document.body.classList.add("no-text");
    }, j);
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(300);
    await p.screenshot({ path: path.join(__dirname, j.file), type: "jpeg", quality: 92 });
    console.log(j.file);
  }
  await browser.close();
})();

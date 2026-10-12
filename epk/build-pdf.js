// Erzeugt assets/press/scot-and-millfield-epk.pdf (Download auf der Website) aus epk/epk.html (A4, 2 Seiten).
// Aufruf: node epk/build-pdf.js   (benötigt Playwright mit Chromium)
const path = require("path");
const fs = require("fs");
const { chromium } = require("playwright");

(async () => {
  const dir = __dirname;
  const src = path.join(dir, "epk.html");
  // Marken-Icons aus der Startseite übernehmen, damit sie identisch bleiben
  const index = fs.readFileSync(path.join(dir, "..", "index.html"), "utf8");
  const sprite = index.match(/<svg width="0" height="0"[\s\S]*?<\/svg>/)[0];
  const html = fs.readFileSync(src, "utf8").replace("<!--SPRITE-->", sprite);
  const tmp = path.join(dir, ".epk-build.html");
  fs.writeFileSync(tmp, html);

  const browser = await chromium.launch(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {});
  const page = await browser.newPage();
  await page.goto("file://" + tmp, { waitUntil: "networkidle" });
  await page.evaluate(() => document.fonts.ready);
  await page.pdf({
    path: path.join(dir, "..", "assets", "press", "scot-and-millfield-epk.pdf"),
    format: "A4",
    printBackground: true,
    preferCSSPageSize: true,
  });
  await browser.close();
  fs.unlinkSync(tmp);
  console.log("assets/press/scot-and-millfield-epk.pdf erstellt");
})();

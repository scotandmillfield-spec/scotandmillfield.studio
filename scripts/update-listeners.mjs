// Liest die monatlichen Hörer von der öffentlichen Spotify-Künstlerseite und
// aktualisiert damit assets/js/data.js und den Fallback-Text in index.html.
// Aufruf: node scripts/update-listeners.mjs            (holt die Zahl von Spotify)
//         node scripts/update-listeners.mjs --html f   (liest eine gespeicherte Seite, zum Testen)
// Ausgabe für GitHub Actions: changed=true|false, listeners=139K
import { readFileSync, writeFileSync, appendFileSync } from "node:fs";

const ARTIST_URL = "https://open.spotify.com/artist/6LTgaNvQ4z07MLP5Fs3plh";
const DATA = "assets/js/data.js";
const PAGES = ["index.html", "impressum.html", "datenschutz.html"];

// "139.4K monthly listeners", "139,412 monthly listeners", "1.2M monthly listeners"
export function parseListeners(html) {
  const m = /([\d][\d.,]*)\s*([KkMm])?\s*monthly\s+listeners?/i.exec(html);
  if (!m) return null;
  let raw = m[1];
  const unit = (m[2] || "").toUpperCase();
  if (unit) raw = raw.replace(",", ".");          // 139.4K / 139,4K
  else raw = raw.replace(/[.,](?=\d{3}\b)/g, "");  // 139,412 / 139.412
  const n = parseFloat(raw) * (unit === "M" ? 1e6 : unit === "K" ? 1e3 : 1);
  return Number.isFinite(n) && n > 0 ? Math.round(n) : null;
}

// Gleiche Schreibweise wie bisher auf der Seite: 139K, 1.2M, 950
export function formatListeners(n) {
  if (n >= 1e6) return (Math.round(n / 1e5) / 10).toString().replace(/\.0$/, "") + "M";
  if (n >= 1e3) return Math.round(n / 1e3) + "K";
  return String(n);
}

const AGENTS = [
  // Vorschau-Crawler bekommen die Seite mit fertigen Meta-Tags („… monthly listeners“)
  "facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)",
  "Twitterbot/1.0",
  "Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)",
  "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36",
];

function describe(html) {
  const title = /<title[^>]*>([^<]*)/i.exec(html)?.[1];
  const og = /og:description"\s+content="([^"]*)/i.exec(html)?.[1];
  return `Länge ${html.length}, title=${JSON.stringify(title)}, og:description=${JSON.stringify(og)}`;
}

// Liefert die Hörerzahl oder null; probiert erst einfache Abrufe, dann einen Headless-Browser
async function readListeners() {
  for (const ua of AGENTS) {
    try {
      const res = await fetch(ARTIST_URL, { headers: { "User-Agent": ua, "Accept-Language": "en-US,en;q=0.9" } });
      const html = await res.text();
      const n = parseListeners(html);
      console.log(`[fetch ${ua.split(/[ /]/)[0]}] HTTP ${res.status}, ${describe(html)} → ${n ?? "keine Zahl"}`);
      if (n) return n;
    } catch (e) {
      console.log(`[fetch ${ua.split(/[ /]/)[0]}] Fehler: ${e.message}`);
    }
  }
  let chromium;
  try { ({ chromium } = await import("playwright")); } catch { console.log("[browser] Playwright nicht installiert"); return null; }
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage({ locale: "en-US" });
    await page.goto(ARTIST_URL, { waitUntil: "domcontentloaded", timeout: 45000 });
    await page.waitForFunction(() => /monthly\s+listeners?/i.test(document.body.innerText), null, { timeout: 30000 }).catch(() => {});
    const text = await page.evaluate(() => document.body.innerText);
    const n = parseListeners(text);
    console.log(`[browser] Textlänge ${text.length} → ${n ?? "keine Zahl"}`);
    return n;
  } finally {
    await browser.close();
  }
}

function output(key, value) {
  if (process.env.GITHUB_OUTPUT) appendFileSync(process.env.GITHUB_OUTPUT, `${key}=${value}\n`);
}

async function main() {
  const i = process.argv.indexOf("--html");
  const n = i > -1 ? parseListeners(readFileSync(process.argv[i + 1], "utf8")) : await readListeners();
  if (!n) throw new Error("Monatliche Hörer nicht auf der Spotify-Seite gefunden – Seitenaufbau geändert?");
  const value = formatListeners(n);
  const today = new Date().toISOString().slice(0, 10);

  let data = readFileSync(DATA, "utf8");
  const current = /monthlyListeners:\s*"([^"]*)"/.exec(data)?.[1];
  console.log(`Spotify: ${n} → ${value} (bisher ${current})`);
  output("listeners", value);
  if (current === value) { output("changed", "false"); return; }

  data = data
    .replace(/monthlyListeners:\s*"[^"]*"/, `monthlyListeners: "${value}"`)
    .replace(/monthlyListenersAsOf:\s*"[^"]*"/, `monthlyListenersAsOf: "${today}"`);
  writeFileSync(DATA, data);

  // Fallback ohne JavaScript + Cache-Version, damit Browser die neue data.js laden
  const version = today.replace(/-/g, "");
  for (const page of PAGES) {
    let s = readFileSync(page, "utf8");
    s = s.replace(/(<strong data-listeners>)[^<]*(<\/strong>)/g, `$1${value}$2`)
         .replace(/\?v=[\w-]+/g, `?v=${version}`);
    writeFileSync(page, s);
  }
  output("changed", "true");
}

if (import.meta.url === `file://${process.argv[1]}`) {
  main().catch((e) => { console.error(e.message); process.exit(1); });
}

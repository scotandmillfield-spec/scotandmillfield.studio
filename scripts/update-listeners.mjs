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
  const m = /([\d][\d.,]*)\s*([KkMm])?\s*monthly listeners/.exec(html);
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

async function fetchPage() {
  const res = await fetch(ARTIST_URL, {
    headers: {
      "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36",
      "Accept-Language": "en-US,en;q=0.9",
    },
  });
  if (!res.ok) throw new Error("Spotify antwortet mit HTTP " + res.status);
  return res.text();
}

function output(key, value) {
  if (process.env.GITHUB_OUTPUT) appendFileSync(process.env.GITHUB_OUTPUT, `${key}=${value}\n`);
}

async function main() {
  const i = process.argv.indexOf("--html");
  const html = i > -1 ? readFileSync(process.argv[i + 1], "utf8") : await fetchPage();
  const n = parseListeners(html);
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

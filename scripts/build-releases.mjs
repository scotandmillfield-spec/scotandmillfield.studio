// Schreibt die Release-Kacheln und die strukturierten Daten (JSON-LD) aus assets/js/data.js
// direkt in index.html – damit Suchmaschinen die Titel ohne JavaScript lesen.
// Läuft automatisch vor jedem Deploy; von Hand: node scripts/build-releases.mjs
import { readFileSync, writeFileSync } from "node:fs";
import vm from "node:vm";

const PAGE = "index.html";
const ctx = { window: {} };
vm.runInNewContext(readFileSync("assets/js/data.js", "utf8"), ctx);
const data = ctx.window.SITE_DATA;
const releases = data.releases.slice(0, data.showReleases || 4);

const esc = (s) => String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
const date = (iso) => iso.split("-").reverse().join(".");

// Gleiche Struktur wie renderRelease() in assets/js/site.js
const cards = releases.map((r, i) => `        <article class="release">
          <div class="release__cover"><img src="${esc(r.cover)}" alt="Cover: ${esc(r.title)}" loading="lazy" width="500" height="500"></div>
          <div class="release__text">
            <span class="release__meta">R.${String(i + 1).padStart(2, "0")} · ${date(r.date)}</span>
            <h3 class="release__title" title="${esc(r.title)}">${esc(r.title)}</h3>
            <p class="release__label">${esc(r.label)}</p>
          </div>
          <div class="release__actions">${r.beatport ? `<a class="release__beatport" href="${esc(r.beatport)}" title="Beatport" aria-label="${esc(r.title)} on Beatport" target="_blank" rel="noopener"><svg class="icon" aria-hidden="true"><use href="#i-beatport"></use></svg></a>` : ""}</div>
        </article>`).join("\n");

const ld = {
  "@context": "https://schema.org",
  "@type": "MusicGroup",
  name: "Scot & Millfield",
  url: "https://scotandmillfield.studio/",
  image: "https://scotandmillfield.studio/assets/img/og-image.jpg",
  logo: "https://scotandmillfield.studio/assets/img/icon-512.png",
  description: "Balearic Deep House, forged in the Ruhr, crafted from coal and steel. Daniel Lyall and Maik Drozdzynski from Mülheim an der Ruhr, releasing on The Night Drive, Armada, Black Hole, Enhanced and Harthouse.",
  genre: ["Balearic Deep House", "Deep House", "Melodic House", "Trance"],
  foundingDate: "1999",
  foundingLocation: { "@type": "Place", name: "Mülheim an der Ruhr, Germany" },
  member: [
    { "@type": "OrganizationRole", roleName: "Producer", member: { "@type": "Person", name: "Daniel Lyall" } },
    { "@type": "OrganizationRole", roleName: "Producer", member: { "@type": "Person", name: "Maik Drozdzynski" } },
  ],
  sameAs: [
    "https://open.spotify.com/artist/6LTgaNvQ4z07MLP5Fs3plh",
    "https://instagram.com/scotandmillfield",
    "https://www.beatport.com/artist/scot-millfield/293357",
    "https://soundcloud.com/scotandmillfield",
  ],
  track: releases.map((r) => ({
    "@type": "MusicRecording",
    name: r.title,
    byArtist: { "@type": "MusicGroup", name: r.artists },
    datePublished: r.date,
    ...(r.beatport ? { url: r.beatport } : {}),
  })),
};
const jsonld = `<script type="application/ld+json">\n${JSON.stringify(ld, null, 2)}\n</script>`;

let html = readFileSync(PAGE, "utf8");
const swap = (name, body) => {
  const re = new RegExp(`(<!-- ${name}:START[^>]*-->)[\\s\\S]*?(\\s*<!-- ${name}:END -->)`);
  if (!re.test(html)) throw new Error(`Markierung ${name} fehlt in ${PAGE}`);
  html = html.replace(re, `$1\n${body}$2`);
};
swap("RELEASES", cards);
swap("JSONLD", jsonld);
const latest = data.releases[0];
html = html.replace(/(<span data-latest>)[^<]*(<\/span>)/, `$1Out now — "${esc(latest.title)}" · ${esc(latest.artists)} · Listen on Spotify$2`);
writeFileSync(PAGE, html);
console.log(`index.html: ${releases.length} Releases geschrieben`);

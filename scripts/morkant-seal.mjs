// Morkant: versiegelt die Belohnung mit dem Passwort (PBKDF2-SHA256 + AES-256-GCM).
// In morkant/index.html landet nur der verschlüsselte Block – weder Passwort noch Klartext.
//
// Aufruf (Passwort und Text NICHT in Dateien im Repo speichern):
//   MORKANT_PASS='das passwort' MORKANT_TEXT=$'Zeile 1\nZeile 2' node scripts/morkant-seal.mjs
//
// Der Text darf mehrere Zeilen haben; die erste Zeile wird als Überschrift gezeigt.
import { readFileSync, writeFileSync } from "node:fs";
import { webcrypto as crypto } from "node:crypto";

const ITER = 600000; // gleiche Zahl steht im Block und wird von der Seite gelesen
const PAGE = "morkant/index.html";

// Muss exakt zu normalize() in morkant/assets/js/morkant.js passen
const normalize = (s) => s.normalize("NFC").trim().replace(/\s+/g, " ").toLocaleLowerCase("de");

const pass = process.env.MORKANT_PASS, text = process.env.MORKANT_TEXT;
if (!pass || !text) { console.error("MORKANT_PASS und MORKANT_TEXT setzen."); process.exit(1); }

const enc = new TextEncoder();
const b64 = (u8) => Buffer.from(u8).toString("base64");
const salt = crypto.getRandomValues(new Uint8Array(16));
const iv = crypto.getRandomValues(new Uint8Array(12));
const base = await crypto.subtle.importKey("raw", enc.encode(normalize(pass)), "PBKDF2", false, ["deriveKey"]);
const key = await crypto.subtle.deriveKey({ name: "PBKDF2", hash: "SHA-256", salt, iterations: ITER },
  base, { name: "AES-GCM", length: 256 }, false, ["encrypt"]);
const ct = new Uint8Array(await crypto.subtle.encrypt({ name: "AES-GCM", iv }, key, enc.encode(text)));

const vault = JSON.stringify({ v: 1, iter: ITER, salt: b64(salt), iv: b64(iv), ct: b64(ct) });
let html = readFileSync(PAGE, "utf8");
const re = /(<script type="application\/json" id="vault">)[\s\S]*?(<\/script>)/;
if (!re.test(html)) throw new Error("Vault-Block fehlt in " + PAGE);
html = html.replace(re, `$1${vault}$2`);
writeFileSync(PAGE, html);
console.log(`Versiegelt (${ct.length} Bytes, ${ITER} Iterationen). Passwort-Normalisierung: "${normalize(pass).replace(/./g, "•")}"`);

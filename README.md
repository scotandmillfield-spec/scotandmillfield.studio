# Scot & Millfield – Website

Statische Website im Design „Stahlwerk“. Kein Build-Schritt, keine externen Abhängigkeiten:
`index.html` per Doppelklick öffnen oder über GitHub Pages / Webspace ausliefern.

## Aufbau

```
index.html              Startseite
impressum.html          Impressum (Vorlage – ausfüllen!)
datenschutz.html        Datenschutz (Vorlage – ausfüllen!)
assets/css/site.css     Gestaltung (Farben oben unter :root)
assets/js/data.js       Inhalte: Releases, Hörerzahl, Live-Schalter
assets/js/site.js       Logik: Release-Kacheln, Spotify-Player nach Klick
assets/img/             Bilder, Cover, Favicon
assets/fonts/           Schriften lokal (Archivo, DM Mono – SIL Open Font License)
```

## Inhalte pflegen

Alles Wiederkehrende steht in `assets/js/data.js`:

- **Hörerzahl:** `monthlyListeners` (erscheint im Streifen unter dem Hero und in der Story)
- **Neues Release:** oben in `releases` einen Eintrag ergänzen, Cover nach `assets/img/covers/` legen
- **Spotify-Link je Release:** `spotify` – leer lassen = Play lädt den Künstler-Player
- **Live-Bereich:** `showLive: true` blendet „Live / Sets“ samt Navigationspunkt ein

Timeline, Partner und Texte stehen direkt in `index.html`.

## Offene Platzhalter

In eckigen Klammern, z. B. `[BOOKING-E-MAIL]`, `[MANAGEMENT-E-MAIL]`, `[EPK-DOWNLOAD]`,
`[LAUNCH-DATUM]`, sowie Impressum und Datenschutz.

## Veröffentlichen

**GitHub Pages:** Repository → Settings → Pages → „Deploy from a branch“, Branch `main`, Ordner `/ (root)`.
Für die eigene Domain unter Settings → Pages die Domain eintragen und bei STRATO den DNS-Eintrag
auf GitHub Pages setzen. Hinweis: Bei privaten Repositories braucht Pages ein bezahltes GitHub-Abo.

**STRATO-Webspace:** Inhalt dieses Ordners per SFTP ins Webverzeichnis laden
(oder später automatisch per GitHub Action).

## Datenschutz-Technik

- Schriften lokal, keine Google-Fonts-Verbindung
- Keine Cookies, kein Tracking
- Spotify-Player erst nach Klick (Zwei-Klick-Lösung)

# Scot & Millfield – Website

Statische Website im Design „Stahlwerk“. Kein Build-Schritt, keine externen Abhängigkeiten:
`index.html` per Doppelklick öffnen oder über GitHub Pages / Webspace ausliefern.

## Aufbau

```
index.html              Startseite
impressum.html          Impressum (Vorlage – ausfüllen!)
datenschutz.html        Datenschutz (Vorlage – ausfüllen!)
robots.txt, sitemap.xml Für Suchmaschinen (Domain scotandmillfield.studio)
assets/css/site.css     Gestaltung (Farben oben unter :root)
assets/js/data.js       Inhalte: Releases, Hörerzahl, Live-/Merch-Schalter
assets/js/site.js       Logik: Release-Kacheln, Spotify-Player nach Klick
assets/img/             Bilder, Cover, Favicon
assets/fonts/           Schriften lokal (Archivo, DM Mono – SIL Open Font License)
```

## Inhalte pflegen

Alles Wiederkehrende steht in `assets/js/data.js`:

- **Hörerzahl:** `monthlyListeners` (erscheint im Streifen unter dem Hero und in der Story)
- **Streams gesamt:** `totalStreams` (z. B. `18M+`) – Summe aus dem Spotify-for-Artists-Export, abgerundet auf Millionen; von Hand aktualisieren
- **Neues Release:** oben in `releases` einen Eintrag ergänzen, Cover nach `assets/img/covers/` legen
- **Anzahl Release-Kacheln:** `showReleases` (Standard 4) – neue Releases oben eintragen, ältere rutschen raus
- **Top-Tracks-Player:** rechts neben den Releases; lädt nach Klick auf „Load player“ den Spotify-Künstler-Player mit den beliebtesten Titeln
- **Live-Bereich:** `showLive: true` blendet „Live / Sets“ samt Navigationspunkt ein
- **Booking-Block:** `showBooking: true` blendet unter „Press & Booking“ den Block „Booking“ ein (derzeit aus)
- **Merch-Bereich:** `showMerch: true` blendet „Merch“ samt Navigationspunkt ein (derzeit aus;
  das Anmeldeformular hat noch keinen Versanddienst)

Timeline, Partner und Texte stehen direkt in `index.html`.

**Domain:** Canonical, Social-Media-Vorschau (`og:url`, `og:image`), strukturierte Daten (JSON-LD)
sowie `robots.txt` und `sitemap.xml` verwenden `https://scotandmillfield.studio/`. Bei einer
anderen Domain an diesen Stellen anpassen.

## Offene Platzhalter

In eckigen Klammern, z. B. `[BOOKING-E-MAIL]`, `[MANAGEMENT-E-MAIL]`, `[EPK-DOWNLOAD]`,
`[LAUNCH-DATUM]`, sowie Impressum und Datenschutz.

## Monatliche Hörer automatisch

Die GitHub Action `.github/workflows/monthly-listeners.yml` läuft täglich um ca. 6 Uhr
(04:00 UTC) und liest die monatlichen Hörer von der öffentlichen Spotify-Künstlerseite
(`scripts/update-listeners.mjs`). Hat sich die gerundete Zahl geändert (z. B. 139K → 136K),
schreibt sie die Zahl direkt in `main` und startet anschließend den Deploy zu STRATO.
Ohne Änderung passiert nichts.

- Schutz: Ein Sprung von mehr als 25 % an einem Tag wird nicht übernommen; der Lauf schlägt
  dann fehl und GitHub schickt eine E-Mail.
- Von Hand starten: Actions → „Monatliche Hörer aktualisieren“ → Run workflow
  („Nur testen“ anhaken, um nur zu prüfen, ohne etwas zu übernehmen)
- Schlägt der Lauf ohne Sprung fehl, hat Spotify vermutlich den Seitenaufbau geändert.

## Browser-Cache

CSS und JavaScript sind in den HTML-Dateien mit einer Versionsnummer eingebunden
(`site.css?v=20261012e`). Nach Änderungen an `site.css`, `site.js` oder `data.js` die Nummer
in `index.html`, `impressum.html` und `datenschutz.html` hochzählen, sonst zeigen Browser
eventuell noch die alte Fassung aus dem Cache.

## Veröffentlichen

**Automatisch (aktiv):** Jeder Push auf `main` lädt die Seite per GitHub Action
(`.github/workflows/deploy.yml`) per SFTP zu STRATO in den Ordner `scotandmillfield`.
Zugangsdaten liegen als Secrets `STRATO_HOST`, `STRATO_USER`, `STRATO_PASS` im Repository.
Änderungen deshalb auf einem eigenen Branch vorbereiten und erst nach Prüfung nach `main` übernehmen.

**Alternativ GitHub Pages:** Repository → Settings → Pages → „Deploy from a branch“, Branch `main`, Ordner `/ (root)`.
Für die eigene Domain unter Settings → Pages die Domain eintragen und bei STRATO den DNS-Eintrag
auf GitHub Pages setzen. Hinweis: Bei privaten Repositories braucht Pages ein bezahltes GitHub-Abo.

**Manuell zu STRATO:** Inhalt dieses Ordners per SFTP ins Webverzeichnis laden.

## Datenschutz-Technik

- Schriften lokal, keine Google-Fonts-Verbindung
- Keine Cookies, kein Tracking
- Spotify-Player erst nach Klick (Zwei-Klick-Lösung)

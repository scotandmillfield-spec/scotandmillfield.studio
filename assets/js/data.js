/*
 * Scot & Millfield – Inhalte der Startseite
 * ------------------------------------------
 * Diese Datei ist die einzige Stelle, an der Zahlen und Releases gepflegt werden.
 * Die Seite liest sie beim Laden aus (funktioniert auch lokal per Doppelklick).
 *
 * spotify: Link zum Track/Album auf Spotify ("Teilen → Link kopieren").
 *          Leer lassen = Play-Knopf lädt den Künstler-Player.
 * date:    ISO-Format JJJJ-MM-TT, Anzeige erfolgt als TT.MM.JJJJ.
 * Reihenfolge: neueste zuerst.
 */
window.SITE_DATA = {
  spotifyArtist: "https://open.spotify.com/artist/6LTgaNvQ4z07MLP5Fs3plh",

  // Monatliche Hörer laut Spotify – von Hand aktualisieren.
  monthlyListeners: "139K",
  monthlyListenersAsOf: "2026-10-10",

  // Abschnitt "Live / Sets" ein- (true) oder ausblenden (false).
  showLive: false,

  // Abschnitt "Merch" samt Navigationspunkt ein- (true) oder ausblenden (false).
  showMerch: false,

  // Block "Booking" unter Press & Booking ein- (true) oder ausblenden (false).
  showBooking: false,

  releases: [
    {
      title: "Seven Degrees",
      artists: "Scot & Millfield × Simonic",
      label: "Submental Records",
      date: "2026-08-28",
      cover: "assets/img/covers/seven-degrees.webp",
      beatport: "https://www.beatport.com/release/seven-degrees/7199918",
      spotify: ""
    },
    {
      title: "Broken Pieces",
      artists: "Scot & Millfield",
      label: "The Night Drive",
      date: "2026-06-19",
      cover: "assets/img/covers/broken-pieces.webp",
      beatport: "https://www.beatport.com/release/broken-pieces/6949903",
      spotify: ""
    },
    {
      title: "Anamnisi (take control)",
      artists: "Scot & Millfield, aqeliki",
      label: "The Night Drive",
      date: "2026-03-13",
      cover: "assets/img/covers/anamnisi.webp",
      beatport: "https://www.beatport.com/release/anamnisi-take-control/5894328",
      spotify: ""
    },
    {
      title: "Astralight",
      artists: "Scot & Millfield, Björn Mandry",
      label: "SSL Music",
      date: "2026-01-30",
      cover: "assets/img/covers/astralight.webp",
      beatport: "https://www.beatport.com/release/astralight/5888464",
      spotify: ""
    },
    {
      title: "Shut Wide Eyes",
      artists: "Scot & Millfield, Simon Alves",
      label: "Harthouse",
      date: "2025-11-28",
      cover: "assets/img/covers/shut-wide-eyes.webp",
      beatport: "https://www.beatport.com/release/shut-wide-eyes/5544662",
      spotify: ""
    }
  ]
};

# 1AB Weiterlesen (Version 0.1.0, nur Vorbereitung)

Eigenständiges Plugin (Hooks wie Mini-Statistik, aber ohne deren Code). Standardmäßig komplett AUS. Contextual Related Posts bleibt unberührt.

- Weiterlesen-Box: automatisch / manuell / gemischt je Artikel (Metabox), Gewicht je Artikel
- Harte Regeln: gleiche Seite, gleiche Sprache (Polylang, sonst Locale), gleiche Kategorie (automatisch), kein noindex, keine Selbstverlinkung, kein gleiches Fokus-Keyword
- Zwei gepushte Artikel (Einstellungen): teilen sich Platz 1, A/B per Zufall im Browser, ohne Speicherung
- Teaser weiter oben (Testvariante): nur zwischen Top-Level-Absätzen, nie in Listen/Tabellen/Boxen
- Exit-Popup A/B/C/D: A = Push 1, B = Push 2, C = 3 passende Textlinks, D = bester automatischer Treffer. Erscheint höchstens alle 7 Tage je Besucher (serverseitige Wochenkennung), nie dieselbe Variante wie zuletzt
- Messung: nur Tageszähler (Einblendung/Klick), Auswertung mit 95-%-Bereich unter Einstellungen > Weiterlesen (1AB)
- Leicht: ein kleines Inline-Skript, kein externer Request beim Laden; das Popup holt erst nach Auslöser (Verzögerung, Mauszeiger oben raus / Scrollen nach oben am Handy) eine Antwort vom Server
- Test: `php tests-wl/test-weiterlesen.php`

Nicht getestet: echtes WordPress (nur PHP-Syntax, Logiktests und Popup-Vorschau). Vor Livegang auf einer Testseite prüfen, insbesondere mit Seiten-Cache.

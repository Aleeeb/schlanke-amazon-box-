# 1AB Weiterlesen (Version 0.2.0, nur Vorbereitung)

Eigenständiges Plugin (Hooks wie Mini-Statistik, aber ohne deren Code). Standardmäßig komplett AUS. Contextual Related Posts bleibt unberührt.

- Weiterlesen-Box: automatisch / manuell / gemischt je Artikel (Metabox), Gewicht je Artikel
- Harte Regeln: gleiche Seite, gleiche Sprache (Polylang, sonst Locale), gleiche Kategorie (automatisch), kein noindex, keine Selbstverlinkung, kein gleiches Fokus-Keyword
- Bis zu fünf gepushte Artikel (A bis E, Auswahl im Backend mit Suche und Vorschlägen nach Besucherzahlen): teilen sich Platz 1 der Box, Zufall im Browser ohne Speicherung
- Teaser weiter oben (Testvariante): nur zwischen Top-Level-Absätzen, nie in Listen/Tabellen/Boxen
- Exit-Popup A bis E = die gepushten Artikel. Variante = Hash aus Wochenkennung und Tageszahl (wechselt täglich, nichts im Browser). Höchstens alle 7 Tage je Besucher
- Messung: nur Tageszähler (Einblendung/Klick), Auswertung mit 95-%-Bereich unter Einstellungen > Weiterlesen (1AB)
- Leicht: ein kleines Inline-Skript, kein externer Request beim Laden; das Popup holt erst nach Auslöser (Verzögerung, Mauszeiger oben raus / Scrollen nach oben am Handy) eine Antwort vom Server
- Test: `php tests-wl/test-weiterlesen.php`

Nicht getestet: echtes WordPress (nur PHP-Syntax, Logiktests und Popup-Vorschau). Vor Livegang auf einer Testseite prüfen, insbesondere mit Seiten-Cache.

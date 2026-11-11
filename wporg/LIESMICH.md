# Vorbereitung für WordPress.org

Ordner `1ab-stats/` ist die kostenlose Basis der Statistik als eigenständiges Plugin (Arbeitstitel „1AB Stats“).
Sie wurde neu und schlank geschrieben und übernimmt die bewährten Teile aus `statistik/` (Zählskript, Besucher-Kennung, Länder aus Zeitzone).
Der Hauptzweig, das Verzeichnis `statistik/` und die Live-Seiten sind davon nicht betroffen.

## Stand

- Seit 11.11.2026 (zweiter Schritt): Bausteine „Knopf, Bild, Karte“ mit eigenem A/B-Test je Baustein, einsetzbar als Block, Shortcode `[oneab_element id="1"]` oder klassisches Widget. Variante A kommt vom Server, B wird im Browser ausgelost, ohne Flackern und ohne Speicherung. Test mit 40 echten Browser-Besuchen: Zähler stimmen genau mit den ausgelosten Varianten und Klicks überein.
- Freemius-SDK 2.13.5 ist eingebunden (`vendor/freemius`, Produkt 44600). Die Datei `uninstall.php` ist dafür entfallen; das Aufräumen hängt jetzt am Freemius-Ereignis `after_uninstall`. Erster Pro-Teil: Klicks je Produkt (`includes/class-oneab-stats-products__premium_only.php`, wird von Freemius aus der Gratis-Fassung entfernt).
- Seit 11.11.2026 zusätzlich: Heatmap (Lesetiefe, Verweildauer, Klickpunkte als reine Summen) und Weiterlesen-Karten mit A/B-Test (Überschrift, Aussehen, Platz; Auslosung im Browser ohne Speicherung, Tageszähler).
- Zweiter Test auf WordPress 7.1.4 mit MariaDB 10.11: 30 echte Browser-Besuche, Zähler für Heatmap und A/B-Test stimmen, Ansichten geprüft, Löschen entfernt alle Tabellen. Die Heatmap-Meldung beim Verlassen der Seite ließ sich im ferngesteuerten Testbrowser nur über das Ereignis „Seite verborgen“ auslösen, nicht über eine echte Navigation; das bitte einmal auf einer echten Seite gegenprüfen.

- Prüfung mit dem WordPress-Regelwerk für Code (WPCS 3.4, Standard „WordPress“): keine Fehler, keine Warnungen.
- Test auf leerer Installation (WordPress 7.1.4, PHP 8.3, SQLite): Aktivieren, Zählen eines echten Browser-Besuchs, Klicks auf Amazon- und eBay-Links, Bericht, Speichern der Einstellungen. Roboter, fremde Herkunft und Nicht-Partnerlinks werden verworfen. Das Plugin setzt kein Cookie und legt nichts im Browser ab.
- Deutsch als Übersetzung liegt bei (`languages/`).

## Noch offen vor der Einreichung

- Das offizielle Werkzeug „Plugin Check“ konnte hier nicht laufen (wordpress.org ist aus der Arbeitsumgebung nicht erreichbar). Es muss einmal auf einer echten Installation laufen.
- Test mit einem Cache-Plugin und auf einer echten Seite mit vielen Artikeln (Auswahl der Weiterlesen-Artikel, Heatmap bei echter Navigation).
- Endgültiger Name. Bei einem anderen Namen ändern sich Ordner, Textdomain und Hauptdatei.
- `Contributors` in `readme.txt` (Benutzername bei WordPress.org) eintragen.
- Bildschirmfotos und Symbol für das Verzeichnis.
- Festlegen, welche Teile Pro werden (bisher nur Klicks je Produkt). WordPress.org erlaubt in der Gratis-Fassung keine gesperrten Funktionen und keine künstlichen Obergrenzen; Pro muss also zusätzlicher Code sein.
- Plan „Pro“ in Freemius fertig anlegen (Stand 11.11.: nur 4,90 € monatlich für 1 Seite gespeichert) und den Baustein in `1ab-stats.php` mit dem Schnipsel aus dem Freemius-Konto abgleichen.
- Einwilligungsseite von Freemius auf einer echten Installation prüfen (in der Arbeitsumgebung ist Freemius nicht erreichbar).

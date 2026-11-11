# Vorbereitung für WordPress.org

Ordner `1ab-stats/` ist die kostenlose Basis der Statistik als eigenständiges Plugin (Arbeitstitel „1AB Stats“).
Sie wurde neu und schlank geschrieben und übernimmt die bewährten Teile aus `statistik/` (Zählskript, Besucher-Kennung, Länder aus Zeitzone).
Der Hauptzweig, das Verzeichnis `statistik/` und die Live-Seiten sind davon nicht betroffen.

## Stand

- Prüfung mit dem WordPress-Regelwerk für Code (WPCS 3.4, Standard „WordPress“): keine Fehler, keine Warnungen.
- Test auf leerer Installation (WordPress 7.1.4, PHP 8.3, SQLite): Aktivieren, Zählen eines echten Browser-Besuchs, Klicks auf Amazon- und eBay-Links, Bericht, Speichern der Einstellungen. Roboter, fremde Herkunft und Nicht-Partnerlinks werden verworfen. Das Plugin setzt kein Cookie und legt nichts im Browser ab.
- Deutsch als Übersetzung liegt bei (`languages/`).

## Noch offen vor der Einreichung

- Das offizielle Werkzeug „Plugin Check“ konnte hier nicht laufen (wordpress.org ist aus der Arbeitsumgebung nicht erreichbar). Es muss einmal auf einer echten Installation laufen.
- Test mit MySQL/MariaDB statt SQLite und mit einem Cache-Plugin.
- Endgültiger Name. Bei einem anderen Namen ändern sich Ordner, Textdomain und Hauptdatei.
- `Contributors` in `readme.txt` (Benutzername bei WordPress.org) eintragen.
- Bildschirmfotos und Symbol für das Verzeichnis.
- Pro als getrenntes Zusatz-Plugin (Anknüpfpunkte: Aktionen `oneab_stats_event` und `oneab_stats_report_after`).

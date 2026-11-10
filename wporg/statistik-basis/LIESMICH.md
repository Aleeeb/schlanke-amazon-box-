# Statistik-Basis für WordPress.org (Entwurf)

Bereinigte Kopie von `statistik/mini-statistik.zip` (0.37.0). Der Hauptzweig und die Live-Seiten sind nicht betroffen.

Schon erledigt:
- Updater über GitHub entfernt (Richtlinie 8), damit auch alle Bezüge auf das GitHub-Konto
- Abruf beim alten Amazon-Bilddienst entfernt
- Lizenz GPLv2 oder neuer und Text Domain im Kopf
- `uninstall.php` räumt Tabellen und Einstellungen beim Löschen auf
- `readme.txt` als Gerüst

Noch offen bis zur Einreichung:
- Pro-Teile in ein Zusatz-Plugin auslagern (Echtzeit, Pfade, Zentrale, GA-Import, Roboter-Bericht, Klicks je Produkt)
- Produktbilder von Amazon-Servern in der Verwaltung gehören zum Pro-Teil und müssen aus der Basis raus
- Texte übersetzbar machen (Englisch als Grundsprache, Deutsch als Übersetzung)
- Eindeutige Namen (Klasse, Optionen, Tabellen) und endgültiger Plugin-Name
- Skripte und Stile über `wp_enqueue_*` einbinden
- `X-Forwarded-For` nur auf Wunsch des Betreibers auswerten
- Prüfung mit dem Werkzeug „Plugin Check“ und Test auf leerer WordPress-Installation

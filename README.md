# 1AB Affiliate Help

WordPress-Plugin für Amazon-Partnerlinks (Ersatz für AAWP).

## Release
1. Version in `1ab-affiliate-help.php` erhöhen (Kopfzeile und `const VERSION`).
2. Tests laufen lassen: `for t in tests/*.php; do php $t; done` (alle müssen „Alles bestanden“ melden).
3. ZIP mit Ordner `1ab-affiliate-help/` bauen und als `1ab-affiliate-help.zip` ablegen.
4. `update-1ab-affiliate-help.json` mit Version, ZIP-Adresse und `sha256` des ZIPs (`sha256sum 1ab-affiliate-help.zip`) aktualisieren. Das Plugin prüft die Summe vor dem Einspielen.

Die alte Linie `schlanke-amazon-box.*` und `update.json` bleiben nur für Seiten, die noch nicht umgestellt sind.

## Datenschutz und Aufbewahrung
Klick-, Seiten-, A/B- und Heatmap-Daten enthalten keine IP und kein Cookie. Standardmäßig wird nichts gelöscht. Wer will, setzt `sab_aufbewahrung_tage` auf eine Tageszahl, dann werden ältere Zeilen täglich entfernt. Obergrenze der Messungen: `sab_messungen_je_minute` (Filter, Standard 600).

## 1AB-Test-Statistik (Unterordner `statistik/`)
Zweites Plugin im selben Repo (Ordner `mini-statistik/`, Hauptdatei `mini-statistik.php`). Update-Quelle: `statistik/update.json` mit Version, ZIP-Adresse (`statistik/mini-statistik.zip`) und `sha256`. Release: Version in Kopfzeile und `const VERSION` erhöhen, ZIP mit Ordner `mini-statistik/` bauen, nach `statistik/mini-statistik.zip` legen, `sha256sum` in `statistik/update.json` eintragen. Es gibt nur dieses eine Repo für beide Plugins.

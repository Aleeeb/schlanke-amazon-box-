# 1AB Affiliate Help

WordPress-Plugin für Amazon-Partnerlinks (Ersatz für AAWP).

## Release
1. Version in `1ab-affiliate-help.php` erhöhen (Kopfzeile und `const VERSION`).
2. Tests laufen lassen: `for t in tests/*.php; do php $t; done` (alle müssen „Alles bestanden“ melden).
3. ZIP mit Ordner `1ab-affiliate-help/` bauen und als `1ab-affiliate-help.zip` ablegen.
4. `update-1ab-affiliate-help.json` mit Version, ZIP-Adresse und `sha256` des ZIPs (`sha256sum 1ab-affiliate-help.zip`) aktualisieren. Das Plugin prüft die Summe vor dem Einspielen.

Die alte Linie `schlanke-amazon-box.*` und `update.json` bleiben nur für Seiten, die noch nicht umgestellt sind.

## Datenschutz und Aufbewahrung
Klick-, Seiten-, A/B- und Heatmap-Daten enthalten keine IP und kein Cookie. Sie werden täglich nach `sab_aufbewahrung_tage` Tagen gelöscht (Standard 400, 0 = nie). Obergrenze der Messungen: `sab_messungen_je_minute` (Filter, Standard 600).

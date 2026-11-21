#!/bin/bash
# Veröffentlichung 1AB Speed: Quelle -> ZIP (versioniert) -> push ZIP -> echte URL prüfen -> erst dann Update-Datei schreiben und pushen.
# Aufruf: ./release-1ab-speed.sh <Pfad zum Plugin-Ordner 1ab-speed> 
set -e
SRC="$1"; [ -d "$SRC" ] || { echo "Plugin-Ordner fehlt"; exit 1; }
VER=$(grep -m1 "^ \* Version:" "$SRC/1ab-speed.php" | sed 's/.*: *//')
RAW=https://raw.githubusercontent.com/Aleeeb/schlanke-amazon-box-/main
Z="1ab-speed-$VER.zip"
rm -rf speed && mkdir -p speed && cp -r "$SRC/." speed/
T=$(mktemp -d); mkdir "$T/1ab-speed"; cp -r "$SRC/." "$T/1ab-speed/"; (cd "$T" && zip -qr "$OLDPWD/$Z" 1ab-speed)
cp "$Z" 1ab-speed.zip   # alter Name bleibt für ältere Versionen
SHA=$(sha256sum "$Z" | cut -d' ' -f1)
git add speed "$Z" 1ab-speed.zip && git commit -qm "1AB Speed $VER (ZIP)" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FRG9LxyfJeUyg8Vypiqwb6" && git push -q origin HEAD:main
# Prüfen: die echte Adresse (frisch, mit Zufallsanhang) muss die gleiche Summe liefern
for i in $(seq 1 20); do
  G=$(curl -sL "$RAW/$Z?t=$RANDOM$RANDOM" | sha256sum | cut -d' ' -f1)
  [ "$G" = "$SHA" ] && break; echo "Warte ($i) …"; sleep 6
done
[ "$G" = "$SHA" ] || { echo "FEHLER: ZIP bei GitHub hat andere Summe. Update-Datei NICHT geschrieben."; exit 1; }
printf '{"version":"%s","zip":"https://github.com/Aleeeb/schlanke-amazon-box-/raw/main/%s","sha256":"%s"}\n' "$VER" "$Z" "$SHA" > update-1ab-speed.json
git add update-1ab-speed.json && git commit -qm "Update-Datei 1AB Speed $VER" -m "Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_01FRG9LxyfJeUyg8Vypiqwb6" && git push -q origin HEAD:main
echo "OK $VER $SHA"

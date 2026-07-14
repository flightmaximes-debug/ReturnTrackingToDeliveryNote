# Null-Logik-Buildtest

Version `0.5.0` dient ausschließlich dazu, den PlentyONE-Plugin-Build zu prüfen.

## Sicherheitsumfang

- Führt keinerlei Auftragslogik aus.
- Liest keine Retourendaten.
- Ändert keine Auftragsdaten.
- Verwendet keine externe Verbindung.
- Registriert weder Event noch Ereignisaktion.
- Schreibt keinen Logeintrag.
- Deklariert ausdrücklich PHP `>=8.0 <8.5`.

## Zweck der Diagnose

Wenn auch diese Version bei der statischen Codeprüfung ins Zeitlimit läuft, ist
keine Plugin-Funktion dafür verantwortlich. Dann liegt die Ursache im
Bereitstellungsprozess, der Umgebung oder der Verarbeitung des Repositorys.

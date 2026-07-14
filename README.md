# Minimaler Build-Diagnosetest

Version `0.4.0` dient ausschließlich dazu, den PlentyONE-Plugin-Build zu prüfen.

## Sicherheitsumfang

- Fest auf Auftrags-ID **468574** beschränkt.
- Liest keine Retourendaten.
- Greift nicht auf `ReturnsRepositoryContract` zu.
- Ändert keine Auftragsdaten.
- Verwendet keine externe Verbindung.
- Schreibt nur einen festen Text in das PlentyONE-Plugin-Log.
- Deklariert ausdrücklich PHP `>=8.0 <8.5`.

## Testaktion

Die registrierte Ereignisaktion heißt:

```text
TEST: Plugin-Build und Auftrags-ID im Log prüfen
```

Wird sie für Auftrag 468574 ausgeführt, entsteht der Logeintrag:

```text
Minimaler Plugin-Test wurde für Auftrag 468574 ausgeführt.
```

Identifier:

```text
ReturnTrackingToDeliveryNote::minimalBuildTestSuccessful
```

Für alle anderen Aufträge beendet sich die Aktion ohne Logeintrag.

## Zweck der Diagnose

Wenn auch diese Minimalversion beim Bereitstellen ins Zeitlimit läuft, liegt die
Ursache nicht an der Retourenabfrage. Wenn sie erfolgreich baut, wird die
Retourenabfrage anschließend schrittweise wieder ergänzt.

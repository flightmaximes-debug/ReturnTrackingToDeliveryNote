# Test: Retouren-Sendungsnummer auslesen

Diese Testversion liest nach der Erzeugung eines Retourenlabels die neueste in
PlentyONE gespeicherte Retouren-Sendungsnummer und gibt sie zusammen mit der
Auftrags-ID im **Plugin-Log** aus.

Die Testversion verändert weder den Auftrag noch dessen Eigenschaften.

## Feste Sicherheitseinschränkung

Die Version `0.3.0` ist fest auf die Auftrags-ID **468574** beschränkt. Die
Auftrags-ID wird geprüft, bevor das Retouren-Repository abgefragt wird. Für jeden
anderen Auftrag beendet sich die Aktion sofort; es werden weder Retourendaten
gelesen noch Logeinträge erzeugt oder Auftragsdaten geändert.

## Warum das Plugin-Log statt einer lokalen TXT-Datei?

Plugins laufen in der PlentyONE-Cloud und können dort keine beliebige lokale
Textdatei dauerhaft auf dem Dateisystem ablegen. Das Plugin-Log ist die dafür
vorgesehene persistente Textausgabe und kann in PlentyONE angezeigt, kopiert und
für die Auswertung exportiert werden.

## Ereignisaktion einrichten

1. Plugin in das Plugin-Set aufnehmen, bereitstellen und aktivieren.
2. Unter **Einrichtung » Aufträge » Ereignisse** eine neue Ereignisaktion anlegen.
3. Als Ereignis **Dokumente » Retourenlabel generiert** wählen.
4. Optional den Filter **Auftrag » Auftrag mit Retourenpaketnummer » Ja** setzen.
5. Als Aktion unter **Plugins** bzw. **Retoure** die Aktion
   **TEST: Retouren-Sendungsnummer im Plugin-Log ausgeben** wählen.
6. Ereignisaktion aktivieren und speichern.

Die vorhandene Aktion **Retoure beim Versanddienstleister anmelden** bleibt
unverändert. Diese Testaktion soll erst nach der erfolgreichen Label-Erzeugung
laufen.

## Erwartete Textausgabe

Bei Erfolg enthält das Plugin-Log einen Eintrag mit dem Identifier
`ReturnTrackingToDeliveryNote::trackingNumberDetected` und beispielsweise:

```text
Retourensendungsnummer 12345678901 gehört zu Auftrag 4711.
```

Zusätzlich werden die strukturierten Felder `orderId` und
`returnTrackingNumber` ausgegeben.

Wenn PlentyONE zu diesem Zeitpunkt keine Nummer liefert, erscheint der Identifier
`ReturnTrackingToDeliveryNote::trackingNumberMissing`. Bei technischen Fehlern
erscheint `ReturnTrackingToDeliveryNote::readFailed`.

## Abnahmetest

1. Für Auftrag **468574** ein GLS-ShipIT-Retourenlabel erzeugen.
2. Den Bereich **Daten » Log** öffnen.
3. Nach `ReturnTrackingToDeliveryNote` oder dem Plugin filtern.
4. Prüfen, ob Auftrags-ID und GLS-Retourensendungsnummer korrekt ausgegeben wurden.
5. Kontrollieren, dass die externe Lieferscheinnummer am Auftrag unverändert ist.
6. Optional bei einem anderen Auftrag ein Label erzeugen und bestätigen, dass
   dieses Plugin dafür keinen Logeintrag erzeugt.

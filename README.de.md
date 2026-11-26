# Paymos für Shopware 6

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | **Deutsch** | [Türkçe](README.tr.md)

Offizielles Paymos-Zahlungsplugin für Shopware 6. Mit der Installation wird eine einzelne
Zahlungsart hinzugefügt — **Pay with crypto (Paymos)** —, die Käuferinnen und Käufer zur gehosteten
Paymos-Zahlungsseite weiterleitet. Dort wählen sie Stablecoin und Netzwerk und zahlen direkt aus
der eigenen Wallet. Die Bestelltransaktion wechselt erst über einen signierten Webhook auf `paid`,
sobald die Transaktion On-Chain bestätigt wurde. Die Rückkehr in den Shop entscheidet niemals darüber.

[![Video ansehen: 12 offizielle CMS-Krypto-Plugins — 1-Klick-Verbindung (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video-Walkthrough (1:12, Untertitel in 6 Sprachen):** [Auf YouTube ansehen](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Voraussetzungen

Deklariert in `PaymosPayments/composer.json`:

- `shopware/core`: `~6.5.7 || ~6.6.0 || ~6.7.0` — Shopware 6.5.7 sowie spätere 6.5-Patches, die
  gesamte 6.6-Reihe und 6.7
- `php`: `>=8.2`
- Eine Storefront über HTTPS; bei einer reinen HTTP-Basis-URL wird das Verbinden abgelehnt
- Ein Paymos-Konto; im Dashboard sollte das gewünschte Projekt bereits geöffnet sein

Das Archiv bündelt das Paymos PHP SDK direkt im Plugin, weil Shopware beim Hochladen eines ZIPs
kein Composer für eigene Plugin-Abhängigkeiten ausführt. Außerdem enthält es das kompilierte
Administration-Modul in beiden von Shopware genutzten Formaten — webpack für 6.5 und 6.6, Vite für
6.7 — sodass kein Admin-Build erforderlich ist.

## Installation

Laden Sie `paymos-shopware6-<version>.zip` unter
[Releases](https://github.com/Paymos-labs/shopware6/releases/latest) oder im Bereich **CMS
integration** des Paymos Dashboards herunter. Der Wurzelordner im Archiv heißt `PaymosPayments/`,
entsprechend dem technischen Ordnernamen, den Shopware erwartet.

Über die Administration: **Extensions → My extensions → Upload extension**, Datei auswählen,
anschließend installieren und aktivieren.

Über die Shell, nachdem das Archiv nach `custom/plugins/` entpackt wurde:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate PaymosPayments
bin/console cache:clear
```

Die Aktivierung legt die Zahlungsart an und schaltet sie ein. Fügen Sie sie jedem Verkaufskanal
hinzu, der sie anbieten soll, unter **Sales Channels → Ihr Kanal → Payment methods**. Die
Zahlungsart bleibt auch nach Anlage einer Bestellung wählbar, sodass Käuferinnen und Käufer nach
einem fehlgeschlagenen Versuch direkt aus der Bestellung erneut zahlen können, statt neu zu beginnen.

Veröffentlichte Archive sind für alle Händler identisch und enthalten weder API-Schlüssel noch
API Secret, Projekt-ID, Webhook-Geheimnis, OAuth-Token oder Gerätecode. Die bloße Installation
stellt noch keine Verbindung her.

## Verbinden

Das Plugin registriert ein eigenes Administration-Modul, über das die Verbindung hergestellt wird
— nicht über die Konfigurationskarte der Erweiterung.

1. Wählen Sie im Paymos Dashboard das Projekt, über das der Shop abrechnen soll. Das dort geöffnete
   Projekt wird gebunden; im Plugin gibt es keine zweite Auswahl.
2. Öffnen Sie in Shopware **Settings → Plugins → Paymos** und klicken Sie auf **Connect Paymos**.
3. Bestätigen Sie Shop-URL und Projekt im geöffneten Tab. Ein blockierter Tab ist keine Sackgasse —
   die Seite zeigt dann Bestätigungslink und Benutzercode direkt an.
4. Warten Sie auf `Paymos connected.`

Eine einzige Bestätigung deckt Sandbox und Live ab. Ihr einzelner aktiver Payment-Schlüssel wird
weiterverwendet — oder angelegt, wenn keiner existiert — und ein Invoice-Webhook wird registriert unter:

```text
https://your-store.example/paymos/webhook
```

Ein bestehender Webhook wird nur wiederverwendet, wenn Callback-URL, Kategorie und Projekt exakt
übereinstimmen; unter dieser Adresse wird nichts stillschweigend überschrieben.

Zugangsdaten werden verschlüsselt als AES-256-GCM-Umschlag im `SystemConfigService` abgelegt,
verschlüsselt über das `kernel.secret` der Installation, und niemals an den Browser zurückgesendet.
Es gibt kein Eingabefeld für Geheimnisse.

## Pro Verkaufskanal

Die Konfigurationskarte des Plugins wird pro Verkaufskanal-ID ausgelesen, sodass beide Felder je
Kanal einstellbar sind:

| Feld | Standard | Wirkung |
|---|---|---|
| Mode | `sandbox` | Welchen Zugangsdatensatz der Checkout dieses Kanals nutzt |
| Debug logging | off | Reguläre Webhook-Diagnose ins Shopware-Log; betriebliche Fehler werden immer protokolliert |

Ein Kanal kann noch in der Sandbox testen, während ein anderer bereits echte Live-Zahlungen
entgegennimmt. Die Zugangsdaten selbst gelten installationsweit — einmal verbinden und jeden Kanal
allein über **Mode** umschalten. Der Webhook-Endpunkt gehört zu keinem einzelnen Kanal und liest
den globalen Wert.

## Status von Bestelltransaktionen

| Paymos-Ereignis | Bestelltransaktion |
|---|---|
| Käufer zur gehosteten Zahlungsseite weitergeleitet | bleibt `open` |
| `invoice.confirming` | `open`, wird wieder geöffnet, falls sie den Status verlassen hatte |
| `invoice.underpaid_waiting` | `open`; der Restbetrag steht noch aus |
| `invoice.awaiting_payment` | `open`; ein Reorg hat eine bereits gezählte Zahlung zurückgenommen |
| `invoice.paid`, `invoice.paid_over` | `paid` |
| `invoice.underpaid` | `failed` |
| `invoice.expired`, `invoice.cancelled` | `cancelled` |

Shopwares Zustandsmaschine kennt keinen Übergang zwischen zwei Endzuständen, und das Plugin
fordert keinen an. Eine bereits als `cancelled` markierte Transaktion bleibt von einem späteren
`underpaid` unberührt; eine `cancelled`- oder `failed`-Transaktion, die sich als bezahlt erweist,
wird zuerst wieder geöffnet und dann auf `paid` gesetzt. Nichts stuft eine Transaktion herab, die
bereits `paid`, `refunded` oder `refunded_partially` erreicht hat.

Kehrt die Kundschaft von der gehosteten Zahlungsseite zurück, liest der Handler die Transaktion
statt der URL. Ist der Webhook noch nicht eingetroffen, bleibt die Transaktion unverändert — eine
Exception an dieser Stelle würde auf die Fehlerseite führen und eine gleich bestätigte Zahlung
abbrechen. Ein Abbruch-Link, der nach bereits abgeschlossener Zahlung angeklickt wird, macht sie
nicht rückgängig.

## Vor dem Livegang testen

1. Lassen Sie **Mode** für den zu testenden Kanal auf Sandbox und geben Sie eine Bestellung auf.
2. Öffnen Sie diese Rechnung im Paymos Dashboard (ebenfalls in Sandbox) und klicken Sie auf
   **Pay Full**, **Pay 50%**, **Pay 150%** oder **Cancel**. Jede Aktion sendet echte
   Lebenszyklus-Ereignisse, ohne Mittel zu bewegen.
3. Prüfen Sie den Transaktionsstatus der Bestellung sowie das Shopware-Log bei aktiviertem Debug logging.
4. Stellen Sie **Mode** dieses Kanals auf Live um. Die Live-Zugangsdaten kamen mit derselben
   Bestätigung, sodass kein erneutes Verbinden nötig ist.

## Webhooks und Wiederherstellung

`POST /paymos/webhook` ist eine Storefront-Route; Zustellungen treffen also auf der
Storefront-Domain ein. Die Prüfung erfolgt über den Header `X-Webhook-Signature` — Hex-HMAC-SHA256
über `{timestamp}.{body}` — und eine ungültige Signatur oder veraltete Zeitmarke liefert `401`.
`X-Webhook-Id` bleibt über Wiederholungen hinweg stabil, sodass Duplikate quittiert werden, ohne
die Bestellung anzutasten. Bevor ein Endereignis etwas ändert, wird die Rechnung über die Merchant
API zurückgelesen und mit dem gespeicherten Snapshot abgeglichen.

Ein Zustellzyklus umfasst 11 Versuche über rund 16 Stunden. Für alles, was darüber hinausgeht,
liefert das Plugin einen Konsolenbefehl mit:

```bash
bin/console paymos:reconcile
```

Er ruft offene Rechnungen und ausstehende Erstattungen erneut ab und wendet sie über dieselben
geschützten Pfade an wie der Webhook. Führen Sie ihn in einem Live-Shop alle paar Minuten per Cron
aus.

## Erstattungen

Das Plugin unterstützt native Erstattungen über die Paymos Merchant API (`/v1/refunds`) in zwei Modi:

- `full` — erstattet den vollen bezahlten Rechnungsbetrag;
- `overpayment` — erstattet ausschließlich den Überzahlungsbetrag einer `invoice.paid_over`-Transaktion,
  während die Bestellung bezahlt bleibt.

Die Mittel fließen ausschließlich an die verifizierte Wallet-Adresse `first_sender` zurück, die
Paymos bei Bezahlung der Rechnung erfasst hat. Eine Empfängeradresse wird nicht manuell eingegeben,
und der Erstattungsschutz des Plugins blockiert beliebige Teilerstattungen ebenso wie unverifizierte
manuelle Statusübergänge für Erstattungen bei Paymos-Bestelltransaktionen.

Öffnen Sie in der Shopware Administration **Settings → Plugins → Paymos**, geben Sie die **Order
transaction ID** in der Karte **Paymos refunds** ein und nutzen Sie **Full refund** (`full`),
**Refund overpayment** (`overpayment`) oder **Check status**. Shopware setzt die Bestelltransaktion
erst dann auf `refunded` (bei `full`) bzw. protokolliert die Überzahlungserstattung, wenn Paymos
`completed` über `POST /paymos/webhook` oder `bin/console paymos:reconcile` bestätigt hat. Führen
Sie den Befehl alle paar Minuten per Cron aus, um verpasste Webhooks nachzuholen. **Check status**
zeigt lediglich den aktuellen Zustand an — es führt weder einen Abgleich durch noch wendet es
Erstattungen an. Im Sandbox-Modus lassen sich Endzustände (`completed`, `failed`, `cancelled`) ohne
Geldbewegung simulieren.

## Shopware-Upgrade

6.5 und 6.6 nutzen das asynchrone Payment-Handler-Interface; 6.7 hat es durch
`AbstractPaymentHandler` ersetzt. Ein einziges Archiv enthält beide Varianten und bindet die API
des laufenden Kerns, aber der in der Zahlungsart gespeicherte Handler wird bei der Installation
geschrieben. Deaktivieren und reaktivieren Sie das Plugin nach einem Hauptversions-Upgrade: Die
Aktivierung schreibt den Handler neu, sodass die Zahlungsart wieder auflöst. Die Zahlungsart wird
stets über ihren technischen Namen `paymos_crypto` gefunden, der zwischen den Versionen unverändert
bleibt.

## Fehlerbehebung

**Kein Paymos-Eintrag unter Settings.** Das Administration-Modul stammt aus den kompilierten Assets
des Plugins. Führen Sie `bin/console assets:install` und `bin/console cache:clear` aus und laden
Sie den Adminbereich mit einem Hard-Refresh neu.

**Die Zahlungsart fehlt im Checkout.** Sie existiert und ist aktiv, doch Shopware zeigt nur
Zahlungsarten an, die einem Verkaufskanal zugeordnet sind. Fügen Sie sie den Zahlungsarten des
Kanals hinzu und prüfen Sie, ob eine Regel sie für Ihren Testwarenkorb ausschließt.

**Verbinden schlägt wegen der Shop-URL fehl.** Akzeptiert wird nur `https://`, und die URL entsteht
aus dem Request, den Shopware selbst sieht. Ein Reverse-Proxy mit TLS-Terminierung muss das Schema
über Shopwares Trusted-Proxy-Konfiguration weitergeben — sonst meldet Shopware weiter `http://` und
die Bestätigung wird abgelehnt.

## Links

- Dokumentation: [paymos.io/de/docs/cms-shopware6](https://paymos.io/de/docs/cms-shopware6)
- Quellcode und Releases: [Paymos-labs/shopware6](https://github.com/Paymos-labs/shopware6)
- Support: [support@paymos.io](mailto:support@paymos.io)

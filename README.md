# Shopware 6: Scheduled Tasks und Message Queue per URL-Cron

Kostenloses Plugin (MIT) für Shopware 6.5 bis 6.7. Es arbeitet geplante Aufgaben und die Nachrichten-Warteschlange ab, sobald eine geheime Adresse aufgerufen wird. Damit reicht ein ganz normaler Cronjob, der eine Adresse aufruft. Einen CLI-Worker oder Supervisor braucht es nicht.

[English version below](#english)

## Wann brauche ich das?

Shopware 6 erledigt Hintergrundarbeit über **Scheduled Tasks** und die **Message Queue**, etwa Sitemap, Aufräumen, Newsletter, Indizes und alles, was Plugins anmelden. Ab Werk macht das der Admin-Worker, aber nur, solange jemand die Administration geöffnet hat.

**Wenn du per Cron die Konsole aufrufen kannst, brauchst du dieses Plugin nicht.** Dann reichen zwei Einträge pro Minute:

```
* * * * * php /pfad/zum/shop/bin/console scheduled-task:run --time-limit=60
* * * * * php /pfad/zum/shop/bin/console messenger:consume async low_priority --time-limit=60
```

Danach schaltest du den Admin-Worker in `config/packages/shopware.yaml` ab (`shopware.admin_worker.enable_admin_worker: false`).

Das Plugin ist für Hosting gedacht, bei dem ein Cronjob nur eine Adresse per `wget` oder `curl` aufrufen kann, oder für Shops ganz ohne Server-Cron.

## Was bei jedem Aufruf passiert

1. Fällige Scheduled Tasks werden eingeplant, wie beim Admin-Worker.
2. Die Warteschlange wird abgearbeitet (Transporte `webhook`, `async`, `low_priority`, `default`, je nachdem, was die Version kennt), bis sie leer ist oder 25 Sekunden um sind.
3. Läuft ein Aufruf noch, antwortet ein zweiter mit `busy` und tut nichts (Sperre gegen Doppelläufe).
4. Solange Aufrufe kommen, ist der Admin-Worker aus (abschaltbar). Bleiben die Aufrufe 15 Minuten aus, schaltet er sich von selbst wieder ein. Die Aufgaben bleiben also nie dauerhaft liegen, und an der `shopware.yaml` musst du nichts ändern.

## Installation

1. Lade die **ZIP-Datei** unter [Releases](../../releases) herunter.
   Der grüne Knopf „Code → Download ZIP“ funktioniert nicht direkt, weil der Ordner darin anders heißt.
2. In der Administration öffnest du **Erweiterungen → Meine Erweiterungen → Erweiterung hochladen**, dann installieren und aktivieren.

Mit Composer ([Packagist](https://packagist.org/packages/cronjob-de/shopware6-task-runner)):

```
composer require cronjob-de/shopware6-task-runner
bin/console plugin:refresh
bin/console plugin:install --activate CronjobDeTaskRunner
```

Ohne Composer auf der Konsole: Die ZIP nach `custom/plugins/` entpacken, dann `bin/console plugin:refresh` und `bin/console plugin:install --activate CronjobDeTaskRunner`.

## Einrichten

Unter **Erweiterungen → Meine Erweiterungen → Cronjob.de → Konfigurieren** steht die fertige Adresse zum Kopieren:

```
https://dein-shop.de/api/cronjob-de/run?token=<Schlüssel>
```

Diese Adresse lässt du jede Minute bis alle 5 Minuten aufrufen. Das geht mit jedem Hoster-Cron oder Cron-Dienst, der eine Adresse aufrufen kann, zum Beispiel:

```
*/5 * * * * curl -fsS -o /dev/null "https://dein-shop.de/api/cronjob-de/run?token=<Schlüssel>"
```

- Der Aufrufer sollte mindestens 30 Sekunden auf die Antwort warten. Wartet er kürzer, setzt du das Zeitbudget mit `&time=10` herunter (erlaubt sind 5 bis 55 Sekunden).
- Statt `?token=` geht auch die Kopfzeile `X-Cronjob-Token: <Schlüssel>`.
- Leerst du das Schlüsselfeld und speicherst, entsteht ein neuer Schlüssel. Die alte Adresse antwortet dann mit 403.

Für den Cron-Dienst Cronjob.de gibt es in der Konfiguration zusätzlich einen Verbindungslink, der dort das Formular vorausfüllt. Offen gesagt: Den Dienst betreibe ich. Das Plugin funktioniert aber mit jedem anderen Aufrufer genauso.

## Antworten

| Status | Text | Bedeutung |
|---|---|---|
| 200 | `OK: scheduled tasks queued, 12 messages handled, 0 failed, 0 to retry (3.4 s)` | alles erledigt |
| 200 | `OK - busy: the previous call is still working.` | voriger Aufruf läuft noch |
| 403 | `Access denied: token missing or wrong.` | Schlüssel fehlt oder ist falsch |
| 500 | `ERROR: …` | Ausnahme, oder Nachrichten sind endgültig fehlgeschlagen |

Ausnahmen stehen zusätzlich im Shopware-Log.

## Getestet

Getestet mit Shopware 6.6.9 und 6.7.15. Dienste und Route sind ab Version 1.1.2 im PHP-Format definiert, damit lädt das Plugin auch unter 6.8 ohne XML.

Fehler und Fragen bitte unter [Issues](../../issues) melden.

## Lizenz

MIT, siehe [LICENSE](LICENSE). © 2026 Joscha Schultze

---

<a name="english"></a>
# English

Free plugin (MIT) for Shopware 6.5 to 6.7. Every call of a secret address runs the due scheduled tasks and works through the message queue. A plain cronjob that calls a URL is enough: no CLI worker, no supervisor.

**If your cron can run the console, you don't need this plugin.** Run `bin/console scheduled-task:run` and `bin/console messenger:consume async low_priority` every minute (each with `--time-limit=60`) and set `shopware.admin_worker.enable_admin_worker: false`. The plugin is meant for hosting where a cronjob can only call a URL, or where there is no server cron at all.

**Each call:**
- queues due scheduled tasks;
- consumes the queue (`webhook`, `async`, `low_priority`, `default`) until it is empty or 25 seconds have passed;
- answers `busy` while a previous call is still running.

While calls keep coming, the admin worker is switched off (optional). It switches back on by itself after 15 minutes without a call.

**Install:** download the **ZIP file** from [Releases](../../releases). GitHub's "Download ZIP" button won't work as is, because the folder name inside is different. Upload it under Extensions → My extensions → Upload extension, then install and activate. With Composer: `composer require cronjob-de/shopware6-task-runner`, then `bin/console plugin:refresh` and `bin/console plugin:install --activate CronjobDeTaskRunner`.

**Set up:** copy the address from the plugin configuration (`https://your-shop.com/api/cronjob-de/run?token=<token>`) and call it every 1 to 5 minutes from any cron service or hosting cron. The caller should wait at least 30 seconds. Otherwise lower the budget with `&time=10` (5–55 s). Instead of `?token=` you can also send the header `X-Cronjob-Token`.

The configuration also has a connection link for the cron service Cronjob.de, which I run. The plugin works the same with any other caller.

**Responses:**

| Status | Meaning |
|---|---|
| 200 `OK: …` | done |
| 200 `OK - busy` | previous call still running |
| 403 | token missing or wrong |
| 500 `ERROR: …` | exception or failed messages |

Tested with Shopware 6.6.9 and 6.7.15. Please report problems under [Issues](../../issues).

License: MIT, © 2026 Joscha Schultze

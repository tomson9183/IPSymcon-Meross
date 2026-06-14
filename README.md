# Meross

[![IP-Symcon](https://img.shields.io/badge/IP--Symcon-8.0-blue.svg)](https://www.symcon.de)
[![Version](https://img.shields.io/badge/Modul--Version-1.9.9-orange.svg)]()
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

Bibliothek zur **lokalen** Steuerung von Meross-Geräten über das LAN – ohne MQTT,
ohne dauerhafte Cloud-Verbindung, ohne Python. Die Meross-Cloud wird nur **einmalig**
beim Einrichten genutzt (Geräteliste, Konto-Key und lokale IPs holen).

Folgende Module beinhaltet diese Bibliothek:

- __Meross Konfigurator__ – findet die Geräte des Kontos und legt passende Instanzen an.
- __Meross Gerät__ – steuert ein einzelnes Gerät lokal (Steckdose, Rollladen, Thermostat, …).

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#5-statusvariablen-und-profile)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Hinweise](#8-hinweise)

## 1. Funktionsumfang

- Lokale Steuerung per signiertem HTTP an `http://<geräte-ip>/config`, Status per Polling.
- Automatische Geräteerkennung über den Konfigurator (Key und IP vorausgefüllt).
- Interaktive Kachel-Visualisierungen für Thermostat, Rollladen und Steckdose.

Unterstützte Geräte:

| Modelle | Typ | Status |
|---|---|---|
| MSS110/210/210P/310/310H/420F/425E/510/550/570/620/710 | Steckdose / Schalter / Leiste (bis 6 Kanäle, inkl. Messwerte) | MSS210P getestet |
| MRS100 | Rollladen | getestet |
| MTS200 / MTS200B / MTS215B / MTS960 | Eigenständiger WLAN-Thermostat | MTS200/215B getestet |
| MSL100/120/320/420/430, MSS560 | Lampe / Dimmer / LED | dokumentiert, zu testen |
| MSG100 / MSG200 | Garagentoröffner | dokumentiert, zu testen |
| MSH300 (+ MS100/MS200/MTS100/MTS150) | Hub mit Sensoren & Thermostat-Ventilen | dokumentiert, zu testen |

## 2. Voraussetzungen

- IP-Symcon ab **Version 8.0**
- Meross-Geräte im selben lokalen Netz; **feste IP** (z. B. in der Fritzbox) empfohlen
- Meross-Konto (E-Mail + Passwort) für den einmaligen Geräte-Abruf

## 3. Software-Installation

- Über den **Module Store** das Modul „Meross" installieren.
- Alternativ über die Kerninstanz **Modules** das Repository hinzufügen:
  `https://github.com/tomson9183/IPSymcon-Meross`

## 4. Einrichten der Instanzen in IP-Symcon

- Unter „Instanz hinzufügen" den **Meross Konfigurator** wählen.
  1. Meross **E-Mail** und **Passwort** eintragen, **Region** wählen, **Übernehmen**.
  2. **„Geräte aus der Cloud laden"** – holt Konto-Key, Geräteliste und sucht die
     lokalen IPs (bei 2FA den Code eintragen und erneut laden). *Nur hier* erfolgt
     eine Cloud-Anmeldung.
  3. Geräte mit **„Hinzufügen"** anlegen – die **Meross Gerät**-Instanzen werden mit
     Key und IP vorausgefüllt erstellt. Danach läuft alles lokal.

Eine **Meross Gerät**-Instanz kann auch manuell angelegt werden. Einstellungen:

| Name | Beschreibung |
|---|---|
| Lokale IP des Geräts | IP-Adresse im LAN (feste IP empfohlen) |
| Gerätetyp | Steckdose / Rollladen / Thermostat / Lampe / Garage / Hub … |
| Name | Bezeichnung der Instanz |
| Konto-Key | wird beim Hinzufügen aus der Cloud automatisch gesetzt |
| Status alle … Sekunden abfragen | Poll-Intervall (Standard 3, 0 = aus) |
| Kachel-Darstellung | Automatisch / Hell / Dunkel |

> **Hinweis:** Findet die Suche keine IP, im Konfigurator das **Subnetz** eintragen
> (z. B. `192.168.178`) und „Lokale IPs erneut suchen", oder dem Gerät im Router eine
> feste IP geben und sie unter „Lokale IP des Geräts" eintragen.

## 5. Statusvariablen und Profile

Je nach Gerätetyp werden die passenden Variablen angelegt:

| Gerätetyp | Ident | Bezeichnung | Profil |
|---|---|---|---|
| Steckdose/Leiste | `STATE0`…`STATE5` | Schalter/Kanäle | ~Switch / MERO.Plug |
| | `POWER` / `VOLTAGE` / `CURRENT` / `ENERGY_TODAY` | Messwerte (falls vorhanden) | ~Watt / ~Volt / ~Ampere / ~Electricity |
| Rollladen | `MOVE` | Auf / Stop / Zu | (Buttons) |
| | `LEVEL` | Position | MERO.Pos |
| Thermostat | `TEMP` / `SET` | Ist- / Soll-Temperatur | ~Temperature / MERO.SetTemp |
| | `ONOFF` / `HEAT` / `MODE` | Ein-Aus / Heizt / Modus | ~Switch / MERO.Heat / (Buttons) |
| | `HUMI` | Luftfeuchte (nur Modelle, die sie liefern) | ~Humidity.F |
| Lampe | `STATE` / `BRIGHT` / `COLOR` / `CTEMP` | An-Aus / Helligkeit / Farbe / Farbtemperatur | MERO.Light / MERO.Bright / ~HexColor / MERO.CTemp |
| Garage | `DOOR` | Garagentor | MERO.Garage |
| Hub | `TROOM_` / `TSET_` / `VON_` / `VHEAT_` / `VMODE_` / `STEMP_` / `SHUM_` / `SDOOR_` / `BAT_` (je Unter-Gerät) | Ventile & Sensoren | dynamisch |

Profile (werden automatisch angelegt): `MERO.Plug`, `MERO.Pos`, `MERO.SetTemp`,
`MERO.Heat`, `MERO.Light`, `MERO.Bright`, `MERO.CTemp`, `MERO.Garage`, `MERO.Mts100Mode`.

## 6. Visualisierung

Thermostat, Rollladen und Steckdose besitzen eine eigene **interaktive Kachel**
(Thermostat-Dial mit Soll-Tippern und Modus, Rollladen-Fenster mit Auf/Stop/Zu und
Positions-Slider, Steckdose mit Schalter und Messwerten). Die Kacheln **skalieren mit
der eingestellten Kachelgröße** und passen sich dem **Hell-/Dunkel-Theme** an (je
Instanz über „Kachel-Darstellung" umstellbar). Alle Variablen lassen sich zusätzlich
einzeln in der Visualisierung verwenden.

> **Luftfeuchte MTS215B (Matter):** Dieses Modell gibt die Luftfeuchtigkeit nicht über
> die lokale Schnittstelle heraus (nur über Matter/Cloud) und zeigt sie daher lokal
> nicht an. Temperatur, Soll, Modus und Heizstatus funktionieren normal.

## 7. PHP-Befehlsreferenz

```php
// Status des Geräts sofort abrufen (statt auf den nächsten Poll zu warten)
MERO_Update(integer $InstanzID);
```

Das Schalten/Stellen erfolgt über die `RequestAction`-Funktion (Bedienung direkt in
der Visualisierung), eigene Public-Schaltbefehle sind nicht erforderlich.

## 8. Hinweise

- Inoffizielles Community-Modul, **keine Verbindung** zu Meross.
- Die genutzte Schnittstelle ist Community-dokumentiert; bitte die
  [Meross-Nutzungsbedingungen](https://www.meross.com/en-gc/terms-of-use) beachten.
  Nutzung **privat, nicht-kommerziell, auf eigenes Risiko**.
- Möglichst selten in der Cloud anmelden: Bei zu häufigen Anmeldungen sperrt Meross das
  Konto vorübergehend (ca. 5 Stunden). Der Konfigurator meldet sich nur per Knopfdruck an.
- Bereitstellung „wie besehen" ohne Gewähr (MIT) – besonders bei sicherheitsrelevanten
  Geräten (Garagentor, Heizung) eigenverantwortlich prüfen.

## Lizenz

MIT – siehe [LICENSE](LICENSE).
